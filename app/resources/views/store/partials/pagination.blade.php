@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Paginação">
        @if (! $paginator->onFirstPage())
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Página anterior"><x-ui-icon name="chevron-left" class="w-4 h-4" /></a>
        @endif
        @foreach ($elements as $element)
            @if (is_string($element))
                <span>…</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Próxima página"><x-ui-icon name="chevron-right" class="w-4 h-4" /></a>
        @endif
    </nav>
@endif
