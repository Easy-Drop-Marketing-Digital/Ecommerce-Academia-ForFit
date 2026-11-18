@php $logo = setting('store.logo'); @endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Finalizar compra') | {{ setting('store.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.tracking')
    @stack('head')
</head>
<body class="min-h-screen bg-[#f7f7f8]" x-data>
    <header class="bg-white border-b border-[#ececec]">
        <div class="page-width h-16 flex items-center justify-between">
            <a href="{{ route('home') }}" aria-label="Voltar para a loja">
                @if ($logo)<img src="{{ img_url($logo) }}" alt="{{ setting('store.name') }}" class="h-[18px] w-auto" width="175" height="18">@else<span class="font-bold">{{ setting('store.name') }}</span>@endif
            </a>
            <span class="flex items-center gap-1.5 text-[12px] font-semibold text-[#121212]"><x-ui-icon name="lock" class="w-4 h-4 text-brand" /> PAGAMENTO 100% SEGURO</span>
        </div>
    </header>

    <main>@yield('content')</main>

    <footer class="page-width py-8 text-center text-[11.5px] text-gray-500 leading-relaxed">
        @include('partials.payment-icons')
        <p class="mt-4">{{ setting('store.name') }}@if (setting('store.cnpj')) · CNPJ {{ setting('store.cnpj') }}@endif · {{ setting('store.email') }}</p>
        <p>
            <a href="{{ route('policy.show', 'refund-policy') }}" class="underline" target="_blank">Trocas e devoluções</a> ·
            <a href="{{ route('policy.show', 'privacy-policy') }}" class="underline" target="_blank">Privacidade</a> ·
            <a href="{{ route('policy.show', 'terms-of-service') }}" class="underline" target="_blank">Termos</a>
        </p>
    </footer>

    <div class="toast" x-cloak x-show="$store.ui.toast" x-transition x-text="$store.ui.toast" role="status" aria-live="polite"></div>
    @stack('scripts')
</body>
</html>
