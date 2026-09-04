@php
    $items = $items ?? [];
@endphp

<nav aria-label="Breadcrumb" class="mb-4">
    <ol class="flex flex-wrap items-center justify-center gap-2 text-xs uppercase tracking-[0.14em] text-slate-500">
        @foreach ($items as $item)
            <li class="inline-flex items-center gap-2">
                @if (! $loop->first)
                    <span>/</span>
                @endif
                @if (!empty($item['url']))
                    <a href="{{ $item['url'] }}" class="hover:text-slate-900">{{ $item['label'] }}</a>
                @else
                    <span class="text-slate-900">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
