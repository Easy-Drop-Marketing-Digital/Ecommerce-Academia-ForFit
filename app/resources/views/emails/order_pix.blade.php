@extends('emails.layout')
@section('preheader', 'Pague o PIX de '.money($order->total).' para confirmar seu pedido #'.$order->number.'.')
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Falta só o PIX, {{ explode(' ', $order->customer_name)[0] }}!</h1>
<p style="margin:0 0 6px;">Recebemos seu pedido <strong>#{{ $order->number }}</strong>. Assim que o PIX for pago, ele é confirmado na hora.</p>
<p style="margin:0;">Valor: <strong>{{ money($order->total) }}</strong>@if ($order->pix_expires_at) · válido até <strong>{{ $order->pix_expires_at->format('d/m') }} às {{ $order->pix_expires_at->format('H:i') }}</strong>@endif</p>
@if ($order->pix_code)
    <p style="margin:18px 0 6px;font-size:13px;color:#555;">PIX copia e cola (copie e cole no app do seu banco):</p>
    <div style="font-family:Consolas,monospace;font-size:11.5px;word-break:break-all;background:#f6f6f6;border:1px dashed #cfcfcf;border-radius:8px;padding:12px;">{{ $order->pix_code }}</div>
@endif
@include('emails.partials.button', ['url' => $order->publicUrl(), 'label' => 'Ver QR Code e pagar'])
@include('emails.partials.items')
@include('emails.partials.address')
@endsection
