@extends('layouts.store')

@php
    $first = $images[0]['src'] ?? null;
    $price = $selected['price'] ?? $product->min_price;
    $compare = $selected['compare'] ?? null;
    $off = discount_percent($price, $compare);
    // destaque: maior parcela sem juros (ex.: 6x); sem isso, a maior com juros
    $freePlan = collect($plan)->where('interest', false)->last();
    $maxInst = $freePlan && $freePlan['n'] > 1 ? $freePlan : end($plan);
    $desc = trim(strip_tags((string) $product->description));
    $pixPct = pix_percent();
    $pix = pix_price($price);
    $brand = $product->brand;
    $isKit = (bool) preg_match('/^(kit|pack|combo)\b/i', $product->title);
    $freeAbove = \App\Models\ShippingMethod::where('is_active', true)->whereNotNull('free_above')->min('free_above');
    $pickup = \App\Models\ShippingMethod::where('is_active', true)->where('price', 0)->whereNull('free_above')->where('name', 'like', '%retir%')->exists();
@endphp

@section('title', ($product->seo_title ?: $product->title).' | '.setting('store.name'))
@section('description', $product->seo_description ?: \Illuminate\Support\Str::limit($desc, 160))
@section('canonical', route('product.show', $product->slug))
@section('og_type', 'product')
@if ($first)@section('og_image', $first)@endif

@push('head')
    @php $viewItem = ['items' => [['variant_id' => $selected['id'] ?? null, 'content_id' => $selected['content_id'] ?? null, 'name' => $product->title, 'price' => $price, 'quantity' => 1]]]; @endphp
    <script>sdq.push(['view_item', @json($viewItem)]);</script>
    <meta property="product:price:amount" content="{{ number_format($price / 100, 2, '.', '') }}">
    <meta property="product:price:currency" content="BRL">
    <script type="application/ld+json">{!! json_encode(array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->title,
        'description' => \Illuminate\Support\Str::limit($desc, 5000),
        'image' => array_column(array_slice($images, 0, 10), 'src'),
        'sku' => $selected['sku'] ?? (string) $product->id,
        'brand' => ['@type' => 'Brand', 'name' => $brand?->name ?? setting('store.name')],
        'aggregateRating' => $reviews['count'] > 0 ? ['@type' => 'AggregateRating', 'ratingValue' => round($reviews['avg'], 1), 'reviewCount' => $reviews['count'], 'bestRating' => 5, 'worstRating' => 1] : null,
        'review' => array_map(fn ($r) => ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $r['author']], 'reviewRating' => ['@type' => 'Rating', 'ratingValue' => $r['rating'], 'bestRating' => 5], 'reviewBody' => $r['body']], array_slice($reviews['items'], 0, 5)) ?: null,
        'offers' => array_map(fn ($v) => [
            '@type' => 'Offer',
            'url' => route('product.show', $product->slug).'?variant='.$v['id'],
            'sku' => $v['sku'] ?: (string) $v['id'],
            'priceCurrency' => 'BRL',
            'price' => number_format($v['price'] / 100, 2, '.', ''),
            'availability' => $v['available'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@type' => 'Organization', 'name' => setting('store.name')],
        ], array_slice($variants, 0, 100)),
    ], fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
