@extends('layouts.checkout')

@section('title', 'Pedido #'.$order->number)

@php
    $isPix = $order->payment_method === 'pix';
    $paid = $order->isPaid();
    $waitingPix = $isPix && ! $paid && $order->status === 'pending' && $order->pix_code;
    $refused = in_array($order->payment_status, ['refused', 'failed'], true);
    $steps = ['paid' => 'Pagamento aprovado', 'processing' => 'Em separação', 'shipped' => 'Enviado', 'delivered' => 'Entregue'];
    $reached = match ($order->status) { 'paid' => 1, 'processing' => 2, 'shipped' => 3, 'delivered' => 4, default => 0 };
@endphp

@if ($paid)
    @push('head')
    <script>
        (function () {
            var key = 'sd_purchase_' + @json($order->event_id);
            try { if (localStorage.getItem(key)) return; localStorage.setItem(key, '1'); } catch (e) {}
            sdq.push(['purchase', @json($purchase)]);
        })();
    </script>
    @endpush
@endif

@section('content')
<div class="mx-auto px-5 py-6 md:py-10 max-w-[760px]"
     x-data="orderPage(@js([
        'statusUrl' => route('order.status.json', $order->public_token),
        'retryUrl' => route('order.retry', $order->public_token),
        'polling' => $waitingPix || $order->payment_status === 'processing',
        'pixExpiresAt' => $order->pix_expires_at?->toIso8601String(),
        'plan' => array_values($plan),
        'pixCode' => $order->pix_code,
        'paid' => $paid,
     ]))">

    {{-- Cabeçalho de status --}}
    <div class="bg-white rounded-xl border border-[#ececec] p-6 text-center">
        <template x-if="paid">
            <div>
                <div class="w-14 h-14 rounded-full bg-brand text-white flex items-center justify-center mx-auto mb-3"><x-ui-icon name="check" class="w-7 h-7" /></div>
                <h1 class="text-xl md:text-2xl font-bold">Pagamento aprovado!</h1>
                <p class="text-gray-600 mt-1">Obrigada, {{ explode(' ', $order->customer_name)[0] }}! Seu pedido <b>#{{ $order->number }}</b> foi confirmado.</p>
                <p class="text-[13px] text-gray-500 mt-2">Enviamos a confirmação para <b>{{ $order->customer_email }}</b>. Você receberá o código de rastreio {{ setting('delivery.tracking_days') }}.</p>
            </div>
        </template>

        <template x-if="!paid">
            <div>
                @if ($order->status === 'canceled')
                    <h1 class="text-xl md:text-2xl font-bold">Pedido #{{ $order->number }} cancelado</h1>
                    <p class="text-gray-600 mt-1">O pagamento não foi concluído dentro do prazo. Nenhum valor foi cobrado.</p>
                    <a href="{{ route('home') }}" class="btn btn--dark mt-5">Voltar para a loja</a>
                @elseif ($waitingPix)
                    <h1 class="text-xl md:text-2xl font-bold">Falta pouco! Pague o PIX para confirmar</h1>
                    <p class="text-gray-600 mt-1">Pedido <b>#{{ $order->number }}</b> · Total <b>{{ money($order->total) }}</b></p>
                @elseif ($refused)
                    <h1 class="text-xl md:text-2xl font-bold">Pagamento não aprovado</h1>
                    <p class="text-gray-600 mt-1">O banco emissor recusou o cartão. Seu pedido <b>#{{ $order->number }}</b> continua reservado — tente outro cartão ou pague com PIX.</p>
                @else
                    <h1 class="text-xl md:text-2xl font-bold">Recebemos seu pedido #{{ $order->number }}</h1>
                    <p class="text-gray-600 mt-1" x-show="!retryOpen">Estamos confirmando o pagamento. Esta página atualiza sozinha.</p>
                @endif
            </div>
        </template>
    </div>

    {{-- PIX: QR + copia e cola --}}
    @if ($waitingPix)
        <div class="bg-white rounded-xl border border-[#ececec] p-6 mt-4" x-show="!paid">
            <div class="grid md:grid-cols-[220px_1fr] gap-6 items-center">
                @if ($order->pix_qr_base64)
                    <img src="{{ $order->pix_qr_base64 }}" alt="QR Code PIX" class="w-[220px] h-[220px] mx-auto border border-[#eee] rounded-lg">
                @endif
                <div>
                    <ol class="text-[13.5px] text-gray-700 grid gap-1.5 list-decimal ml-4 mb-4">
                        <li>Abra o app do seu banco e escolha <b>Pagar com PIX</b>.</li>
                        <li>Escaneie o QR Code ou use o <b>PIX Copia e Cola</b>.</li>
                        <li>Confirme o pagamento de <b>{{ money($order->total) }}</b>.</li>
                    </ol>
                    <label class="text-[12px] text-gray-500" for="pix-code">Código PIX copia e cola</label>
                    <textarea id="pix-code" readonly rows="3" class="w-full text-[11.5px] border border-line rounded-lg p-2 font-mono break-all resize-none" @focus="$el.select()">{{ $order->pix_code }}</textarea>
                    <button type="button" class="btn btn--brand btn--block mt-2" @click="copyPix">
                        <span x-text="copied ? 'Código copiado!' : 'Copiar código PIX'"></span>
                    </button>
                    @if (config('services.nixfy.sandbox') && ! app()->isProduction() && $order->gateway_uuid)
                        <a href="{{ route('dev.sandbox.pay', $order->gateway_uuid) }}" class="btn btn--outline btn--block mt-2 !border-dashed">[Sandbox local] Simular pagamento do PIX</a>
                    @endif
                    <p class="flex items-center gap-2 text-[12.5px] text-gray-600 mt-3" x-show="remaining">
                        <span class="w-2 h-2 rounded-full bg-brand animate-pulse"></span>
                        Aguardando pagamento · expira em <b x-text="remaining"></b>
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Nova tentativa (cartão recusado / erro) --}}
    @if ($canRetry)
        <div class="bg-white rounded-xl border border-[#ececec] p-6 mt-4" x-show="!paid">
            <h2 class="text-[16px] font-bold mb-3">Tentar novamente</h2>
            <div class="grid grid-cols-2 gap-2 mb-4">
                <button type="button" @click="method = 'pix'" class="border rounded-lg px-3 py-3 text-left" :class="method === 'pix' ? 'border-brand bg-[#f2fcf8]' : 'border-line'"><b class="block text-[14px]">PIX</b><span class="text-[12px] text-gray-500">Aprovação imediata</span></button>
                <button type="button" @click="method = 'credit_card'" class="border rounded-lg px-3 py-3 text-left" :class="method === 'credit_card' ? 'border-brand bg-[#f2fcf8]' : 'border-line'"><b class="block text-[14px]">Outro cartão</b><span class="text-[12px] text-gray-500">Em até {{ setting('nixfy.max_installments', 12) }}x</span></button>
            </div>
            <div x-show="method === 'credit_card'" x-cloak class="grid gap-3">
                <input type="text" x-model="card.number" @input="card.number = masks.card($event.target.value)" inputmode="numeric" autocomplete="cc-number" placeholder="Número do cartão" class="h-12 border border-line rounded-lg px-3">
                <input type="text" x-model="card.holder" @input="card.holder = $event.target.value.toUpperCase()" autocomplete="cc-name" placeholder="Nome impresso no cartão" class="h-12 border border-line rounded-lg px-3 uppercase">
                <div class="grid grid-cols-2 gap-3">
                    <input type="text" x-model="card.exp" @input="card.exp = masks.exp($event.target.value)" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA" class="h-12 border border-line rounded-lg px-3">
                    <input type="text" x-model="card.cvv" @input="card.cvv = $event.target.value.replace(/\D/g,'').slice(0,4)" inputmode="numeric" autocomplete="cc-csc" placeholder="CVV" class="h-12 border border-line rounded-lg px-3">
                </div>
                <select x-model.number="installments" class="h-12 border border-line rounded-lg px-3">
                    <template x-for="p in plan" :key="p.n"><option :value="p.n" x-text="`${p.n}x de ${money(p.value)}` + (p.interest ? ` (total ${money(p.total)})` : ' sem juros')"></option></template>
                </select>
            </div>
            <p class="text-danger text-[13px] mt-2" x-show="error" x-text="error"></p>
            <button type="button" class="btn btn--brand btn--block btn--lg mt-4" @click="retry" :disabled="busy">
                <span x-show="!busy" x-text="method === 'pix' ? 'Gerar PIX' : 'Pagar com cartão'"></span>
                <span x-show="busy" x-cloak>Processando...</span>
            </button>
        </div>
    @endif

    {{-- Andamento --}}
    @if ($paid && $order->status !== 'refunded')
        <div class="bg-white rounded-xl border border-[#ececec] p-6 mt-4">
            <h2 class="text-[16px] font-bold mb-4">Acompanhe seu pedido</h2>
            <ol class="grid grid-cols-4 gap-2 text-center text-[11.5px]">
                @foreach (array_values($steps) as $i => $label)
                    <li>
                        <span class="w-8 h-8 rounded-full mx-auto mb-1 flex items-center justify-center {{ $i < $reached ? 'bg-brand text-white' : 'bg-soft text-gray-400' }}">
                            @if ($i < $reached)<x-ui-icon name="check" class="w-4 h-4" />@else{{ $i + 1 }}@endif
                        </span>
                        <span class="{{ $i < $reached ? 'font-semibold' : 'text-gray-500' }}">{{ $label }}</span>
                    </li>
                @endforeach
            </ol>
            @if ($order->tracking_code)
                <div class="mt-5 border border-line rounded-lg p-3 text-[13px] flex flex-wrap items-center justify-between gap-2">
                    <span>Código de rastreio: <b>{{ $order->tracking_code }}</b> @if ($order->carrier)({{ $order->carrier }})@endif</span>
                    @if ($order->tracking_url)<a href="{{ $order->tracking_url }}" target="_blank" rel="noopener" class="btn btn--dark !min-h-10">Rastrear entrega</a>@endif
                </div>
            @endif
        </div>
    @endif

    @if (in_array($order->status, ['shipped', 'delivered'], true))
        <div class="bg-white rounded-xl border border-[#ececec] p-6 mt-4 flex flex-wrap items-center justify-between gap-3">
            <div><p class="font-bold">Já recebeu? Conte o que achou!</p><p class="text-[13px] text-gray-600">Sua avaliação ajuda outras clientes a escolher.</p></div>
            <a href="{{ route('reviews.form', $order->public_token) }}" class="btn btn--dark">★ Avaliar minha compra</a>
        </div>
    @endif

    {{-- Detalhes --}}
    <div class="bg-white rounded-xl border border-[#ececec] p-6 mt-4">
        <h2 class="text-[16px] font-bold mb-3">Detalhes do pedido #{{ $order->number }}</h2>
        <div class="grid gap-3">
            @foreach ($order->items as $item)
                <div class="flex gap-3">
                    <img src="{{ img_url($item->image, 200) }}" alt="" class="w-14 aspect-[4/5] object-cover rounded bg-soft">
                    <div class="flex-1 min-w-0">
                        <p class="text-[13px] line-clamp-2">{{ $item->title }}</p>
                        <p class="text-[12px] text-gray-500">{{ $item->variant_title }} · Qtd. {{ $item->quantity }}</p>
                    </div>
                    <span class="text-[13px] font-semibold">{{ money($item->unit_price * $item->quantity) }}</span>
                </div>
            @endforeach
        </div>
        <dl class="border-t border-[#f0f0f0] mt-4 pt-4 grid gap-1.5 text-[13.5px]">
            <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ money($order->subtotal) }}</dd></div>
            @foreach ($order->discounts as $d)
                @if ($d->amount > 0)<div class="flex justify-between text-brand"><dt>{{ $d->code ? 'Cupom '.$d->code : $d->title }}</dt><dd>− {{ money($d->amount) }}</dd></div>@endif
            @endforeach
            <div class="flex justify-between"><dt>Frete ({{ $order->shipping_method }})</dt><dd>{{ $order->shipping_total ? money($order->shipping_total) : 'Grátis' }}</dd></div>
            @if ($order->interest_total)<div class="flex justify-between"><dt>Juros ({{ $order->installments }}x)</dt><dd>{{ money($order->interest_total) }}</dd></div>@endif
            <div class="flex justify-between font-bold text-[15px] border-t border-[#f0f0f0] pt-2 mt-1"><dt>Total</dt><dd>{{ money($order->total) }}</dd></div>
            <div class="flex justify-between text-[12.5px] text-gray-500"><dt>Pagamento</dt><dd>{{ $isPix ? 'PIX' : 'Cartão '.($order->card_brand ? $order->card_brand.' ' : '').($order->card_last4 ? '•••• '.$order->card_last4 : '').' · '.$order->installments.'x' }}</dd></div>
        </dl>
        @php $a = $order->shipping_address; @endphp
        <div class="border-t border-[#f0f0f0] mt-4 pt-4 text-[13px] text-gray-700">
            <p class="font-semibold text-black mb-1">Entrega</p>
            <p>{{ $order->customer_name }}</p>
            <p>{{ $a['street'] }}, {{ $a['number'] }}@if (! empty($a['complement'])) – {{ $a['complement'] }}@endif</p>
            <p>{{ $a['neighborhood'] }} · {{ $a['city'] }}/{{ $a['state'] }} · CEP {{ \App\Support\Br::formatCep($a['zip']) }}</p>
            @if ($order->shipping_estimate)<p class="text-gray-500 mt-1">Prazo estimado: {{ $order->shipping_estimate }}</p>@endif
        </div>
    </div>

    <p class="text-center text-[12.5px] text-gray-500 mt-6">Dúvidas? Fale com a gente: {{ setting('store.email') }}@if (setting('store.whatsapp')) · WhatsApp {{ setting('store.whatsapp') }}@endif</p>
    <div class="text-center mt-4"><a href="{{ route('home') }}" class="link-underline">Continuar comprando</a></div>
</div>
@endsection
