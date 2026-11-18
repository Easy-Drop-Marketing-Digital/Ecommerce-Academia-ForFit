@extends('emails.layout')
@section('preheader', 'Estoque baixo em '.count($variants).' variação(ões).')
@section('content')
<h1 style="font-size:20px;margin:0 0 8px;">Estoque baixo</h1>
<p style="margin:0 0 12px;">Estas variações ficaram com {{ $threshold }} unidades ou menos depois das últimas vendas:</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
    @foreach ($variants as $v)
        <tr>
            <td style="padding:7px 0;border-bottom:1px solid #f2f2f2;">{{ $v->product?->title }}<br><span style="color:#777;">{{ $v->title }}{{ $v->sku ? ' · '.$v->sku : '' }}</span></td>
            <td align="right" style="padding:7px 0;border-bottom:1px solid #f2f2f2;font-weight:700;color:{{ $v->stock <= 0 ? '#c0392b' : '#111' }};">{{ $v->stock }}</td>
        </tr>
    @endforeach
</table>
@include('emails.partials.button', ['url' => url('/admin/estoque'), 'label' => 'Abrir estoque no admin', 'color' => '#111111'])
@endsection
