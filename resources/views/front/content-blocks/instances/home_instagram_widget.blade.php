@include('front.content-blocks.types.instagram_curated_grid', [
    'block' => $block,
    'translation' => $translation,
    'slot' => $slot ?? null,
    'blockItems' => $blockItems ?? collect(),
])
