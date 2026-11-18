<x-filament-widgets::widget>
    <a href="{{ $url }}" class="sd-alert">
        <strong>{{ count($problems) }} {{ count($problems) === 1 ? 'item precisa' : 'itens precisam' }} de atenção para a loja vender normalmente:</strong>
        {{ collect($problems)->pluck('label')->implode(' · ') }} — ver detalhes em Saúde da loja →
    </a>
</x-filament-widgets::widget>
