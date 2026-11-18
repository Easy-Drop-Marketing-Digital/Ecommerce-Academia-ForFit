<?php

namespace App\Services\Mail;

use App\Mail\StoreMail;
use App\Models\EmailLog;
use App\Models\EmailSuppression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Envio de todos os e-mails da loja.
 *  - respeita o liga/desliga de cada e-mail (Admin → Configurações → E-mails automáticos)
 *  - registra cada envio em email_logs (enviado/falhou, id da Resend) — tela "E-mails enviados" no admin
 *  - nunca lança erro para quem chamou: falha de e-mail não pode quebrar checkout nem webhook
 */
class EmailService
{
    /** Nome de cada e-mail no admin */
    public const TEMPLATES = [
        'order_pix' => 'PIX gerado (código e QR)',
        'order_received' => 'Pedido recebido (cartão em análise)',
        'pix_reminder' => 'Lembrete de PIX não pago',
        'order_paid' => 'Pagamento aprovado',
        'payment_refused' => 'Pagamento recusado',
        'order_shipped' => 'Pedido enviado (rastreio)',
        'order_delivered' => 'Pedido entregue (pede avaliação)',
        'order_canceled' => 'Pedido cancelado',
        'order_refunded' => 'Reembolso',
        'welcome' => 'Boas-vindas (nova conta)',
        'password_reset' => 'Criar/recuperar senha',
        'cart_recovery_1' => 'Carrinho abandonado — 1º lembrete',
        'cart_recovery_2' => 'Carrinho abandonado — 2º lembrete',
        'cart_recovery_3' => 'Carrinho abandonado — 3º lembrete',
        'admin_new_order' => 'Interno: novo pedido pago',
        'admin_low_stock' => 'Interno: estoque baixo',
        'admin_card_blocked' => 'Interno: cartão bloqueado (antifraude)',
    ];

    /** Templates de marketing levam link de descadastro e respeitam o descadastro */
    public const MARKETING = ['cart_recovery'];

    public function enabled(string $template): bool
    {
        $key = str_starts_with($template, 'cart_recovery') ? 'cart_recovery' : $template;

        return (bool) (int) setting('emails.'.$key, 1);
    }

    public function send(string $template, ?string $to, string $subject, array $data = [], ?Model $about = null, bool $force = false): ?EmailLog
    {
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL) || (! $force && ! $this->enabled($template))) {
            return null;
        }

        $marketing = (bool) array_filter(self::MARKETING, fn ($m) => str_starts_with($template, $m));
        if ($marketing && EmailSuppression::isSuppressed($to)) {
            return null; // pediu para não receber
        }
        $unsubscribe = $marketing ? URL::signedRoute('email.unsubscribe', ['email' => mb_strtolower($to)]) : null;

        $log = EmailLog::create([
            'template' => $template,
            'to' => mb_strtolower($to),
            'subject' => mb_substr($subject, 0, 250),
            'subject_model_type' => $about?->getMorphClass(),
            'subject_model_id' => $about?->getKey(),
            'status' => 'queued',
        ]);

        try {
            $sent = Mail::to($to)->send(new StoreMail($template, $subject, $data, $unsubscribe));
            $log->update(['status' => 'sent', 'provider_message_id' => $sent?->getMessageId()]);
            if ($about && method_exists($about, 'log')) {
                $about->log('email', 'E-mail enviado: '.$subject);
            }
        } catch (\Throwable $e) {
            report($e);
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }

        return $log;
    }

    /** E-mails internos (pedido pago, estoque baixo) para a lista definida em Configurações → E-mails */
    public function sendToAdmins(string $template, string $subject, array $data = [], ?Model $about = null): void
    {
        $list = array_filter(array_map('trim', explode(',', (string) setting('mail.admin_notify', ''))));
        foreach ($list as $to) {
            $this->send($template, $to, $subject, $data, $about);
        }
    }
}
