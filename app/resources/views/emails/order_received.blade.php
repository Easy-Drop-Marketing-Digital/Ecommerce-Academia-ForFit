@extends('emails.layout')
@section('preheader', 'Recebemos seu pedido #'.$order->number.' e estamos confirmando o pagamento.')
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Recebemos seu pedido #{{ $order->number }}</h1>
<p style="margin:0;">Obrigada, {{ explode(' ', $order->customer_name)[0] }}! O pagamento com cartão está em análise e você recebe outro e-mail assim que ele for aprovado.</p>
@include('emails.partials.button', ['url' => $order->publicUrl(), 'label' => 'Acompanhar pedido', 'color' => '#111111'])
@include('emails.partials.items')
@include('emails.partials.address')
@endsection
