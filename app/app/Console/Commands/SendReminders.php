<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Models\Order;
use App\Services\Mail\OrderEmails;
use Illuminate\Console\Command;

/**
 * Lembretes automáticos (agendado a cada 10 minutos):
 *  - PIX gerado e não pago há 30 min (antes de vencer) → 1 lembrete
 *  - Carrinho abandonado com e-mail → até 3 lembretes (horas configuráveis; o 3º pode levar cupom)
 */
class SendReminders extends Command
{
    protected $signature = 'emails:reminders';

    protected $description = 'Envia lembrete de PIX não pago e e-mails de carrinho abandonado';

    public function handle(OrderEmails $emails): int
    {
        $pix = 0;
        if ((bool) (int) setting('emails.pix_reminder', 1)) {
            Order::where('status', 'pending')->where('payment_method', 'pix')->whereNotNull('pix_code')
                ->whereNull('pix_reminder_sent_at')->whereNull('paid_at')
                ->where('created_at', '<=', now()->subMinutes(30))
                ->where(fn ($q) => $q->whereNull('pix_expires_at')->orWhere('pix_expires_at', '>', now()->addMinutes(15)))
                ->limit(200)->get()
                ->each(function (Order $o) use ($emails, &$pix) {
                    $emails->pixReminder($o);
                    $pix++;
                });
        }

        $carts = 0;
        if ((bool) (int) setting('emails.cart_recovery', 1)) {
            $hours = array_values(array_filter(array_map('intval', explode(',', (string) setting('cart_recovery.hours', '1,24,48')))));
            $hours = array_pad(array_slice($hours, 0, 3), 3, end($hours) ?: 48);

            Cart::whereIn('status', ['active', 'abandoned'])
                ->whereNotNull('email')->whereNotNull('checkout_started_at')
                ->where('recovery_emails_sent', '<', 3)
                ->where('checkout_started_at', '>=', now()->subDays(7))
                ->whereHas('items')
                ->limit(300)->get()
                ->each(function (Cart $cart) use ($emails, $hours, &$carts) {
                    $step = $cart->recovery_emails_sent + 1;
                    if ($cart->checkout_started_at->gt(now()->subHours($hours[$step - 1]))) {
                        return; // ainda não chegou a hora deste lembrete
                    }
                    // Nunca dois lembretes colados (ex.: agendador parado por horas): respeita o intervalo entre eles
                    $gap = $step > 1 ? max(1, $hours[$step - 1] - $hours[$step - 2]) : 0;
                    if ($gap && $cart->last_recovery_email_at && $cart->last_recovery_email_at->gt(now()->subHours($gap))) {
                        return;
                    }
                    // Já comprou depois de abandonar? Não incomoda.
                    $bought = Order::where('customer_email', $cart->email)->whereNotNull('paid_at')
                        ->where('created_at', '>=', $cart->checkout_started_at)->exists();
                    if ($bought) {
                        $cart->forceFill(['status' => 'converted'])->save();

                        return;
                    }
                    $emails->cartRecovery($cart, $step);
                    $cart->forceFill(['status' => 'abandoned', 'recovery_emails_sent' => $step, 'last_recovery_email_at' => now()])->save();
                    $carts++;
                });
        }

        $this->info("PIX lembrados: $pix · carrinhos lembrados: $carts");

        return self::SUCCESS;
    }
}
