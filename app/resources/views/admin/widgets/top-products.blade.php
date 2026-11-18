<x-filament-widgets::widget>
    <x-filament::section heading="Produtos mais vendidos" description="Por receita aprovada no período">
        @if (empty($rows))
            <p class="text-sm text-gray-500">Nenhuma venda no período.</p>
        @else
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th class="pb-2">Produto</th><th class="pb-2 text-right">Peças</th><th class="pb-2 text-right">Receita</th></tr></thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-3">
                            @if ($r['product_id'])
                                <a href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $r['product_id']]) }}" class="hover:underline">{{ \Illuminate\Support\Str::limit($r['title'], 55) }}</a>
                            @else {{ $r['title'] }} @endif
                        </td>
                        <td class="py-2 text-right">{{ $r['qty'] }}</td>
                        <td class="py-2 text-right font-medium">{{ money((int) $r['revenue']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
