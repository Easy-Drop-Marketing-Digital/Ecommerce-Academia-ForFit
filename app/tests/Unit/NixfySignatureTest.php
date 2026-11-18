<?php

namespace Tests\Unit;

use App\Services\Payments\NixfyGateway;
use PHPUnit\Framework\TestCase;

class NixfySignatureTest extends TestCase
{
    private function gw(): NixfyGateway
    {
        return new NixfyGateway(['webhook_secret' => 'whsec_test', 'postback_secret' => 'pb_test', 'base_url' => 'http://x']);
    }

    public function test_valid_webhook_signature_is_accepted(): void
    {
        $body = '{"uuid":"abc","status":"PAGO"}';
        $ts = (string) time();
        $sig = 'sha256='.hash_hmac('sha256', "$ts.$body", 'whsec_test');

        $this->assertTrue($this->gw()->verifyWebhook($body, $ts, $sig));
    }

    public function test_tampered_body_is_rejected(): void
    {
        $ts = (string) time();
        $sig = 'sha256='.hash_hmac('sha256', $ts.'.{"status":"PENDENTE"}', 'whsec_test');

        $this->assertFalse($this->gw()->verifyWebhook('{"status":"PAGO"}', $ts, $sig));
    }

    public function test_old_timestamp_is_rejected(): void
    {
        $body = '{}';
        $ts = (string) (time() - 600);
        $sig = 'sha256='.hash_hmac('sha256', "$ts.$body", 'whsec_test');

        $this->assertFalse($this->gw()->verifyWebhook($body, $ts, $sig));
    }

    public function test_postback_signature(): void
    {
        $body = '{"uuid":"abc","status":"PAGO"}';
        $this->assertTrue($this->gw()->verifyPostback($body, hash_hmac('sha256', $body, 'pb_test')));
        $this->assertFalse($this->gw()->verifyPostback($body, hash_hmac('sha256', $body, 'wrong')));
    }

    public function test_status_mapping(): void
    {
        $this->assertSame('paid', NixfyGateway::mapStatus('PAGO'));
        $this->assertSame('med', NixfyGateway::mapStatus('MED'));
        $this->assertSame('pending', NixfyGateway::mapStatus('DESCONHECIDO'));
    }
}
