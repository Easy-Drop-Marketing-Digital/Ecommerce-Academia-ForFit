@extends('layouts.store')

@section('title', 'Página não encontrada | '.setting('store.name'))
@section('noindex', '1')

@section('content')
<div class="page-width text-center py-24">
    <p class="text-sm text-gray-500 mb-2">Erro 404</p>
    <h1 class="text-2xl font-bold mb-3">Página não encontrada</h1>
    <p class="text-gray-600 mb-8">O link pode estar incorreto ou a página foi removida.</p>
    <a href="{{ route('category.show', 'all') }}" class="btn btn--dark">Continuar comprando</a>
</div>
@endsection
