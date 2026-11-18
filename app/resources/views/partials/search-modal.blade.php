<div x-cloak x-show="$store.ui.search" class="overlay" x-transition.opacity @click="$store.ui.search = false"></div>
<div x-cloak x-show="$store.ui.search" x-transition class="fixed top-0 inset-x-0 z-[75] bg-white shadow-lg"
     @keydown.escape.window="$store.ui.search = false"
     x-effect="if ($store.ui.search) $nextTick(() => $refs.q.focus())">
    <form action="{{ route('search') }}" method="get" class="page-width flex items-center gap-3 py-4" role="search">
        <x-ui-icon name="search" class="w-5 h-5 shrink-0" />
        <label for="q-header" class="sr-only">Buscar produtos</label>
        <input x-ref="q" id="q-header" name="q" type="search" placeholder="O que você procura?" autocomplete="off"
               class="flex-1 h-12 text-base outline-none border-b border-line focus:border-black">
        <button type="button" class="icon-btn" @click="$store.ui.search = false" aria-label="Fechar busca"><x-ui-icon name="close" /></button>
    </form>
</div>
