<?php

namespace App\Support\Media;

use App\Models\Catalog\Manufacturer\Manufacturer;
use Illuminate\Support\Str;

class ManufacturerLogo
{
    /** @return array{url: ?string, variant: string} */
    public static function resolve(Manufacturer $manufacturer): array
    {
        $uploaded = $manufacturer->getFirstMedia('manufacturer_logo');
        if (MediaUrl::hasUsableOriginal($uploaded)) {
            return ['url' => (string) $uploaded->getUrl(), 'variant' => ''];
        }

        $keys = $manufacturer->translations
            ->flatMap(fn ($translation): array => [
                (string) $translation->slug,
                Str::slug((string) $translation->name),
            ])
            ->push(Str::slug((string) $manufacturer->code))
            ->unique();
        $assets = (array) config('manufacturer-logo-assets', []);

        foreach ($keys as $key) {
            $logo = $assets[$key] ?? [];
            $path = trim((string) ($logo['path'] ?? ''));
            if ($path !== '' && is_file(public_path($path))) {
                return [
                    'url' => asset($path),
                    'variant' => (string) ($logo['variant'] ?? ''),
                ];
            }
        }

        $known = trim((string) config('manufacturer_logos.'.$manufacturer->code, ''));
        if ($known !== '') {
            return ['url' => $known, 'variant' => ''];
        }

        // These imported image fields contain product photos rather than logos.
        if ($keys->intersect(['vayox', 'weicon', 'k-trade'])->isNotEmpty()) {
            return ['url' => null, 'variant' => ''];
        }

        return ['url' => LegacyCatalogImage::first($manufacturer), 'variant' => ''];
    }
}
