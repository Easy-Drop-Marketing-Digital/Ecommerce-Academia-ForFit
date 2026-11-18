@php
    $logo = setting('store.logo_light') ?: setting('store.logo');
    $social = array_filter([
        'instagram' => setting('store.instagram'),
        'facebook' => setting('store.facebook'),
        'tiktok' => setting('store.tiktok'),
    ]);
    $legal = setting('store.legal_name');
    $cnpj = setting('store.cnpj');
@endphp
<section class="footer-band">
    <div class="page-width footer-band__inner">
        <div>
            <strong>Treina na ForFit? Retire grátis na sua unidade.</strong>
            <span class="text-[13.5px] opacity-90">Escolha “Retirar na academia” no checkout e busque seu pedido sem pagar frete.</span>
        </div>
        @if ($wa = setting('store.whatsapp'))
            <a href="https://wa.me/55{{ preg_replace('/D/', '', $wa) }}" target="_blank" rel="noopener" class="btn btn--brand !text-white"><x-ui-icon name="whatsapp" class="w-5 h-5" /> Tirar dúvidas no WhatsApp</a>
        @endif
    </div>
</section>
<footer class="site-footer">
    <div class="page-width">
        <div class="site-footer__top">
            <div>
                <a href="{{ route('home') }}" aria-label="{{ setting('store.name') }}">
                    @if ($logo)<img src="{{ img_url($logo) }}" alt="{{ setting('store.name') }}" class="h-[46px] w-auto" width="200" height="46" loading="lazy">@else<span class="text-lg font-bold">{{ setting('store.name') }}</span>@endif
                </a>
                <p class="text-[13px] mt-4 max-w-[280px] leading-relaxed">Loja oficial da Academia ForFit. Suplementos Growth e Max Titanium originais, com revenda autorizada, estoque próprio e envio rápido.</p>
                @if ($social)
                    <div class="site-footer__social">
                        @foreach ($social as $net => $url)
                            <a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ ucfirst($net) }}"><x-ui-icon :name="$net" /></a>
                        @endforeach
                    </div>
                @endif
            </div>

            @foreach ($menus['footer'] ?? [] as $col)
                <div x-data="{ open: false }">
                    <h3>
                        <button type="button" class="w-full flex justify-between items-center md:pointer-events-none" @click="open = !open" :aria-expanded="open">
                            {{ $col['title'] }} <x-ui-icon name="chevron-down" class="w-4 h-4 md:hidden transition" ::class="open && 'rotate-180'" />
                        </button>
                    </h3>
                    <ul class="max-md:hidden" :class="open && '!block'">
                        @foreach ($col['items'] as $item)
                            <li><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <div class="site-footer__mid">
            <div>
                <h3>Central de atendimento</h3>
                <ul class="site-footer__contact">
                    <li><x-ui-icon name="clock" /> {{ setting('store.hours') }}</li>
                    <li><x-ui-icon name="mail" /> <a href="mailto:{{ setting('store.email') }}">{{ setting('store.email') }}</a></li>
                    @if ($wa = setting('store.whatsapp'))
                        <li><x-ui-icon name="whatsapp" /> <a href="https://wa.me/55{{ preg_replace('/\D/', '', $wa) }}" target="_blank" rel="noopener">{{ $wa }}</a></li>
                    @endif
                    @if ($addr = setting('store.address'))<li><x-ui-icon name="pin" /> {{ $addr }}</li>@endif
                </ul>
            </div>
            <div>
                <h3>Formas de pagamento</h3>
                @include('partials.payment-icons')
                <div class="secure-seal"><x-ui-icon name="lock" /> Site seguro · SSL</div><div class="secure-seal"><x-ui-icon name="shield" /> Produtos originais com nota fiscal</div>
            </div>
        </div>
    </div>

    <div class="site-footer__bottom">
        <div class="page-width">
            © {{ now()->year }} {{ setting('store.name') }}. Todos os direitos reservados.<br>
            @if ($legal || $cnpj)
                {{ $legal }}@if ($legal && $cnpj) · @endif @if ($cnpj)CNPJ {{ $cnpj }}@endif<br>
            @endif
            {{ setting('store.address') ?: '' }}
        </div>
    </div>
</footer>
