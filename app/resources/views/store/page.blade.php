@extends('layouts.store')

@section('title', ($page->seo_title ?: $page->title.' | '.setting('store.name')))
@section('description', $page->seo_description ?: \Illuminate\Support\Str::limit(trim(strip_tags($page->renderedContent())), 160))
@section('canonical', $page->url())

@section('content')
<article class="page-content">
    <h1>{{ $page->title }}</h1>

    @if ($page->slug === 'rastrear-pedido')
        <form method="post" action="{{ route('order.track') }}" class="panel p-5 mb-8 grid gap-3 sm:grid-cols-[1fr_1.4fr_auto] sm:items-end">
            @csrf
            <label class="block"><span class="text-[12.5px] text-gray-600">Número do pedido</span>
                <input name="number" value="{{ old('number') }}" required inputmode="numeric" placeholder="Ex.: 10025" class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
            <label class="block"><span class="text-[12.5px] text-gray-600">E-mail da compra</span>
                <input name="email" type="email" value="{{ old('email') }}" required class="w-full h-12 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
            <button class="btn btn--dark !min-h-12">Rastrear</button>
            @error('number')<p class="text-danger text-[13px] sm:col-span-3">{{ $message }}</p>@enderror
        </form>
    @endif

    <div class="rte">{!! $page->renderedContent() !!}</div>
</article>
@endsection
