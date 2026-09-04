@include('front.content-blocks.instances.izdvojeniartikli', [
    'block' => $block,
    'translation' => $translation,
    'slot' => $slot ?? null,
    'blockItems' => $blockItems ?? collect(),
    'products' => $products ?? collect(),
])
