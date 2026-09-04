@if ($paginator->hasPages())
    <div class="w-full">
        <p class="mb-4 text-center text-sm text-slate-500">
            {{ __('pagination.showing', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
        </p>
        <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex items-center justify-center px-2">
            <div class="flex max-w-full flex-wrap items-center justify-center gap-1">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <span class="inline-flex h-9 min-w-9 items-center justify-center border border-slate-300 bg-slate-100 px-2 text-xs text-slate-400 sm:h-10 sm:min-w-10 sm:px-3 sm:text-sm">
                    {!! __('pagination.previous') !!}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex h-9 min-w-9 items-center justify-center border border-slate-300 bg-white px-2 text-xs font-medium text-slate-700 hover:bg-slate-100 focus:z-10 sm:h-10 sm:min-w-10 sm:px-3 sm:text-sm">
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            {{-- Pagination Elements --}}
            @foreach ($elements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <span class="inline-flex h-9 min-w-9 items-center justify-center border border-slate-300 bg-white px-2 text-xs text-slate-500 sm:h-10 sm:min-w-10 sm:px-3 sm:text-sm">{{ $element }}</span>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="inline-flex h-9 min-w-9 items-center justify-center border border-slate-900 bg-slate-900 px-2 text-xs font-semibold text-white sm:h-10 sm:min-w-10 sm:px-3 sm:text-sm">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="inline-flex h-9 min-w-9 items-center justify-center border border-slate-300 bg-white px-2 text-xs font-medium text-slate-700 hover:bg-slate-100 focus:z-10 sm:h-10 sm:min-w-10 sm:px-3 sm:text-sm" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex h-9 min-w-9 items-center justify-center border border-slate-300 bg-white px-2 text-xs font-medium text-slate-700 hover:bg-slate-100 focus:z-10 sm:h-10 sm:min-w-10 sm:px-3 sm:text-sm">
                    {!! __('pagination.next') !!}
                </a>
            @else
                <span class="inline-flex h-9 min-w-9 items-center justify-center border border-slate-300 bg-slate-100 px-2 text-xs text-slate-400 sm:h-10 sm:min-w-10 sm:px-3 sm:text-sm">
                    {!! __('pagination.next') !!}
                </span>
            @endif
            </div>
        </nav>
    </div>
@endif
