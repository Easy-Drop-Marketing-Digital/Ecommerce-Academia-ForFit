@extends('layouts.store')

@section('title', 'Avalie sua compra | '.setting('store.name'))
@section('noindex', '1')

@section('content')
<div class="mx-auto px-5 py-10 max-w-[720px]">
    <h1 class="text-2xl font-bold text-center">Como foi a sua experiência?</h1>
    <p class="text-center text-[13.5px] text-gray-600 mt-2 mb-8">Pedido #{{ $order->number }} · Sua opinião ajuda outras clientes a escolher 💛</p>

    @if (session('status'))
        <div class="bg-[#f2fcf8] border border-brand text-[13.5px] rounded-lg p-3 mb-6 text-center">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="bg-[#fff5f4] border border-danger text-danger text-[13px] rounded-lg p-3 mb-6">{{ $errors->first() }}</div>
    @endif

    @forelse ($items as $item)
        @php $img = $item->product?->images->first(); @endphp
        <form method="post" action="{{ route('reviews.store', $order->public_token) }}" enctype="multipart/form-data"
              class="panel p-5 mb-5" x-data="{ rating: 0, hover: 0 }">
            @csrf
            <input type="hidden" name="product_id" value="{{ $item->product_id }}">
            <input type="hidden" name="rating" :value="rating">
            <div class="flex gap-4 items-center mb-4">
                <img src="{{ img_url($img?->path ?? $item->image, 200) }}" alt="" class="w-16 aspect-[4/5] object-cover rounded bg-soft">
                <div>
                    <p class="font-semibold text-[14px]">{{ $item->title }}</p>
                    <p class="text-[12px] text-gray-500">{{ $item->variant_title }}</p>
                </div>
            </div>

            <p class="text-[13px] text-gray-600 mb-1">Sua nota</p>
            <div class="flex gap-1 mb-4" @mouseleave="hover = 0" role="radiogroup" aria-label="Nota de 1 a 5">
                @for ($s = 1; $s <= 5; $s++)
                    <button type="button" class="text-[32px] leading-none" :class="(hover || rating) >= {{ $s }} ? 'text-[#f5a623]' : 'text-[#ddd]'"
                            @click="rating = {{ $s }}" @mouseenter="hover = {{ $s }}" aria-label="{{ $s }} estrela{{ $s > 1 ? 's' : '' }}">★</button>
                @endfor
            </div>

            <label class="block mb-3"><span class="text-[12.5px] text-gray-600">Conte o que achou (caimento, tecido, entrega...)</span>
                <textarea name="body" rows="3" maxlength="2000" class="w-full border border-line rounded-lg p-3 text-[14px] outline-none focus:border-black"></textarea></label>
            <div class="grid sm:grid-cols-2 gap-3 mb-4">
                <label class="block"><span class="text-[12.5px] text-gray-600">Nome que vai aparecer</span>
                    <input name="author" required value="{{ explode(' ', $order->customer_name)[0] }}" class="w-full h-11 border border-line rounded-lg px-3 outline-none focus:border-black"></label>
                <label class="block"><span class="text-[12.5px] text-gray-600">Fotos (opcional, até 3)</span>
                    <input type="file" name="photos[]" accept="image/*" multiple class="w-full text-[12.5px] mt-2"></label>
            </div>
            <button class="btn btn--brand btn--block" :disabled="!rating">Enviar avaliação</button>
        </form>
    @empty
        <div class="panel p-8 text-center">
            <p class="font-semibold">{{ $done ? 'Você já avaliou todos os produtos deste pedido. Obrigada! 💛' : 'Nenhum produto para avaliar.' }}</p>
            <a href="{{ route('home') }}" class="btn btn--dark mt-5">Voltar para a loja</a>
        </div>
    @endforelse
</div>
@endsection
