@if (isset($loc['latitude'], $loc['longitude'], $loc['accuracy']))
    <a class="underline" rel="noopener noreferrer" target="_blank" href="https://www.openstreetmap.org/?mlat={{ $loc['latitude'] }}&amp;mlon={{ $loc['longitude'] }}#map=16/{{ $loc['latitude'] }}/{{ $loc['longitude'] }}">{{ $loc['latitude'] }}, {{ $loc['longitude'] }}</a>
    <small>(±{{ $loc['accuracy'] }} m)</small>
    @if (!empty($loc['recorded_at']))<small class="block">Zabilježeno pri unosu {{ \Carbon\Carbon::parse($loc['recorded_at'])->timezone(config('app.timezone'))->format('d.m.Y. H:i') }}</small>@endif
@else
    Lokacija nije zabilježena
@endif
