@extends('emails.layout')
@php
    // Tradução dos sinais do antifraude para a equipe
    $explain = function (string $flag) {
        return match (true) {
            $flag === 'card_has_chargeback' => 'Este cartão já teve chargeback na loja',
            $flag === 'holder_name_mismatch' => 'Nome no cartão diferente do nome da compradora',
            (bool) preg_match('/^card_used_by_(\d+)_other_customers$/', $flag, $m) => "Cartão usado por {$m[1]} outro(s) CPF(s)",
            (bool) preg_match('/^customer_tried_(\d+)_other_cards$/', $flag, $m) => "Compradora tentou {$m[1]} cartões diferentes",
            (bool) preg_match('/^ip_(\d+)_refusals_1h$/', $flag, $m) => "{$m[1]} recusas do mesmo IP na última hora",
            default => $flag,
        };
    };
@endphp
@section('preheader', 'Pedido #'.$order->number.' — cartão bloqueado antes de ir para a Nixfy.')
@section('content')
<h1 style="font-size:20px;margin:0 0 8px;">Cartão bloqueado pelo antifraude</h1>
<p style="margin:0 0 12px;">A tentativa de pagamento do pedido <strong>#{{ $order->number }}</strong> ({{ money($order->total) }}) foi barrada antes de chegar à Nixfy. A cliente viu uma mensagem neutra sugerindo outro cartão ou PIX.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border-collapse:collapse;margin:0 0 6px;">
    <tr><td style="padding:6px 0;color:#777;">Cliente</td><td align="right" style="padding:6px 0;">{{ $order->customer_name }} · {{ $order->customer_email }}</td></tr>
    <tr><td style="padding:6px 0;color:#777;">Pontuação de risco</td><td align="right" style="padding:6px 0;font-weight:700;color:#c0392b;">{{ $score }}/100</td></tr>
</table>
@if ($flags)
    <p style="margin:12px 0 4px;font-weight:700;">Motivos</p>
    <ul style="margin:0;padding-left:18px;font-size:13px;">
        @foreach ($flags as $f)<li style="margin:3px 0;">{{ $explain($f) }}</li>@endforeach
    </ul>
@endif
<p style="margin:12px 0 0;font-size:12.5px;color:#666;">Se for uma cliente legítima, ela ainda pode pagar com PIX pela página do pedido. Para afrouxar a regra, ajuste Configurações → Antifraude.</p>
@include('emails.partials.button', ['url' => url('/admin/orders/'.$order->id), 'label' => 'Abrir pedido no admin', 'color' => '#111111'])
@endsection
