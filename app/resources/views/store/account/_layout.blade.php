@extends('layouts.store')

@section('noindex', '1')

@section('content')
<div class="page-width py-10 md:py-14">
    <div class="max-w-[440px] mx-auto">
        <h1 class="text-2xl font-bold text-center mb-2">@yield('heading')</h1>
        @hasSection('sub')<p class="text-center text-[13.5px] text-gray-600 mb-6">@yield('sub')</p>@endif
        @if (session('status'))
            <div class="bg-[#f2fcf8] border border-brand text-[13px] rounded-lg p-3 mb-4">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-[#fff5f4] border border-danger text-danger text-[13px] rounded-lg p-3 mb-4">{{ $errors->first() }}</div>
        @endif
        <div class="panel p-6">
            @yield('form')
        </div>
        @yield('after')
    </div>
</div>
@endsection
