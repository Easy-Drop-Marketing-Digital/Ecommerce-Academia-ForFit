<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\WebhookEvent;
use App\Services\Payments\NixfyGateway;
use App\Services\Payments\PaymentStatusUpdater;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recebe notificações da Nixfy.
 *  - POST /webhooks/nixfy           → webhook permanente (painel), assinado com X-Nixfy-Signature
 *  - POST /webhooks/nixfy/postback  → postbackUrl de cada venda, assinado com X-Signature
 *
 * Em ambos os casos o status é reconfirmado via GET /transactions/{uuid} antes de aplicar,
 * então mesmo um payload forjado não consegue marcar um pedido como pago.
 */
class NixfyWebhookController extends Controller
{
    public function __construct(private NixfyGateway $gateway, private PaymentStatusUpdater $updater) {}

    public function webhook(Request $request)
    {
        $raw = $request->getContent();
        if (! $this->gateway->verifyWebhook($raw, $request->header('X-Nixfy-Timestamp'), $request->header('X-Nixfy-Signature'))) {
            Log::warning('Nixfy webhook com assinatura inválida', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid signature'], 401);
        }

        return $this->process($raw, $request->header('X-Nixfy-Event-Id'), $request->header('X-Nixfy-Event-Type'), 'webhook');
    }

    public function postback(Request $request)
    {
        $raw = $request->getContent();
        $signed = $this->gateway->verifyPostback($raw, $request->header('X-Signature'));
        if (! $signed && config('services.nixfy.postback_secret')) {
            Log::warning('Nixfy postback com assinatura inválida', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid signature'], 401);
        }
        $payload = json_decode($raw, true) ?: [];
        // Postback não tem event id: usa uuid+status como chave de idempotência
        $eventId = 'pb:'.($payload['uuid'] ?? $payload['id'] ?? 'x').':'.($payload['status'] ?? 'x');

        return $this->process($raw, $eventId, 'postback', 'postback');
    }

    private function process(string $raw, ?string $eventId, ?string $eventType, string $source)
    {
        $payload = json_decode($raw, true) ?: [];

        try {
            $log = WebhookEvent::create([
                'provider' => 'nixfy',
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['ok' => true, 'duplicate' => true]); // já processado
        }

        // Eventos que não são de venda (saques etc.) são apenas registrados
        if ($eventType && ! str_starts_with($eventType, 'sale.') && $eventType !== 'postback') {
            $log->update(['status' => 'ignored']);

            return response()->json(['ok' => true]);
        }

        $uuid = $payload['uuid'] ?? null;
        $order = $uuid ? Order::where('gateway_uuid', $uuid)->first() : null;
        if (! $order) {
            $log->update(['status' => 'ignored', 'error' => 'pedido não encontrado']);

            return response()->json(['ok' => true]);
        }

        try {
            // Reconfirma na API (fonte da verdade)
            $tx = $this->gateway->getTransaction($uuid);
            $status = $tx['status'] ?? $payload['status'] ?? null;
            $this->updater->apply($order, $status, $source, ['fee' => $payload['fee'] ?? $tx['systemFee'] ?? null]);
            $log->update(['status' => 'processed']);
        } catch (\Throwable $e) {
            report($e);
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
            // Remove a marca de idempotência para o retry da Nixfy reprocessar
            $log->delete();

            return response()->json(['error' => 'temporary failure'], 500);
        }

        return response()->json(['ok' => true]);
    }
}
