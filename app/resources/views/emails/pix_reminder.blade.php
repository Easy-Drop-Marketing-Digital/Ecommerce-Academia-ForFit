@extends('emails.layout')
@section('preheader', 'Suas peças estão reservadas — finalize o PIX do pedido #'.$order->number.'.')
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Suas peças estão reservadas 💛</h1>
<p style="margin:0;">{{ explode(' ', $order->customer_name)[0] }}, o PIX do pedido <strong>#{{ $order->number }}</strong> ainda não foi pago. Separamos tudo para você@if ($order->pix_expires_at) até <strong>{{ $order->pix_expires_at->format('d/m') }} às {{ $order->pix_expires_at->format('H:i') }}</strong>@endif — depois disso o pedido é cancelado automaticamente.</p>
@if ($order->pix_code)
    <p style="margin:18px 0 6px;font-size:13px;color:#555;">PIX copia e cola:</p>
    <div style="font-family:Consolas,monospace;font-size:11.5px;word-break:break-all;background:#f6f6f6;border:1px dashed #cfcfcf;border-radius:8px;padding:12px;">{{ $order->pix_code }}</div>
@endif
@include('emails.partials.button', ['url' => $order->publicUrl(), 'label' => 'Pagar agora · '.money($order->total)])
@include('emails.partials.items')
@endsection
