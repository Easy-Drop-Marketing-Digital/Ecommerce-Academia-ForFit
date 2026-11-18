<x-filament-widgets::widget>
    <x-filament::section heading="Vendas por origem e campanha" description="UTM gravada no pedido (utm_source / utm_campaign)">
        @if (empty($rows))
            <p class="text-sm text-gray-500">Nenhuma venda no período.</p>
        @else
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th class="pb-2">Origem</th><th class="pb-2">Campanha</th><th class="pb-2 text-right">Pedidos</th><th class="pb-2 text-right">Receita</th><th class="pb-2 text-right">Ticket médio</th></tr></thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-3 font-medium">{{ $r['source'] }}</td>
                        <td class="py-2 pr-3 text-gray-600 dark:text-gray-400">{{ $r['campaign'] }}</td>
                        <td class="py-2 text-right">{{ $r['orders'] }}</td>
                        <td class="py-2 text-right font-medium">{{ money((int) $r['revenue']) }}</td>
                        <td class="py-2 text-right">{{ money(intdiv((int) $r['revenue'], max(1, (int) $r['orders']))) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
