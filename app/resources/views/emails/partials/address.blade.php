@php $a = $order->shipping_address ?? []; @endphp
<p style="margin:18px 0 0;font-size:13px;color:#555;line-height:1.6;">
    <strong style="color:#111;">Entrega</strong><br>
    {{ $order->customer_name }}<br>
    {{ $a['street'] ?? '' }}, {{ $a['number'] ?? '' }}@if (! empty($a['complement'])) – {{ $a['complement'] }}@endif<br>
    {{ $a['neighborhood'] ?? '' }} · {{ $a['city'] ?? '' }}/{{ $a['state'] ?? '' }} · CEP {{ \App\Support\Br::formatCep($a['zip'] ?? '') }}
    @if ($order->shipping_estimate)<br>Prazo estimado: {{ $order->shipping_estimate }}@endif
</p>
