@include('front.content-blocks.types.featured_categories', [
    'block' => $block,
    'translation' => $translation,
    'slot' => $slot ?? null,
    'categories' => $categories,
])
