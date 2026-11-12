@include('front.partials.herrera-brand-strip', [
    'herreraWidgetBlock' => $block,
    'herreraWidgetTranslation' => $translation,
    'herreraManualManufacturers' => $blockItems->contains('item_type', 'manufacturer') ? $manufacturers : null,
])
