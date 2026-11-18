<section class="section">
    <div class="page-width">
        @if ($title ?? null)<div class="section-title"><h2>{{ $title }}</h2></div>@endif
        <div class="scroller-wrap scroller-host" x-data="scroller">
            <button type="button" class="scroller-arrow scroller-arrow--prev" @click="scroll(-1)" aria-label="Coleções anteriores"><x-ui-icon name="chevron-left" class="w-4 h-4" /></button>
            <div class="scroller scroller--collections" x-ref="track">
                @foreach ($items as $c)
                    <a href="{{ $c['url'] }}" class="collection-tile" draggable="false">
                        <div class="collection-tile__media">
                            <img src="{{ $c['image'] }}" alt="{{ $c['name'] }}" width="300" height="300" loading="lazy" decoding="async" draggable="false">
                        </div>
                        <p class="collection-tile__name">{{ $c['name'] }}</p>
                    </a>
                @endforeach
            </div>
            <button type="button" class="scroller-arrow scroller-arrow--next" @click="scroll(1)" aria-label="Próximas coleções"><x-ui-icon name="chevron-right" class="w-4 h-4" /></button>
        </div>
    </div>
</section>
