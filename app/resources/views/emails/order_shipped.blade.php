@extends('emails.layout')
@section('preheader', 'Seu pedido #'.$order->number.' foi enviado! Código de rastreio: '.$order->tracking_code)
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Seu pedido está a caminho! 🚚</h1>
<p style="margin:0 0 12px;">{{ explode(' ', $order->customer_name)[0] }}, o pedido <strong>#{{ $order->number }}</strong> foi enviado.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="background:#f6f6f6;border-radius:10px;width:100%;"><tr><td style="padding:14px 16px;font-size:13px;">
    Código de rastreio: <strong style="font-size:15px;">{{ $order->tracking_code }}</strong>@if ($order->carrier) ({{ $order->carrier }})@endif
    @if ($order->shipping_estimate)<br><span style="color:#666;">Prazo estimado: {{ $order->shipping_estimate }}</span>@endif
</td></tr></table>
<p style="margin:12px 0 0;font-size:12.5px;color:#666;">O rastreio pode levar alguns dias úteis para mostrar a primeira atualização.</p>
@include('emails.partials.button', ['url' => $order->tracking_url ?: $order->publicUrl(), 'label' => 'Rastrear entrega', 'color' => '#111111'])
@include('emails.partials.items')
@include('emails.partials.address')
@endsection
