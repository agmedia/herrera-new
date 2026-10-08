<?php

namespace App\Support;

use DOMDocument;

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
        [$name, $style] = self::normalize($name, $style);
        $basePath = 'vendor/fontawesome-pro-'.self::VERSION;
        $relativePath = self::optimizedSpritePath($name, $style) ?? $basePath.'/sprites/'.$style.'.svg';
        $modified = is_file(public_path($relativePath)) ? filemtime(public_path($relativePath)) : 0;

        return asset($relativePath).'?v='.self::VERSION.'-'.(int) $modified.'#'.$name;
    }

    /** @return array{viewBox: string, content: string}|null */
    public static function inline(string $name, string $style = 'solid'): ?array
    {
        [$name, $style] = self::normalize($name, $style);
        $relativePath = self::optimizedSpritePath($name, $style);
        if ($relativePath === null) {
            return null;
        }

        static $cachedSymbols = [];
        $path = public_path($relativePath);
        $modified = filemtime($path);
        if (! isset($cachedSymbols[$path]) || $cachedSymbols[$path]['modified'] !== $modified) {
            $source = @file_get_contents($path);
            if (! is_string($source)) {
                return null;
            }

            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                if (! $document->loadXML($source, LIBXML_NONET)) {
                    return null;
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }

            $symbols = [];
            foreach ($document->getElementsByTagName('symbol') as $symbol) {
                $viewBox = $symbol->getAttribute('viewBox');
                if ($viewBox === '') {
                    continue;
                }

                // Only bundled, manifest-listed SVG files supply raw markup.
                $content = '';
                foreach ($symbol->childNodes as $child) {
                    $content .= $document->saveXML($child);
                }
                $symbols[$symbol->getAttribute('id')] = ['viewBox' => $viewBox, 'content' => $content];
            }
            $cachedSymbols[$path] = ['modified' => $modified, 'symbols' => $symbols];
        }

        return $cachedSymbols[$path]['symbols'][$name] ?? null;
    }

    /** @return array{string, string} */
    private static function normalize(string $name, string $style): array
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

        return [$name, $style];
    }

    private static function optimizedSpritePath(string $name, string $style): ?string
    {
        $basePath = 'vendor/fontawesome-pro-'.self::VERSION;
        $optimizedPath = $basePath.'/storefront-sprites/'.$style.'.svg';
        $manifest = self::manifest($basePath);

        return isset($manifest[$style]) && is_array($manifest[$style])
            && in_array($name, $manifest[$style], true)
            && is_file(public_path($optimizedPath))
                ? $optimizedPath
                : null;
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
