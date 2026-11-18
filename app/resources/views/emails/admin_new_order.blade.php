@extends('emails.layout')
@section('preheader', 'Pedido #'.$order->number.' pago — '.money($order->total))
@section('content')
<h1 style="font-size:20px;margin:0 0 8px;">Novo pedido pago: #{{ $order->number }}</h1>
<p style="margin:0;">{{ $order->customer_name }} · {{ money($order->total) }} · {{ $order->payment_method === 'pix' ? 'PIX' : 'Cartão '.$order->installments.'x' }}
    @if ($order->utm_source)<br>Origem: {{ $order->utm_source }}{{ $order->utm_campaign ? ' / '.$order->utm_campaign : '' }}@endif
    @if ($order->risk_score >= 20)<br><strong style="color:#c0392b;">Atenção: risco do cartão {{ $order->risk_score }}/100 — confira antes de enviar.</strong>@endif
</p>
@include('emails.partials.button', ['url' => url('/admin/orders/'.$order->id), 'label' => 'Abrir no admin', 'color' => '#111111'])
@include('emails.partials.items')
@include('emails.partials.address')
@endsection
