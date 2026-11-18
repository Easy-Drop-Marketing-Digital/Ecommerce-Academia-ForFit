<div x-cloak x-show="$store.ui.menu" class="overlay" x-transition.opacity @click="$store.ui.menu = false"></div>
<aside x-cloak x-show="$store.ui.menu" class="drawer drawer--left menu-drawer"
       x-transition:enter="transition ease-out duration-250" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
       x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
       @keydown.escape.window="$store.ui.menu = false" aria-label="Menu">
    <div class="drawer__head !bg-blue !text-white">
        <span class="drawer__title">Categorias</span>
        <button type="button" class="icon-btn" @click="$store.ui.menu = false" aria-label="Fechar menu"><x-ui-icon name="close" /></button>
    </div>
    <nav class="drawer__body">
        @foreach ($menus['main'] ?? [] as $item)
            <a href="{{ $item['url'] }}" class="flex items-center justify-between">{{ $item['title'] }} <x-ui-icon name="chevron-right" class="w-4 h-4 text-muted" /></a>
        @endforeach
        @if (! empty($menus['brands']))
            <p class="text-[11px] font-extrabold uppercase tracking-widest text-muted mt-6 mb-1">Marcas</p>
            @foreach ($menus['brands'] as $b)
                <a href="{{ $b['url'] }}" class="flex items-center justify-between">{{ $b['title'] }} <x-ui-icon name="chevron-right" class="w-4 h-4 text-muted" /></a>
            @endforeach
        @endif
    </nav>
    <div class="drawer__foot">
        <a href="{{ route('account.index') }}" class="btn btn--blue btn--block"><x-ui-icon name="user" class="w-5 h-5" /> Minha conta</a>
    </div>
</aside>
