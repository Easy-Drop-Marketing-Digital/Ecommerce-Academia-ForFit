@extends('emails.layout')
@php
    $first = $cart->name ? explode(' ', trim($cart->name))[0] : null;
    $titles = [
        1 => 'Você esqueceu algo no carrinho',
        2 => 'Suas peças ainda estão esperando por você',
        3 => $coupon ? 'Um presente para você finalizar 💛' : 'Última chamada para as suas peças',
    ];
@endphp
@section('preheader', $titles[$step].' — '.setting('store.name'))
@section('content')
<h1 style="font-size:21px;margin:0 0 8px;">{{ $titles[$step] }}{{ $first ? ', '.$first : '' }}</h1>
@if ($step === 1)
    <p style="margin:0;">Vimos que você separou algumas peças e não finalizou. Guardamos tudo no seu carrinho:</p>
@elseif ($step === 2)
    <p style="margin:0;">As peças que você escolheu fazem sucesso e o estoque é limitado. Finalize antes que acabem:</p>
@else
    <p style="margin:0;">Seu carrinho continua salvo.@if ($coupon) Use o cupom <strong style="background:#111;color:#fff;padding:2px 8px;border-radius:5px;">{{ $coupon }}</strong> no checkout e ganhe um desconto especial.@endif</p>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 6px;border-collapse:collapse;">
    @foreach ($items as $i)
        <tr>
            <td width="64" style="padding:8px 12px 8px 0;border-bottom:1px solid #f2f2f2;">
                <img src="{{ $i['image'] }}" alt="" width="56" style="display:block;width:56px;height:70px;object-fit:cover;border-radius:6px;border:0;">
            </td>
            <td style="padding:8px 0;border-bottom:1px solid #f2f2f2;font-size:13px;">
                <strong>{{ $i['title'] }}</strong>@if ($i['variant_title'])<br><span style="color:#777;">{{ $i['variant_title'] }}</span>@endif
            </td>
            <td align="right" style="padding:8px 0;border-bottom:1px solid #f2f2f2;font-size:13px;white-space:nowrap;">{{ money($i['line_total']) }}</td>
        </tr>
    @endforeach
</table>
<p style="margin:6px 0 0;text-align:right;font-size:14px;">Total: <strong>{{ money($subtotal) }}</strong></p>

@include('emails.partials.button', ['url' => $cart->recoveryUrl(), 'label' => 'Finalizar minha compra'])
<p style="margin:0;font-size:12.5px;color:#666;text-align:center;">PIX com aprovação na hora ou cartão em até {{ setting('nixfy.max_installments', 12) }}x · Frete grátis</p>
@endsection
