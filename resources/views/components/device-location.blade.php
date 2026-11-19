@props(['model' => null])
<div class="my-2 text-xs" data-device-location>
    <button type="button" class="rounded border px-2 py-1" data-location-button>Dodaj lokaciju uređaja</button>
    <span role="status" data-location-status>Neobavezno · lokacija u trenutku unosa</span>
    @foreach (['latitude', 'longitude', 'accuracy'] as $field)
        <input type="hidden" data-coordinate="{{ $field }}" @if ($model) wire:model="{{ $model }}.{{ $field }}" @else name="location[{{ $field }}]" @endif>
    @endforeach
</div>
@once
    <script defer src="{{ asset('front-theme/scripts/device-location.js') }}?v={{ filemtime(public_path('front-theme/scripts/device-location.js')) }}"></script>
@endonce
