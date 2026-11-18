@extends('layouts.store')

@section('title', 'Seu carrinho | '.setting('store.name'))
@section('noindex', '1')

@section('content')
<div class="mx-auto px-5 py-8 max-w-[900px]">
    <h1 class="text-2xl font-bold mb-6">Seu carrinho</h1>

    <template x-if="!$store.cart.data.items.length">
        <div class="text-center py-16">
            <p class="font-semibold mb-2">Seu carrinho está vazio</p>
            <a href="{{ route('category.show', 'all') }}" class="btn btn--dark mt-4">Continuar comprando</a>
        </div>
    </template>

    <div x-show="$store.cart.data.items.length" class="grid md:grid-cols-[1fr_320px] gap-8" :class="$store.cart.busy && 'opacity-60'">
        <div>
            <template x-for="item in $store.cart.data.items" :key="item.id">
                <div class="flex gap-4 py-4 border-b border-[#efefef]">
                    <a :href="item.url" class="w-24 shrink-0 aspect-[4/5] bg-soft overflow-hidden rounded"><img :src="item.image" :alt="item.title" class="w-full h-full object-cover"></a>
                    <div class="flex-1 min-w-0">
                        <a :href="item.url" class="text-sm font-medium" x-text="item.title"></a>
                        <p class="text-[12px] text-gray-500 mt-0.5" x-show="item.variant_title" x-text="item.variant_title"></p>
                        <p class="text-sm mt-1"><span x-text="$store.cart.money(item.price)"></span> <s class="text-gray-400 text-[12px]" x-show="item.compare_at_price > item.price" x-text="$store.cart.money(item.compare_at_price)"></s></p>
                        <div class="flex items-center gap-3 mt-3">
                            <div class="qty qty--sm">
                                <button type="button" @click="$store.cart.update(item.id, item.quantity - 1)" aria-label="Diminuir">−</button>
                                <input type="number" :value="item.quantity" min="1" max="99" aria-label="Quantidade" @change="$store.cart.update(item.id, parseInt($event.target.value) || 1)">
                                <button type="button" @click="$store.cart.update(item.id, item.quantity + 1)" aria-label="Aumentar">+</button>
                            </div>
                            <button type="button" class="text-[12px] underline" @click="$store.cart.update(item.id, 0)">Remover</button>
                        </div>
                    </div>
                    <div class="text-sm font-semibold whitespace-nowrap" x-text="$store.cart.money(item.line_total)"></div>
                </div>
            </template>
        </div>
        <aside class="panel p-5 h-fit">
            <div class="flex justify-between text-sm text-brand font-semibold mb-2" x-show="$store.cart.data.savings > 0"><span>Você economiza</span><span x-text="$store.cart.data.savings_formatted"></span></div>
            <div class="flex justify-between items-baseline mb-1"><span class="text-sm">Total estimado</span><span class="text-xl font-semibold" x-text="$store.cart.data.subtotal_formatted"></span></div>
            <p class="text-[12px] text-gray-500 mb-4">Frete grátis e descontos aplicados no checkout.</p>
            <a href="{{ route('checkout') }}" class="btn btn--brand btn--block btn--lg">Finalizar a compra</a>
            <a href="{{ route('category.show', 'all') }}" class="block text-center text-[13px] underline mt-4">Continuar comprando</a>
        </aside>
    </div>
</div>
@endsection
