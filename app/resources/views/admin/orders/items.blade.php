@php $order = $getRecord(); @endphp
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                <th class="py-2 pr-3">Produto</th><th class="py-2 px-3">SKU</th><th class="py-2 px-3 text-right">Qtd</th>
                <th class="py-2 px-3 text-right">Unitário</th><th class="py-2 px-3 text-right">Desconto</th><th class="py-2 pl-3 text-right">Total</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($order->items as $i)
            <tr class="border-b border-gray-100 dark:border-white/5">
                <td class="py-2 pr-3">
                    <div class="flex items-center gap-3">
                        <img src="{{ img_url($i->image, 120) }}" alt="" class="w-10 h-12 object-cover rounded">
                        <div>
                            @if ($i->product_id)
                                <a href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $i->product_id]) }}" class="font-medium hover:underline">{{ $i->title }}</a>
                            @else
                                <span class="font-medium">{{ $i->title }}</span>
                            @endif
                            <div class="text-gray-500 text-xs">{{ $i->variant_title }}</div>
                        </div>
                    </div>
                </td>
                <td class="py-2 px-3 text-gray-500">{{ $i->sku ?: '—' }}</td>
                <td class="py-2 px-3 text-right">{{ $i->quantity }}</td>
                <td class="py-2 px-3 text-right">{{ money($i->unit_price) }}</td>
                <td class="py-2 px-3 text-right">{{ $i->discount ? '− '.money($i->discount) : '—' }}</td>
                <td class="py-2 pl-3 text-right font-medium">{{ money($i->total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <dl class="mt-4 ml-auto max-w-xs text-sm space-y-1">
        <div class="flex justify-between"><dt class="text-gray-500">Subtotal</dt><dd>{{ money($order->subtotal) }}</dd></div>
        @foreach ($order->discounts as $d)
            @if ($d->amount > 0)<div class="flex justify-between text-success-600"><dt>{{ $d->code ? 'Cupom '.$d->code : $d->title }}</dt><dd>− {{ money($d->amount) }}</dd></div>@endif
        @endforeach
        <div class="flex justify-between"><dt class="text-gray-500">Frete</dt><dd>{{ $order->shipping_total ? money($order->shipping_total) : 'Grátis' }}</dd></div>
        @if ($order->interest_total)<div class="flex justify-between"><dt class="text-gray-500">Juros ({{ $order->installments }}x)</dt><dd>{{ money($order->interest_total) }}</dd></div>@endif
        <div class="flex justify-between font-semibold text-base border-t border-gray-200 dark:border-white/10 pt-2"><dt>Total</dt><dd>{{ money($order->total) }}</dd></div>
    </dl>
</div>
