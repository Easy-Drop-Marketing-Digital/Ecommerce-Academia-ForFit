@props(['p', 'center' => false, 'eager' => false])
@php $isKit = (bool) preg_match('/^(kit|pack|combo)\b/i', $p['title']); @endphp
<div @class(['card', 'card--center' => $center])>
    <a href="{{ $p['url'] }}" class="card__media" aria-label="{{ $p['title'] }}">
        <img src="{{ $p['image'] }}" @if ($p['srcset'] ?? null) srcset="{{ $p['srcset'] }}" sizes="(min-width: 990px) 22vw, 47vw" @endif alt="{{ $p['alt'] }}" width="600" height="600" loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="async">
        @if ($p['image_hover'])
            <img src="{{ $p['image_hover'] }}" @if ($p['srcset_hover'] ?? null) srcset="{{ $p['srcset_hover'] }}" sizes="(min-width: 990px) 22vw, 47vw" @endif alt="" class="card__hover" width="600" height="600" loading="lazy" decoding="async">
        @endif
        @if ($p['sold_out'])
            <span class="badge-soldout">Esgotado</span>
        @elseif ($p['off'])
            <span class="badge-off">-{{ $p['off'] }}%</span>
        @endif
        @if ($isKit)<span class="badge-kit">Kit</span>@endif
    </a>
    <div class="card__info">
        @if ($p['brand'] ?? null)<span class="card__brand">{{ $p['brand'] }}</span>@endif
        <a href="{{ $p['url'] }}"><h3 class="card__title">{{ $p['title'] }}</h3></a>
        @if (($p['rating'] ?? null) && setting('reviews.show_on_cards', 1))
            <div class="card__rating"><x-stars :value="$p['rating']['avg']" :size="12" /> <span>({{ $p['rating']['count'] }})</span></div>
        @endif
        <div class="card__price">
            @if ($p['compare'])<s class="compare">{{ money($p['compare']) }}</s>@endif
            @if ($p['pix_price'] ?? null)
                <span class="card__pix">{{ money($p['pix_price']) }}<small>no PIX</small></span>
                @if ($p['installments'])
                    <span class="price">ou <b>{{ $p['installments']['n'] }}x de {{ money($p['installments']['value']) }}</b>{{ $p['installments']['interest'] ? '' : ' sem juros' }}</span>
                @else
                    <span class="price">ou {{ money($p['price']) }} no cartão</span>
                @endif
            @else
                <span class="card__pix">{{ money($p['price']) }}</span>
                @if ($p['installments'])<p class="card__installments">em até {{ $p['installments']['n'] }}x de <b>{{ money($p['installments']['value']) }}</b></p>@endif
            @endif
        </div>
        <div class="card__buy">
            @if ($p['sold_out'])
                <a href="{{ $p['url'] }}" class="btn btn--outline">Ver produto</a>
            @elseif ($p['single_variant_id'])
                <button type="button" class="btn btn--brand" @click.prevent="$store.cart.add({{ $p['single_variant_id'] }}, 1, { productId: {{ $p['id'] }} })" :disabled="$store.cart.busy">Comprar</button>
            @else
                <a href="{{ $p['url'] }}" class="btn btn--brand">Escolher sabor</a>
            @endif
        </div>
    </div>
</div>
