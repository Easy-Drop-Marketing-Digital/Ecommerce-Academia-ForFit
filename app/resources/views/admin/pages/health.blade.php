<x-filament-panels::page>
    @php $checks = $this->checks(); @endphp
    <div class="sd-modules">
        @foreach ($checks as $c)
            <div class="sd-module sd-health sd-health--{{ $c['status'] }}">
                <div class="sd-module__icon">@svg($c['icon'], 'w-5 h-5')</div>
                <div class="sd-module__title">{{ $c['label'] }}</div>
                <div class="sd-module__desc">{{ $c['detail'] }}</div>
                @if ($c['hint'])<div class="sd-health__hint">{{ $c['hint'] }}</div>@endif
                <div class="sd-module__status sd-health__status">
                    {{ ['ok' => 'Tudo certo', 'warn' => 'Atenção', 'error' => 'Precisa de ação'][$c['status']] }}
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
