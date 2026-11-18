@extends('emails.layout')
@section('preheader', 'Sua conta na '.setting('store.name').' está pronta.')
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Bem-vinda, {{ $customer->firstName() }}! 💛</h1>
<p style="margin:0 0 6px;">Sua conta na {{ setting('store.name') }} está pronta. Por ela você acompanha seus pedidos, códigos de rastreio e compras anteriores.</p>
<p style="margin:0;">Suplementos Growth e Max Titanium originais, com revenda autorizada e envio rápido. Bons treinos!</p>
@include('emails.partials.button', ['url' => url('/'), 'label' => 'Conhecer as novidades', 'color' => '#111111'])
@endsection
