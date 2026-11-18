<section class="section">
    <div class="page-width">
        @if ($title || $list['view_all'])
            <div class="section-title">
                <h2>{{ $title }}</h2>
                @if ($list['view_all'])<a href="{{ $list['view_all']['url'] }}">{{ $list['view_all']['label'] }} →</a>@endif
            </div>
        @endif
        <div class="scroller-wrap scroller-host" x-data="scroller">
            <button type="button" class="scroller-arrow scroller-arrow--prev" @click="scroll(-1)" aria-label="Anteriores"><x-ui-icon name="chevron-left" class="w-4 h-4" /></button>
            <div class="scroller scroller--products" x-ref="track">
                @foreach ($list['products'] as $p)
                    <x-product-card :p="$p" />
                @endforeach
            </div>
            <button type="button" class="scroller-arrow scroller-arrow--next" @click="scroll(1)" aria-label="Próximos"><x-ui-icon name="chevron-right" class="w-4 h-4" /></button>
        </div>
    </div>
</section>
