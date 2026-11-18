<x-filament-widgets::widget>
    <x-filament::section heading="Funil de conversão" description="Sessões que chegaram a cada etapa no período">
        <div class="space-y-3">
            @foreach ($steps as $i => $s)
                <div>
                    <div class="flex justify-between text-sm">
                        <span class="font-medium">{{ $s['label'] }}</span>
                        <span class="text-gray-500">{{ number_format($s['value'], 0, ',', '.') }}
                            @if ($i > 0)<span class="ml-2 text-xs">({{ number_format($s['pct_prev'], 1, ',', '') }}% da etapa anterior)</span>@endif
                        </span>
                    </div>
                    <div class="h-2.5 rounded-full bg-gray-100 dark:bg-white/10 mt-1 overflow-hidden">
                        <div class="h-full rounded-full bg-primary-500" style="width: {{ max(1, min(100, $s['pct_total'])) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
        @if (! empty($sources['sessions']))
            <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
                <div>
                    <p class="font-medium mb-2">Sessões por origem</p>
                    @foreach (array_slice($sources['sessions'], 0, 6, true) as $src => $n)
                        <div class="flex justify-between py-0.5"><span class="text-gray-600 dark:text-gray-400">{{ $src }}</span><span>{{ number_format($n, 0, ',', '.') }}</span></div>
                    @endforeach
                </div>
                <div>
                    <p class="font-medium mb-2">Dispositivo</p>
                    @foreach ($sources['devices'] as $dev => $n)
                        <div class="flex justify-between py-0.5"><span class="text-gray-600 dark:text-gray-400">{{ ['mobile' => 'Celular', 'desktop' => 'Computador', 'tablet' => 'Tablet'][$dev] ?? $dev }}</span><span>{{ number_format($n, 0, ',', '.') }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
