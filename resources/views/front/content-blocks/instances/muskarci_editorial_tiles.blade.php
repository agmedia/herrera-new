@include('front.content-blocks.types.category_editorial_tiles', [
    'block' => $block,
    'translation' => $translation,
    'slot' => $slot ?? null,
    'blockItems' => $blockItems ?? collect(),
])
