@php
    $storeName = setting('store.name', config('app.name'));
    $logo = setting('store.logo');
    $title = trim($__env->yieldContent('title')) ?: $storeName;
    $description = trim($__env->yieldContent('description')) ?: 'Suplementos Growth e Max Titanium originais com revenda autorizada. Whey, creatina, pré-treino e vitaminas com desconto no PIX e parcelamento sem juros.';
    // compartilhamento (WhatsApp/Facebook): imagem da página → imagem da marca 1200x630 → logo
    $share = setting('store.share_image');
    $ogImage = trim($__env->yieldContent('og_image')) ?: ($share ? img_url($share) : ($logo ? img_url($logo) : null));
    $canonical = trim($__env->yieldContent('canonical')) ?: url()->current();
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description), 160) }}">
    <link rel="canonical" href="{{ $canonical }}">
    @hasSection('noindex')<meta name="robots" content="noindex,follow">@endif
    <meta name="theme-color" content="#0a4fd6">

    {{-- Open Graph / compartilhamento --}}
    <meta property="og:site_name" content="{{ $storeName }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description), 200) }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    @if ($ogImage)<meta property="og:image" content="{{ $ogImage }}">@endif
    <meta name="twitter:card" content="summary_large_image">

    {{-- Verificações de domínio (Admin → Configurações) --}}
    @if ($v = setting('meta.domain_verification'))<meta name="facebook-domain-verification" content="{{ $v }}">@endif
    @if ($v = setting('google.site_verification'))<meta name="google-site-verification" content="{{ $v }}">@endif

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- trechos entre <!--sd:...--> são de cada visitante: o cache de página os preenche a cada acesso --}}
    <!--sd:track-->@include('partials.tracking')<!--/sd:track-->
    @stack('head')
</head>
<body class="min-h-screen flex flex-col" x-data>
    <a href="#conteudo" class="sr-only focus:not-sr-only focus:absolute focus:p-3 focus:bg-white focus:z-50">Pular para o conteúdo</a>

    @include('partials.header')

    <main id="conteudo" class="flex-1">
        @yield('content')
    </main>

    @include('partials.footer')
    @include('partials.cart-drawer')
    @include('partials.menu-drawer')
    @include('partials.search-modal')

    <div class="toast" x-cloak x-show="$store.ui.toast" x-transition x-text="$store.ui.toast" role="status" aria-live="polite"></div>

    <!--sd:cart-->@include('partials.cart-data')<!--/sd:cart-->
    @if (session('toast'))<script>window.addEventListener('load', () => window.Alpine && Alpine.store('ui').notify(@js(session('toast'))));</script>@endif
    @stack('scripts')
</body>
</html>
