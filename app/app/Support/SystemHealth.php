<?php

namespace App\Support;

use App\Models\EmailLog;
use App\Models\ProductImage;
use App\Models\TrackingDispatch;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Checagens de "a loja está saudável?" para a tela Saúde da loja e o aviso do Painel.
 * status: ok | warn | error
 */
class SystemHealth
{
    public const HEARTBEAT = 'health.cron_at';

    /** Chamado pelo agendador a cada minuto (routes/console.php) */
    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT, now()->timestamp);
    }

    /**
     * @param  bool  $fresh  true na própria tela; o selo do menu e o aviso do Painel usam o resultado de até 1 min atrás
     * @return list<array{key:string,label:string,status:string,detail:string,hint:?string,icon:string}>
     */
    public static function checks(bool $fresh = false): array
    {
        if (! $fresh) {
            return Cache::remember('health.checks', 60, fn () => self::checks(true));
        }
        $prod = app()->isProduction();
        $c = [];

        // 1) Agendador
        $beat = Cache::get(self::HEARTBEAT);
        $c[] = self::item('cron', 'Tarefas automáticas (cron)', 'heroicon-o-clock',
            $beat && $beat >= now()->subMinutes(3)->timestamp ? 'ok' : 'error',
            $beat ? 'Última execução '.\Carbon\Carbon::createFromTimestamp($beat)->diffForHumans() : 'Nunca executou',
            'Sem o cron, PIX vencidos não são cancelados, lembretes não saem e eventos não chegam ao Meta. Na Hostinger: hPanel → Avançado → Cron Jobs, a cada minuto: cd '.base_path().' && php artisan schedule:run');

        // 2) Pagamentos
        $keys = config('services.nixfy.public_key') && config('services.nixfy.private_key') && config('services.nixfy.webhook_secret');
        $sandbox = (bool) config('services.nixfy.sandbox');
        $lastHook = WebhookEvent::where('provider', 'nixfy')->latest('id')->value('created_at');
        $failedHooks = WebhookEvent::where('provider', 'nixfy')->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();
        $c[] = self::item('nixfy', 'Pagamentos — Nixfy', 'heroicon-o-credit-card',
            ! $keys || ($sandbox && $prod) ? 'error' : ($failedHooks ? 'warn' : 'ok'),
            ! $keys ? 'Chaves ou segredo do webhook não cadastrados'
                : ($sandbox ? 'Modo simulação ligado' : 'Chaves cadastradas · último aviso da Nixfy: '.($lastHook ? \Carbon\Carbon::parse($lastHook)->diffForHumans() : 'nenhum ainda'))
                .($failedHooks ? " · $failedHooks aviso(s) com erro em 24h" : ''),
            ! $keys ? 'Admin → Configurações da loja → Pagamentos — Nixfy. No painel da Nixfy, o webhook deve apontar para '.url('/webhooks/nixfy') : null);

        // 3) E-mails
        $mailer = config('mail.default');
        $failedMail = EmailLog::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();
        $c[] = self::item('email', 'E-mails (Resend)', 'heroicon-o-envelope',
            $mailer === 'log' || ($mailer === 'resend' && ! config('services.resend.key')) ? ($prod ? 'error' : 'warn') : ($failedMail ? 'warn' : 'ok'),
            ($mailer === 'resend' && config('services.resend.key') ? 'Enviando pela Resend' : 'Não estão saindo de verdade (modo de teste)')
                .($failedMail ? " · $failedMail falha(s) em 24h" : ''),
            $mailer === 'resend' && config('services.resend.key') ? null : 'Cadastre a chave da Resend em Configurações da loja → E-mail e verifique o domínio (SPF/DKIM) na Resend.');

        // 4) Rastreamento (Meta, TikTok, Google)
        $pending = TrackingDispatch::where('status', 'pending')->where('created_at', '<=', now()->subMinutes(10))->count();
        $failedTrack = TrackingDispatch::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();
        $meta = config('services.meta.pixel_id') && config('services.meta.capi_token');
        $c[] = self::item('tracking', 'Pixels e API de Conversões', 'heroicon-o-signal',
            ! $meta || $pending > 100 ? 'warn' : ($failedTrack > 20 ? 'warn' : 'ok'),
            ($meta ? 'Meta Pixel + API de Conversões ativos' : 'Meta Pixel/API de Conversões não configurados')
                .($pending ? " · $pending evento(s) atrasado(s)" : '').($failedTrack ? " · $failedTrack com erro em 24h" : ''),
            $meta ? ($pending ? 'Eventos atrasados indicam que o cron não está rodando.' : null) : 'Configurações da loja → Meta (Facebook/Instagram).');

        // 5) Fotos
        $remote = ProductImage::where('path', 'like', 'http%')->count();
        $c[] = self::item('images', 'Fotos dos produtos', 'heroicon-o-photo',
            $remote ? 'warn' : 'ok',
            $remote ? "$remote foto(s) ainda no servidor da Shopify" : 'Todas na hospedagem, em WebP',
            $remote ? 'Antes de cancelar a Shopify, rode: php artisan images:localize' : null);

        // 6) Velocidade
        $stats = PageCache::stats();
        $c[] = self::item('cache', 'Cache de páginas', 'heroicon-o-bolt',
            PageCache::enabled() || ! $prod ? 'ok' : 'warn',
            PageCache::enabled() ? $stats['pages'].' página(s) prontas em cache' : 'Desligado'.($prod ? '' : ' (ambiente de testes)'),
            PageCache::enabled() || ! $prod ? null : 'Ative com PAGE_CACHE=true no .env para aguentar picos (Black Friday).');

        // 7) Servidor
        $debug = (bool) config('app.debug');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $link = file_exists(public_path('storage'));
        $free = @disk_free_space(storage_path()) ?: null;
        $problems = array_filter([
            $debug && $prod ? 'modo debug LIGADO (expõe dados em erros)' : null,
            ! $https && $prod ? 'APP_URL sem https' : null,
            ! $link ? 'link de imagens ausente (php artisan storage:link)' : null,
            $free !== null && $free < 1024 ** 3 ? 'menos de 1 GB livre' : null,
        ]);
        $c[] = self::item('server', 'Servidor', 'heroicon-o-server',
            $debug && $prod || ! $link ? 'error' : ($problems ? 'warn' : 'ok'),
            $problems ? ucfirst(implode(' · ', $problems)) : 'PHP '.PHP_VERSION.($free ? ' · '.round($free / 1024 ** 3, 1).' GB livres' : ''),
            null);

        // 8) Erros recentes
        $errors = self::recentErrors();
        $failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $c[] = self::item('errors', 'Erros nas últimas 24h', 'heroicon-o-exclamation-triangle',
            $errors + $failedJobs > 20 ? 'warn' : 'ok',
            $errors + $failedJobs ? "$errors erro(s) no log · $failedJobs tarefa(s) com falha" : 'Nenhum',
            $errors ? 'Detalhes em storage/logs (peça ao desenvolvedor para revisar se o número crescer).' : null);

        // 9) Acesso ao admin
        $no2fa = User::whereNull('app_authentication_secret')->count();
        $c[] = self::item('admin', 'Segurança do admin', 'heroicon-o-shield-check',
            $no2fa ? 'warn' : 'ok',
            $no2fa ? "$no2fa usuário(s) sem verificação em duas etapas" : 'Todos com verificação em duas etapas',
            $no2fa ? 'Cada pessoa ativa em: menu do usuário (canto superior direito) → Perfil → App autenticador.' : null);

        return $c;
    }

    /** Problemas graves para o aviso no topo do Painel */
    public static function critical(): array
    {
        return array_values(array_filter(self::checks(), fn ($c) => $c['status'] === 'error'));
    }

    private static function item(string $key, string $label, string $icon, string $status, string $detail, ?string $hint): array
    {
        return compact('key', 'label', 'icon', 'status', 'detail', 'hint');
    }

    /** Conta erros de hoje/ontem no fim do log (lê só os últimos 512 KB) */
    private static function recentErrors(): int
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        $since = now()->subDay();
        $count = 0;
        foreach ($files as $file) {
            if (filemtime($file) < $since->timestamp) {
                continue;
            }
            $fh = @fopen($file, 'r');
            if (! $fh) {
                continue;
            }
            $size = filesize($file);
            fseek($fh, max(0, $size - 512 * 1024));
            $chunk = (string) stream_get_contents($fh);
            fclose($fh);
            preg_match_all('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/m', $chunk, $m);
            foreach ($m[1] as $ts) {
                if ($ts >= $since->format('Y-m-d H:i:s')) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
