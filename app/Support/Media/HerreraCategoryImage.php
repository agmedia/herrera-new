<?php

namespace App\Support\Media;

use App\Models\Catalog\Category\Category;

class HerreraCategoryImage
{
    public static function url(Category $category, string $locale, string $fallbackLocale, string $storeName): ?string
    {
        if (! str_contains(strtolower(trim($storeName)), 'herrera')
            || $category->scope !== Category::SCOPE_CATALOG
            || (int) ($category->parent_id ?? 0) !== 0) {
            return null;
        }

        $translations = $category->translations;
        $hrSlug = $translations->firstWhere('locale', 'hr')?->slug;
        if ($hrSlug === null && $category->exists) {
            $hrSlug = $category->translations()->where('locale', 'hr')->value('slug');
        }

        foreach (array_unique(array_filter([
            $hrSlug,
            $translations->firstWhere('locale', $locale)?->slug,
            $translations->firstWhere('locale', $fallbackLocale)?->slug,
        ])) as $slug) {
            $path = ltrim((string) config('herrera-category-photography.'.trim((string) $slug), ''), '/');
            if ($path !== '' && is_file(public_path($path)) && filesize(public_path($path)) > 0) {
                return asset($path);
            }
        }

        return null;
    }
}
