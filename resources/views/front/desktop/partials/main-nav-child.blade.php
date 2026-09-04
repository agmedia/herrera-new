@php
    $children = collect($child['children'] ?? []);
    $depthClass = 'desktop-mega-depth-'.min(4, max(0, (int) $level));
    $textSize = match (true) {
        $level >= 2 => 'text-[11px]',
        $level === 1 => 'text-[12px]',
        default => 'text-[13px]',
    };
    $weightClass = $level === 0 ? 'font-light' : 'font-light';
@endphp
<li>
    <a href="{{ $child['url'] ?? '#' }}" class="{{ $depthClass }} flex items-center justify-between rounded-md px-1.5 py-0.5 {{ $textSize }} {{ $weightClass }} text-slate-500 transition hover:bg-white/70 hover:text-slate-900">
        <span>{{ $child['label'] ?? '' }}</span>
    </a>

    @if ($children->isNotEmpty())
        <ul class="space-y-1 pb-1">
            @foreach ($children as $nestedChild)
                @include('front.desktop.partials.main-nav-child', ['child' => $nestedChild, 'level' => $level + 1])
            @endforeach
        </ul>
    @endif
</li>
