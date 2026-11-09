@if ((bool) data_get($block->payload, 'show_campaign_period') && ($slot->starts_at || $slot->ends_at))
    <p class="mt-2 text-center text-sm text-slate-600" data-campaign-period>
        @if ($slot->starts_at && $slot->ends_at)
            {{ __('Akcija traje:') }}
            <time datetime="{{ $slot->starts_at->toIso8601String() }}">{{ $slot->starts_at->format('d.m.Y. H:i') }}</time>
            – <time datetime="{{ $slot->ends_at->toIso8601String() }}">{{ $slot->ends_at->format('d.m.Y. H:i') }}</time>
        @elseif ($slot->ends_at)
            {{ __('Akcija traje do:') }}
            <time datetime="{{ $slot->ends_at->toIso8601String() }}">{{ $slot->ends_at->format('d.m.Y. H:i') }}</time>
        @else
            {{ __('Akcija traje od:') }}
            <time datetime="{{ $slot->starts_at->toIso8601String() }}">{{ $slot->starts_at->format('d.m.Y. H:i') }}</time>
        @endif
    </p>
@endif
