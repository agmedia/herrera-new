<?php

namespace App\Services\Content;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Catalog\Clearance\ClearanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FeaturedCategoriesService
{
    public const SOURCES = ['manual', 'all_root'];

    public static function source(?array $payload): string
    {
        $source = (string) ($payload['category_source'] ?? 'manual');

        return in_array($source, self::SOURCES, true) ? $source : 'manual';
    }

    public function forBlock(
        ContentBlock $block,
        string $locale,
        string $fallbackLocale,
        bool $hideOutOfStockProducts = false,
        bool $includeCounts = true,
        bool $excludeClearance = false,
        bool $excludeHomeShortcuts = false,
    ): Collection {
        $source = self::source(is_array($block->payload) ? $block->payload : null);
        $selectedIds = $block->items
            ->where('item_type', 'category')
            ->pluck('item_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $query = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->currentlyVisible()
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'media' => fn ($q) => $q
                    ->whereIn('collection_name', ['category_icon', 'category_banner'])
                    ->orderBy('order_column')
                    ->orderBy('id'),
            ]);

        if ($includeCounts) {
            $query->withCount([
                'descendants as subcategories_count' => fn ($q) => $q
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->currentlyVisible(),
            ]);
        }

        if ($source === 'all_root') {
            $query->whereNull('parent_id')->orderBy('sort_order')->orderBy('id');
            if ($excludeHomeShortcuts) {
                $this->excludeHomeShortcuts($query);
            } elseif ($excludeClearance) {
                $this->excludeClearance($query);
            }
        } else {
            $query->whereIn('id', $selectedIds);
        }

        $categories = $query->get();
        if ($source === 'manual') {
            $categories = $categories
                ->sortBy(fn ($category) => array_search((int) $category->id, $selectedIds, true))
                ->values();
        }

        if (! $includeCounts) {
            return $categories;
        }

        // Only layouts displaying totals need to traverse descendants and count products.
        $categories->each(function (Category $category) use ($hideOutOfStockProducts): void {
            $scopeIds = Category::query()
                ->descendantsAndSelf((int) $category->id)
                ->filter(static fn (Category $item): bool => $item->scope === Category::SCOPE_CATALOG && $item->isCurrentlyVisible())
                ->pluck('id');

            $count = Product::query()
                ->visibleOnStorefront($hideOutOfStockProducts)
                ->whereHas('categories', fn ($q) => $q
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->currentlyVisible()
                    ->whereIn('categories.id', $scopeIds))
                ->distinct()
                ->count('products.id');

            $category->setAttribute('products_count', $count);
        });

        return $categories;
    }

    public function excludeHomeShortcuts(Builder $query): Builder
    {
        // The Herrera home page already has dedicated clearance and new-arrivals links.
        return $this->excludeClearance($query)->where(fn (Builder $categories) => $categories
            ->whereNull('code')
            ->orWhere('code', 'not like', 'herrera-oc-category-%')
            ->orWhereDoesntHave('translations', fn (Builder $translations) => $translations
                ->where('locale', 'hr')->where('slug', 'novo')->where('name', 'Novo')));
    }

    public function excludeClearance(Builder $query): Builder
    {
        $clearanceCategoryId = (int) app(ClearanceService::class)->settings()['category_id'];

        return $query
            ->when($clearanceCategoryId > 0, fn (Builder $categories) => $categories->whereKeyNot($clearanceCategoryId))
            ->where(fn (Builder $categories) => $categories->whereNull('code')->orWhereNotIn('code', ['rasprodaja', 'clearance']))
            ->whereDoesntHave('translations', fn (Builder $translations) => $translations
                ->where('locale', 'hr')
                ->where(fn (Builder $croatian) => $croatian->where('slug', 'rasprodaja')->orWhere('name', 'Rasprodaja')));
    }
}
