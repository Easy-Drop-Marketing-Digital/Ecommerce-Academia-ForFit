@extends('store.account._layout')

@section('title', 'Criar conta | '.setting('store.name'))
@section('heading', 'Criar conta')

@section('form')
<form method="post" action="{{ route('account.register') }}" class="grid gap-3">
    @csrf
    <label class="block"><span class="text-[12.5px] text-gray-600">Nome</span>
        <input type="text" name="name" value="{{ old('name') }}" required autocomplete="name" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
    <label class="block"><span class="text-[12.5px] text-gray-600">E-mail</span>
        <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
    <label class="block"><span class="text-[12.5px] text-gray-600">Senha (mínimo 8 caracteres)</span>
        <input type="password" name="password" required minlength="8" autocomplete="new-password" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
    <label class="block"><span class="text-[12.5px] text-gray-600">Confirme a senha</span>
        <input type="password" name="password_confirmation" required autocomplete="new-password" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
    <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" name="accepts_marketing" value="1" checked class="accent-black"> Quero receber novidades e ofertas</label>
    <button class="btn btn--dark btn--block mt-1">Criar conta</button>
</form>
@endsection

@section('after')
<p class="text-center text-[13.5px] mt-5">Já tem conta? <a href="{{ route('account.login') }}" class="underline font-semibold">Entrar</a></p>
@endsection