<div class="page-width" x-data="productPage(@js([
    'productId' => $product->id,
    'options' => $options,
    'variants' => $variants,
    'selected' => $selected,
    'fallbackPrice' => $product->min_price,
]))">
    <nav class="breadcrumbs" aria-label="Você está em">
        <a href="{{ route('home') }}">Início</a> <span>/</span>
        @if ($category)<a href="{{ route('category.show', $category->slug) }}">{{ $category->name }}</a> <span>/</span>@endif
        <span class="text-black line-clamp-1">{{ $product->title }}</span>
    </nav>

    <div class="pdp">
        {{-- Coluna esquerda: galeria + descrição --}}
        <div class="flex flex-col gap-5 min-w-0">
            <div class="panel pdp__gallery">
                <div class="pdp__slides" x-ref="slides" @scroll.debounce.80ms="onScrollSlides">
                    @foreach ($images as $k => $img)
                        <div class="pdp__main-img">
                            <img src="{{ $img['src'] }}" @if ($img['srcset'] ?? null) srcset="{{ $img['srcset'] }}" sizes="(min-width: 990px) 50vw, 100vw" @endif alt="{{ $img['alt'] }}" width="1200" height="1500"
                                 @if ($k === 0) fetchpriority="high" loading="eager" @else loading="lazy" @endif decoding="async">
                        </div>
                    @endforeach
                </div>
                @if (count($images) > 1)
                    <div class="pdp__thumbs" role="tablist" aria-label="Fotos do produto">
                        @foreach ($images as $k => $img)
                            <button type="button" @click="img = {{ $k }}" :aria-current="img === {{ $k }}" aria-label="Foto {{ $k + 1 }}">
                                <img src="{{ $img['thumb'] }}" alt="" width="62" height="78" loading="lazy">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @include('store.partials.product-specs', ['class' => 'hidden lg:block'])
        </div>

        {{-- Coluna direita: informações e compra --}}
        <div class="panel pdp__info">
            <div class="flex flex-wrap items-center gap-1.5">
                @if ($brand)<a href="{{ $brand->url() }}" class="pdp__brand">{{ $brand->name }}</a><span class="text-line">|</span>@endif
                <span class="pill pill--ok">✓ Original · revenda autorizada</span>
                @if ($isKit)<span class="pill pill--dark">KIT</span>@endif
                @if ($product->sales_count >= 10)<span class="pill pill--soft">{{ number_format($product->sales_count, 0, ',', '.') }} vendidos</span>@endif
            </div>
            <h1 class="pdp__title">{{ $product->title }}</h1>
            @if ($reviews['count'] > 0)
                <a href="#avaliacoes" class="flex items-center gap-2 text-[12.5px] text-gray-600 -mt-1 mb-3"><x-stars :value="$reviews['avg']" :size="15" /> {{ number_format($reviews['avg'], 1, ',', '') }} · {{ number_format($reviews['count'], 0, ',', '.') }} avaliações</a>
            @endif

            <div class="pdp__price">
                <s class="compare" x-show="compare" x-text="compare ? 'de ' + money(compare) : ''" @if (! $compare) x-cloak @endif>{{ $compare ? 'de '.money($compare) : '' }}</s>
                <span class="save" x-show="off" x-text="'-' + off + '%'" @if (! $off) x-cloak @endif>{{ $off ? '-'.$off.'%' : '' }}</span>
            </div>
            @if ($pix)
                <p class="pdp__pix"><span x-text="money(Math.round(price * {{ (100 - $pixPct) / 100 }}))">{{ money($pix) }}</span><small>no PIX ({{ rtrim(rtrim(number_format($pixPct, 1, ',', ''), '0'), ',') }}% OFF)</small></p>
                <p class="pdp__installments">ou <b x-text="money(price)">{{ money($price) }}</b>@if ($maxInst && $maxInst['n'] > 1) em até {{ $maxInst['n'] }}x de <b>{{ money($maxInst['value']) }}</b>{{ $maxInst['interest'] ? '' : ' sem juros' }}@endif no cartão</p>
            @else
                <p class="pdp__pix" x-text="money(price)">{{ money($price) }}</p>
                @if ($maxInst && $maxInst['n'] > 1)<p class="pdp__installments">em até {{ $maxInst['n'] }}x de <b>{{ money($maxInst['value']) }}</b></p>@endif
            @endif
            <button type="button" class="pdp__pay-btn" @click="payModal = true"><x-ui-icon name="card" class="w-4 h-4" /> Opções de pagamento</button>

            @foreach ($options as $oi => $opt)
                <div class="opt">
                    <p class="opt__label">{{ $opt['name'] }}: <b x-text="selected[{{ $oi }}]"></b></p>
                    @if ($opt['type'] === 'select')
                        <select class="opt__select" aria-label="{{ $opt['name'] }}" @change="pick({{ $oi }}, $event.target.value)">
                            @foreach ($opt['values'] as $val)
                                <option value="{{ $val }}" :selected="selected[{{ $oi }}] === @js($val)" :disabled="!isAvailable({{ $oi }}, @js($val))">{{ $val }}</option>
                            @endforeach
                        </select>
                    @else
                    <div class="opt__values" role="radiogroup" aria-label="{{ $opt['name'] }}">
                        @foreach ($opt['values'] as $val)
                            <button type="button" class="opt__value" role="radio"
                                    @click="pick({{ $oi }}, @js($val))"
                                    :aria-pressed="selected[{{ $oi }}] === @js($val)"
                                    :aria-checked="selected[{{ $oi }}] === @js($val)"
                                    :class="!isAvailable({{ $oi }}, @js($val)) && 'is-unavailable'">{{ $val }}</button>
                        @endforeach
                    </div>
                    @endif
                </div>
            @endforeach

            {{-- Como no tema atual: sem seletor de quantidade aqui (ajuste no carrinho) — CTA em largura total --}}
            <div class="mt-5" x-ref="buy">
                <button type="button" class="btn btn--brand btn--lg btn--block whitespace-nowrap" @click="buy" :disabled="$store.cart.busy || !available">
                    <span x-show="available" class="inline-flex items-center gap-2"><x-ui-icon name="cart" class="w-5 h-5" /> ADICIONAR AO CARRINHO</span>
                    <span x-show="!available" x-cloak>ESGOTADO</span>
                </button>
            </div>

            <div class="trust-box">
                <span class="trust-box__icon" style="background: var(--color-brand)"><x-ui-icon name="truck" /></span>
                <div><strong>{{ $freeAbove ? 'Frete grátis acima de '.money((int) $freeAbove) : 'Envio para todo o Brasil' }}</strong><span>Despacho {{ setting('delivery.processing_days') }} · entrega em {{ setting('delivery.transit_days') }}</span></div>
            </div>
            @if ($pickup)
                <div class="trust-box">
                    <span class="trust-box__icon"><x-ui-icon name="store" /></span>
                    <div><strong>Retire grátis na academia</strong><span>Escolha “Retirar na academia ForFit” no checkout</span></div>
                </div>
            @endif
            <div class="trust-box">
                <span class="trust-box__icon"><x-ui-icon name="shield" /></span>
                <div><strong>Original e lacrado</strong><span>Revenda autorizada {{ $brand?->name ?? '' }} · com nota fiscal</span></div>
            </div>
        </div>

        @include('store.partials.product-specs', ['class' => 'lg:hidden'])
    </div>

    {{-- Avaliações --}}
    @if ($reviews['count'] > 0)
        <section id="avaliacoes" class="panel reviews mb-10 scroll-mt-32" x-data="reviewsList(@js($reviews))">
            <h2 class="text-[18px] font-semibold mb-5">Avaliações de clientes</h2>
            <div class="grid md:grid-cols-[260px_1fr] gap-6 md:gap-10 pb-6 border-b border-[#f0f0f0]">
                <div class="text-center md:text-left">
                    <p class="text-[44px] font-bold leading-none">{{ number_format($reviews['avg'], 1, ',', '') }}</p>
                    <x-stars :value="$reviews['avg']" :size="20" class="mt-2" />
                    <p class="text-[13px] text-gray-500 mt-1">{{ number_format($reviews['count'], 0, ',', '.') }} avaliações</p>
                </div>
                <div class="grid gap-1.5">
                    @foreach ($reviews['dist'] as $star => $n)
                        <button type="button" class="flex items-center gap-3 text-[12.5px] hover:opacity-80" @click="filter({{ $star }})" :class="rating === {{ $star }} && 'font-bold'">
                            <span class="w-14 text-left">{{ $star }} estrela{{ $star > 1 ? 's' : '' }}</span>
                            <span class="reviews__bar"><span style="width: {{ $reviews['count'] ? round($n * 100 / $reviews['count']) : 0 }}%"></span></span>
                            <span class="w-12 text-right text-gray-500">{{ $n }}</span>
                        </button>
                    @endforeach
                    <button type="button" class="text-[12px] underline text-left mt-1" x-show="rating" x-cloak @click="filter(null)">Ver todas as notas</button>
                </div>
            </div>

            <div :class="loading && 'opacity-50'">
                <template x-for="r in items" :key="r.id">
                    <article class="review">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <span class="stars" style="--size: 14px"><span class="stars__fill" :style="`width: ${r.rating * 20}%`"></span></span>
                                <strong class="text-[13.5px]" x-text="r.author"></strong>
                                <span class="text-[11px] text-brand font-semibold" x-show="r.verified">✓ Compra verificada</span>
                            </div>
                            <time class="text-[12px] text-gray-400" x-text="r.date"></time>
                        </div>
                        <p class="font-semibold text-[13.5px] mt-2" x-show="r.title" x-text="r.title"></p>
                        <p class="text-[13.5px] text-gray-700 mt-1.5 leading-relaxed" x-show="r.body" x-text="r.body"></p>
                        <div class="flex gap-2 mt-3" x-show="r.images.length">
                            <template x-for="img in r.images" :key="img">
                                <a :href="img" target="_blank" rel="noopener"><img :src="img" alt="Foto da cliente" class="w-20 h-24 object-cover rounded" loading="lazy"></a>
                            </template>
                        </div>
                        <div class="review__reply" x-show="r.reply"><strong>Resposta da {{ setting('store.name') }}:</strong> <span x-text="r.reply"></span></div>
                    </article>
                </template>
                <p class="text-[13px] text-gray-500 py-6 text-center" x-show="!items.length && !loading">Nenhuma avaliação com essa nota.</p>
            </div>
            <div class="text-center mt-6" x-show="next">
                <button type="button" class="btn btn--outline" @click="more" :disabled="loading">Carregar mais avaliações</button>
            </div>
        </section>
    @endif

    @if ($related->isNotEmpty())
        <section class="pb-4">
            <div class="section-title"><h2>Você também pode gostar</h2></div>
            <div class="scroller-wrap" x-data="scroller">
                <button type="button" class="scroller-arrow scroller-arrow--prev" @click="scroll(-1)" aria-label="Anteriores"><x-ui-icon name="chevron-left" class="w-4 h-4" /></button>
                <div class="scroller scroller--products max-md:-mr-5 max-md:pr-5" x-ref="track">
                    @foreach ($related as $p)<x-product-card :p="$p" />@endforeach
                </div>
                <button type="button" class="scroller-arrow scroller-arrow--next" @click="scroll(1)" aria-label="Próximos"><x-ui-icon name="chevron-right" class="w-4 h-4" /></button>
            </div>
        </section>
    @endif

    {{-- Modal: opções de pagamento --}}
    <div x-cloak x-show="payModal" class="modal" @keydown.escape.window="payModal = false" role="dialog" aria-modal="true" aria-label="Opções de pagamento">
        <div class="overlay !z-0" @click="payModal = false"></div>
        <div class="modal__box z-10" x-transition>
            <button type="button" class="icon-btn modal__close" @click="payModal = false" aria-label="Fechar"><x-ui-icon name="close" /></button>
            <h2 class="text-lg font-semibold mb-4">Opções de pagamento</h2>
            <div class="flex items-center justify-between border border-line rounded-lg p-3 mb-4">
                <span class="font-semibold">PIX{{ $pix ? ' ('.rtrim(rtrim(number_format($pixPct, 1, ',', ''), '0'), ',').'% OFF)' : '' }}</span><span class="font-semibold" x-text="money(Math.round(price * {{ (100 - $pixPct) / 100 }}))">{{ money($pix ?? $price) }}</span>
            </div>
            <p class="font-semibold mb-2">Cartão de crédito</p>
            <table class="w-full text-[13px]">
                <tbody>
                @foreach ($plan as $row)
                    <tr class="border-b border-[#f3f3f3]">
                        <td class="py-2">{{ $row['n'] }}x de {{ money($row['value']) }} {{ $row['interest'] ? '' : 'sem juros' }}</td>
                        <td class="py-2 text-right text-gray-500">{{ $row['interest'] ? 'Total '.money($row['total']) : money($row['total']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="text-[11px] text-gray-500 mt-3">Valores calculados para {{ money($price) }}. O total final é exibido no checkout.</p>
        </div>
    </div>

    {{-- Barra fixa de compra --}}
    <div class="sticky-atc" x-cloak x-show="showSticky" x-transition.opacity>
        <div class="flex-1 min-w-0 hidden sm:block">
            <p class="text-[13px] line-clamp-1">{{ $product->title }}</p>
            <p class="text-[13px] font-semibold" x-text="money(price)"></p>
        </div>
        <div class="sm:hidden">
            <p class="text-[15px] font-semibold leading-tight" x-text="money(price)"></p>
            @if ($maxInst && $maxInst['n'] > 1)<p class="text-[11px] text-gray-500">ou {{ $maxInst['n'] }}x de {{ money($maxInst['value']) }}</p>@endif
        </div>
        <button type="button" class="btn btn--brand flex-1 sm:flex-none sm:min-w-64" @click="buy" :disabled="$store.cart.busy || !available">COMPRAR</button>
    </div>
</div>
@endsection
