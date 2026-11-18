<?php

namespace Tests\Feature;

use App\Events\OrderPlaced;
use App\Filament\Resources\EmailLogs\Pages\ManageEmailLogs;
use App\Mail\StoreMail;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\EmailSuppression;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\CustomerResetPassword;
use App\Services\Mail\EmailPreview;
use App\Services\Mail\EmailService;
use App\Services\Orders\OrderManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EmailsTest extends TestCase
{
    use DatabaseTransactions;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();
        Http::fake();
        Mail::fake();
        $this->product = Product::create(['title' => 'Vestido E-mail', 'slug' => 'vestido-email-'.uniqid(), 'status' => 'active']);
        $this->variant = $this->product->variants()->create(['title' => 'M', 'price' => 15990, 'stock' => 50]);
    }

    private function order(array $extra = []): Order
    {
        $o = Order::create($extra + [
            'customer_name' => 'Joana Lima', 'customer_email' => 'joana-'.uniqid().'@example.com', 'customer_phone' => '11987654321', 'customer_document' => '52998224725',
            'shipping_address' => ['zip' => '01001000', 'street' => 'Praça da Sé', 'number' => '1', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP'],
            'subtotal' => 15990, 'total' => 15990, 'payment_method' => 'pix', 'status' => 'pending', 'payment_status' => 'pending',
            'pix_code' => '00020126580014br.gov.bcb.pix0136teste', 'pix_expires_at' => now()->addHours(2), 'gateway_uuid' => (string) Str::uuid(),
        ]);
        $o->items()->create(['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'title' => 'Vestido E-mail', 'variant_title' => 'M', 'unit_price' => 15990, 'quantity' => 1, 'total' => 15990]);

        return $o;
    }

    private function cart(array $extra = []): Cart
    {
        $cart = Cart::create($extra + ['token' => (string) Str::uuid(), 'email' => 'carrinho-'.uniqid().'@example.com', 'name' => 'Bia Souza', 'checkout_started_at' => now()->subHours(2)]);
        $cart->items()->create(['variant_id' => $this->variant->id, 'quantity' => 1]);

        return $cart;
    }

    /** Roda o que foi agendado para depois da resposta (e-mails do pedido) */
    private function afterResponse(): void
    {
        $this->app->terminate();
    }

    private function sent(string $template, ?string $to = null): \Illuminate\Support\Collection
    {
        return Mail::sent(StoreMail::class, fn (StoreMail $m) => $m->template === $template && (! $to || $m->hasTo($to)));
    }

    public function test_every_template_renders(): void
    {
        $this->order();
        $this->cart();
        Customer::create(['name' => 'Cliente Render', 'email' => 'render-'.uniqid().'@example.com']);

        foreach (array_keys(EmailService::TEMPLATES) as $template) {
            $data = app(EmailPreview::class)->data($template, 'teste@example.com');
            $html = (new StoreMail($template, 'Assunto', $data, str_starts_with($template, 'cart_') ? 'https://exemplo.test/sair' : null))->render();
            $this->assertStringContainsString(setting('store.name'), $html, $template);
        }
    }

    public function test_pix_order_gets_pix_email_then_shipping_email(): void
    {
        $order = $this->order();
        event(new OrderPlaced($order));
        $this->afterResponse();

        $this->assertCount(1, $this->sent('order_pix', $order->customer_email));
        $this->assertSame('sent', EmailLog::where('to', $order->customer_email)->where('template', 'order_pix')->value('status'));
        $this->assertTrue($order->events()->where('type', 'email')->exists());

        $order->forceFill(['status' => 'paid', 'payment_status' => 'paid', 'paid_at' => now()])->save();
        app(OrderManager::class)->markShipped($order->fresh(), 'BR123456789', 'Correios');
        $this->afterResponse();
        $this->assertCount(1, $this->sent('order_shipped', $order->customer_email));
    }

    public function test_switched_off_email_is_not_sent(): void
    {
        Setting::set('emails.order_shipped', '0');
        $order = $this->order(['status' => 'paid', 'payment_status' => 'paid', 'paid_at' => now()]);

        app(OrderManager::class)->markShipped($order, 'BR123456789');
        $this->afterResponse();

        $this->assertCount(0, $this->sent('order_shipped'));
    }

    public function test_pix_reminder_goes_once_after_30_minutes(): void
    {
        $fresh = $this->order();
        $old = $this->order();
        $old->forceFill(['created_at' => now()->subMinutes(40)])->saveQuietly();

        $this->artisan('emails:reminders')->assertSuccessful();
        $this->artisan('emails:reminders')->assertSuccessful();

        $this->assertCount(1, $this->sent('pix_reminder', $old->customer_email));
        $this->assertCount(0, $this->sent('pix_reminder', $fresh->customer_email));
        $this->assertNotNull($old->fresh()->pix_reminder_sent_at);
    }

    public function test_cart_recovery_steps_respect_timing_and_unsubscribe(): void
    {
        Setting::set('cart_recovery.hours', '1,24,48');
        $cart = $this->cart();

        $this->artisan('emails:reminders');
        $this->artisan('emails:reminders'); // mesmo minuto: não repete
        $first = $this->sent('cart_recovery_1', $cart->email);
        $this->assertCount(1, $first);
        $this->assertSame(1, $cart->fresh()->recovery_emails_sent);
        $unsubscribe = $first->first()->unsubscribeUrl;
        $this->assertNotNull($unsubscribe);

        $this->travel(25)->hours();
        $this->artisan('emails:reminders');
        $this->assertCount(1, $this->sent('cart_recovery_2', $cart->email));

        // Cliente clica em "não quero mais receber"
        $this->get($unsubscribe)->assertOk()->assertSee('não vai mais receber');
        $this->assertTrue(EmailSuppression::isSuppressed($cart->email));

        // Novo carrinho do mesmo e-mail também não recebe lembrete
        $this->cart(['email' => $cart->email, 'checkout_started_at' => now()->subHours(3)]);
        $this->travel(30)->hours();
        $this->artisan('emails:reminders');
        $this->assertCount(0, $this->sent('cart_recovery_3', $cart->email));
        $this->assertCount(1, $this->sent('cart_recovery_1', $cart->email));
    }

    public function test_unsubscribe_link_cannot_be_forged(): void
    {
        $this->get('/email/descadastrar?email=alguem@example.com')->assertForbidden();
        $this->assertFalse(EmailSuppression::isSuppressed('alguem@example.com'));
    }

    public function test_cart_of_someone_who_already_bought_is_not_reminded(): void
    {
        $cart = $this->cart();
        $this->order(['customer_email' => $cart->email, 'status' => 'paid', 'payment_status' => 'paid', 'paid_at' => now()]);

        $this->artisan('emails:reminders');

        $this->assertCount(0, $this->sent('cart_recovery_1', $cart->email));
        $this->assertSame('converted', $cart->fresh()->status);
    }

    public function test_team_is_alerted_once_when_antifraud_blocks_a_card(): void
    {
        Setting::set('mail.admin_notify', 'equipe@example.com');
        $order = $this->order(['payment_method' => 'credit_card', 'pix_code' => null]);
        $emails = app(\App\Services\Mail\OrderEmails::class);

        $emails->cardBlocked($order, 70, ['customer_tried_3_other_cards']);
        $emails->cardBlocked($order, 80, ['customer_tried_4_other_cards']); // nova tentativa logo depois

        $sent = $this->sent('admin_card_blocked', 'equipe@example.com');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Compradora tentou 3 cartões diferentes', $sent->first()->render());
    }

    public function test_password_reset_uses_store_layout(): void
    {
        $customer = Customer::create(['name' => 'Rita', 'email' => 'rita-'.uniqid().'@example.com']);
        $html = (new CustomerResetPassword('token-123'))->toMail($customer)->render();

        $this->assertStringContainsString('token-123', $html);
        $this->assertStringContainsString(setting('store.name'), $html);
    }

    public function test_admin_sends_test_email_without_touching_the_order(): void
    {
        $order = $this->order();
        $owner = User::create(['name' => 'Dona', 'email' => 'dona-'.uniqid().'@example.com', 'password' => 'senha-de-teste-123', 'role' => 'owner']);
        $this->actingAs($owner);

        Livewire::test(ManageEmailLogs::class)
            ->callAction('test', ['template' => 'order_paid', 'to' => 'teste-admin@example.com'])
            ->assertHasNoActionErrors();

        $this->assertCount(1, $this->sent('order_paid', 'teste-admin@example.com'));
        $this->assertCount(0, $this->sent('order_paid', $order->customer_email));
        $this->assertFalse($order->events()->where('type', 'email')->exists());
    }
}
