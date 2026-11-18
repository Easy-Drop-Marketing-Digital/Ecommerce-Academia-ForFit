@php
    $logo = setting('store.logo_light') ?: setting('store.logo');
    $nav = $menus['main'] ?? [];
    $brands = $menus['brands'] ?? [];
    $here = url()->current();
    // faixa do topo: mensagens montadas com as regras reais da loja
    $freeAbove = \App\Models\ShippingMethod::where('is_active', true)->whereNotNull('free_above')->min('free_above');
    $pix = pix_percent();
    $parcelas = (int) setting('nixfy.interest_free_installments', 1);
    $whats = preg_replace('/\D/', '', (string) setting('store.whatsapp'));
    $kits = collect($nav)->first(fn ($i) => str_contains(mb_strtolower($i['title']), 'kit'));
    $linkItems = collect($nav)->reject(fn ($i) => $kits && $i['url'] === $kits['url'])->values();
@endphp
<div class="topbar">
    <div class="page-width topbar__inner">
        @if ($freeAbove)<span class="topbar__msg"><x-ui-icon name="truck" /> <b>FRETE GRÁTIS</b> acima de {{ money((int) $freeAbove) }}</span>@endif
        @if ($pix > 0)<span class="topbar__msg"><x-ui-icon name="pix" /> <b>{{ rtrim(rtrim(number_format($pix, 1, ',', ''), '0'), ',') }}% OFF</b> no PIX</span>@endif
        @if ($parcelas > 1)<span class="topbar__msg"><x-ui-icon name="card" /> Até <b>{{ $parcelas }}x sem juros</b></span>@endif
        <span class="topbar__msg"><x-ui-icon name="shield" /> Revenda autorizada <b>Growth</b> e <b>Max Titanium</b></span>
    </div>
</div>

<header class="site-header">
    <div class="page-width site-header__inner">
        <button type="button" class="icon-btn site-header__burger -ml-2" @click="$store.ui.menu = true" aria-label="Abrir menu">
            <x-ui-icon name="menu" />
        </button>
        <a href="{{ route('home') }}" class="site-header__logo" aria-label="{{ setting('store.name') }} — página inicial">
            @if ($logo)<img src="{{ img_url($logo) }}" alt="{{ setting('store.name') }}" width="200" height="46">@else<span class="text-2xl font-black italic">{{ setting('store.name') }}</span>@endif
        </a>

        <form action="{{ route('search') }}" method="get" class="header-search" role="search">
            <label for="q-desk" class="sr-only">Buscar produtos</label>
            <input id="q-desk" name="q" type="search" placeholder="Encontre o suplemento ideal para você" value="{{ request()->routeIs('search') ? request('q') : '' }}" autocomplete="off">
            <button type="submit" aria-label="Buscar"><x-ui-icon name="search" /></button>
        </form>

        <div class="header-actions">
            <a href="{{ route('account.index') }}" class="header-action" aria-label="Minha conta">
                <x-ui-icon name="user" />
                <span class="header-action__text">Olá! Entre<small>ou cadastre-se</small></span>
            </a>
            @if ($whats)
                <a href="https://wa.me/{{ strlen($whats) <= 11 ? '55'.$whats : $whats }}" target="_blank" rel="noopener" class="header-action hidden lg:inline-flex" aria-label="Fale conosco no WhatsApp">
                    <x-ui-icon name="whatsapp" />
                    <span class="header-action__text">Fale conosco<small>WhatsApp</small></span>
                </a>
            @endif
            <button type="button" class="header-action" @click="$store.cart.open = true" aria-label="Abrir carrinho">
                <span class="relative inline-flex"><x-ui-icon name="cart" /><span class="cart-count" x-cloak x-show="$store.cart.data.count > 0" x-text="$store.cart.data.count"></span></span>
                <span class="header-action__text">Carrinho</span>
            </button>
        </div>
    </div>
    <form action="{{ route('search') }}" method="get" class="header-search header-search--mobile" role="search">
        <label for="q-mob" class="sr-only">Buscar produtos</label>
        <input id="q-mob" name="q" type="search" placeholder="Buscar whey, creatina, pré-treino..." autocomplete="off">
        <button type="submit" aria-label="Buscar"><x-ui-icon name="search" /></button>
    </form>

    {{-- Barra de categorias (computador) --}}
    <nav class="catbar" aria-label="Categorias" x-data="{ mega: false }" @mouseleave="mega = false" @keydown.escape="mega = false">
        <div class="page-width catbar__inner">
            <button type="button" class="catbar__all" @mouseenter="mega = true" @click="mega = !mega" :aria-expanded="mega">
                <x-ui-icon name="grid" /> Categorias <x-ui-icon name="chevron-down" class="!w-3.5 !h-3.5" />
            </button>
            @foreach ($linkItems as $item)
                <a href="{{ $item['url'] }}" class="catbar__link" @if ($item['url'] === $here) aria-current="page" @endif>{{ $item['title'] }}</a>
            @endforeach
            @if ($kits)<a href="{{ $kits['url'] }}" class="catbar__cta">{{ $kits['title'] }}</a>@endif
        </div>
        <div class="megamenu" x-cloak x-show="mega" x-transition.opacity>
            <div class="page-width megamenu__grid">
                <div>
                    <h4>Categorias</h4>
                    <div class="megamenu__cols">
                        @foreach ($nav as $item)<a href="{{ $item['url'] }}">{{ $item['title'] }}</a>@endforeach
                    </div>
                </div>
                <div>
                    <h4>Marcas</h4>
                    @foreach ($brands as $b)<a href="{{ $b['url'] }}">{{ $b['title'] }}</a>@endforeach
                    <a href="{{ route('category.show', 'all') }}">Todos os produtos</a>
                </div>
                <div class="megamenu__promo">
                    <strong>{{ $pix > 0 ? rtrim(rtrim(number_format($pix, 1, ',', ''), '0'), ',').'% OFF no PIX' : 'Suplementos originais' }}</strong>
                    <p class="text-[13px] opacity-85">Growth e Max Titanium com revenda autorizada{{ $parcelas > 1 ? " e até {$parcelas}x sem juros" : '' }}.</p>
                    @if ($kits)<a href="{{ $kits['url'] }}" class="btn btn--brand !min-h-10 !text-[12.5px] mt-3 !text-white">Ver kits e combos</a>@endif
                </div>
            </div>
        </div>
    </nav>
</header>
