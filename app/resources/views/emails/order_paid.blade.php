@extends('emails.layout')
@section('preheader', 'Pagamento aprovado! Seu pedido #'.$order->number.' já está sendo preparado.')
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Pagamento aprovado! 🎉</h1>
<p style="margin:0 0 6px;">{{ explode(' ', $order->customer_name)[0] }}, seu pedido <strong>#{{ $order->number }}</strong> foi confirmado e já está sendo preparado.</p>
<p style="margin:0;">Você recebe o código de rastreio por e-mail {{ setting('delivery.tracking_days') }}.</p>
@include('emails.partials.button', ['url' => $order->publicUrl(), 'label' => 'Acompanhar pedido', 'color' => '#111111'])
@include('emails.partials.items')
@include('emails.partials.address')
@endsection
