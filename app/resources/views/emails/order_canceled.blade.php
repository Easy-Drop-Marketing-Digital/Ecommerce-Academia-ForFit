@extends('emails.layout')
@section('preheader', 'O pedido #'.$order->number.' foi cancelado. Nenhum valor foi cobrado.')
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Pedido #{{ $order->number }} cancelado</h1>
<p style="margin:0 0 6px;">{{ explode(' ', $order->customer_name)[0] }}, {{ $order->payment_method === 'pix' ? 'o PIX não foi pago dentro do prazo' : 'o pagamento não foi concluído' }} e o pedido foi cancelado. <strong>Nenhum valor foi cobrado.</strong></p>
<p style="margin:0;">Ainda quer as peças? É só fazer o pedido de novo — se ainda houver estoque, elas são suas.</p>
@include('emails.partials.button', ['url' => url('/'), 'label' => 'Voltar para a loja', 'color' => '#111111'])
@include('emails.partials.items')
@endsection
