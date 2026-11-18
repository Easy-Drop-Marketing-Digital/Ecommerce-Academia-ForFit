<?php

namespace App\Services\Mail;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Cart\CartService;
use Illuminate\Support\Facades\Cache;

/** Monta assunto + dados de cada e-mail da loja e envia pelo EmailService. */
class OrderEmails
{
    public function __construct(private EmailService $mail) {}

    private function send(string $template, Order $order, string $subject): void
    {
        $order->loadMissing('items', 'discounts');
        $this->mail->send($template, $order->customer_email, $subject, ['order' => $order], $order);
    }

    /** Logo após criar o pedido: PIX gerado ou cartão em análise (pago/recusado têm e-mails próprios) */
    public function placed(Order $order): void
    {
        if ($order->isPaid() || in_array($order->payment_status, ['refused', 'failed'], true)) {
            return;
        }
        if ($order->payment_method === 'pix' && $order->pix_code) {
            $this->pix($order);
        } elseif ($order->payment_method === 'credit_card') {
            $this->send('order_received', $order, "Recebemos seu pedido #{$order->number}");
        }
    }

    public function pix(Order $order): void
    {
        $this->send('order_pix', $order, "Seu PIX do pedido #{$order->number} — ".money($order->total));
    }

    public function pixReminder(Order $order): void
    {
        $this->send('pix_reminder', $order, "Suas peças estão reservadas — finalize o PIX do pedido #{$order->number}");
        $order->forceFill(['pix_reminder_sent_at' => now()])->saveQuietly();
    }

    public function paid(Order $order): void
    {
        $this->send('order_paid', $order, "Pagamento aprovado! Pedido #{$order->number} confirmado");
        $order->loadMissing('items');
        $this->mail->sendToAdmins('admin_new_order', "[Pedido pago] #{$order->number} — ".money($order->total), ['order' => $order], $order);
    }

    public function refused(Order $order): void
    {
        // várias tentativas seguidas geram um único e-mail (30 min)
        $recent = EmailLog::where('template', 'payment_refused')->where('subject_model_type', $order->getMorphClass())
            ->where('subject_model_id', $order->id)->where('created_at', '>=', now()->subMinutes(30))->exists();
        if (! $recent) {
            $this->send('payment_refused', $order, "Pagamento não aprovado — seu pedido #{$order->number} continua reservado");
        }
    }

    public function shipped(Order $order): void
    {
        $this->send('order_shipped', $order, "Seu pedido #{$order->number} foi enviado! 🚚");
    }

    public function delivered(Order $order): void
    {
        $this->send('order_delivered', $order, "Pedido #{$order->number} entregue — conta pra gente o que achou?");
    }

    public function canceled(Order $order): void
    {
        $this->send('order_canceled', $order, "Pedido #{$order->number} cancelado");
    }

    public function refunded(Order $order): void
    {
        $this->send('order_refunded', $order, "Reembolso do pedido #{$order->number}");
    }

    public function welcome(Customer $customer): void
    {
        $this->mail->send('welcome', $customer->email, 'Bem-vinda à '.setting('store.name').'!', ['customer' => $customer], $customer);
    }

    /** Lembrete de carrinho abandonado (passo 1, 2 ou 3) */
    public function cartRecovery(Cart $cart, int $step): ?EmailLog
    {
        $data = app(CartService::class)->toArray($cart->loadMissing('items.variant.product.images', 'items.variant.image'));
        if (! $data['items']) {
            return null;
        }
        $coupon = $step === 3 ? (trim((string) setting('cart_recovery.coupon')) ?: null) : null;
        $subjects = [
            1 => 'Você esqueceu algo no carrinho 👀',
            2 => 'Suas peças ainda estão esperando por você',
            3 => $coupon ? "Cupom {$coupon} liberado para você finalizar 💛" : 'Última chamada para as suas peças',
        ];

        return $this->mail->send("cart_recovery_{$step}", $cart->email, $subjects[$step], [
            'cart' => $cart, 'step' => $step, 'items' => $data['items'], 'subtotal' => $data['subtotal'], 'coupon' => $coupon,
        ], $cart);
    }

    /** Aviso interno: antifraude barrou o cartão (1 aviso por pedido por hora, mesmo com várias tentativas) */
    public function cardBlocked(Order $order, int $score, array $flags): void
    {
        if (Cache::add('cardblocked.'.$order->id, 1, now()->addHour())) {
            $this->mail->sendToAdmins('admin_card_blocked', "[Antifraude] Cartão bloqueado no pedido #{$order->number}",
                ['order' => $order, 'score' => $score, 'flags' => $flags], $order);
        }
    }

    /** Aviso interno: variações do pedido que ficaram com estoque baixo (1 aviso por variação por dia) */
    public function lowStock(Order $order): void
    {
        $threshold = (int) setting('stock.low_threshold', 10);
        $ids = $order->items()->pluck('variant_id')->filter();
        $low = ProductVariant::with('product')->whereIn('id', $ids)->where('stock', '<=', $threshold)->get()
            ->filter(fn ($v) => $v->product?->track_stock && ! $v->product?->allow_backorder)
            ->filter(fn ($v) => Cache::add('lowstock.'.$v->id.'.'.now()->toDateString(), 1, now()->endOfDay()));
        if ($low->isNotEmpty()) {
            $this->mail->sendToAdmins('admin_low_stock', '[Estoque baixo] '.$low->count().' variação(ões)', ['variants' => $low, 'threshold' => $threshold]);
        }
    }
}
