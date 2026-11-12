@include('front.partials.herrera-home', [
    'herreraHomeSection' => 'products',
    'herreraWidgetBlock' => $block,
    'herreraWidgetTranslation' => $translation,
    'herreraManualProducts' => $blockItems->contains('item_type', 'product') ? $products : null,
])
