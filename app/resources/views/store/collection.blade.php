@extends('layouts.store')

@section('title', $meta['seo_title'])
@if ($meta['seo_description'])@section('description', $meta['seo_description'])@endif
@if ($meta['canonical'])@section('canonical', $meta['canonical'])@endif
@if (! empty($meta['noindex']) || request()->hasAny(['sort_by', 'availability', 'price_min', 'price_max', 'marca']))@section('noindex', '1')@endif

@isset($meta['search'])
    @if ($meta['search'] !== '')
        @push('head')<script>sdq.push(['search', { search: @json($meta['search']) }]);</script>@endpush
    @endif
@endisset

@section('content')
<div class="page-width">
    @php $brandPage = $meta['brand'] ?? null; @endphp
    <nav class="breadcrumbs" aria-label="Você está em">
        <a href="{{ route('home') }}">Início</a> <span>/</span>
        @if ($brandPage)<span>Marcas</span> <span>/</span>@endif
        <span class="text-ink">{{ $meta['title'] }}</span>
    </nav>
    @if ($brandPage && $brandPage->banner_image)
        <div class="brand-hero"><img src="{{ img_url($brandPage->banner_image, 1920) }}" alt="{{ $brandPage->name }}" width="1920" height="360"></div>
    @endif
    <div class="collection-head">
        <h1>{{ $meta['title'] }}</h1>
        @if ($meta['description'])<div class="rte mt-2 text-[13px] text-gray-600">{!! $meta['description'] !!}</div>@endif
        @isset($meta['search'])
            <form action="{{ route('search') }}" method="get" class="mt-4 flex gap-2 max-w-xl" role="search">
                <input name="q" type="search" value="{{ $meta['search'] }}" placeholder="Buscar produtos" class="flex-1 h-11 border border-line rounded-lg px-3 outline-none focus:border-black">
                <button class="btn btn--blue !min-h-11">Buscar</button>
            </form>
        @endisset
    </div>

    <div class="collection-layout">
    <aside class="filters" aria-label="Filtros">
        @php $brandLink = fn ($slug) => request()->fullUrlWithQuery(['marca' => $slug, 'page' => null]); @endphp
        @if (($brands ?? collect())->count() > 1)
            <h3>Marca</h3>
            <a href="{{ $brandLink(null) }}" class="filter-chip" aria-current="{{ $filters['brand'] ? 'false' : 'true' }}">Todas as marcas</a>
            @foreach ($brands as $b)
                <a href="{{ $brandLink($b->slug) }}" class="filter-chip" aria-current="{{ $filters['brand'] === $b->slug ? 'true' : 'false' }}">{{ $b->name }}</a>
            @endforeach
        @endif
        <h3>Categorias</h3>
        @foreach ($menus['main'] ?? [] as $item)
            <a href="{{ $item['url'] }}" class="filter-chip" aria-current="{{ $item['url'] === url()->current() ? 'true' : 'false' }}">{{ $item['title'] }}</a>
        @endforeach
    </aside>
    <div class="min-w-0">
    @php
        $base = request()->except(['page']);
        $activeCount = (int) filled($filters['availability']) + (int) ($filters['price_min'] !== null || $filters['price_max'] !== null);
    @endphp
    <form method="get" class="facets" x-data="{ open: null }" @click.outside="open = null">
        @isset($meta['search'])<input type="hidden" name="q" value="{{ $meta['search'] }}">@endisset
        @if ($filters['brand'])<input type="hidden" name="marca" value="{{ $filters['brand'] }}">@endif
        <div class="flex items-center gap-1 md:gap-3 relative">
            <span class="hidden md:inline text-gray-500">Filtrar:</span>

            <div class="relative">
                <button type="button" class="facet-btn" @click="open = open === 'av' ? null : 'av'" :aria-expanded="open === 'av'">Disponibilidade <x-ui-icon name="chevron-down" class="w-3.5 h-3.5" /></button>
                <div x-cloak x-show="open === 'av'" class="facet-pop">
                    <label class="flex items-center gap-2 py-1.5"><input type="radio" name="availability" value="" @checked(! $filters['availability'])> Todos</label>
                    <label class="flex items-center gap-2 py-1.5"><input type="radio" name="availability" value="in" @checked($filters['availability'] === 'in')> Em estoque</label>
                    <label class="flex items-center gap-2 py-1.5"><input type="radio" name="availability" value="out" @checked($filters['availability'] === 'out')> Esgotado</label>
                    <button class="btn btn--blue btn--block !min-h-10 mt-3">Aplicar</button>
                </div>
            </div>

            <div class="relative">
                <button type="button" class="facet-btn" @click="open = open === 'pr' ? null : 'pr'" :aria-expanded="open === 'pr'">Preço <x-ui-icon name="chevron-down" class="w-3.5 h-3.5" /></button>
                <div x-cloak x-show="open === 'pr'" class="facet-pop">
                    <p class="text-[12px] text-gray-500 mb-3">O maior preço é {{ money($filters['price_ceiling']) }}</p>
                    <div class="flex items-center gap-2">
                        <label class="flex-1"><span class="sr-only">De</span><input name="price_min" inputmode="decimal" placeholder="De R$" value="{{ $filters['price_min'] !== null ? number_format($filters['price_min'] / 100, 2, ',', '') : '' }}" class="w-full h-10 border border-line rounded-lg px-2"></label>
                        <span>–</span>
                        <label class="flex-1"><span class="sr-only">Até</span><input name="price_max" inputmode="decimal" placeholder="Até R$" value="{{ $filters['price_max'] !== null ? number_format($filters['price_max'] / 100, 2, ',', '') : '' }}" class="w-full h-10 border border-line rounded-lg px-2"></label>
                    </div>
                    <button class="btn btn--blue btn--block !min-h-10 mt-3">Aplicar</button>
                </div>
            </div>

            @if ($activeCount)
                <a href="{{ url()->current() }}{{ isset($meta['search']) ? '?q='.urlencode($meta['search']) : '' }}" class="text-[12px] underline underline-offset-2 ml-1">Limpar filtros</a>
            @endif
        </div>

        <div class="flex items-center gap-3">
            <span class="hidden md:inline text-gray-500">{{ $paginator->total() }} {{ $paginator->total() === 1 ? 'item' : 'itens' }}</span>
            <label class="flex items-center gap-1">
                <span class="sr-only md:not-sr-only text-gray-500">Classificar:</span>
                <select name="sort_by" onchange="this.form.submit()" class="bg-transparent text-[13px] py-1 pr-1 outline-none cursor-pointer">
                    @foreach ($sorts as $key => $label)
                        <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </form>

    @if ($cards->isEmpty())
        <div class="text-center py-20">
            <p class="font-semibold mb-2">Nenhum produto encontrado.</p>
            <p class="text-sm text-gray-500 mb-6">Tente remover filtros ou buscar por outro termo.</p>
            <a href="{{ route('category.show', 'all') }}" class="btn btn--blue">Ver todos os produtos</a>
        </div>
    @else
        <div class="product-grid">
            @foreach ($cards as $i => $p)
                <x-product-card :p="$p" :eager="$i < 4" />
            @endforeach
        </div>
        {{ $paginator->onEachSide(1)->links('store.partials.pagination') }}
    @endif
    </div>
    </div>
</div>
@endsection
