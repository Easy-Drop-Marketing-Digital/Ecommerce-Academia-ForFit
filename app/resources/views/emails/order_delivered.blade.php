@extends('emails.layout')
@section('preheader', 'Seu pedido #'.$order->number.' foi entregue. Conta pra gente o que achou?')
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Chegou! Esperamos que você ame 💛</h1>
<p style="margin:0 0 6px;">{{ explode(' ', $order->customer_name)[0] }}, seu pedido <strong>#{{ $order->number }}</strong> foi entregue.</p>
<p style="margin:0;">Sua opinião ajuda muitas outras mulheres a escolher. Leva menos de 1 minuto:</p>
@include('emails.partials.button', ['url' => route('reviews.form', $order->public_token), 'label' => '★ Avaliar minha compra'])
<p style="margin:0;font-size:12.5px;color:#666;">Precisa trocar o tamanho? Veja como na <a href="{{ route('policy.show', 'refund-policy') }}" style="color:#111;">política de trocas e devoluções</a>.</p>
@endsection
