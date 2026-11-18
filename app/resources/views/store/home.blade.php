@extends('layouts.store')

@section('title', setting('store.name').' | Suplementos Growth e Max Titanium originais')
@section('description', 'Whey protein, creatina, pré-treino e vitaminas Growth e Max Titanium com revenda autorizada. Desconto no PIX, parcelamento sem juros e retirada na academia ForFit.')

@section('content')
    @foreach ($sections as $idx => $section)
        @switch($section['type'])
            @case('slideshow')
                @include('store.sections.slideshow', ['slides' => $section['data'], 'settings' => $section['settings'], 'first' => $idx === 0])
                @break
            @case('collection_list')
                @include('store.sections.collection-list', ['items' => $section['data'], 'title' => $section['title']])
                @break
            @case('product_list')
                @include('store.sections.product-list', ['list' => $section['data'], 'title' => $section['title']])
                @break
            @case('benefits')
                @include('store.sections.benefits', ['items' => $section['data']])
                @break
            @case('brand_list')
                @include('store.sections.brand-list', ['brands' => $section['data'], 'title' => $section['title']])
                @break
        @endswitch
    @endforeach
@endsection
