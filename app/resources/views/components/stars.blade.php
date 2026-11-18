@props(['value' => 0, 'size' => 14])
{{-- Estrelas com preenchimento proporcional (ex.: 4,8 = 96%) --}}
<span {{ $attributes->merge(['class' => 'stars']) }} style="--size: {{ $size }}px" role="img" aria-label="{{ number_format((float) $value, 1, ',', '') }} de 5 estrelas">
    <span class="stars__fill" style="width: {{ max(0, min(100, (float) $value * 20)) }}%"></span>
</span>
