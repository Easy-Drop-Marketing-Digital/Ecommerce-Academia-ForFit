@php
    $logo = setting('store.logo');
    $store = setting('store.name', config('app.name'));
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subject ?? $store }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Montserrat,Helvetica,Arial,sans-serif;color:#111;">
    {{-- Pré-visualização na caixa de entrada --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">@yield('preheader')</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;">
        <tr><td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #ececec;">
                <tr><td align="center" style="padding:26px 24px 18px;border-bottom:1px solid #f0f0f0;">
                    <a href="{{ url('/') }}" style="text-decoration:none;color:#111;">
                        @if ($logo)<img src="{{ img_url($logo) }}" alt="{{ $store }}" width="220" style="display:block;width:220px;height:auto;border:0;">@else<strong style="font-size:20px;">{{ $store }}</strong>@endif
                    </a>
                </td></tr>
                <tr><td style="padding:30px 28px 10px;font-size:14px;line-height:1.6;color:#222;">
                    @yield('content')
                </td></tr>
                <tr><td style="padding:22px 28px 28px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #f0f0f0;">
                        <tr><td style="padding-top:18px;font-size:12px;line-height:1.6;color:#777;text-align:center;">
                            Dúvidas? Responda este e-mail ou fale com a gente: <a href="mailto:{{ setting('store.email') }}" style="color:#111;">{{ setting('store.email') }}</a>
                            @if ($wa = setting('store.whatsapp')) · WhatsApp {{ $wa }}@endif
                            <br>{{ $store }}@if (setting('store.cnpj')) · CNPJ {{ setting('store.cnpj') }}@endif
                            <br><a href="{{ route('policy.show', 'refund-policy') }}" style="color:#777;">Trocas e devoluções</a> ·
                            <a href="{{ route('policy.show', 'privacy-policy') }}" style="color:#777;">Privacidade</a>
                            @if (! empty($unsubscribeUrl))
                                <br><a href="{{ $unsubscribeUrl }}" style="color:#999;">Não quero mais receber estes e-mails</a>
                            @endif
                        </td></tr>
                    </table>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
