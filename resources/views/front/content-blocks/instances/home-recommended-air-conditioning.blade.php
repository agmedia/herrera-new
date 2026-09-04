@include('front.content-blocks.types.category_products_carousel', [
    'block' => $block,
    'translation' => $translation,
    'slot' => $slot ?? null,
    'products' => $products,
    'categories' => $categories,
])
