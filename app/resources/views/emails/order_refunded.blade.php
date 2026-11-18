@extends('emails.layout')
@section('preheader', 'Reembolso do pedido #'.$order->number.' iniciado.')
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Reembolso do pedido #{{ $order->number }}</h1>
<p style="margin:0 0 6px;">{{ explode(' ', $order->customer_name)[0] }}, o pedido foi cancelado e o reembolso de <strong>{{ money($order->total) }}</strong> foi iniciado no mesmo meio de pagamento.</p>
<p style="margin:0;">{{ $order->payment_method === 'pix' ? 'A devolução do PIX acontece em até 5 dias úteis.' : 'O estorno no cartão pode aparecer em até 2 faturas, conforme a operadora.' }}</p>
@include('emails.partials.items')
@endsection
