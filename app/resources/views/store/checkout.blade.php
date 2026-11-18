@extends('layouts.checkout')

@section('title', 'Finalizar compra')

@php
    $field = 'w-full h-12 rounded-lg border bg-white px-3 text-[15px] outline-none transition focus:border-black';
@endphp

@section('content')
<div class="mx-auto px-5 py-5 md:py-8 max-w-[1240px]" x-data="checkout(@js(['quote' => $quote, 'prefill' => $prefill, 'coupon' => $coupon ?? null, 'items' => collect($cart['items'])->map(fn ($i) => ['variant_id' => $i['variant_id'], 'content_id' => $i['content_id'], 'name' => $i['title'], 'price' => $i['price'], 'quantity' => $i['quantity']])->values()]))">

    {{-- Resumo recolhível (mobile) --}}
    <button type="button" class="md:hidden w-full flex items-center justify-between bg-white border border-[#ececec] rounded-xl px-4 py-3 mb-4"
            @click="summaryOpen = !summaryOpen" :aria-expanded="summaryOpen">
        <span class="flex items-center gap-2 text-[13px] font-semibold"><x-ui-icon name="bag" class="w-5 h-5" /> <span x-text="summaryOpen ? 'Ocultar resumo' : 'Ver resumo do pedido'"></span> <x-ui-icon name="chevron-down" class="w-4 h-4 transition" ::class="summaryOpen && 'rotate-180'" /></span>
        <span class="font-semibold" x-text="money(q.total)"></span>
    </button>

    <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_380px] md:gap-8 items-start">
        <form class="flex flex-col gap-5 min-w-0" @submit.prevent="submit" novalidate>

            {{-- 1. Identificação --}}
            <section class="bg-white rounded-xl border border-[#ececec] p-5">
                <h2 class="flex items-center gap-2 text-[16px] font-bold mb-4"><span class="w-6 h-6 rounded-full bg-black text-white text-[12px] flex items-center justify-center">1</span> Identificação</h2>
                <div class="grid gap-3">
                    <label class="block">
                        <span class="text-[12.5px] text-gray-600">E-mail</span>
                        <input type="email" x-model.trim="f.email" @blur="capture(); if (coupon) refresh()" autocomplete="email" inputmode="email" class="{{ $field }}" :class="errors.email ? 'border-danger' : 'border-line'" :data-error="!!errors.email" placeholder="seuemail@exemplo.com">
                        <small class="text-danger" x-show="errors.email" x-text="errors.email"></small>
                    </label>
                    <label class="block">
                        <span class="text-[12.5px] text-gray-600">Nome completo</span>
                        <input type="text" x-model="f.name" @blur="capture()" autocomplete="name" class="{{ $field }}" :class="errors.name ? 'border-danger' : 'border-line'" :data-error="!!errors.name">
                        <small class="text-danger" x-show="errors.name" x-text="errors.name"></small>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-[12.5px] text-gray-600">CPF</span>
                            <input type="text" x-model="f.document" @input="f.document = masks.cpf($event.target.value)" @blur="capture()" inputmode="numeric" autocomplete="off" class="{{ $field }}" :class="errors.document ? 'border-danger' : 'border-line'" :data-error="!!errors.document" placeholder="000.000.000-00">
                            <small class="text-danger" x-show="errors.document" x-text="errors.document"></small>
                        </label>
                        <label class="block">
                            <span class="text-[12.5px] text-gray-600">Celular / WhatsApp</span>
                            <input type="tel" x-model="f.phone" @input="f.phone = masks.phone($event.target.value)" @blur="capture()" inputmode="tel" autocomplete="tel-national" class="{{ $field }}" :class="errors.phone ? 'border-danger' : 'border-line'" :data-error="!!errors.phone" placeholder="(11) 99999-9999">
                            <small class="text-danger" x-show="errors.phone" x-text="errors.phone"></small>
                        </label>
                    </div>
                    <label class="flex items-center gap-2 text-[12.5px] text-gray-600">
                        <input type="checkbox" x-model="f.accepts_marketing" class="accent-black"> Quero receber novidades e ofertas exclusivas por e-mail
                    </label>
                </div>
            </section>

            {{-- 2. Entrega --}}
            <section class="bg-white rounded-xl border border-[#ececec] p-5">
                <h2 class="flex items-center gap-2 text-[16px] font-bold mb-4"><span class="w-6 h-6 rounded-full bg-black text-white text-[12px] flex items-center justify-center">2</span> Entrega</h2>
                <div class="grid gap-3">
                    <label class="block max-w-[220px]">
                        <span class="text-[12.5px] text-gray-600">CEP</span>
                        <div class="relative">
                            <input type="text" x-model="f.zip" @input="f.zip = masks.cep($event.target.value); if ($event.target.value.replace(/\D/g,'').length === 8) lookupCep()" inputmode="numeric" autocomplete="postal-code" class="{{ $field }}" :class="errors.zip ? 'border-danger' : 'border-line'" :data-error="!!errors.zip" placeholder="00000-000">
                            <span x-show="cepLoading" class="absolute right-3 top-3.5 w-5 h-5 border-2 border-gray-300 border-t-black rounded-full animate-spin"></span>
                        </div>
                        <small class="text-danger" x-show="errors.zip" x-text="errors.zip"></small>
                        <a href="https://buscacepinter.correios.com.br/app/endereco/index.php" target="_blank" rel="noopener" class="text-[11.5px] underline text-gray-500">Não sei meu CEP</a>
                    </label>

                    <div class="grid gap-3" x-show="addressLocked" x-collapse>
                        <label class="block">
                            <span class="text-[12.5px] text-gray-600">Endereço</span>
                            <input type="text" x-ref="street" x-model="f.street" @blur="capture()" autocomplete="address-line1" class="{{ $field }}" :class="errors.street ? 'border-danger' : 'border-line'" :data-error="!!errors.street">
                            <small class="text-danger" x-show="errors.street" x-text="errors.street"></small>
                        </label>
                        <div class="grid grid-cols-[110px_1fr] gap-3">
                            <label class="block">
                                <span class="text-[12.5px] text-gray-600">Número</span>
                                <input type="text" x-ref="number" x-model="f.number" @blur="capture()" class="{{ $field }}" :class="errors.number ? 'border-danger' : 'border-line'" :data-error="!!errors.number">
                                <small class="text-danger" x-show="errors.number" x-text="errors.number"></small>
                            </label>
                            <label class="block">
                                <span class="text-[12.5px] text-gray-600">Complemento <span class="text-gray-400">(opcional)</span></span>
                                <input type="text" x-model="f.complement" @blur="capture()" autocomplete="address-line2" class="{{ $field }} border-line" placeholder="Apto, bloco, referência">
                            </label>
                        </div>
                        <div class="grid grid-cols-[1fr_1fr_76px] gap-3">
                            <label class="block">
                                <span class="text-[12.5px] text-gray-600">Bairro</span>
                                <input type="text" x-model="f.neighborhood" class="{{ $field }}" :class="errors.neighborhood ? 'border-danger' : 'border-line'" :data-error="!!errors.neighborhood">
                            </label>
                            <label class="block">
                                <span class="text-[12.5px] text-gray-600">Cidade</span>
                                <input type="text" x-model="f.city" autocomplete="address-level2" class="{{ $field }}" :class="errors.city ? 'border-danger' : 'border-line'" :data-error="!!errors.city">
                            </label>
                            <label class="block">
                                <span class="text-[12.5px] text-gray-600">UF</span>
                                <input type="text" x-model="f.state" @input="f.state = $event.target.value.toUpperCase().slice(0,2)" maxlength="2" autocomplete="address-level1" class="{{ $field }} uppercase" :class="errors.state ? 'border-danger' : 'border-line'" :data-error="!!errors.state">
                            </label>
                        </div>
                    </div>

                    <fieldset class="mt-1" x-show="q.shipping_options.length">
                        <legend class="text-[12.5px] text-gray-600 mb-2">Forma de entrega</legend>
                        <div class="grid gap-2">
                            <template x-for="opt in q.shipping_options" :key="opt.id">
                                <label class="flex items-center gap-3 border rounded-lg px-3 py-3 cursor-pointer" :class="shippingId === opt.id ? 'border-brand bg-[#f2fcf8]' : 'border-line'">
                                    <input type="radio" name="shipping" :value="opt.id" x-model.number="shippingId" class="accent-[#16c789]">
                                    <span class="flex-1">
                                        <span class="block text-[14px] font-semibold" x-text="opt.name"></span>
                                        <span class="block text-[12px] text-gray-500" x-text="opt.estimate || opt.description"></span>
                                    </span>
                                    <span class="text-[14px] font-semibold" :class="opt.price === 0 && 'text-brand'" x-text="opt.price === 0 ? 'Grátis' : money(opt.price)"></span>
                                </label>
                            </template>
                        </div>
                    </fieldset>
                </div>
            </section>

            {{-- 3. Pagamento --}}
            <section class="bg-white rounded-xl border border-[#ececec] p-5">
                <h2 class="flex items-center gap-2 text-[16px] font-bold mb-4"><span class="w-6 h-6 rounded-full bg-black text-white text-[12px] flex items-center justify-center">3</span> Pagamento</h2>

                <div class="grid grid-cols-2 gap-2 mb-4" role="radiogroup" aria-label="Forma de pagamento">
                    <button type="button" role="radio" :aria-checked="method === 'pix'" @click="method = 'pix'"
                            class="border rounded-lg px-3 py-3 text-left" :class="method === 'pix' ? 'border-brand bg-[#f2fcf8]' : 'border-line'">
                        <span class="block font-semibold text-[14px]">PIX</span>
                        <span class="block text-[12px] text-gray-500">Aprovação imediata</span>
                    </button>
                    <button type="button" role="radio" :aria-checked="method === 'credit_card'" @click="method = 'credit_card'"
                            class="border rounded-lg px-3 py-3 text-left" :class="method === 'credit_card' ? 'border-brand bg-[#f2fcf8]' : 'border-line'">
                        <span class="block font-semibold text-[14px]">Cartão de crédito</span>
                        <span class="block text-[12px] text-gray-500">Em até {{ setting('nixfy.max_installments', 12) }}x</span>
                    </button>
                </div>

                <div x-show="method === 'pix'" class="text-[13px] text-gray-600 grid gap-2">
                    <p class="flex items-start gap-2"><x-ui-icon name="check" class="w-4 h-4 text-brand shrink-0 mt-0.5" /> O QR Code e o código “copia e cola” aparecem na próxima tela.</p>
                    <p class="flex items-start gap-2"><x-ui-icon name="check" class="w-4 h-4 text-brand shrink-0 mt-0.5" /> Pagamento confirmado na hora, 24h por dia.</p>
                    <p class="flex items-start gap-2"><x-ui-icon name="clock" class="w-4 h-4 shrink-0 mt-0.5" /> O código vale por {{ (int) setting('nixfy.pix_expires_in_days', 1) <= 1 ? '24 horas' : setting('nixfy.pix_expires_in_days').' dias' }}.</p>
                </div>

                <div x-show="method === 'credit_card'" x-cloak class="grid gap-3">
                    <label class="block">
                        <span class="text-[12.5px] text-gray-600">Número do cartão</span>
                        <div class="relative">
                            <input type="text" x-model="card.number" @input="card.number = masks.card($event.target.value)" inputmode="numeric" autocomplete="cc-number" class="{{ $field }} pr-24" :class="errors.card_number ? 'border-danger' : 'border-line'" :data-error="!!errors.card_number" placeholder="0000 0000 0000 0000">
                            <span class="absolute right-3 top-3.5 text-[12px] font-semibold text-gray-500" x-text="brand"></span>
                        </div>
                        <small class="text-danger" x-show="errors.card_number" x-text="errors.card_number"></small>
                    </label>
                    <label class="block">
                        <span class="text-[12.5px] text-gray-600">Nome impresso no cartão</span>
                        <input type="text" x-model="card.holder" @input="card.holder = $event.target.value.toUpperCase()" autocomplete="cc-name" class="{{ $field }} uppercase" :class="errors.card_holder ? 'border-danger' : 'border-line'" :data-error="!!errors.card_holder">
                        <small class="text-danger" x-show="errors.card_holder" x-text="errors.card_holder"></small>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-[12.5px] text-gray-600">Validade</span>
                            <input type="text" x-model="card.exp" @input="card.exp = masks.exp($event.target.value)" inputmode="numeric" autocomplete="cc-exp" class="{{ $field }}" :class="errors.card_exp ? 'border-danger' : 'border-line'" :data-error="!!errors.card_exp" placeholder="MM/AA">
                            <small class="text-danger" x-show="errors.card_exp" x-text="errors.card_exp"></small>
                        </label>
                        <label class="block">
                            <span class="text-[12.5px] text-gray-600">CVV</span>
                            <input type="text" x-model="card.cvv" @input="card.cvv = $event.target.value.replace(/\D/g,'').slice(0,4)" inputmode="numeric" autocomplete="cc-csc" class="{{ $field }}" :class="errors.card_cvv ? 'border-danger' : 'border-line'" :data-error="!!errors.card_cvv" placeholder="123">
                            <small class="text-danger" x-show="errors.card_cvv" x-text="errors.card_cvv"></small>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-[12.5px] text-gray-600">Parcelas</span>
                        <select x-model.number="installments" class="{{ $field }} border-line">
                            <template x-for="p in installmentOptions" :key="p.n">
                                <option :value="p.n" :selected="p.n === installments" x-text="`${p.n}x de ${money(p.value)}` + (p.interest ? ` (total ${money(p.total)})` : ' sem juros')"></option>
                            </template>
                        </select>
                    </label>
                    <p class="flex items-center gap-1.5 text-[11.5px] text-gray-500"><x-ui-icon name="lock" class="w-3.5 h-3.5" /> Seus dados são criptografados e enviados direto ao processador de pagamento. Não armazenamos o número nem o CVV do cartão.</p>
                </div>

                <button type="submit" class="btn btn--brand btn--block btn--lg mt-5" :disabled="submitting">
                    <span x-show="!submitting" x-text="method === 'pix' ? 'Gerar PIX · ' + money(q.total) : 'Pagar ' + money(q.total)"></span>
                    <span x-show="submitting" x-cloak class="flex items-center gap-2"><span class="w-5 h-5 border-2 border-white/40 border-t-white rounded-full animate-spin"></span> Processando...</span>
                </button>
                <p class="text-center text-[11.5px] text-gray-500 mt-3">Ao finalizar, você concorda com os <a href="{{ route('policy.show', 'terms-of-service') }}" target="_blank" class="underline">Termos</a> e a <a href="{{ route('policy.show', 'privacy-policy') }}" target="_blank" class="underline">Política de Privacidade</a>.</p>
            </section>
        </form>

        {{-- Resumo do pedido --}}
        <aside class="bg-white rounded-xl border border-[#ececec] p-5 md:sticky md:top-5 max-md:row-start-1" :class="!summaryOpen && 'max-md:hidden'">
            <h2 class="text-[16px] font-bold mb-3">Resumo do pedido</h2>
            <div class="grid gap-3 max-h-[320px] overflow-y-auto pr-1">
                @foreach ($cart['items'] as $item)
                    <div class="flex gap-3">
                        <div class="relative w-14 shrink-0 aspect-[4/5] bg-soft rounded overflow-hidden">
                            <img src="{{ $item['image'] }}" alt="" class="w-full h-full object-cover">
                            <span class="absolute -top-0 -right-0 bg-black text-white text-[10px] font-bold rounded-bl px-1.5">{{ $item['quantity'] }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[12.5px] leading-snug line-clamp-2">{{ $item['title'] }}</p>
                            @if ($item['variant_title'])<p class="text-[11.5px] text-gray-500">{{ $item['variant_title'] }}</p>@endif
                        </div>
                        <span class="text-[12.5px] font-semibold whitespace-nowrap">{{ money($item['line_total']) }}</span>
                    </div>
                @endforeach
            </div>
            <a href="{{ route('cart.show') }}" class="text-[12px] underline text-gray-500 inline-block mt-3">Editar carrinho</a>

            <div class="border-t border-[#f0f0f0] mt-4 pt-4">
                <button type="button" class="text-[13px] font-semibold flex items-center gap-1" @click="couponOpen = !couponOpen" x-show="q.code_status !== 'applied'">
                    <x-ui-icon name="tag" class="w-4 h-4" /> Tem um cupom?
                </button>
                <div x-show="couponOpen && q.code_status !== 'applied'" x-collapse class="flex gap-2 mt-2">
                    <input type="text" x-model="coupon" @keydown.enter.prevent="applyCoupon" placeholder="Código do cupom" class="flex-1 h-11 border border-line rounded-lg px-3 uppercase outline-none focus:border-black">
                    <button type="button" class="btn btn--dark !min-h-11 !px-4" @click="applyCoupon">Aplicar</button>
                </div>
                <p class="text-[12px] mt-1" :class="q.code_status === 'applied' ? 'text-brand' : 'text-danger'" x-show="coupon && q.code_message" x-text="q.code_message"></p>
                <button type="button" class="text-[12px] underline" x-show="q.code_status === 'applied'" @click="removeCoupon">Remover cupom</button>
            </div>

            <dl class="border-t border-[#f0f0f0] mt-4 pt-4 grid gap-1.5 text-[13.5px]" :class="loading && 'opacity-50'">
                <div class="flex justify-between"><dt>Subtotal</dt><dd x-text="money(q.subtotal)"></dd></div>
                <template x-for="d in q.discounts" :key="d.title">
                    <div class="flex justify-between text-brand" x-show="d.amount > 0"><dt x-text="d.code ? `Cupom ${d.code}` : d.title"></dt><dd x-text="'− ' + money(d.amount)"></dd></div>
                </template>
                <div class="flex justify-between"><dt>Frete</dt><dd :class="q.shipping === 0 && 'text-brand font-semibold'" x-text="q.shipping === 0 ? 'Grátis' : money(q.shipping)"></dd></div>
                <div class="flex justify-between" x-show="q.interest > 0"><dt>Juros do parcelamento</dt><dd x-text="money(q.interest)"></dd></div>
                <div class="flex justify-between items-baseline border-t border-[#f0f0f0] pt-3 mt-2"><dt class="font-semibold">Total</dt><dd class="text-[22px] font-bold" x-text="money(q.total)"></dd></div>
                <p class="text-[12px] text-gray-500 text-right" x-show="method === 'credit_card' && installments > 1" x-text="`${installments}x de ${money((q.installment_plan[installments-1] || {}).value)}`"></p>
                <p class="text-[12px] text-brand font-semibold text-right" x-show="method === 'credit_card' && q.pix_total < q.total" x-text="`ou ${money(q.pix_total)} no PIX`"></p>
                <p class="text-[12px] text-brand font-semibold" x-show="q.compare_total > q.subtotal" x-text="`Você está economizando ${money(q.compare_total - q.subtotal + q.discount_total)}`"></p>
            </dl>

            <ul class="border-t border-[#f0f0f0] mt-4 pt-4 grid gap-2 text-[12px] text-gray-600">
                <li class="flex gap-2"><x-ui-icon name="truck" class="w-4 h-4 shrink-0" /> Entrega para todo o Brasil com código de rastreio</li>
                <li class="flex gap-2"><x-ui-icon name="return" class="w-4 h-4 shrink-0" /> 7 dias para devolução com reembolso integral</li>
                <li class="flex gap-2"><x-ui-icon name="shield" class="w-4 h-4 shrink-0" /> Produtos originais Growth e Max Titanium, com nota fiscal</li>
            </ul>
        </aside>
    </div>
</div>
@endsection
