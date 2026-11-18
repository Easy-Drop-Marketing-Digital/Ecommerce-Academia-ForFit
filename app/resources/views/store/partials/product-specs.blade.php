{{-- Descrição + ficha técnica (products.specs) em abas: Descrição, Modo de uso, Tabela nutricional, Ingredientes, Dúvidas --}}
@php
    $s = $product->specs ?? [];
    $tabs = array_filter([
        'desc' => ($product->description || ! empty($s['destaques'])) ? 'Descrição' : null,
        'uso' => (! empty($s['modo_de_uso']) || ! empty($s['preparo'])) ? 'Modo de uso' : null,
        'tabela' => ! empty($s['tabela']['linhas']) ? 'Tabela nutricional' : null,
        'ingredientes' => (! empty($s['ingredientes']) || ! empty($s['alergicos']) || ! empty($s['advertencias'])) ? 'Ingredientes' : null,
        'faq' => ! empty($s['faq']) ? 'Dúvidas' : null,
    ]);
@endphp
@if ($tabs)
<div class="panel pdp__desc {{ $class ?? '' }}" x-data="{ tab: @js(array_key_first($tabs)) }">
    <div class="spec-tabs" role="tablist">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" :aria-selected="tab === @js($key)" @click="tab = @js($key)">{{ $label }}</button>
        @endforeach
    </div>

    <div x-show="tab === 'desc'" role="tabpanel">
        @if (! empty($s['destaques']))
            <ul class="spec-list mb-5">@foreach ($s['destaques'] as $d)<li>{{ $d }}</li>@endforeach</ul>
        @endif
        @if ($product->description && ! (! empty($s['destaques']) && str_starts_with(trim($product->description), '<ul>')))
            <div class="rte">{!! lazy_media($product->description) !!}</div>
        @endif
        @if (! empty($s['beneficios']) && ($s['beneficios'] !== ($s['destaques'] ?? null)))
            <h3 class="font-extrabold mt-5 mb-2">Benefícios</h3>
            <ul class="spec-list">@foreach ($s['beneficios'] as $d)<li>{{ $d }}</li>@endforeach</ul>
        @endif
        @if (! empty($s['tamanho']))<p class="text-[13px] text-muted mt-4">Conteúdo: {{ $s['tamanho'] }}</p>@endif
    </div>

    <div x-cloak x-show="tab === 'uso'" role="tabpanel" class="rte">
        @if (! empty($s['modo_de_uso']))<h3>Recomendação de consumo</h3><p>{{ $s['modo_de_uso'] }}</p>@endif
        @if (! empty($s['preparo']))<h3>Modo de preparo</h3><p>{{ $s['preparo'] }}</p>@endif
        @if (! empty($s['conservacao']))<h3>Conservação</h3><p>{{ $s['conservacao'] }}</p>@endif
    </div>

    @if (! empty($s['tabela']['linhas']))
        <div x-cloak x-show="tab === 'tabela'" role="tabpanel" class="overflow-x-auto">
            <table class="nutri">
                <caption>INFORMAÇÃO NUTRICIONAL
                    <span class="block text-[12px] font-semibold mt-1">@if ($s['tabela']['porcoes'])Porções por embalagem: {{ $s['tabela']['porcoes'] }} · @endif Porção: {{ $s['tabela']['porcao'] }}</span>
                </caption>
                @foreach ($s['tabela']['linhas'] as $i => $linha)
                    <tr>
                        @foreach ((array) $linha as $cel)
                            @if ($i === 0)<th>{{ $cel }}</th>@else<td>{{ $cel }}</td>@endif
                        @endforeach
                    </tr>
                @endforeach
            </table>
            @if (! empty($s['tabela']['rodape']))<p class="text-[12px] text-muted mt-3 max-w-[520px]">{{ $s['tabela']['rodape'] }}</p>@endif
            <p class="text-[12px] text-muted mt-2 max-w-[520px]">*% Valores Diários de referência com base em uma dieta de 2.000 kcal. Confira sempre o rótulo do produto recebido.</p>
        </div>
    @endif

    <div x-cloak x-show="tab === 'ingredientes'" role="tabpanel" class="rte">
        @if (! empty($s['ingredientes']))<h3>Ingredientes</h3><p>{{ $s['ingredientes'] }}</p>@endif
        @if (! empty($s['alergicos']))<h3>Alérgicos</h3><p>{{ $s['alergicos'] }}</p>@endif
        @if (! empty($s['advertencias']))<h3>Advertências</h3><p>{{ $s['advertencias'] }}</p>@endif
    </div>

    @if (! empty($s['faq']))
        <div x-cloak x-show="tab === 'faq'" role="tabpanel" class="faq">
            @foreach ($s['faq'] as $f)
                <details><summary>{{ $f['pergunta'] }}</summary><p>{{ $f['resposta'] }}</p></details>
            @endforeach
        </div>
    @endif
</div>
@endif
