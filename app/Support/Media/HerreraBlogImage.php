<?php

namespace App\Support\Media;

use App\Models\Content\Blog\BlogPost;

class HerreraBlogImage
{
    /** Uploaded covers take precedence over the versioned local editorial fallback. */
    public static function first(BlogPost $post, array $conversions = [], bool $preferWebp = false): ?string
    {
        foreach ($post->getMedia('blog_cover') as $media) {
            if (! MediaUrl::hasUsableSource($media, $conversions)) {
                continue;
            }

            foreach ($conversions as $conversion) {
                if ($url = MediaUrl::conversionOrNull($media, $conversion, $preferWebp)) {
                    return $url;
                }
            }

            if (MediaUrl::hasUsableOriginal($media)) {
                return (string) $media->getUrl();
            }
        }

        $path = self::assetPath(data_get($post->payload, 'herrera_cover_path'));

        return $path !== null ? asset($path) : LegacyCatalogImage::first($post, $conversions, $preferWebp);
    }

    public static function localPath(BlogPost $post): ?string
    {
        $path = self::assetPath(data_get($post->payload, 'herrera_cover_path'));

        return $path !== null ? public_path($path) : null;
    }

    /** Only a real, nonempty file inside this public asset directory is allowed. */
    public static function assetPath(mixed $path): ?string
    {
        if (! is_string($path)
            || ! preg_match('~\Aassets/blog/herrera/[a-z0-9][a-z0-9-]*\.(?:jpg|jpeg|png|webp)\z~D', $path)) {
            return null;
        }

        $publicRoot = realpath(public_path());
        $file = realpath(public_path($path));
        if ($publicRoot === false || $file === false
            || ! str_starts_with($file, $publicRoot.'/assets/blog/herrera/')
            || ! is_file($file) || filesize($file) === 0) {
            return null;
        }

        return $path;
    }
}
