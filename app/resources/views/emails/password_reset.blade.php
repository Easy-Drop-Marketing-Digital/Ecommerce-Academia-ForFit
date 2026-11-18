@extends('emails.layout')
@section('preheader', 'Crie uma nova senha para acessar seus pedidos.')
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">Criar nova senha</h1>
<p style="margin:0;">Recebemos um pedido para criar ou redefinir a senha da sua conta. Clique no botão abaixo — o link vale por 60 minutos.</p>
@include('emails.partials.button', ['url' => $url, 'label' => 'Criar nova senha', 'color' => '#111111'])
<p style="margin:0;font-size:12.5px;color:#666;">Se não foi você, ignore este e-mail: sua senha continua a mesma.</p>
@endsection
