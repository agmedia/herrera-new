@include('front.content-blocks.types.products_carousel', [
    'block' => $block,
    'translation' => $translation,
    'slot' => $slot ?? null,
    'products' => $products,
    'categories' => $categories,
    'categoryProductsMode' => true,
])
