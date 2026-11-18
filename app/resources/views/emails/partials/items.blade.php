{{-- Itens e totais do pedido --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 6px;border-collapse:collapse;">
    @foreach ($order->items as $i)
        <tr>
            <td width="64" style="padding:8px 12px 8px 0;border-bottom:1px solid #f2f2f2;vertical-align:top;">
                <img src="{{ img_url($i->image, 160) }}" alt="" width="56" style="display:block;width:56px;height:70px;object-fit:cover;border-radius:6px;border:0;">
            </td>
            <td style="padding:8px 0;border-bottom:1px solid #f2f2f2;vertical-align:top;font-size:13px;">
                <strong>{{ $i->title }}</strong>
                @if ($i->variant_title)<br><span style="color:#777;">{{ $i->variant_title }}</span>@endif
                <br><span style="color:#777;">Qtd. {{ $i->quantity }}</span>
            </td>
            <td align="right" style="padding:8px 0;border-bottom:1px solid #f2f2f2;vertical-align:top;font-size:13px;white-space:nowrap;">{{ money($i->unit_price * $i->quantity) }}</td>
        </tr>
    @endforeach
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
    <tr><td style="padding:3px 0;color:#555;">Subtotal</td><td align="right">{{ money($order->subtotal) }}</td></tr>
    @foreach ($order->discounts as $d)
        @if ($d->amount > 0)<tr><td style="padding:3px 0;color:#16a06b;">{{ $d->code ? 'Cupom '.$d->code : $d->title }}</td><td align="right" style="color:#16a06b;">− {{ money($d->amount) }}</td></tr>@endif
    @endforeach
    <tr><td style="padding:3px 0;color:#555;">Frete</td><td align="right">{{ $order->shipping_total ? money($order->shipping_total) : 'Grátis' }}</td></tr>
    @if ($order->interest_total)<tr><td style="padding:3px 0;color:#555;">Juros ({{ $order->installments }}x)</td><td align="right">{{ money($order->interest_total) }}</td></tr>@endif
    <tr><td style="padding:8px 0 0;font-size:15px;"><strong>Total</strong></td><td align="right" style="padding:8px 0 0;font-size:15px;"><strong>{{ money($order->total) }}</strong></td></tr>
</table>
