<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaUrl
{
    public static function hasUsableOriginal(?Media $media): bool
    {
        if (! $media) {
            return false;
        }

        $path = $media->getPath();

        return is_string($path) && $path !== '' && is_file($path) && filesize($path) > 0;
    }

    /**
     * @param  array<int, string>  $conversions
     */
    public static function hasUsableSource(?Media $media, array $conversions = []): bool
    {
        if (! $media) {
            return false;
        }

        if (self::hasUsableOriginal($media)) {
            return true;
        }

        foreach ($conversions as $conversion) {
            $conversion = trim($conversion);
            if ($conversion === '') {
                continue;
            }

            if (self::hasUsableConversion($media, $conversion) || self::hasUsableConversion($media, $conversion.'_webp')) {
                return true;
            }
        }

        return false;
    }

    public static function conversionOrNull(?Media $media, string $conversion, bool $preferWebp = false): ?string
    {
        if (! $media) {
            return null;
        }

        $baseConversion = trim($conversion);
        if ($baseConversion === '') {
            return null;
        }

        $webpConversion = $baseConversion.'_webp';

        if ($preferWebp && self::hasUsableConversion($media, $webpConversion)) {
            return (string) $media->getUrl($webpConversion);
        }

        if (self::hasUsableConversion($media, $baseConversion)) {
            return (string) $media->getUrl($baseConversion);
        }

        if ($preferWebp && self::hasUsableConversion($media, $webpConversion)) {
            return (string) $media->getUrl($webpConversion);
        }

        return null;
    }

    public static function conversion(?Media $media, string $conversion, bool $preferWebp = false): ?string
    {
        if (! $media) {
            return null;
        }

        $baseConversion = trim($conversion);
        if ($baseConversion === '') {
            return (string) $media->getUrl();
        }

        return self::conversionOrNull($media, $baseConversion, $preferWebp) ?? (string) $media->getUrl();
    }

    private static function hasUsableConversion(Media $media, string $conversionName): bool
    {
        if (! $media->hasGeneratedConversion($conversionName)) {
            return false;
        }

        $path = $media->getPath($conversionName);

        return is_string($path) && $path !== '' && is_file($path);
    }
}
