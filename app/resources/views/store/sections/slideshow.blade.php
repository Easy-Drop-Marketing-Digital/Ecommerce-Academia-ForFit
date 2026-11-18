@php $count = count($slides); @endphp
<section @class(['slideshow', 'mt-10' => ! $first]) x-data="slideshow({{ $count }}, {{ (int) ($settings['autoplay'] ?? 0) }})"
         @touchstart.passive="touchStart" @touchend="touchEnd" aria-roledescription="carrossel" aria-label="Destaques">
    <div class="slideshow__track" :style="`transform: translateX(-${i * 100}%)`">
        @foreach ($slides as $k => $s)
            <div class="slideshow__slide" @if ($count > 1) aria-roledescription="slide" aria-label="{{ $k + 1 }} de {{ $count }}" @endif>
                @if ($s['link'])<a href="{{ $s['link'] }}">@endif
                <picture>
                    <source media="(max-width: 749px)" srcset="{{ $s['mobile'] }}">
                    <img src="{{ $s['desktop'] }}" alt="{{ $s['alt'] }}" width="1920" height="960"
                         @if ($first && $k === 0) fetchpriority="high" loading="eager" @else loading="lazy" @endif decoding="async">
                </picture>
                @if ($s['link'])</a>@endif
            </div>
        @endforeach
    </div>
    @if ($count > 1)
        <button type="button" class="slideshow__arrow slideshow__arrow--prev" @click="prev" aria-label="Anterior"><x-ui-icon name="chevron-left" class="w-5 h-5" /></button>
        <button type="button" class="slideshow__arrow slideshow__arrow--next" @click="next" aria-label="Próximo"><x-ui-icon name="chevron-right" class="w-5 h-5" /></button>
        <div class="slideshow__dots">
            @foreach ($slides as $k => $s)
                <button type="button" @click="go({{ $k }})" :aria-current="i === {{ $k }}" aria-label="Ir para o slide {{ $k + 1 }}"></button>
            @endforeach
        </div>
    @endif
</section>
