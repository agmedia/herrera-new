<?php

namespace App\Services\Integrations\Msan;

use App\Models\Integrations\Msan\MsanProduct;
use App\Services\Integrations\Msan\Exceptions\MsanProductImageNotFoundException;
use App\Services\Integrations\Msan\Exceptions\MsanProductImagePreviewUnavailableException;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

class MsanProductImagePreviewService
{
    private const CACHE_MAX_AGE_SECONDS = 86400;

    /** Longer than the maximum 300 second M SAN request timeout, plus processing time. */
    private const LOCK_LEASE_SECONDS = 420;

    private const LOCK_WAIT_SECONDS = 20;

    private const MAX_IMAGE_BYTES = 12 * 1024 * 1024;

    private const MAX_IMAGE_DIMENSION = 8000;

    private const MAX_IMAGE_PIXELS = 24_000_000;

    private const PREVIEW_SIZE = 192;

    private const SUPPORTED_MIME_TYPES = [
        'image/avif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(private readonly MsanClient $client) {}

    /**
     * Returns a private, validated local cache path without exposing the M SAN URL.
     */
    public function cachedPath(MsanProduct $product): string
    {
        $sourceUrl = trim((string) $product->image_url);
        if ($sourceUrl === '') {
            throw new MsanProductImageNotFoundException('M SAN artikl nema sliku.');
        }

        $safeUrl = $this->httpsImageUrl($sourceUrl);
        $productId = (int) $product->getKey();
        if ($productId < 1) {
            throw new MsanProductImagePreviewUnavailableException('M SAN pregled slike trenutačno nije dostupan.');
        }

        $cacheKey = hash('sha256', implode("\0", [
            (string) $productId,
            $sourceUrl,
            (string) $product->catalog_checksum,
        ]));
        $relativeDirectory = 'integrations/msan/admin-image-previews/'.$productId;
        $relativePath = $relativeDirectory.'/'.$cacheKey.'.webp';
        $absolutePath = Storage::disk('local')->path($relativePath);

        if ($this->isReusablePreview($absolutePath)) {
            return $absolutePath;
        }

        if (! Storage::disk('local')->makeDirectory($relativeDirectory)) {
            throw new MsanProductImagePreviewUnavailableException('M SAN pregled slike trenutačno nije dostupan.');
        }

        try {
            return Cache::lock(
                'integrations:msan:admin-image-preview:product:'.$productId,
                self::LOCK_LEASE_SECONDS,
            )->block(self::LOCK_WAIT_SECONDS, function () use (
                $absolutePath,
                $relativeDirectory,
                $relativePath,
                $safeUrl,
            ): string {
                if ($this->isReusablePreview($absolutePath)) {
                    $this->pruneCachedVersions($relativeDirectory, $relativePath);

                    return $absolutePath;
                }
                if (is_link($absolutePath)) {
                    throw new MsanProductImagePreviewUnavailableException('M SAN pregled slike trenutačno nije dostupan.');
                }

                $attemptId = (string) Str::uuid();
                $sourcePath = $absolutePath.'.source-'.$attemptId;
                $temporaryPreviewPath = $absolutePath.'.part-'.$attemptId.'.webp';

                try {
                    try {
                        $this->client->downloadProductImage($safeUrl, $sourcePath);
                    } catch (Exception $exception) {
                        throw new MsanProductImagePreviewUnavailableException(
                            'M SAN pregled slike trenutačno nije dostupan.',
                            previous: $exception,
                        );
                    }

                    if (! $this->isValidImage($sourcePath)) {
                        throw new MsanProductImageNotFoundException('M SAN datoteka nije podržana slika.');
                    }
                    $this->assertSafeImageDimensions($sourcePath);

                    try {
                        $image = Image::load($sourcePath);
                    } catch (Exception $exception) {
                        throw new MsanProductImageNotFoundException(
                            'M SAN datoteka nije podržana slika.',
                            previous: $exception,
                        );
                    }

                    try {
                        $image
                            ->fit(Fit::Max, self::PREVIEW_SIZE, self::PREVIEW_SIZE)
                            ->format('webp')
                            ->quality(82)
                            ->save($temporaryPreviewPath);
                    } catch (Exception $exception) {
                        throw new MsanProductImagePreviewUnavailableException(
                            'M SAN pregled slike trenutačno nije dostupan.',
                            previous: $exception,
                        );
                    }

                    if (! $this->isValidImage($temporaryPreviewPath)
                        || is_link($absolutePath)
                        || ! @rename($temporaryPreviewPath, $absolutePath)
                    ) {
                        throw new MsanProductImagePreviewUnavailableException('M SAN pregled slike trenutačno nije dostupan.');
                    }
                    @chmod($absolutePath, 0600);
                    clearstatcache(true, $absolutePath);
                    $this->pruneCachedVersions($relativeDirectory, $relativePath);
                } finally {
                    foreach ([$sourcePath, $temporaryPreviewPath] as $temporaryPath) {
                        if (is_file($temporaryPath) && ! is_link($temporaryPath)) {
                            @unlink($temporaryPath);
                        }
                    }
                }

                return $absolutePath;
            });
        } catch (MsanProductImageNotFoundException|MsanProductImagePreviewUnavailableException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new MsanProductImagePreviewUnavailableException(
                'M SAN pregled slike trenutačno nije dostupan.',
                previous: $exception,
            );
        }
    }

    public function mimeType(string $path): string
    {
        $mime = is_file($path) ? (mime_content_type($path) ?: '') : '';

        if (! in_array($mime, self::SUPPORTED_MIME_TYPES, true)) {
            throw new MsanProductImagePreviewUnavailableException('M SAN pregled slike trenutačno nije dostupan.');
        }

        return $mime;
    }

    private function isValidImage(string $path): bool
    {
        if (! is_file($path) || is_link($path)) {
            return false;
        }

        $size = filesize($path);

        return is_int($size)
            && $size > 0
            && $size <= self::MAX_IMAGE_BYTES
            && in_array(mime_content_type($path) ?: '', self::SUPPORTED_MIME_TYPES, true);
    }

    private function isReusablePreview(string $path): bool
    {
        if (! $this->isValidImage($path)) {
            return false;
        }

        clearstatcache(true, $path);
        $modifiedAt = @filemtime($path);

        return is_int($modifiedAt) && $modifiedAt >= time() - self::CACHE_MAX_AGE_SECONDS;
    }

    private function assertSafeImageDimensions(string $path): void
    {
        $dimensions = @getimagesize($path);
        if (! is_array($dimensions)) {
            throw new MsanProductImageNotFoundException('M SAN datoteka nije podržana slika.');
        }

        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        $mime = strtolower((string) ($dimensions['mime'] ?? ''));
        if ($width < 1
            || $height < 1
            || $width > self::MAX_IMAGE_DIMENSION
            || $height > self::MAX_IMAGE_DIMENSION
            || $width > intdiv(self::MAX_IMAGE_PIXELS, $height)
            || ! in_array($mime, self::SUPPORTED_MIME_TYPES, true)
        ) {
            throw new MsanProductImageNotFoundException('M SAN datoteka nije podržana slika.');
        }
    }

    private function pruneCachedVersions(string $relativeDirectory, string $currentRelativePath): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files($relativeDirectory) as $candidate) {
            if ($candidate === $currentRelativePath
                || preg_match('/\A[a-f0-9]{64}\.webp\z/D', basename($candidate)) !== 1
            ) {
                continue;
            }

            $disk->delete($candidate);
        }
    }

    private function httpsImageUrl(string $imageUrl): string
    {
        $parts = parse_url($imageUrl);
        if (! is_array($parts)) {
            throw new MsanProductImageNotFoundException('M SAN URL slike nije valjan.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)
            || strtolower((string) ($parts['host'] ?? '')) !== MsanClient::HOST
            || $path === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true))
        ) {
            throw new MsanProductImageNotFoundException('M SAN URL slike nije valjan.');
        }

        return 'https://'.MsanClient::HOST.$path
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
