<?php

namespace App\Support;

use App\Models\Catalog\Product\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductSpecificationPresenter
{
    /**
     * Keep imported specifications that are not already displayed by an attribute
     * panel. This is a presentation-only filter: both source records are retained.
     */
    public function remainingRows(Product $product, string $locale, string $fallbackLocale): Collection
    {
        if (! $product->relationLoaded('technicalSpecificationRows')) {
            return collect();
        }

        $displayedPairs = [];
        $attributes = $product->relationLoaded('attributes')
            ? $product->attributes->reject(fn ($attribute): bool => $attribute->isMsanManaged())
            : collect();

        foreach ($attributes->groupBy('group_code') as $groupCode => $groupAttributes) {
            $items = $groupAttributes->map(function ($attribute) use ($locale, $fallbackLocale): array {
                $translation = $attribute->translations->firstWhere('locale', $locale)
                    ?? $attribute->translations->firstWhere('locale', $fallbackLocale)
                    ?? $attribute->translations->first();

                return [
                    'label' => trim((string) ($translation?->group_name ?? '')),
                    'value' => trim((string) ($translation?->name ?? $attribute->code)),
                ];
            })->filter(fn (array $item): bool => $item['value'] !== '')
                ->unique('value')->values();

            if ($items->isEmpty()) {
                continue;
            }

            $fallbackLabels = ['sastav' => 'Sastav', 'kvaliteta' => 'Kvaliteta', 'garancija' => 'Garancija'];
            $label = $items->pluck('label')->first(fn (string $value): bool => $value !== '')
                ?: ($fallbackLabels[$groupCode] ?? Str::headline((string) $groupCode));

            foreach ($items as $item) {
                $displayedPairs[$this->pairKey($label, $item['value'])] = true;
            }
            $displayedPairs[$this->pairKey($label, $items->pluck('value')->implode(', '))] = true;
        }

        return $product->technicalSpecificationRows
            ->filter(function ($row) use (&$displayedPairs): bool {
                $label = trim((string) ($row->item_name ?? ''));
                $value = $this->valueText($row);
                if ($label === '' || $value === '') {
                    return false;
                }

                $pair = $this->pairKey($label, $value);
                if (isset($displayedPairs[$pair])) {
                    return false;
                }

                $displayedPairs[$pair] = true;

                return true;
            })->values();
    }

    public function valueText(object $row): string
    {
        $value = collect((array) ($row->values ?? []))->flatten()
            ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->unique()->implode(', ');
        $measure = trim((string) ($row->measure ?? ''));

        return $value !== '' && $measure !== '' && ! Str::endsWith(Str::lower($value), Str::lower($measure))
            ? $value.' '.$measure
            : $value;
    }

    private function pairKey(string $label, string $value): string
    {
        $normalize = static fn (string $text): string => Str::lower(
            trim(preg_replace('/\s+/u', ' ', $text) ?? $text),
        );

        return hash('sha256', serialize([$normalize($label), $normalize($value)]));
    }
}
