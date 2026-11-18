@extends('store.account._layout')

@section('title', 'Nova senha | '.setting('store.name'))
@section('heading', 'Defina sua senha')

@section('form')
<form method="post" action="{{ route('account.password.update') }}" class="grid gap-3">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <label class="block"><span class="text-[12.5px] text-gray-600">E-mail</span>
        <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
    <label class="block"><span class="text-[12.5px] text-gray-600">Nova senha (mínimo 8 caracteres)</span>
        <input type="password" name="password" required minlength="8" autocomplete="new-password" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
    <label class="block"><span class="text-[12.5px] text-gray-600">Confirme a senha</span>
        <input type="password" name="password_confirmation" required autocomplete="new-password" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
    <button class="btn btn--dark btn--block mt-1">Salvar senha</button>
</form>
@endsection
