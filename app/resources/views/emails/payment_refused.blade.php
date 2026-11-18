@extends('emails.layout')
@section('preheader', 'O pagamento do pedido #'.$order->number.' não foi aprovado — suas peças continuam reservadas.')
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Pagamento não aprovado</h1>
<p style="margin:0 0 6px;">{{ explode(' ', $order->customer_name)[0] }}, o banco emissor não aprovou o cartão no pedido <strong>#{{ $order->number }}</strong>. Isso costuma acontecer por limite, segurança do banco ou algum dado digitado.</p>
<p style="margin:0;">Suas peças continuam reservadas: tente outro cartão ou pague com <strong>PIX</strong> (aprovação na hora).</p>
@include('emails.partials.button', ['url' => $order->publicUrl(), 'label' => 'Tentar novamente'])
@include('emails.partials.items')
@endsection
