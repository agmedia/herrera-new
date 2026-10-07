<?php

namespace App\Support;

class FontAwesomeIcon
{
    public const VERSION = '7.3.1';

    public const STYLES = [
        'brands', 'chisel-regular', 'duotone', 'duotone-light', 'duotone-regular',
        'duotone-thin', 'etch-solid', 'graphite-thin', 'jelly-duo-regular',
        'jelly-fill-regular', 'jelly-regular', 'light', 'mosaic-solid',
        'notdog-duo-solid', 'notdog-solid', 'pixel-regular', 'regular',
        'sharp-duotone-light', 'sharp-duotone-regular', 'sharp-duotone-solid',
        'sharp-duotone-thin', 'sharp-light', 'sharp-regular', 'sharp-solid',
        'sharp-thin', 'slab-duo-regular', 'slab-press-duo-regular',
        'slab-press-regular', 'slab-regular', 'solid', 'thin', 'thumbprint-light',
        'utility-duo-semibold', 'utility-fill-semibold', 'utility-semibold',
        'vellum-solid', 'whiteboard-semibold',
    ];

    public static function url(string $name, string $style = 'solid'): string
    {
        $name = strtolower(trim($name));
        $style = strtolower(trim($style));
        $style = in_array($style, self::STYLES, true) ? $style : 'solid';

        // Invalid input never becomes a file path, query string or SVG fragment.
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name)) {
            $name = 'circle-question';
            $style = 'solid';
        }
        $name = $name === 'long-arrow-right' ? 'arrow-right-long' : $name;

        $basePath = 'vendor/fontawesome-pro-'.self::VERSION;
        $optimizedPath = $basePath.'/storefront-sprites/'.$style.'.svg';
        $manifest = self::manifest($basePath);
        $relativePath = isset($manifest[$style]) && is_array($manifest[$style])
            && in_array($name, $manifest[$style], true)
            && is_file(public_path($optimizedPath))
                ? $optimizedPath
                : $basePath.'/sprites/'.$style.'.svg';
        $modified = is_file(public_path($relativePath)) ? filemtime(public_path($relativePath)) : 0;

        return asset($relativePath).'?v='.self::VERSION.'-'.(int) $modified.'#'.$name;
    }

    /** @return array<string, array<int, string>> */
    private static function manifest(string $basePath): array
    {
        static $cachedPath = null;
        static $cachedModified = null;
        static $cachedManifest = [];

        $path = public_path($basePath.'/storefront-sprites/manifest.json');
        $modified = is_file($path) ? filemtime($path) : 0;
        if ($cachedPath !== $path || $cachedModified !== $modified) {
            $contents = $modified ? file_get_contents($path) : false;
            $decoded = is_string($contents) ? json_decode($contents, true) : null;
            $cachedManifest = is_array($decoded) ? $decoded : [];
            $cachedPath = $path;
            $cachedModified = $modified;
        }

        return $cachedManifest;
    }
}
