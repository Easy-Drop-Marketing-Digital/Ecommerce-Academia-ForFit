<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Primeira instalação em produção, na ordem certa e sem repetir o que já foi feito:
 * banco → catálogo (pacote gerado por catalog:export: produtos, fotos, avaliações, banners, páginas…)
 * → link das imagens → feed → dono do admin → otimização.
 * Não acessa nenhuma outra loja: tudo vem do pacote.
 *
 *   php artisan store:install --catalogo=../catalogo-AAAAMMDD-HHMM.zip --owner=email@dominio
 *
 * Pode rodar de novo sem medo: cada etapa pula se já estiver feita.
 */
class StoreInstall extends Command
{
    protected $signature = 'store:install
        {--catalogo= : Pacote do catálogo (.zip gerado por php artisan catalog:export)}
        {--owner= : E-mail do dono do admin (a senha é pedida aqui no terminal)}';

    protected $description = 'Instala a loja do zero (catálogo, imagens, dono do admin)';

    public function handle(): int
    {
        if (! config('app.key')) {
            $this->error('APP_KEY vazia. Rode antes: php artisan key:generate');

            return self::FAILURE;
        }
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Sem conexão com o banco. Confira DB_HOST, DB_DATABASE, DB_USERNAME e DB_PASSWORD no .env.');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $this->step('Banco de dados', fn () => $this->call('migrate', ['--force' => true]));

        $this->step('Catálogo (produtos, fotos, avaliações, banners, páginas)', function () {
            if (Product::exists()) {
                $this->line('  já instalado — pulando (para trocar: php artisan catalog:import arquivo.zip)');

                return;
            }
            $file = $this->option('catalogo') ?: $this->ask('Caminho do pacote do catálogo (.zip)');
            $this->call('catalog:import', ['arquivo' => $file, '--force' => true]);
        });

        $this->step('Páginas e políticas', fn () => Page::exists()
            ? $this->line('  já existem (vieram no pacote)')
            : $this->call('db:seed', ['--force' => true]));

        $this->step('Link das imagens (storage)', function () {
            if (file_exists(public_path('storage'))) {
                $this->line('  já existe');

                return;
            }
            try {
                $this->call('storage:link');
            } catch (\Throwable) {
                // hospedagem compartilhada desliga symlink()/exec() no PHP — o terminal consegue
                $this->warn('  Este servidor não deixa o PHP criar o link. Rode no terminal, dentro da pasta da loja:');
                $this->line('  ln -s ../storage/app/public public/storage');
            }
        });

        $this->step('Catálogo para Meta e Google (feed)', fn () => $this->call('feeds:build'));

        $this->step('Dono do admin', function () {
            if (User::where('role', 'owner')->exists()) {
                $this->line('  já existe — pulando');

                return;
            }
            $email = $this->option('owner') ?: $this->ask('E-mail do dono do admin');
            $this->call('admin:owner', ['email' => $email]);
        });

        $this->step('Otimização (config, rotas, views, admin)', function () {
            $this->call('optimize');
            $this->call('filament:optimize');
        });

        $this->newLine();
        $this->info('Loja instalada.');
        $this->line('Próximos passos:');
        $this->line('  1. Cron (hPanel → Avançado → Cron Jobs), a cada minuto:');
        $this->line('     cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');
        $this->line('  2. Entre em '.url('/admin').' e ative a verificação em duas etapas (Perfil).');
        $this->line('  3. Cadastre as chaves em Configurações da loja (Nixfy, Resend, Meta, Google, TikTok).');
        $this->line('  4. Abra Configurações → Saúde da loja: tudo deve ficar verde antes de apontar o domínio.');

        return self::SUCCESS;
    }

    private function step(string $title, callable $fn): void
    {
        $this->newLine();
        $this->components->info($title);
        $fn();
    }
}
