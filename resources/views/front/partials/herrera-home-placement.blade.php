@foreach ($items as $homePlacementItem)
    @include('components.content-placement', ['items' => collect([$homePlacementItem])])
    @if ($isHerreraHome && ! $isManagedHerreraHome && (string) data_get($homePlacementItem, 'block.type') === 'popular_brands')
        @include('front.partials.herrera-popular-products')
    @endif
@endforeach
