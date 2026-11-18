@extends('layouts.store')

@section('title', 'Minha conta | '.setting('store.name'))
@section('noindex', '1')

@section('content')
<div class="mx-auto px-5 py-8 md:py-12 max-w-[900px]">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold">Olá, {{ $customer->firstName() }}!</h1>
            <p class="text-[13.5px] text-gray-600">{{ $customer->email }}</p>
        </div>
        <form method="post" action="{{ route('account.logout') }}">@csrf<button class="text-[13px] underline">Sair</button></form>
    </div>
    @if (session('status'))<div class="bg-[#f2fcf8] border border-brand text-[13px] rounded-lg p-3 mb-4">{{ session('status') }}</div>@endif

    <h2 class="text-lg font-semibold mb-3">Meus pedidos</h2>
    @forelse ($orders as $o)
        <a href="{{ $o->publicUrl() }}" class="panel p-4 mb-3 flex flex-wrap items-center gap-4 hover:border-black transition">
            <div class="flex -space-x-3">
                @foreach ($o->items->take(3) as $it)
                    <img src="{{ img_url($it->image, 120) }}" alt="" class="w-11 aspect-[4/5] object-cover rounded border-2 border-white bg-soft">
                @endforeach
            </div>
            <div class="flex-1 min-w-[160px]">
                <p class="font-semibold text-[14px]">Pedido #{{ $o->number }}</p>
                <p class="text-[12.5px] text-gray-500">{{ $o->created_at->format('d/m/Y') }} · {{ $o->items->sum('quantity') }} {{ $o->items->sum('quantity') === 1 ? 'item' : 'itens' }}</p>
            </div>
            <span @class(['pill', 'pill--dark' => in_array($o->status, ['paid', 'processing', 'shipped', 'delivered']), 'pill--soft' => ! in_array($o->status, ['paid', 'processing', 'shipped', 'delivered'])])>{{ $o->statusLabel() }}</span>
            <span class="font-semibold text-[14px]">{{ money($o->total) }}</span>
        </a>
    @empty
        <div class="panel p-8 text-center">
            <p class="font-semibold mb-2">Você ainda não tem pedidos.</p>
            <a href="{{ route('category.show', 'all') }}" class="btn btn--dark mt-3">Ver produtos</a>
        </div>
    @endforelse
    {{ $orders->links('store.partials.pagination') }}
</div>
@endsection
