<?php

namespace App\Models\Concerns;

use App\Support\Media\MediaProfileRegistry;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait HasConfiguredMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $modelClass = static::class;

        foreach (MediaProfileRegistry::collectionsForModel($modelClass) as $collectionName => $collectionConfig) {
            $collection = $this->addMediaCollection((string) $collectionName);

            if ((bool) ($collectionConfig['single_file'] ?? false)) {
                $collection->singleFile();
            }

            $keepLatest = (int) ($collectionConfig['only_keep_latest'] ?? 0);
            if ($keepLatest > 1) {
                $collection->onlyKeepLatest($keepLatest);
            }

            $mimeTypes = array_values(array_filter((array) ($collectionConfig['accept_mime_types'] ?? [])));
            if ($mimeTypes !== []) {
                $collection->acceptsMimeTypes($mimeTypes);
            }
        }
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $modelClass = static::class;
        $conversionMap = MediaProfileRegistry::conversionMapForModel($modelClass);

        foreach ($conversionMap as $conversionName => $conversionConfig) {
            $preset = (array) ($conversionConfig['preset'] ?? []);
            $collections = array_values(array_filter(array_unique((array) ($conversionConfig['collections'] ?? []))));

            if ($preset === [] || $collections === []) {
                continue;
            }

            $conversion = $this->addMediaConversion((string) $conversionName)
                ->performOnCollections(...$collections);

            $this->applyPresetToConversion($conversion, $preset, $media);

            // Always register a paired WebP conversion so frontend can toggle WebP delivery.
            if (! str_ends_with((string) $conversionName, '_webp')) {
                $webpPreset = $preset;
                $webpPreset['format'] = 'webp';
                if (! isset($webpPreset['quality']) || ! is_numeric($webpPreset['quality'])) {
                    $webpPreset['quality'] = 84;
                }

                $webpConversion = $this->addMediaConversion((string) $conversionName.'_webp')
                    ->performOnCollections(...$collections);

                $this->applyPresetToConversion($webpConversion, $webpPreset, $media);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $preset
     */
    private function applyPresetToConversion(Conversion $conversion, array $preset, ?Media $media): void
    {
        $width = isset($preset['width']) ? (int) $preset['width'] : null;
        $height = isset($preset['height']) ? (int) $preset['height'] : null;
        $fit = $this->resolveFit((string) ($preset['fit'] ?? ''));
        $manualCropApplied = $this->applyCropBoxManipulation($conversion, $media);

        if ($fit === Fit::Crop && $width && $height) {
            $focalPoint = $manualCropApplied ? null : $this->resolveFocalPointPixels($media);

            if ($focalPoint) {
                $conversion->focalCropAndResize($width, $height, $focalPoint['x'], $focalPoint['y']);
            } else {
                $conversion->fit($fit, $width, $height);
            }
        } elseif ($fit && ($width || $height)) {
            $conversion->fit($fit, $width, $height);
        } elseif ($width || $height) {
            $conversion->resize($width, $height);
        }

        if (isset($preset['quality']) && is_numeric($preset['quality'])) {
            $conversion->quality((int) $preset['quality']);
        }

        $format = trim((string) ($preset['format'] ?? ''));
        if ($format !== '') {
            $conversion->format($format);
        } else {
            $conversion->keepOriginalImageFormat();
        }

        if (($preset['queued'] ?? null) === false) {
            $conversion->nonQueued();
        }
    }

    private function resolveFit(string $fit): ?Fit
    {
        return match (strtolower(trim($fit))) {
            'crop' => Fit::Crop,
            'contain' => Fit::Contain,
            'fill' => Fit::Fill,
            'fill-max', 'fill_max' => Fit::FillMax,
            'max' => Fit::Max,
            'stretch' => Fit::Stretch,
            default => null,
        };
    }

    private function applyCropBoxManipulation(Conversion $conversion, ?Media $media): bool
    {
        if (! $media) {
            return false;
        }

        $crop = (array) data_get($media->custom_properties, 'image_edit.crop_box', []);
        if (! ((bool) ($crop['enabled'] ?? false))) {
            return false;
        }

        $dimensions = $this->sourceImageDimensions($media);
        if (! $dimensions) {
            return false;
        }

        [$imageWidth, $imageHeight] = [$dimensions['width'], $dimensions['height']];
        $xPercent = $this->clampPercent($crop['x'] ?? 0, 0);
        $yPercent = $this->clampPercent($crop['y'] ?? 0, 0);
        $widthPercent = $this->clampPercent($crop['width'] ?? 100, 1);
        $heightPercent = $this->clampPercent($crop['height'] ?? 100, 1);

        $cropX = (int) floor(($xPercent / 100) * $imageWidth);
        $cropY = (int) floor(($yPercent / 100) * $imageHeight);
        $cropWidth = (int) max(1, floor(($widthPercent / 100) * $imageWidth));
        $cropHeight = (int) max(1, floor(($heightPercent / 100) * $imageHeight));

        $cropX = max(0, min($cropX, $imageWidth - 1));
        $cropY = max(0, min($cropY, $imageHeight - 1));
        $cropWidth = max(1, min($cropWidth, $imageWidth - $cropX));
        $cropHeight = max(1, min($cropHeight, $imageHeight - $cropY));

        if ($cropWidth < 2 || $cropHeight < 2) {
            return false;
        }

        $conversion->manualCrop($cropWidth, $cropHeight, $cropX, $cropY);

        return true;
    }

    /**
     * @return array{x: int, y: int}|null
     */
    private function resolveFocalPointPixels(?Media $media): ?array
    {
        if (! $media) {
            return null;
        }

        $focal = (array) data_get($media->custom_properties, 'image_edit.focal_point', []);
        if ($focal === []) {
            return null;
        }

        $dimensions = $this->sourceImageDimensions($media);
        if (! $dimensions) {
            return null;
        }

        $xPercent = $this->clampPercent($focal['x'] ?? 50, 0);
        $yPercent = $this->clampPercent($focal['y'] ?? 50, 0);

        $x = (int) round(($xPercent / 100) * max(1, $dimensions['width'] - 1));
        $y = (int) round(($yPercent / 100) * max(1, $dimensions['height'] - 1));

        return ['x' => $x, 'y' => $y];
    }

    /**
     * @return array{width: int, height: int}|null
     */
    private function sourceImageDimensions(Media $media): ?array
    {
        $path = $media->getPath();
        if (! is_file($path)) {
            return null;
        }

        $size = @getimagesize($path);
        if (! is_array($size) || ! isset($size[0], $size[1])) {
            return null;
        }

        return [
            'width' => (int) $size[0],
            'height' => (int) $size[1],
        ];
    }

    private function clampPercent(mixed $value, float $min): float
    {
        $number = is_numeric($value) ? (float) $value : $min;

        return max($min, min(100.0, $number));
    }
}
