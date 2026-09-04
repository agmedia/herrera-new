@include('front.content-blocks.types.blogs_carousel', [
    'block' => $block,
    'translation' => $translation,
    'slot' => $slot ?? null,
    'blockItems' => $blockItems ?? collect(),
])
