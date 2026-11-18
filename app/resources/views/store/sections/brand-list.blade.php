<section class="section">
    <div class="page-width">
        @if ($title)<div class="section-title"><h2>{{ $title }}</h2></div>@endif
        <div class="brand-cards">
            @foreach ($brands as $b)
                <a href="{{ $b['url'] }}" class="brand-card" style="background: linear-gradient(110deg, {{ $b['color'] }} 0%, #06173d 85%)">
                    <small>REVENDA AUTORIZADA</small>
                    @if ($b['logo'])<img src="{{ $b['logo'] }}" alt="{{ $b['name'] }}" loading="lazy">@else<strong>{{ $b['name'] }}</strong>@endif
                    <span>Ver {{ $b['count'] }} produtos <x-ui-icon name="chevron-right" class="w-4 h-4" /></span>
                </a>
            @endforeach
        </div>
    </div>
</section>
