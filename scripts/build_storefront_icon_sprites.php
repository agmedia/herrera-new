<?php

declare(strict_types=1);

$icons = [
    'solid' => [
        'arrow-right',
        'arrow-up-right',
        'arrow-up',
        'bag-shopping',
        'bars',
        'check',
        'chevron-down',
        'chevron-right',
        'circle-check',
        'circle-info',
        'cookie-bite',
        'credit-card',
        'grip',
        'heart',
        'list',
        'lock',
        'magnifying-glass',
        'minus',
        'plus',
        'rotate-left',
        'scissors',
        'shield-halved',
        'sliders',
        'table-cells',
        'table-cells-large',
        'table-columns',
        'triangle-exclamation',
        'truck-fast',
        'xmark',
    ],
    'regular' => [
        'arrow-right',
        'envelope',
        'heart',
        'list-check',
        'phone',
        'user',
    ],
    'light' => [
        'battery-half',
        'bolt',
        'boxes-stacked',
        'candle-holder',
        'car-bolt',
        'faucet',
        'heart-pulse',
        'helmet-safety',
        'house',
        'kitchen-set',
        'lightbulb',
        'outlet',
        'reel',
        'ruler',
        'screwdriver-wrench',
        'sensor',
        'tag',
        'temperature-half',
        'toolbox',
        'tv',
    ],
    'brands' => [
        'facebook-f',
        'instagram',
        'linkedin-in',
        'tiktok',
        'x-twitter',
        'youtube',
    ],
];

$projectRoot = dirname(__DIR__);
$assetDirectory = $projectRoot.'/public/vendor/fontawesome-pro-7.3.1';
$sourceDirectory = $assetDirectory.'/sprites';
$targetDirectory = $assetDirectory.'/storefront-sprites';

if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0755, true) && ! is_dir($targetDirectory)) {
    throw new RuntimeException('Unable to create storefront sprite directory.');
}

foreach ($icons as $style => $names) {
    $source = file_get_contents($sourceDirectory.'/'.$style.'.svg');
    if (! is_string($source)) {
        throw new RuntimeException("Unable to read {$style} sprite.");
    }

    preg_match_all('/<symbol\\s+id="([^"]+)"[^>]*>.*?<\\/symbol>/s', $source, $matches, PREG_SET_ORDER);
    $symbols = [];

    foreach ($matches as $match) {
        $symbol = (string) $match[0];

        // The original 7.3.1 bag artwork spans y=-32..480. Keep its paths
        // unchanged and include the complete handle in the generated viewport.
        if ($match[1] === 'bag-shopping' && in_array($style, ['solid', 'regular'], true)) {
            $symbol = preg_replace('/viewBox="[^"]+"/', 'viewBox="0 -32 448 512"', $symbol, 1) ?? $symbol;
        }

        $symbols[(string) $match[1]] = $symbol;
    }

    $selected = [];
    foreach ($names as $name) {
        if (! isset($symbols[$name])) {
            throw new RuntimeException("Missing {$style} icon: {$name}");
        }

        $selected[] = $symbols[$name];
    }

    $sprite = implode("\n", [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<!-- Font Awesome Pro 7.3.1 by Fonticons, Inc. Commercial license: ../LICENSE.txt -->',
        '<svg xmlns="http://www.w3.org/2000/svg" style="display: none;">',
        ...array_map(static fn (string $symbol): string => '  '.$symbol, $selected),
        '</svg>',
        '',
    ]);

    if (file_put_contents($targetDirectory.'/'.$style.'.svg', $sprite) === false) {
        throw new RuntimeException("Unable to write {$style} storefront sprite.");
    }
}

if (file_put_contents($targetDirectory.'/manifest.json', json_encode($icons, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
    throw new RuntimeException('Unable to write storefront sprite manifest.');
}
