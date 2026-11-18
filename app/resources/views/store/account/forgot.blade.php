@extends('store.account._layout')

@section('title', 'Criar ou recuperar senha | '.setting('store.name'))
@section('heading', 'Criar ou recuperar senha')
@section('sub', 'Informe o e-mail usado na compra. Enviaremos um link para você definir sua senha.')

@section('form')
<form method="post" action="{{ route('account.password.email') }}" class="grid gap-3">
    @csrf
    <label class="block"><span class="text-[12.5px] text-gray-600">E-mail</span>
        <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
    <button class="btn btn--dark btn--block mt-1">Enviar link</button>
</form>
@endsection

@section('after')
<p class="text-center text-[13.5px] mt-5"><a href="{{ route('account.login') }}" class="underline">Voltar para o login</a></p>
@endsection
