@extends('store.account._layout')

@section('title', 'Entrar | '.setting('store.name'))
@section('heading', 'Minha conta')
@section('sub', 'Entre para acompanhar seus pedidos.')

@section('form')
<form method="post" action="{{ route('account.login') }}" class="grid gap-3">
    @csrf
    <label class="block"><span class="text-[12.5px] text-gray-600">E-mail</span>
        <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
    <label class="block"><span class="text-[12.5px] text-gray-600">Senha</span>
        <input type="password" name="password" required autocomplete="current-password" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
    <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" name="remember" value="1" class="accent-black"> Manter conectada</label>
    <button class="btn btn--dark btn--block mt-1">Entrar</button>
    <a href="{{ route('account.password.request') }}" class="text-center text-[13px] underline">Criar ou recuperar senha</a>
</form>
@endsection

@section('after')
<p class="text-center text-[13.5px] mt-5">Ainda não tem conta? <a href="{{ route('account.register') }}" class="underline font-semibold">Cadastre-se</a></p>
<p class="text-center text-[12.5px] text-gray-500 mt-2">Comprou sem cadastro? Use <a href="{{ route('account.password.request') }}" class="underline">Criar senha</a> com o e-mail do pedido, ou <a href="{{ route('page.show', 'rastrear-pedido') }}" class="underline">rastreie pelo número do pedido</a>.</p>
@endsection
