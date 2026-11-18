@extends('layouts.store')

@section('title', 'Descadastro confirmado | '.setting('store.name'))
@section('noindex', '1')

@section('content')
<div class="mx-auto px-5 py-16 max-w-[560px] text-center">
    <h1 class="text-2xl font-bold">Pronto, você não vai mais receber nossos lembretes</h1>
    <p class="text-[14px] text-gray-600 mt-3">
        O e-mail <strong>{{ $email }}</strong> foi removido da lista de lembretes e novidades.
        Você continua recebendo apenas o que é sobre os seus pedidos (confirmação, PIX, envio e entrega).
    </p>
    <p class="text-[13px] text-gray-500 mt-6">Mudou de ideia? É só responder qualquer e-mail nosso pedindo para voltar.</p>
    <a href="{{ route('home') }}" class="btn btn--dark mt-8">Voltar para a loja</a>
</div>
@endsection
