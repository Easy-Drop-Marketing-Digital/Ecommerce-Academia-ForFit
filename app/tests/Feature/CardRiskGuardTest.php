<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Payments\CardRiskGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CardRiskGuardTest extends TestCase
{
    use DatabaseTransactions;

    private const CARD = ['number' => '4111 1111 1111 1111', 'holder_name' => 'MARIA SILVA', 'exp_month' => '12', 'exp_year' => '2030', 'cvv' => '123'];

    private function order(string $doc, string $email, string $name = 'Maria Silva'): Order
    {
        return Order::create([
            'customer_name' => $name, 'customer_email' => $email, 'customer_phone' => '11999999999',
            'customer_document' => $doc, 'shipping_address' => ['zip' => '01001000'],
            'subtotal' => 10000, 'total' => 10000, 'payment_method' => 'credit_card',
        ]);
    }

    public function test_fingerprint_is_stable_and_does_not_contain_the_card_number(): void
    {
        $a = CardRiskGuard::fingerprint('4111 1111 1111 1111');
        $b = CardRiskGuard::fingerprint('4111-1111-1111-1111');

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
        $this->assertStringNotContainsString('41111111', $a);
        $this->assertNotSame($a, CardRiskGuard::fingerprint('5555555555554444'));
    }

    public function test_clean_first_purchase_is_allowed_and_nothing_sensitive_is_stored(): void
    {
        $guard = new CardRiskGuard;
        $order = $this->order('11111111111', 'a@x.com');

        $check = $guard->assess($order, self::CARD, '10.0.0.1');
        $this->assertFalse($check['blocked']);

        $guard->record($order, $check, 'paid', self::CARD, 'VISA', '10.0.0.1');
        $order->refresh();

        $this->assertSame('1111', $order->card_last4);
        $this->assertSame('411111', $order->card_bin);
        $this->assertSame('12/2030', $order->card_exp);
        // Nem o número completo nem o CVV aparecem em nenhuma coluna do pedido ou das tentativas
        $dump = json_encode($order->getAttributes()).json_encode(DB::table('card_attempts')->get());
        $this->assertStringNotContainsString('4111111111111111', $dump);
        $this->assertStringNotContainsString('"123"', $dump);
    }

    public function test_same_card_on_many_customers_is_blocked(): void
    {
        $guard = new CardRiskGuard;
        foreach (['22222222222', '33333333333'] as $i => $doc) {
            $o = $this->order($doc, "c$i@x.com");
            $guard->record($o, $guard->assess($o, self::CARD, "10.0.1.$i"), 'paid', self::CARD, 'VISA', "10.0.1.$i");
        }

        $third = $this->order('44444444444', 'c9@x.com');
        $check = $guard->assess($third, self::CARD, '10.0.1.9');

        $this->assertTrue($check['blocked']);
        $this->assertStringContainsString('card_used_by_2_other_customers', implode(',', $check['flags']));
    }

    public function test_card_testing_bot_is_blocked_by_ip(): void
    {
        $guard = new CardRiskGuard;
        for ($i = 0; $i < 5; $i++) {
            $o = $this->order('5555555555'.$i, "bot$i@x.com");
            $card = ['number' => '400000000000000'.$i] + self::CARD;
            $guard->record($o, $guard->assess($o, $card, '200.1.1.1'), 'refused', $card, 'VISA', '200.1.1.1');
        }

        $o = $this->order('66666666666', 'bot9@x.com');
        $check = $guard->assess($o, ['number' => '5555555555554444'] + self::CARD, '200.1.1.1');

        $this->assertTrue($check['blocked']);
    }

    public function test_card_with_previous_chargeback_is_blocked(): void
    {
        $guard = new CardRiskGuard;
        $old = $this->order('77777777777', 'old@x.com');
        $guard->record($old, $guard->assess($old, self::CARD, '10.0.2.1'), 'paid', self::CARD);
        $old->update(['payment_status' => 'chargeback']);

        $new = $this->order('77777777777', 'old@x.com');
        $this->assertTrue($guard->assess($new, self::CARD, '10.0.2.1')['blocked']);
    }
}
