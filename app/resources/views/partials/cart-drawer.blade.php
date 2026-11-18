<div x-cloak x-show="$store.cart.open" class="overlay" x-transition.opacity @click="$store.cart.open = false"></div>
<aside x-cloak x-show="$store.cart.open" class="drawer drawer--right"
       x-transition:enter="transition ease-out duration-250" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
       x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
       @keydown.escape.window="$store.cart.open = false" role="dialog" aria-modal="true" aria-label="Carrinho">
    <div class="drawer__head">
        <span class="drawer__title">Carrinho <span class="drawer__badge" x-text="$store.cart.data.count"></span></span>
        <button type="button" class="icon-btn" @click="$store.cart.open = false" aria-label="Fechar carrinho"><x-ui-icon name="close" /></button>
    </div>

    <div class="drawer__body" :class="$store.cart.busy && 'opacity-60 pointer-events-none'">
        <template x-if="!$store.cart.data.items.length">
            <div class="text-center py-16">
                <x-ui-icon name="bag" class="w-10 h-10 mx-auto mb-3 text-muted" />
                <p class="font-semibold mb-1">Seu carrinho está vazio</p>
                <p class="text-sm text-gray-500 mb-6">Que tal conferir as novidades?</p>
                <a href="{{ route('category.show', 'all') }}" class="btn btn--dark">Continuar comprando</a>
            </div>
        </template>

        <template x-for="item in $store.cart.data.items" :key="item.id">
            <div class="flex gap-3 py-4 border-b border-[#f3f3f3]">
                <a :href="item.url" class="w-[72px] shrink-0 aspect-square bg-white border border-line overflow-hidden rounded-lg">
                    <img :src="item.image" :alt="item.title" class="w-full h-full object-contain p-1" loading="lazy">
                </a>
                <div class="flex-1 min-w-0">
                    <div class="flex justify-between gap-3">
                        <a :href="item.url" class="text-[13px] leading-snug line-clamp-2" x-text="item.title"></a>
                        <span class="text-[13px] font-semibold whitespace-nowrap" x-text="$store.cart.money(item.line_total)"></span>
                    </div>
                    <p class="text-[12px] text-gray-500 mt-0.5" x-show="item.variant_title" x-text="item.variant_title"></p>
                    <p class="text-[12px] mt-0.5">
                        <span x-text="$store.cart.money(item.price)"></span>
                        <s class="text-gray-400 ml-1" x-show="item.compare_at_price > item.price" x-text="$store.cart.money(item.compare_at_price)"></s>
                    </p>
                    <div class="flex items-center gap-3 mt-2">
                        <div class="qty qty--sm">
                            <button type="button" @click="$store.cart.update(item.id, item.quantity - 1)" aria-label="Diminuir">−</button>
                            <input type="number" :value="item.quantity" min="1" max="99" aria-label="Quantidade"
                                   @change="$store.cart.update(item.id, parseInt($event.target.value) || 1)">
                            <button type="button" @click="$store.cart.update(item.id, item.quantity + 1)" aria-label="Aumentar">+</button>
                        </div>
                        <button type="button" class="text-gray-500 hover:text-black" @click="$store.cart.update(item.id, 0)" aria-label="Remover item">
                            <x-ui-icon name="trash" class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div class="drawer__foot" x-show="$store.cart.data.items.length">
        <div class="flex justify-between text-[13px] text-brand font-semibold mb-1" x-show="$store.cart.data.savings > 0">
            <span>Você economiza</span><span x-text="$store.cart.data.savings_formatted"></span>
        </div>
        {{-- Frete: o mesmo que o checkout pré-seleciona (entrega grátis quando houver) --}}
        <template x-if="$store.cart.data.shipping">
            <div>
                <div class="mb-3 rounded-lg bg-soft px-3 py-2.5" x-show="$store.cart.data.shipping.missing">
                    <p class="text-[12.5px]">Faltam <b class="text-blue" x-text="$store.cart.data.shipping.missing_formatted"></b> para ganhar <b>frete grátis</b></p>
                    <div class="h-1.5 rounded-full bg-[#dde3ee] mt-2 overflow-hidden"><div class="h-full bg-brand rounded-full transition-all" :style="`width: ${$store.cart.data.shipping.progress}%`"></div></div>
                </div>
                <div class="flex justify-between text-[13.5px] mb-1">
                    <span>Subtotal</span><span class="font-semibold" x-text="$store.cart.data.subtotal_formatted"></span>
                </div>
                <div class="flex justify-between items-start gap-3 text-[13.5px] pb-3 mb-3 border-b border-line">
                    <span>Frete <span class="text-[12px] text-gray-500" x-show="$store.cart.data.shipping.estimate" x-text="'· ' + $store.cart.data.shipping.estimate"></span></span>
                    <span class="font-bold whitespace-nowrap" :class="$store.cart.data.shipping.price === 0 ? 'text-success' : ''" x-text="$store.cart.data.shipping.price_formatted"></span>
                </div>
            </div>
        </template>
        <div class="flex justify-between items-baseline mb-1">
            <span class="text-[14px]">Total estimado</span>
            <span class="text-[20px] font-extrabold" x-text="$store.cart.data.total_formatted || $store.cart.data.subtotal_formatted"></span>
        </div>
        <p class="text-[11.5px] text-gray-500 mb-3" x-show="$store.cart.data.shipping && $store.cart.data.shipping.has_options">Outras opções (expressa e retirada na academia) no checkout.</p>
        <a href="{{ route('checkout') }}" class="btn btn--brand btn--block btn--lg">Finalizar a compra</a>
        <p class="flex items-center justify-center gap-1.5 text-[11.5px] text-gray-500 mt-3"><x-ui-icon name="lock" class="w-3.5 h-3.5" /> Compra 100% segura · PIX ou cartão em até {{ setting('nixfy.max_installments', 12) }}x</p>
    </div>
</aside>
