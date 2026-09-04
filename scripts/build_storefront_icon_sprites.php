<?php

declare(strict_types=1);

$icons = [
    'solid' => [
        'arrow-right',
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
        'sliders',
        'table-cells',
        'table-cells-large',
        'table-columns',
        'triangle-exclamation',
        'truck-fast',
        'xmark',
    ],
    'regular' => [
        'heart',
        'user',
    ],
    'brands' => [
        'facebook-f',
        'instagram',
        'tiktok',
        'youtube',
    ],
];

$projectRoot = dirname(__DIR__);
$sourceDirectory = $projectRoot.'/public/front-theme/fonts/sprites';
$targetDirectory = $projectRoot.'/public/front-theme/fonts/storefront-sprites';

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
        $symbols[(string) $match[1]] = (string) $match[0];
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
        '<!-- Font Awesome Free 6.0.0: https://fontawesome.com/license/free -->',
        '<svg xmlns="http://www.w3.org/2000/svg" style="display: none;">',
        ...array_map(static fn (string $symbol): string => '  '.$symbol, $selected),
        '</svg>',
        '',
    ]);

    if (file_put_contents($targetDirectory.'/'.$style.'.svg', $sprite) === false) {
        throw new RuntimeException("Unable to write {$style} storefront sprite.");
    }
}
