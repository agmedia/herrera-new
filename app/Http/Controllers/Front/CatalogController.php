<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Http\Controllers\Front\Concerns\ResolvesGridColumns;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\User;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\WishlistService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\MediaUrl;
use App\Support\ProductMaterialLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CatalogController extends Controller
{
    use ResolvesFrontendView;
    use ResolvesGridColumns;

    private ?bool $hideOutOfStockProductsCache = null;

    public function autocomplete(Request $request): JsonResponse
    {
        $settings = app(SystemSettingsService::class);
        abort_unless((bool) $settings->get('store_search_autocomplete_enabled', false), 404);

        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $search = trim((string) $request->query('q', ''));
        $configuration = $this->autocompleteConfiguration($settings);

        if (mb_strlen($search) < 2) {
            return $this->autocompleteResponse($search, $this->emptyAutocompleteGroups());
        }

        $groups = [
            'products' => $configuration['products_enabled']
                ? $this->autocompleteProducts($request, $locale, $fallbackLocale, $search, $configuration)
                : ['total' => 0, 'items' => []],
            'categories' => $configuration['categories_enabled']
                ? $this->autocompleteCategories($locale, $fallbackLocale, $search, $configuration['categories_limit'])
                : ['total' => 0, 'items' => []],
            'manufacturers' => $configuration['manufacturers_enabled']
                ? $this->autocompleteManufacturers($locale, $fallbackLocale, $search, $configuration['manufacturers_limit'])
                : ['total' => 0, 'items' => []],
            'blog' => $configuration['blog_enabled']
                ? $this->autocompleteBlogPosts($locale, $fallbackLocale, $search, $configuration['blog_limit'])
                : ['total' => 0, 'items' => []],
        ];

        return $this->autocompleteResponse($search, $groups);
    }

    /**
     * @param  array<string, bool|int>  $configuration
     * @return array{total:int,items:array<int,array<string,mixed>>}
     */
    private function autocompleteProducts(
        Request $request,
        string $locale,
        string $fallbackLocale,
        string $search,
        array $configuration
    ): array {
        $with = [
            'translations' => fn ($translationQuery) => $translationQuery
                ->select(['id', 'product_id', 'locale', 'slug', 'name', 'excerpt'])
                ->whereIn('locale', [$locale, $fallbackLocale]),
        ];

        if ($configuration['show_product_price']) {
            $with[] = 'taxRate:id,rate,rate_type,is_active';
        }

        if ($configuration['show_product_image']) {
            $with[] = 'media';
        }

        if ($configuration['show_product_brand']) {
            $with['manufacturer'] = fn ($manufacturerQuery) => $manufacturerQuery
                ->where('is_active', true)
                ->with([
                    'translations' => fn ($translationQuery) => $translationQuery
                        ->select(['id', 'manufacturer_id', 'locale', 'name'])
                        ->whereIn('locale', [$locale, $fallbackLocale]),
                ]);
        }

        $query = Product::query()
            ->visibleOnStorefront($this->hideOutOfStockProducts())
            ->with($with);

        $this->applyProductSearch($query, $locale, $fallbackLocale, $search);

        $total = (clone $query)->count('products.id');
        $viewer = $request->user();
        $pricing = app(ProductPricePresentationService::class);
        $preferWebp = (bool) app(SystemSettingsService::class)->get('store_images_use_webp', true);

        $products = $query
            ->orderByDesc('products.id')
            ->limit((int) $configuration['products_limit'])
            ->get()
            ->map(function (Product $product) use (
                $locale,
                $fallbackLocale,
                $viewer,
                $pricing,
                $preferWebp,
                $configuration
            ): array {
                $translation = $product->translations->firstWhere('locale', $locale)
                    ?? $product->translations->firstWhere('locale', $fallbackLocale)
                    ?? $product->translations->first();

                $slug = (string) ($translation?->slug ?? $product->id);
                $price = $configuration['show_product_price']
                    ? $pricing->forProduct($product, $viewer)
                    : null;
                $oldGross = $price['old_gross'] ?? null;
                $imageUrl = null;
                $brand = null;

                if ($configuration['show_product_image']) {
                    $mainMedia = $product->media->firstWhere('collection_name', 'product_main')
                        ?? $product->media->firstWhere('collection_name', 'product_gallery')
                        ?? $product->getFirstMedia('product_main')
                        ?? $product->getFirstMedia('product_gallery');
                    $imageUrl = MediaUrl::conversionOrNull($mainMedia, 'card_320w', $preferWebp)
                        ?? MediaUrl::conversionOrNull($mainMedia, 'card_480w', $preferWebp)
                        ?? ($mainMedia ? (string) $mainMedia->getUrl() : null);
                }

                if ($configuration['show_product_brand'] && $product->manufacturer) {
                    $brandTranslation = $product->manufacturer->translations->firstWhere('locale', $locale)
                        ?? $product->manufacturer->translations->firstWhere('locale', $fallbackLocale)
                        ?? $product->manufacturer->translations->first();
                    $brand = $brandTranslation?->name;
                }

                return [
                    'id' => (int) $product->id,
                    'kind' => 'product',
                    'name' => (string) ($translation?->name ?? $product->code),
                    'brand' => $brand,
                    'sku' => $configuration['show_product_sku']
                        ? (string) ($product->sku ?: $product->code)
                        : null,
                    'url' => route('products.show', ['slug' => $slug]),
                    'image_url' => $imageUrl,
                    'price' => $price !== null
                        ? number_format((float) ($price['current_gross'] ?? 0), 2).' €'
                        : null,
                    'old_price' => $price !== null && $oldGross !== null
                        ? number_format((float) $oldGross, 2).' €'
                        : null,
                    'has_discount' => $price !== null
                        && $oldGross !== null
                        && (float) $oldGross > (float) ($price['current_gross'] ?? 0),
                    'is_b2b_price' => (bool) ($price['is_b2b_price'] ?? false),
                ];
            })
            ->values()
            ->all();

        return [
            'total' => $total,
            'items' => $products,
        ];
    }

    /**
     * @return array{total:int,items:array<int,array<string,mixed>>}
     */
    private function autocompleteCategories(
        string $locale,
        string $fallbackLocale,
        string $search,
        int $limit
    ): array {
        $query = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->currentlyVisible()
            ->whereHas('translations', function ($translationQuery) use ($locale, $fallbackLocale, $search): void {
                $translationQuery
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->whereIn('locale', [$locale, $fallbackLocale])
                    ->where('name', 'like', '%'.$search.'%');
            })
            ->with([
                'translations' => fn ($translationQuery) => $translationQuery
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->whereIn('locale', [$locale, $fallbackLocale]),
            ]);

        $total = (clone $query)->count('categories.id');
        $items = $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (Category $category) use ($locale, $fallbackLocale): array {
                $translation = $category->translations->firstWhere('locale', $locale)
                    ?? $category->translations->firstWhere('locale', $fallbackLocale)
                    ?? $category->translations->first();

                return [
                    'id' => (int) $category->id,
                    'kind' => 'category',
                    'name' => (string) ($translation?->name ?? $category->code),
                    'url' => route('categories.show', ['slug' => (string) ($translation?->slug ?? $category->id)]),
                ];
            })
            ->values()
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    /**
     * @return array{total:int,items:array<int,array<string,mixed>>}
     */
    private function autocompleteManufacturers(
        string $locale,
        string $fallbackLocale,
        string $search,
        int $limit
    ): array {
        if (! app(CatalogFeatureService::class)->useManufacturers()) {
            return ['total' => 0, 'items' => []];
        }

        $query = Manufacturer::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($productQuery) => $productQuery
                ->visibleOnStorefront($this->hideOutOfStockProducts()))
            ->whereHas('translations', function ($translationQuery) use ($locale, $fallbackLocale, $search): void {
                $translationQuery
                    ->whereIn('locale', [$locale, $fallbackLocale])
                    ->where('name', 'like', '%'.$search.'%');
            })
            ->with([
                'translations' => fn ($translationQuery) => $translationQuery
                    ->whereIn('locale', [$locale, $fallbackLocale]),
            ]);

        $total = (clone $query)->count('catalog_manufacturers.id');
        $items = $query
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (Manufacturer $manufacturer) use ($locale, $fallbackLocale): array {
                $translation = $manufacturer->translations->firstWhere('locale', $locale)
                    ?? $manufacturer->translations->firstWhere('locale', $fallbackLocale)
                    ?? $manufacturer->translations->first();

                return [
                    'id' => (int) $manufacturer->id,
                    'kind' => 'manufacturer',
                    'name' => (string) ($translation?->name ?? $manufacturer->code),
                    'url' => route('manufacturers.show', ['slug' => (string) ($translation?->slug ?? $manufacturer->id)]),
                ];
            })
            ->values()
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    /**
     * @return array{total:int,items:array<int,array<string,mixed>>}
     */
    private function autocompleteBlogPosts(
        string $locale,
        string $fallbackLocale,
        string $search,
        int $limit
    ): array {
        if (! app(CatalogFeatureService::class)->useBlog()) {
            return ['total' => 0, 'items' => []];
        }

        $query = BlogPost::query()
            ->where('is_active', true)
            ->where(function ($publishedQuery): void {
                $publishedQuery
                    ->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->whereHas('translations', function ($translationQuery) use ($locale, $fallbackLocale, $search): void {
                $translationQuery
                    ->whereIn('locale', [$locale, $fallbackLocale])
                    ->where(function ($copyQuery) use ($search): void {
                        $copyQuery
                            ->where('title', 'like', '%'.$search.'%')
                            ->orWhere('excerpt', 'like', '%'.$search.'%');
                    });
            })
            ->with([
                'translations' => fn ($translationQuery) => $translationQuery
                    ->whereIn('locale', [$locale, $fallbackLocale]),
            ]);

        $total = (clone $query)->count('content_blog_posts.id');
        $items = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (BlogPost $post) use ($locale, $fallbackLocale): array {
                $translation = $post->translations->firstWhere('locale', $locale)
                    ?? $post->translations->firstWhere('locale', $fallbackLocale)
                    ?? $post->translations->first();

                return [
                    'id' => (int) $post->id,
                    'kind' => 'blog',
                    'name' => (string) ($translation?->title ?? $post->code),
                    'url' => route('blog.show', ['slug' => (string) ($translation?->slug ?? $post->id)]),
                ];
            })
            ->values()
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    /**
     * @return array<string, bool|int>
     */
    private function autocompleteConfiguration(SystemSettingsService $settings): array
    {
        return [
            'products_enabled' => (bool) $settings->get('store_search_autocomplete_products_enabled', true),
            'categories_enabled' => (bool) $settings->get('store_search_autocomplete_categories_enabled', true),
            'manufacturers_enabled' => (bool) $settings->get('store_search_autocomplete_manufacturers_enabled', true),
            'blog_enabled' => (bool) $settings->get('store_search_autocomplete_blog_enabled', true),
            'products_limit' => $settings->getInt('store_search_autocomplete_products_limit', 5, 1, 12),
            'categories_limit' => $settings->getInt('store_search_autocomplete_categories_limit', 6, 1, 10),
            'manufacturers_limit' => $settings->getInt('store_search_autocomplete_manufacturers_limit', 6, 1, 10),
            'blog_limit' => $settings->getInt('store_search_autocomplete_blog_limit', 3, 1, 10),
            'show_product_image' => (bool) $settings->get('store_search_autocomplete_show_product_image', true),
            'show_product_brand' => (bool) $settings->get('store_search_autocomplete_show_product_brand', true),
            'show_product_sku' => (bool) $settings->get('store_search_autocomplete_show_product_sku', true),
            'show_product_price' => (bool) $settings->get('store_search_autocomplete_show_product_price', true),
        ];
    }

    /**
     * @return array<string,array{total:int,items:array<int,array<string,mixed>>}>
     */
    private function emptyAutocompleteGroups(): array
    {
        return [
            'products' => ['total' => 0, 'items' => []],
            'categories' => ['total' => 0, 'items' => []],
            'manufacturers' => ['total' => 0, 'items' => []],
            'blog' => ['total' => 0, 'items' => []],
        ];
    }

    /**
     * @param  array<string,array{total:int,items:array<int,array<string,mixed>>}>  $groups
     */
    private function autocompleteResponse(string $search, array $groups): JsonResponse
    {
        $total = array_sum(array_map(
            static fn (array $group): int => (int) ($group['total'] ?? 0),
            $groups
        ));

        return response()->json([
            'query' => $search,
            'total' => $total,
            'items' => $groups['products']['items'] ?? [],
            'groups' => $groups,
            'search_url' => route('shop.index', ['q' => $search]),
        ]);
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $catalogManufacturer = $request->attributes->get('catalog_manufacturer');
        $isManufacturerPage = $catalogManufacturer instanceof Manufacturer;
        $catalogManufacturerTranslation = $isManufacturerPage
            ? ($catalogManufacturer->translations->firstWhere('locale', $locale)
                ?? $catalogManufacturer->translations->firstWhere('locale', $fallbackLocale)
                ?? $catalogManufacturer->translations->first())
            : null;
        $search = trim((string) $request->query('q', ''));
        $categorySlug = trim((string) $request->query('category', ''));
        $manufacturerSlug = $isManufacturerPage
            ? (string) ($catalogManufacturerTranslation?->slug ?? '')
            : trim((string) $request->query('manufacturer', ''));
        $sort = (string) $request->query('sort', 'newest');
        $availableOnly = $this->normalizeBooleanFilterValue($request->query('available_only'));
        $promoOnly = $this->normalizeBooleanFilterValue($request->query('promo_only'));
        [$priceMin, $priceMax] = $this->normalizedPriceRange(
            $request->query('price_min'),
            $request->query('price_max')
        );
        $categoryScopeIds = $categorySlug !== ''
            ? $this->cachedCatalogCategoryScopeIds($locale, $fallbackLocale, $categorySlug)
            : null;
        $manufacturerId = $isManufacturerPage
            ? (int) $catalogManufacturer->id
            : ($manufacturerSlug !== ''
                ? $this->cachedCatalogManufacturerId($locale, $fallbackLocale, $manufacturerSlug)
                : null);
        $configuredOptionIds = $this->configuredFilterOptionIds();
        $configuredAttributeGroups = $this->configuredFilterAttributeGroups();
        $optionFilters = $this->catalogOptionFilters(
            $locale,
            $fallbackLocale,
            $configuredOptionIds,
            $categoryScopeIds,
            $manufacturerId,
            $availableOnly
        );
        $attributeFilters = $this->catalogAttributeFilters(
            $locale,
            $fallbackLocale,
            $configuredAttributeGroups,
            $categoryScopeIds,
            $manufacturerId,
            $availableOnly
        );

        if ($optionFilters === []) {
            $optionFilters = $this->legacySizeFallbackFilter(
                $locale,
                $fallbackLocale,
                $categoryScopeIds,
                $manufacturerId,
                $availableOnly
            );
        }

        $selectedOptionFilters = [];
        foreach ($optionFilters as $filter) {
            $valueId = (int) $request->query((string) $filter['query_key'], 0);
            $selectedOptionFilters[(string) $filter['query_key']] = $valueId > 0 ? $valueId : null;
        }
        $selectedAttributeFilters = [];
        foreach ($attributeFilters as $filter) {
            $valueId = (int) $request->query((string) $filter['query_key'], 0);
            $selectedAttributeFilters[(string) $filter['query_key']] = $valueId > 0 ? $valueId : null;
        }
        $appliedAttributeFilters = $attributeFilters;
        [$optionFilters, $attributeFilters] = $this->refineCatalogFiltersForSelections(
            $optionFilters,
            $attributeFilters,
            $selectedOptionFilters,
            $selectedAttributeFilters,
            $categoryScopeIds,
            $manufacturerId,
            $availableOnly
        );
        $gridCols = $this->resolveGridCols($request, $this->defaultDesktopGridCols($request));
        $this->queueGridColsCookie($gridCols);

        $query = Product::query()
            ->withStorefrontEnergyData()
            ->select([
                'products.id',
                'products.code',
                'products.sku',
                'products.base_price',
                'products.stock_qty',
                'products.tax_rate_id',
                'products.manufacturer_id',
                'products.is_active',
            ])
            ->withApprovedCommentSummary([$locale, $fallbackLocale])
            ->visibleOnStorefront($this->hideOutOfStockProducts())
            ->with([
                'taxRate:id,rate,rate_type,is_active',
                'translations' => fn ($q) => $q
                    ->select(['id', 'product_id', 'locale', 'slug', 'name', 'excerpt'])
                    ->whereIn('locale', [$locale, $fallbackLocale]),
                'categories.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'manufacturer.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'attributes' => ProductMaterialLabel::eagerLoadAttributes($locale, $fallbackLocale),
                'media' => fn ($q) => $q
                    ->whereIn('collection_name', ['product_main', 'product_gallery'])
                    ->orderBy('order_column')
                    ->orderBy('id'),
                'optionValues' => fn ($q) => $q
                    ->select(['id', 'product_id', 'option_value_id', 'parent_option_value_id', 'sku', 'stock_qty', 'is_active', 'sort_order'])
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with([
                        'optionValue.option' => fn ($optionQuery) => $optionQuery
                            ->select(['id', 'payload']),
                        'optionValue.translations' => fn ($tq) => $tq
                            ->select(['id', 'option_value_id', 'locale', 'name'])
                            ->whereIn('locale', [$locale, $fallbackLocale]),
                        'parentOptionValue.option' => fn ($optionQuery) => $optionQuery
                            ->select(['id', 'payload']),
                        'parentOptionValue.translations' => fn ($tq) => $tq
                            ->select(['id', 'option_value_id', 'locale', 'name'])
                            ->whereIn('locale', [$locale, $fallbackLocale]),
                    ]),
            ]);

        $this->applyProductSearch($query, $locale, $fallbackLocale, $search);

        if ($categorySlug !== '') {
            if ($categoryScopeIds !== []) {
                $query->whereHas('categories', function ($categoryQuery) use ($categoryScopeIds): void {
                    $categoryQuery
                        ->where('scope', Category::SCOPE_CATALOG)
                        ->currentlyVisible()
                        ->whereIn('categories.id', $categoryScopeIds);
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($manufacturerSlug !== '') {
            if ($manufacturerId && $manufacturerId > 0) {
                $query->where('products.manufacturer_id', $manufacturerId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        foreach ($selectedOptionFilters as $selectedOptionValueId) {
            if ($selectedOptionValueId && $selectedOptionValueId > 0) {
                $this->applyOptionValueFilter($query, $selectedOptionValueId);
            }
        }

        foreach ($appliedAttributeFilters as $attributeFilter) {
            $queryKey = (string) $attributeFilter['query_key'];
            $selectedAttributeId = (int) ($selectedAttributeFilters[$queryKey] ?? 0);
            if ($selectedAttributeId <= 0) {
                continue;
            }

            $query->whereHas('attributes', function ($attributeQuery) use ($selectedAttributeId): void {
                $attributeQuery->where('catalog_attributes.id', $selectedAttributeId);
            });
        }

        if ($availableOnly) {
            $query->visibleOnStorefront(true);
        }

        $promoAvailabilityQuery = clone $query;
        $this->applyBasePriceFilter($promoAvailabilityQuery, $priceMin, $priceMax);
        $this->applyPromotionFilter($promoAvailabilityQuery, $request->user());
        $promoFilterAvailable = (clone $promoAvailabilityQuery)->exists();

        if ($promoOnly) {
            $this->applyPromotionFilter($query, $request->user());
        }

        $priceBounds = $this->resolvePriceBounds($query, $priceMin, $priceMax);

        $this->applyBasePriceFilter($query, $priceMin, $priceMax);

        match ($sort) {
            'price_low' => $this->applyPriceSort($query, 'asc'),
            'price_high' => $this->applyPriceSort($query, 'desc'),
            'stock_high' => $query->orderByDesc('products.stock_qty')->orderByDesc('products.id'),
            'oldest' => $query->orderBy('products.id'),
            default => $query->orderByDesc('products.id'),
        };

        $products = $query
            ->paginate($this->shopPerPage($request))
            ->withQueryString();

        $categories = $manufacturerId && $manufacturerId > 0
            ? $this->cachedManufacturerCatalogCategories(
                $manufacturerId,
                $locale,
                $fallbackLocale,
                $availableOnly
            )
            : $this->cachedShopCatalogCategories($locale, $fallbackLocale, $availableOnly);
        $manufacturers = $isManufacturerPage
            ? collect()
            : $this->cachedCatalogManufacturers(
                $locale,
                $fallbackLocale,
                $categoryScopeIds,
                $availableOnly
            );
        $view = $isManufacturerPage ? 'manufacturers.show' : 'shop.index';
        $response = response()->view($this->frontendView($request, $view), [
            'isShopPage' => ! $isManufacturerPage && $this->frontendVariant($request) === 'desktop',
            'isManufacturerPage' => $isManufacturerPage,
            'manufacturer' => $catalogManufacturer,
            'category' => null,
            'products' => $products,
            'categories' => $categories,
            'manufacturers' => $manufacturers,
            'optionFilters' => $this->withSelectedFilters($optionFilters, $selectedOptionFilters),
            'attributeFilters' => $this->withSelectedFilters($attributeFilters, $selectedAttributeFilters),
            'subcategories' => collect(),
            'breadcrumbCategories' => collect(),
            'topBlocks' => $isManufacturerPage
                ? $request->attributes->get('catalog_top_blocks', collect())
                : collect(),
            'bottomBlocks' => $isManufacturerPage
                ? $request->attributes->get('catalog_bottom_blocks', collect())
                : collect(),
            'showCategoryFilters' => true,
            'showCategoryProducts' => true,
            'filters' => [
                'q' => $search,
                'category' => $categorySlug,
                'manufacturer' => $isManufacturerPage ? '' : $manufacturerSlug,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'available_only' => $availableOnly,
                'promo_only' => $promoOnly,
                'sort' => $sort,
                'cols' => $gridCols,
            ],
            'priceBounds' => $priceBounds,
            'promoFilterAvailable' => $promoFilterAvailable,
            'locale' => $locale,
            'fallbackLocale' => $fallbackLocale,
        ]);

        return $this->withDesktopCacheHeaders(
            $request,
            $response,
            $isManufacturerPage ? 'manufacturer:'.$manufacturerSlug : 'shop'
        );
    }

    public function categories(Request $request): View
    {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');

        $categories = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->whereNull('parent_id')
            ->currentlyVisible()
            ->with([
                'translations' => fn ($q) => $q
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->whereIn('locale', [$locale, $fallbackLocale]),
                'media' => fn ($q) => $q->whereIn('collection_name', ['category_icon', 'category_banner']),
            ])
            ->withCount([
                'descendants as subcategories_count' => fn ($q) => $q
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->currentlyVisible(),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view($this->frontendView($request, 'categories.index'), [
            'categories' => $categories,
            'locale' => $locale,
            'fallbackLocale' => $fallbackLocale,
        ]);
    }

    public function showCategory(Request $request, string $slug): Response|RedirectResponse
    {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $variant = $this->frontendVariant($request);
        $search = trim((string) $request->query('q', ''));
        $categorySlug = $slug;
        $manufacturerSlug = trim((string) $request->query('manufacturer', ''));
        $sort = (string) $request->query('sort', 'default');
        $availableOnly = $this->normalizeBooleanFilterValue($request->query('available_only'));
        $promoOnly = $this->normalizeBooleanFilterValue($request->query('promo_only'));
        [$priceMin, $priceMax] = $this->normalizedPriceRange(
            $request->query('price_min'),
            $request->query('price_max')
        );
        $gridCols = $this->resolveGridCols($request, $this->defaultDesktopGridCols($request));
        $this->queueGridColsCookie($gridCols);

        $category = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->currentlyVisible()
            ->whereHas('translations', function ($q) use ($locale, $fallbackLocale, $categorySlug): void {
                $q->where('scope', Category::SCOPE_CATALOG)
                    ->whereIn('locale', [$locale, $fallbackLocale])
                    ->where('slug', $categorySlug);
            })
            ->with([
                'translations' => fn ($q) => $q->where('scope', Category::SCOPE_CATALOG)->whereIn('locale', [$locale, $fallbackLocale]),
            ])
            ->firstOrFail();

        $showCategoryProducts = $category->catalogPageShowsProducts();
        $showCategoryFilters = $category->catalogPageShowsFilters();

        $categoryTreeIds = $category->descendants()
            ->where('scope', Category::SCOPE_CATALOG)
            ->currentlyVisible()
            ->pluck('id');
        $categoryTreeIds->prepend($category->id);
        $categoryScopeIds = $categoryTreeIds
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $manufacturerId = $manufacturerSlug !== ''
            ? $this->cachedCatalogManufacturerId($locale, $fallbackLocale, $manufacturerSlug)
            : null;

        $optionFilters = [];
        $attributeFilters = [];

        if ($showCategoryFilters) {
            $configuredOptionIds = $this->configuredFilterOptionIds();
            $configuredAttributeGroups = array_values(array_unique([
                ...$this->configuredFilterAttributeGroups(),
                'sastav',
                'material',
            ]));
            $optionFilters = $this->catalogOptionFilters(
                $locale,
                $fallbackLocale,
                $configuredOptionIds,
                $categoryScopeIds,
                $manufacturerId,
                $availableOnly
            );
            $attributeFilters = $this->catalogAttributeFilters(
                $locale,
                $fallbackLocale,
                $configuredAttributeGroups,
                $categoryScopeIds,
                $manufacturerId,
                $availableOnly
            );

            if ($optionFilters === []) {
                $optionFilters = $this->legacySizeFallbackFilter(
                    $locale,
                    $fallbackLocale,
                    $categoryScopeIds,
                    $manufacturerId,
                    $availableOnly
                );
            }
        }

        $selectedOptionFilters = [];
        foreach ($optionFilters as $filter) {
            $valueId = (int) $request->query((string) $filter['query_key'], 0);
            $selectedOptionFilters[(string) $filter['query_key']] = $valueId > 0 ? $valueId : null;
        }
        $selectedAttributeFilters = [];
        foreach ($attributeFilters as $filter) {
            $valueId = (int) $request->query((string) $filter['query_key'], 0);
            $selectedAttributeFilters[(string) $filter['query_key']] = $valueId > 0 ? $valueId : null;
        }
        $appliedAttributeFilters = $attributeFilters;
        [$optionFilters, $attributeFilters] = $this->refineCatalogFiltersForSelections(
            $optionFilters,
            $attributeFilters,
            $selectedOptionFilters,
            $selectedAttributeFilters,
            $categoryScopeIds,
            $manufacturerId,
            $availableOnly
        );

        if ($showCategoryProducts) {
            $productsQuery = Product::query()
                ->withStorefrontEnergyData()
                ->visibleOnStorefront($this->hideOutOfStockProducts())
                ->whereHas('categories', function ($categoryQuery) use ($categoryTreeIds): void {
                    $categoryQuery
                        ->where('scope', Category::SCOPE_CATALOG)
                        ->currentlyVisible()
                        ->whereIn('categories.id', $categoryTreeIds);
                });

            $this->applyProductSearch($productsQuery, $locale, $fallbackLocale, $search);

            if ($manufacturerSlug !== '') {
                if ($manufacturerId && $manufacturerId > 0) {
                    $productsQuery->where('products.manufacturer_id', $manufacturerId);
                } else {
                    $productsQuery->whereRaw('1 = 0');
                }
            }

            foreach ($selectedOptionFilters as $selectedOptionValueId) {
                if ($selectedOptionValueId && $selectedOptionValueId > 0) {
                    $this->applyOptionValueFilter($productsQuery, $selectedOptionValueId);
                }
            }

            foreach ($appliedAttributeFilters as $attributeFilter) {
                $queryKey = (string) $attributeFilter['query_key'];
                $selectedAttributeId = (int) ($selectedAttributeFilters[$queryKey] ?? 0);
                if ($selectedAttributeId <= 0) {
                    continue;
                }

                $productsQuery->whereHas('attributes', function ($attributeQuery) use ($selectedAttributeId): void {
                    $attributeQuery->where('catalog_attributes.id', $selectedAttributeId);
                });
            }

            if ($availableOnly) {
                $productsQuery->visibleOnStorefront(true);
            }

            $promoAvailabilityQuery = clone $productsQuery;
            $this->applyBasePriceFilter($promoAvailabilityQuery, $priceMin, $priceMax);
            $this->applyPromotionFilter($promoAvailabilityQuery, $request->user());
            $promoFilterAvailable = (clone $promoAvailabilityQuery)->exists();

            if ($promoOnly) {
                $this->applyPromotionFilter($productsQuery, $request->user());
            }

            $priceBounds = $this->resolvePriceBounds($productsQuery, $priceMin, $priceMax);

            $this->applyBasePriceFilter($productsQuery, $priceMin, $priceMax);

            $productsQuery
                ->select([
                    'products.id',
                    'products.code',
                    'products.sku',
                    'products.base_price',
                    'products.stock_qty',
                    'products.tax_rate_id',
                    'products.manufacturer_id',
                    'products.is_active',
                ])
                ->withApprovedCommentSummary([$locale, $fallbackLocale])
                ->with([
                    'taxRate:id,rate,rate_type,is_active',
                    'translations' => fn ($q) => $q
                        ->select(['id', 'product_id', 'locale', 'slug', 'name', 'excerpt'])
                        ->whereIn('locale', [$locale, $fallbackLocale]),
                    'categories.translations' => fn ($q) => $q
                        ->where('scope', Category::SCOPE_CATALOG)
                        ->whereIn('locale', [$locale, $fallbackLocale]),
                    'manufacturer.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                    'attributes' => ProductMaterialLabel::eagerLoadAttributes($locale, $fallbackLocale),
                    'media' => fn ($q) => $q
                        ->whereIn('collection_name', ['product_main', 'product_gallery'])
                        ->orderBy('order_column')
                        ->orderBy('id'),
                    'optionValues' => fn ($q) => $q
                        ->select(['id', 'product_id', 'option_value_id', 'parent_option_value_id', 'sku', 'stock_qty', 'is_active', 'sort_order'])
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->with([
                            'optionValue.option' => fn ($optionQuery) => $optionQuery
                                ->select(['id', 'payload']),
                            'optionValue.translations' => fn ($tq) => $tq
                                ->select(['id', 'option_value_id', 'locale', 'name'])
                                ->whereIn('locale', [$locale, $fallbackLocale]),
                            'parentOptionValue.option' => fn ($optionQuery) => $optionQuery
                                ->select(['id', 'payload']),
                            'parentOptionValue.translations' => fn ($tq) => $tq
                                ->select(['id', 'option_value_id', 'locale', 'name'])
                                ->whereIn('locale', [$locale, $fallbackLocale]),
                        ]),
                ]);

            match ($sort) {
                'newest' => $productsQuery->orderByDesc('products.id'),
                'price_low' => $this->applyPriceSort($productsQuery, 'asc'),
                'price_high' => $this->applyPriceSort($productsQuery, 'desc'),
                'stock_high' => $productsQuery->orderByDesc('products.stock_qty')->orderByDesc('products.id'),
                'oldest' => $productsQuery->orderBy('products.id'),
                default => $this->applyCategoryDefaultProductSort($productsQuery, $categoryScopeIds, $locale, $fallbackLocale),
            };

            $products = $productsQuery
                ->paginate($this->shopPerPage($request))
                ->withQueryString();
        } else {
            $priceBounds = $this->resolvePriceBounds(null, $priceMin, $priceMax);
            $promoFilterAvailable = false;
            $products = (new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: $this->shopPerPage($request),
                currentPage: max(1, (int) $request->query('page', 1)),
                options: [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ],
            ))->withQueryString();
        }

        $categories = $showCategoryFilters
            ? $this->cachedCatalogCategories($locale, $fallbackLocale, $availableOnly)
            : collect();
        $manufacturers = $showCategoryFilters
            ? $this->cachedCatalogManufacturers(
                $locale,
                $fallbackLocale,
                $categoryScopeIds,
                $availableOnly
            )
            : collect();

        $subcategories = $showCategoryFilters
            ? $category->children()
                ->where('scope', Category::SCOPE_CATALOG)
                ->currentlyVisible()
                ->with(['translations' => fn ($q) => $q
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->whereIn('locale', [$locale, $fallbackLocale])])
                ->withCount(['products' => function ($q) use ($availableOnly, $manufacturerId): void {
                    $q->visibleOnStorefront($this->hideOutOfStockProducts() || $availableOnly);
                    if ($manufacturerId && $manufacturerId > 0) {
                        $q->where('products.manufacturer_id', $manufacturerId);
                    } elseif ($manufacturerId !== null) {
                        $q->whereRaw('1 = 0');
                    }
                }])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
            : collect();
        $subcategories->transform(function (Category $subCategory) use ($availableOnly, $manufacturerId): Category {
            $subTreeIds = Category::query()
                ->descendantsAndSelf($subCategory->id)
                ->where('scope', Category::SCOPE_CATALOG)
                ->filter(fn (Category $category): bool => $category->isCurrentlyVisible())
                ->pluck('id');

            if ($subTreeIds->isEmpty()) {
                $subCategory->setAttribute('products_count', 0);

                return $subCategory;
            }

            $recursiveCount = Product::query()
                ->visibleOnStorefront($this->hideOutOfStockProducts() || $availableOnly)
                ->when(
                    $manufacturerId && $manufacturerId > 0,
                    fn (Builder $query) => $query->where('products.manufacturer_id', $manufacturerId)
                )
                ->when(
                    $manufacturerId !== null && $manufacturerId <= 0,
                    fn (Builder $query) => $query->whereRaw('1 = 0')
                )
                ->whereHas('categories', function ($categoryQuery) use ($subTreeIds): void {
                    $categoryQuery
                        ->where('scope', Category::SCOPE_CATALOG)
                        ->currentlyVisible()
                        ->whereIn('categories.id', $subTreeIds);
                })
                ->distinct('products.id')
                ->count('products.id');

            $subCategory->setAttribute('products_count', $recursiveCount);

            return $subCategory;
        })->filter(fn (Category $subCategory): bool => (int) $subCategory->products_count > 0)->values();

        $breadcrumbCategories = $category->ancestors()
            ->where('scope', Category::SCOPE_CATALOG)
            ->currentlyVisible()
            ->with(['translations' => fn ($q) => $q
                ->where('scope', Category::SCOPE_CATALOG)
                ->whereIn('locale', [$locale, $fallbackLocale])])
            ->orderBy('_lft')
            ->get()
            ->push($category);

        $topBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'category.top',
            locale: $locale,
            targetType: 'category',
            targetRef: $categorySlug,
            frontendVariant: $variant
        );

        $bottomBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'category.bottom',
            locale: $locale,
            targetType: 'category',
            targetRef: $categorySlug,
            frontendVariant: $variant
        );

        $response = response()->view($this->frontendView($request, 'categories.show'), [
            'category' => $category,
            'products' => $products,
            'categories' => $categories,
            'manufacturers' => $manufacturers,
            'optionFilters' => $this->withSelectedFilters($optionFilters, $selectedOptionFilters),
            'attributeFilters' => $this->withSelectedFilters($attributeFilters, $selectedAttributeFilters),
            'subcategories' => $subcategories,
            'breadcrumbCategories' => $breadcrumbCategories,
            'topBlocks' => $topBlocks,
            'bottomBlocks' => $bottomBlocks,
            'showCategoryFilters' => $showCategoryFilters,
            'showCategoryProducts' => $showCategoryProducts,
            'filters' => [
                'q' => $search,
                'manufacturer' => $manufacturerSlug,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'available_only' => $availableOnly,
                'promo_only' => $promoOnly,
                'sort' => $sort,
                'cols' => $gridCols,
            ],
            'priceBounds' => $priceBounds,
            'promoFilterAvailable' => $promoFilterAvailable ?? false,
            'locale' => $locale,
            'fallbackLocale' => $fallbackLocale,
        ]);

        return $this->withDesktopCacheHeaders($request, $response, 'category:'.$categorySlug);
    }

    /**
     * @return array<int, int>
     */
    private function cachedCatalogCategoryScopeIds(
        string $locale,
        string $fallbackLocale,
        string $categorySlug
    ): array {
        $cacheKey = sprintf(
            'front:catalog:category-scope:v1:%s:%s:%s',
            $locale,
            $fallbackLocale,
            sha1($categorySlug)
        );

        $ids = Cache::remember($cacheKey, now()->addMinutes(10), static function () use (
            $locale,
            $fallbackLocale,
            $categorySlug
        ): array {
            $category = Category::query()
                ->where('scope', Category::SCOPE_CATALOG)
                ->currentlyVisible()
                ->whereHas('translations', function ($query) use ($locale, $fallbackLocale, $categorySlug): void {
                    $query
                        ->where('scope', Category::SCOPE_CATALOG)
                        ->whereIn('locale', [$locale, $fallbackLocale])
                        ->where('slug', $categorySlug);
                })
                ->first();

            if (! $category) {
                return [];
            }

            return $category->descendants()
                ->where('scope', Category::SCOPE_CATALOG)
                ->currentlyVisible()
                ->pluck('id')
                ->prepend($category->id)
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        });

        return is_array($ids) ? $ids : [];
    }

    private function cachedCatalogManufacturerId(
        string $locale,
        string $fallbackLocale,
        string $manufacturerSlug
    ): int {
        $cacheKey = sprintf(
            'front:catalog:manufacturer-id:v1:%s:%s:%s',
            $locale,
            $fallbackLocale,
            sha1($manufacturerSlug)
        );

        return (int) Cache::remember($cacheKey, now()->addMinutes(10), static function () use (
            $locale,
            $fallbackLocale,
            $manufacturerSlug
        ): int {
            return (int) (Manufacturer::query()
                ->where('is_active', true)
                ->whereHas('translations', function ($query) use ($locale, $fallbackLocale, $manufacturerSlug): void {
                    $query
                        ->whereIn('locale', [$locale, $fallbackLocale])
                        ->where('slug', $manufacturerSlug);
                })
                ->value('id') ?? 0);
        });
    }

    private function cachedCatalogCategories(
        string $locale,
        string $fallbackLocale,
        bool $availableOnly = false
    ) {
        $hideOutOfStock = $this->hideOutOfStockProducts() || $availableOnly;
        $cacheKey = sprintf('front:catalog:categories:v2:%s:%s:%s', $locale, $fallbackLocale, $hideOutOfStock ? 'hide-oos' : 'all-stock');

        return Cache::remember($cacheKey, now()->addMinutes(10), static function () use ($locale, $fallbackLocale, $hideOutOfStock) {
            return Category::query()
                ->select(['id', 'code', 'sort_order'])
                ->where('scope', Category::SCOPE_CATALOG)
                ->currentlyVisible()
                ->with(['translations' => fn ($q) => $q
                    ->select(['id', 'category_id', 'scope', 'locale', 'name', 'slug'])
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->whereIn('locale', [$locale, $fallbackLocale])])
                ->withCount(['products' => fn ($q) => $q->visibleOnStorefront($hideOutOfStock)])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->filter(fn (Category $category): bool => (int) $category->products_count > 0)
                ->values();
        });
    }

    private function cachedShopCatalogCategories(
        string $locale,
        string $fallbackLocale,
        bool $availableOnly = false
    ) {
        $hideOutOfStock = $this->hideOutOfStockProducts() || $availableOnly;
        $cacheKey = sprintf('front:catalog:shop-root-categories:v2:%s:%s:%s', $locale, $fallbackLocale, $hideOutOfStock ? 'hide-oos' : 'all-stock');

        return Cache::remember($cacheKey, now()->addMinutes(10), static function () use ($locale, $fallbackLocale, $hideOutOfStock) {
            $categories = Category::query()
                ->select(['id', 'code', 'parent_id', 'sort_order'])
                ->where('scope', Category::SCOPE_CATALOG)
                ->whereNull('parent_id')
                ->currentlyVisible()
                ->with(['translations' => fn ($q) => $q
                    ->select(['id', 'category_id', 'scope', 'locale', 'name', 'slug'])
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->whereIn('locale', [$locale, $fallbackLocale])])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return $categories->map(function (Category $category) use ($hideOutOfStock): Category {
                $treeIds = Category::query()
                    ->descendantsAndSelf($category->id)
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->filter(fn (Category $treeCategory): bool => $treeCategory->isCurrentlyVisible())
                    ->pluck('id');

                $productCount = $treeIds->isEmpty()
                    ? 0
                    : Product::query()
                        ->visibleOnStorefront($hideOutOfStock)
                        ->whereHas('categories', function ($categoryQuery) use ($treeIds): void {
                            $categoryQuery
                                ->where('scope', Category::SCOPE_CATALOG)
                                ->currentlyVisible()
                                ->whereIn('categories.id', $treeIds);
                        })
                        ->distinct('products.id')
                        ->count('products.id');

                $category->setAttribute('products_count', $productCount);

                return $category;
            })
                ->filter(fn (Category $category): bool => (int) $category->products_count > 0)
                ->values();
        });
    }

    /**
     * @param  array<int, int>|null  $categoryScopeIds
     */
    private function cachedCatalogManufacturers(
        string $locale,
        string $fallbackLocale,
        ?array $categoryScopeIds = null,
        bool $availableOnly = false
    ) {
        $scopeIds = $this->normalizedCatalogScopeIds($categoryScopeIds);
        if ($categoryScopeIds !== null && $scopeIds === []) {
            return collect();
        }

        $hideOutOfStock = $this->hideOutOfStockProducts() || $availableOnly;
        $categoryKey = $categoryScopeIds === null ? 'all' : sha1(implode(',', $scopeIds));
        $cacheKey = sprintf(
            'front:catalog:manufacturers:v2:%s:%s:%s:%s',
            $locale,
            $fallbackLocale,
            $categoryKey,
            $hideOutOfStock ? 'hide-oos' : 'all-stock'
        );

        return Cache::remember($cacheKey, now()->addMinutes(10), static function () use (
            $locale,
            $fallbackLocale,
            $hideOutOfStock,
            $scopeIds
        ) {
            return Manufacturer::query()
                ->select(['id', 'code', 'sort_order'])
                ->where('is_active', true)
                ->with(['translations' => fn ($q) => $q
                    ->select(['id', 'manufacturer_id', 'locale', 'name', 'slug'])
                    ->whereIn('locale', [$locale, $fallbackLocale])])
                ->withCount(['products' => function ($query) use ($hideOutOfStock, $scopeIds): void {
                    $query->visibleOnStorefront($hideOutOfStock);
                    if ($scopeIds !== []) {
                        $query->whereHas('categories', function ($categoryQuery) use ($scopeIds): void {
                            $categoryQuery
                                ->where('scope', Category::SCOPE_CATALOG)
                                ->currentlyVisible()
                                ->whereIn('categories.id', $scopeIds);
                        });
                    }
                }])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->filter(fn (Manufacturer $manufacturer): bool => (int) $manufacturer->products_count > 0)
                ->values();
        });
    }

    private function cachedManufacturerCatalogCategories(
        int $manufacturerId,
        string $locale,
        string $fallbackLocale,
        bool $availableOnly = false
    ) {
        $hideOutOfStock = $this->hideOutOfStockProducts() || $availableOnly;
        $cacheKey = sprintf(
            'front:catalog:manufacturer-root-categories:v2:%d:%s:%s:%s',
            $manufacturerId,
            $locale,
            $fallbackLocale,
            $hideOutOfStock ? 'hide-oos' : 'all-stock'
        );

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use (
            $manufacturerId,
            $locale,
            $fallbackLocale,
            $hideOutOfStock,
            $availableOnly
        ) {
            return $this->cachedShopCatalogCategories($locale, $fallbackLocale, $availableOnly)
                ->map(function (Category $category) use ($manufacturerId, $hideOutOfStock): Category {
                    $category = clone $category;
                    $treeIds = Category::query()
                        ->descendantsAndSelf($category->id)
                        ->where('scope', Category::SCOPE_CATALOG)
                        ->filter(fn (Category $treeCategory): bool => $treeCategory->isCurrentlyVisible())
                        ->pluck('id');

                    $productCount = $treeIds->isEmpty()
                        ? 0
                        : Product::query()
                            ->visibleOnStorefront($hideOutOfStock)
                            ->where('manufacturer_id', $manufacturerId)
                            ->whereHas('categories', function ($categoryQuery) use ($treeIds): void {
                                $categoryQuery
                                    ->where('scope', Category::SCOPE_CATALOG)
                                    ->currentlyVisible()
                                    ->whereIn('categories.id', $treeIds);
                            })
                            ->distinct('products.id')
                            ->count('products.id');

                    $category->setAttribute('products_count', $productCount);

                    return $category;
                })
                ->filter(fn (Category $category): bool => (int) $category->products_count > 0)
                ->values();
        });
    }

    private function hideOutOfStockProducts(): bool
    {
        if ($this->hideOutOfStockProductsCache === null) {
            $this->hideOutOfStockProductsCache = app(CatalogFeatureService::class)->hideOutOfStockProducts();
        }

        return $this->hideOutOfStockProductsCache;
    }

    /**
     * @param  array<int, int>|null  $scopeIds
     * @return array<int, int>
     */
    private function normalizedCatalogScopeIds(?array $scopeIds): array
    {
        return collect($scopeIds ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function applyProductStockVisibilityToBaseQuery(
        QueryBuilder $query,
        bool $hideOutOfStock,
        string $productTable = 'products'
    ): void {
        if (! $hideOutOfStock) {
            return;
        }

        $query->where(function (QueryBuilder $stockQuery) use ($productTable): void {
            $stockQuery
                ->where($productTable.'.stock_qty', '>', 0)
                ->orWhereExists(function (QueryBuilder $existsQuery) use ($productTable): void {
                    $existsQuery
                        ->selectRaw('1')
                        ->from('catalog_product_option_values as storefront_stock_options')
                        ->whereColumn('storefront_stock_options.product_id', $productTable.'.id')
                        ->where('storefront_stock_options.is_active', true)
                        ->where('storefront_stock_options.stock_qty', '>', 0);
                });
        });
    }

    private function applyCategoryScheduleToBaseQuery(QueryBuilder $query, string $categoryTable = 'categories'): void
    {
        $query
            ->where(function (QueryBuilder $scheduleQuery) use ($categoryTable): void {
                $scheduleQuery
                    ->whereNull($categoryTable.'.starts_at')
                    ->orWhere($categoryTable.'.starts_at', '<=', now());
            })
            ->where(function (QueryBuilder $scheduleQuery) use ($categoryTable): void {
                $scheduleQuery
                    ->whereNull($categoryTable.'.ends_at')
                    ->orWhere($categoryTable.'.ends_at', '>=', now());
            });
    }

    /**
     * @return array<int, int>
     */
    private function configuredFilterOptionIds(): array
    {
        $raw = app(SystemSettingsService::class)->get('store_product_filter_option_ids', []);

        return collect(is_array($raw) ? $raw : [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function configuredFilterAttributeGroups(): array
    {
        $raw = app(SystemSettingsService::class)->get('store_product_filter_attribute_group_codes', []);

        return collect(is_array($raw) ? $raw : [])
            ->map(fn ($code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $optionIds
     * @return array<int, array{label:string,query_key:string,kind:string,values:array<int, array{id:int,label:string,count:int}>}>
     */
    private function catalogOptionFilters(
        string $locale,
        string $fallbackLocale,
        array $optionIds,
        ?array $categoryScopeIds = null,
        ?int $manufacturerId = null,
        bool $availableOnly = false
    ): array {
        if ($optionIds === []) {
            return [];
        }

        $scopeIds = $this->normalizedCatalogScopeIds($categoryScopeIds);
        if (($categoryScopeIds !== null && $scopeIds === []) || ($manufacturerId !== null && $manufacturerId <= 0)) {
            return [];
        }

        $categoryKey = $categoryScopeIds === null ? 'all' : sha1(implode(',', $scopeIds));
        $manufacturerKey = $manufacturerId === null ? 'all' : (string) $manufacturerId;
        $hideOutOfStock = $this->hideOutOfStockProducts() || $availableOnly;
        $cacheKey = sprintf(
            'front:catalog:option-filters:v7:%s:%s:%s:%s:%s:%s',
            $locale,
            $fallbackLocale,
            sha1(implode(',', $optionIds)),
            $categoryKey,
            $manufacturerKey,
            $hideOutOfStock ? 'hide-oos' : 'all-stock'
        );

        $rows = Cache::remember($cacheKey, now()->addMinutes(10), function () use (
            $locale,
            $fallbackLocale,
            $optionIds,
            $scopeIds,
            $manufacturerId,
            $hideOutOfStock
        ): array {
            $valueCounts = $this->catalogOptionValueProductCounts(
                $optionIds,
                $scopeIds,
                $manufacturerId,
                $hideOutOfStock
            );
            if ($valueCounts === []) {
                return [];
            }

            $availableValueIds = array_map('intval', array_keys($valueCounts));
            $options = Option::query()
                ->whereIn('id', $optionIds)
                ->where('is_active', true)
                ->with([
                    'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                    'values' => fn ($valueQuery) => $valueQuery
                        ->where('is_active', true)
                        ->whereIn('id', $availableValueIds)
                        ->with(['translations' => fn ($tq) => $tq->whereIn('locale', [$locale, $fallbackLocale])]),
                ])
                ->get()
                ->keyBy('id');

            $filters = [];
            foreach ($optionIds as $optionId) {
                /** @var Option|null $option */
                $option = $options->get($optionId);
                if (! $option) {
                    continue;
                }

                $optionTranslation = $option->translations->firstWhere('locale', $locale)
                    ?? $option->translations->firstWhere('locale', $fallbackLocale)
                    ?? $option->translations->first();
                $label = trim((string) ($optionTranslation?->name ?? $option->code));
                if ($label === '') {
                    $label = __('ui.shop.filters.size');
                }

                $kind = $this->catalogOptionFilterKind($option);
                $values = $option->values
                    ->map(function (OptionValue $value) use ($locale, $fallbackLocale, $valueCounts): array {
                        $valueTranslation = $value->translations->firstWhere('locale', $locale)
                            ?? $value->translations->firstWhere('locale', $fallbackLocale)
                            ?? $value->translations->first();
                        $valueLabel = trim((string) ($valueTranslation?->name ?? $value->code));

                        return [
                            'id' => (int) $value->id,
                            'label' => $valueLabel !== '' ? $valueLabel : (string) $value->code,
                            'count' => (int) ($valueCounts[(int) $value->id] ?? 0),
                            'sort_rank' => $this->catalogColorSortRank((string) $value->code, $valueLabel),
                            'swatch_image_url' => $this->catalogOptionValueSwatchImageUrl($value),
                        ];
                    })
                    ->filter(fn (array $value): bool => $value['count'] > 0)
                    ->when($kind === 'color', fn ($values) => $values->sortBy([
                        ['sort_rank', 'asc'],
                        ['label', 'asc'],
                        ['id', 'asc'],
                    ]))
                    ->map(function (array $value): array {
                        unset($value['sort_rank']);

                        return $value;
                    })
                    ->values()
                    ->all();

                if ($values === []) {
                    continue;
                }

                $filters[] = [
                    'label' => $label,
                    'query_key' => 'opt_'.$option->id,
                    'option_id' => (int) $option->id,
                    'kind' => $kind,
                    'values' => $values,
                ];
            }

            return $filters;
        });

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param  array<int, string>  $groupCodes
     * @return array<int, array{label:string,query_key:string,values:array<int, array{id:int,label:string}>}>
     */
    private function catalogAttributeFilters(
        string $locale,
        string $fallbackLocale,
        array $groupCodes,
        ?array $categoryScopeIds = null,
        ?int $manufacturerId = null,
        bool $availableOnly = false
    ): array {
        if ($groupCodes === []) {
            return [];
        }

        $scopeIds = $this->normalizedCatalogScopeIds($categoryScopeIds);
        if (($categoryScopeIds !== null && $scopeIds === []) || ($manufacturerId !== null && $manufacturerId <= 0)) {
            return [];
        }

        $categoryKey = $categoryScopeIds === null ? 'all' : sha1(implode(',', $scopeIds));
        $manufacturerKey = $manufacturerId === null ? 'all' : (string) $manufacturerId;
        $hideOutOfStock = $this->hideOutOfStockProducts() || $availableOnly;
        $cacheKey = sprintf(
            'front:catalog:attribute-filters:v4:%s:%s:%s:%s:%s:%s',
            $locale,
            $fallbackLocale,
            sha1(implode(',', $groupCodes)),
            $categoryKey,
            $manufacturerKey,
            $hideOutOfStock ? 'hide-oos' : 'all-stock'
        );

        $rows = Cache::remember($cacheKey, now()->addMinutes(10), function () use (
            $locale,
            $fallbackLocale,
            $groupCodes,
            $scopeIds,
            $manufacturerId,
            $hideOutOfStock
        ): array {
            $attributes = Attribute::query()
                ->whereIn('group_code', $groupCodes)
                ->where('is_active', true)
                ->whereHas('products', function ($productQuery) use ($scopeIds, $manufacturerId, $hideOutOfStock): void {
                    $productQuery->visibleOnStorefront($hideOutOfStock);
                    if ($manufacturerId !== null) {
                        $productQuery->where('products.manufacturer_id', $manufacturerId);
                    }
                    if ($scopeIds !== []) {
                        $productQuery->whereHas('categories', function ($categoryQuery) use ($scopeIds): void {
                            $categoryQuery
                                ->where('scope', Category::SCOPE_CATALOG)
                                ->currentlyVisible()
                                ->whereIn('categories.id', $scopeIds);
                        });
                    }
                })
                ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
                ->orderBy('group_code')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('group_code');

            $filters = [];
            foreach ($groupCodes as $groupCode) {
                $groupRows = $attributes->get($groupCode);
                if (! $groupRows || $groupRows->isEmpty()) {
                    continue;
                }

                $firstTranslation = $groupRows
                    ->flatMap(fn (Attribute $attribute) => $attribute->translations)
                    ->firstWhere('locale', $locale)
                    ?? $groupRows->flatMap(fn (Attribute $attribute) => $attribute->translations)->firstWhere('locale', $fallbackLocale)
                    ?? $groupRows->flatMap(fn (Attribute $attribute) => $attribute->translations)->first();

                $label = match ($groupCode) {
                    'sastav' => in_array(strtolower($locale), ['hr', 'hr-hr'], true) ? 'Sastav' : 'Composition',
                    'material' => in_array(strtolower($locale), ['hr', 'hr-hr'], true) ? 'Sastav' : 'Composition',
                    default => trim((string) ($firstTranslation?->group_name ?? '')),
                };

                if ($label === '') {
                    $label = ucfirst(str_replace('_', ' ', $groupCode));
                }

                $values = $groupRows
                    ->map(function (Attribute $attribute) use ($locale, $fallbackLocale): array {
                        $translation = $attribute->translations->firstWhere('locale', $locale)
                            ?? $attribute->translations->firstWhere('locale', $fallbackLocale)
                            ?? $attribute->translations->first();
                        $valueLabel = trim((string) ($translation?->name ?? $attribute->code));

                        return [
                            'id' => (int) $attribute->id,
                            'label' => $valueLabel !== '' ? $valueLabel : (string) $attribute->code,
                        ];
                    })
                    ->values()
                    ->all();

                if ($values === []) {
                    continue;
                }

                $filters[] = [
                    'label' => $label,
                    'query_key' => 'attr_'.Str::slug($groupCode, '_'),
                    'group_code' => (string) $groupCode,
                    'values' => $values,
                ];
            }

            return $filters;
        });

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array{label:string,query_key:string,values:array<int, array{id:int,label:string}>}>
     */
    private function legacySizeFallbackFilter(
        string $locale,
        string $fallbackLocale,
        ?array $categoryScopeIds = null,
        ?int $manufacturerId = null,
        bool $availableOnly = false
    ): array {
        $sizes = $this->cachedCatalogSizes(
            $locale,
            $fallbackLocale,
            $categoryScopeIds,
            $manufacturerId,
            $availableOnly
        );

        $values = $sizes
            ->map(function (OptionValue $size) use ($locale, $fallbackLocale): array {
                $translation = $size->translations->firstWhere('locale', $locale)
                    ?? $size->translations->firstWhere('locale', $fallbackLocale)
                    ?? $size->translations->first();

                return [
                    'id' => (int) $size->id,
                    'option_id' => (int) $size->option_id,
                    'label' => trim((string) ($translation?->name ?? $size->code)),
                ];
            })
            ->values()
            ->all();

        if ($values === []) {
            return [];
        }

        return [[
            'label' => __('ui.shop.filters.size'),
            'query_key' => 'size',
            'option_id' => (int) ($values[0]['option_id'] ?? 0),
            'kind' => 'default',
            'values' => $values,
        ]];
    }

    private function applyOptionValueFilter($query, int $optionValueId): void
    {
        $query->whereHas('optionValues', function ($optionQuery) use ($optionValueId): void {
            $optionQuery
                ->where('is_active', true)
                ->where(function ($sizeQuery) use ($optionValueId): void {
                    $sizeQuery
                        ->where('option_value_id', $optionValueId)
                        ->orWhere('parent_option_value_id', $optionValueId);
                });
        });
    }

    /**
     * @param  array<int, array{label:string,query_key:string,kind?:string,values:array<int, array{id:int,label:string,count?:int}>}>  $filters
     * @param  array<string, int|null>  $selectedMap
     * @return array<int, array{label:string,query_key:string,kind:string,selected:int|null,values:array<int, array{id:int,label:string,count?:int}>}>
     */
    private function withSelectedFilters(array $filters, array $selectedMap): array
    {
        return array_map(function (array $filter) use ($selectedMap): array {
            $queryKey = (string) $filter['query_key'];
            $selected = (int) ($selectedMap[$queryKey] ?? 0);

            return [
                'label' => (string) $filter['label'],
                'query_key' => $queryKey,
                'kind' => (string) ($filter['kind'] ?? 'default'),
                'selected' => $selected > 0 ? $selected : null,
                'values' => $filter['values'],
            ];
        }, $filters);
    }

    /**
     * Limit every facet to products matching all selections from the other facets.
     *
     * The availability calculation uses at most two aggregate queries regardless
     * of how many filter groups or values are configured.
     *
     * @param  array<int, array<string, mixed>>  $optionFilters
     * @param  array<int, array<string, mixed>>  $attributeFilters
     * @param  array<string, int|null>  $selectedOptionFilters
     * @param  array<string, int|null>  $selectedAttributeFilters
     * @param  array<int, int>|null  $categoryScopeIds
     * @return array{0:array<int, array<string, mixed>>,1:array<int, array<string, mixed>>}
     */
    private function refineCatalogFiltersForSelections(
        array $optionFilters,
        array $attributeFilters,
        array $selectedOptionFilters,
        array $selectedAttributeFilters,
        ?array $categoryScopeIds,
        ?int $manufacturerId,
        bool $availableOnly
    ): array {
        $selectedOptions = collect($optionFilters)
            ->map(function (array $filter) use ($selectedOptionFilters): ?array {
                $queryKey = (string) ($filter['query_key'] ?? '');
                $valueId = (int) ($selectedOptionFilters[$queryKey] ?? 0);
                if ($valueId <= 0) {
                    return null;
                }

                return [
                    'option_id' => (int) ($filter['option_id'] ?? 0),
                    'value_id' => $valueId,
                ];
            })
            ->filter()
            ->values()
            ->all();
        $selectedAttributes = collect($attributeFilters)
            ->map(function (array $filter) use ($selectedAttributeFilters): ?array {
                $queryKey = (string) ($filter['query_key'] ?? '');
                $valueId = (int) ($selectedAttributeFilters[$queryKey] ?? 0);
                $groupCode = trim((string) ($filter['group_code'] ?? ''));
                if ($valueId <= 0 || $groupCode === '') {
                    return null;
                }

                return [
                    'group_code' => $groupCode,
                    'value_id' => $valueId,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($selectedOptions === [] && $selectedAttributes === []) {
            return [$optionFilters, $attributeFilters];
        }

        $scopeIds = $this->normalizedCatalogScopeIds($categoryScopeIds);
        $candidateOptionIds = collect($optionFilters)
            ->flatMap(fn (array $filter): array => array_column((array) ($filter['values'] ?? []), 'id'))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $candidateAttributeIds = collect($attributeFilters)
            ->flatMap(fn (array $filter): array => array_column((array) ($filter['values'] ?? []), 'id'))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $hideOutOfStock = $this->hideOutOfStockProducts() || $availableOnly;
        $cachePayload = [
            'scope' => $categoryScopeIds === null ? null : $scopeIds,
            'manufacturer' => $manufacturerId,
            'hide_out_of_stock' => $hideOutOfStock,
            'candidate_options' => $candidateOptionIds,
            'candidate_attributes' => $candidateAttributeIds,
            'selected_options' => $selectedOptions,
            'selected_attributes' => $selectedAttributes,
        ];
        $cacheKey = 'front:catalog:refined-filter-availability:v1:'.sha1(
            json_encode($cachePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );

        $availability = Cache::remember($cacheKey, now()->addMinutes(10), function () use (
            $candidateOptionIds,
            $candidateAttributeIds,
            $selectedOptions,
            $selectedAttributes,
            $scopeIds,
            $manufacturerId,
            $hideOutOfStock
        ): array {
            $applyProductScope = function (QueryBuilder $query) use (
                $scopeIds,
                $manufacturerId,
                $hideOutOfStock
            ): void {
                $query->where('products.is_active', true);
                $this->applyProductStockVisibilityToBaseQuery($query, $hideOutOfStock);

                if ($manufacturerId !== null) {
                    $query->where('products.manufacturer_id', $manufacturerId);
                }

                if ($scopeIds !== []) {
                    $query
                        ->join('category_product as facet_category_product', 'facet_category_product.product_id', '=', 'products.id')
                        ->join('categories as facet_categories', 'facet_categories.id', '=', 'facet_category_product.category_id')
                        ->where('facet_categories.scope', Category::SCOPE_CATALOG)
                        ->where('facet_categories.is_active', true)
                        ->whereIn('facet_categories.id', $scopeIds);
                    $this->applyCategoryScheduleToBaseQuery($query, 'facet_categories');
                }
            };

            $optionCounts = [];
            if ($candidateOptionIds !== []) {
                $optionQuery = DB::table('catalog_option_values as facet_option_values')
                    ->join('catalog_product_option_values as facet_option_links', function ($join): void {
                        $join->on('facet_option_links.option_value_id', '=', 'facet_option_values.id')
                            ->where('facet_option_links.is_active', true);
                    })
                    ->join('products', 'products.id', '=', 'facet_option_links.product_id')
                    ->where('facet_option_values.is_active', true)
                    ->whereIn('facet_option_values.id', $candidateOptionIds);
                $applyProductScope($optionQuery);

                foreach ($selectedOptions as $selectedOption) {
                    $optionQuery->where(function (QueryBuilder $selectionQuery) use ($selectedOption): void {
                        $selectionQuery
                            ->where('facet_option_values.option_id', (int) $selectedOption['option_id'])
                            ->orWhereExists(function (QueryBuilder $existsQuery) use ($selectedOption): void {
                                $existsQuery
                                    ->selectRaw('1')
                                    ->from('catalog_product_option_values as selected_option_links')
                                    ->whereColumn('selected_option_links.product_id', 'products.id')
                                    ->where('selected_option_links.is_active', true)
                                    ->where(function (QueryBuilder $valueQuery) use ($selectedOption): void {
                                        $valueQuery
                                            ->where('selected_option_links.option_value_id', (int) $selectedOption['value_id'])
                                            ->orWhere('selected_option_links.parent_option_value_id', (int) $selectedOption['value_id']);
                                    });
                            });
                    });
                }

                foreach ($selectedAttributes as $selectedAttribute) {
                    $optionQuery->whereExists(function (QueryBuilder $existsQuery) use ($selectedAttribute): void {
                        $existsQuery
                            ->selectRaw('1')
                            ->from('catalog_attribute_product as selected_attribute_links')
                            ->whereColumn('selected_attribute_links.product_id', 'products.id')
                            ->where('selected_attribute_links.attribute_id', (int) $selectedAttribute['value_id']);
                    });
                }

                $optionCounts = $optionQuery
                    ->selectRaw('facet_option_values.id as value_id, COUNT(DISTINCT products.id) as product_count')
                    ->groupBy('facet_option_values.id')
                    ->pluck('product_count', 'value_id')
                    ->map(fn ($count): int => (int) $count)
                    ->all();
            }

            $attributeIds = [];
            if ($candidateAttributeIds !== []) {
                $attributeQuery = DB::table('catalog_attributes as facet_attributes')
                    ->join('catalog_attribute_product as facet_attribute_links', 'facet_attribute_links.attribute_id', '=', 'facet_attributes.id')
                    ->join('products', 'products.id', '=', 'facet_attribute_links.product_id')
                    ->where('facet_attributes.is_active', true)
                    ->whereIn('facet_attributes.id', $candidateAttributeIds);
                $applyProductScope($attributeQuery);

                foreach ($selectedOptions as $selectedOption) {
                    $attributeQuery->whereExists(function (QueryBuilder $existsQuery) use ($selectedOption): void {
                        $existsQuery
                            ->selectRaw('1')
                            ->from('catalog_product_option_values as selected_option_links')
                            ->whereColumn('selected_option_links.product_id', 'products.id')
                            ->where('selected_option_links.is_active', true)
                            ->where(function (QueryBuilder $valueQuery) use ($selectedOption): void {
                                $valueQuery
                                    ->where('selected_option_links.option_value_id', (int) $selectedOption['value_id'])
                                    ->orWhere('selected_option_links.parent_option_value_id', (int) $selectedOption['value_id']);
                            });
                    });
                }

                foreach ($selectedAttributes as $selectedAttribute) {
                    $attributeQuery->where(function (QueryBuilder $selectionQuery) use ($selectedAttribute): void {
                        $selectionQuery
                            ->where('facet_attributes.group_code', (string) $selectedAttribute['group_code'])
                            ->orWhereExists(function (QueryBuilder $existsQuery) use ($selectedAttribute): void {
                                $existsQuery
                                    ->selectRaw('1')
                                    ->from('catalog_attribute_product as selected_attribute_links')
                                    ->whereColumn('selected_attribute_links.product_id', 'products.id')
                                    ->where('selected_attribute_links.attribute_id', (int) $selectedAttribute['value_id']);
                            });
                    });
                }

                $attributeIds = $attributeQuery
                    ->select('facet_attributes.id')
                    ->distinct()
                    ->pluck('facet_attributes.id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
            }

            return [
                'option_counts' => $optionCounts,
                'attribute_ids' => $attributeIds,
            ];
        });

        $optionCounts = (array) ($availability['option_counts'] ?? []);
        $availableAttributeIds = array_fill_keys(
            array_map('intval', (array) ($availability['attribute_ids'] ?? [])),
            true
        );
        $optionFilters = collect($optionFilters)
            ->map(function (array $filter) use ($optionCounts): array {
                $filter['values'] = collect((array) ($filter['values'] ?? []))
                    ->filter(fn (array $value): bool => (int) ($optionCounts[(int) ($value['id'] ?? 0)] ?? 0) > 0)
                    ->map(function (array $value) use ($optionCounts): array {
                        $value['count'] = (int) ($optionCounts[(int) $value['id']] ?? 0);

                        return $value;
                    })
                    ->values()
                    ->all();

                return $filter;
            })
            ->filter(fn (array $filter): bool => $filter['values'] !== [])
            ->values()
            ->all();
        $attributeFilters = collect($attributeFilters)
            ->map(function (array $filter) use ($availableAttributeIds): array {
                $filter['values'] = collect((array) ($filter['values'] ?? []))
                    ->filter(fn (array $value): bool => isset($availableAttributeIds[(int) ($value['id'] ?? 0)]))
                    ->values()
                    ->all();

                return $filter;
            })
            ->filter(fn (array $filter): bool => $filter['values'] !== [])
            ->values()
            ->all();

        return [$optionFilters, $attributeFilters];
    }

    /**
     * @param  array<int, int>  $optionIds
     * @param  array<int, int>  $scopeIds
     * @return array<int, int>
     */
    private function catalogOptionValueProductCounts(
        array $optionIds,
        array $scopeIds,
        ?int $manufacturerId,
        bool $hideOutOfStock
    ): array {
        if ($optionIds === []) {
            return [];
        }

        $query = DB::table('catalog_option_values as option_values')
            ->join('catalog_product_option_values as product_option_values', function ($join): void {
                $join->on('product_option_values.option_value_id', '=', 'option_values.id')
                    ->where('product_option_values.is_active', true);
            })
            ->join('products', function ($join): void {
                $join->on('products.id', '=', 'product_option_values.product_id')
                    ->where('products.is_active', true);
            })
            ->whereIn('option_values.option_id', $optionIds)
            ->where('option_values.is_active', true);

        $this->applyProductStockVisibilityToBaseQuery($query, $hideOutOfStock);

        if ($manufacturerId !== null) {
            $query->where('products.manufacturer_id', $manufacturerId);
        }

        if ($scopeIds !== []) {
            $query
                ->join('category_product', 'category_product.product_id', '=', 'products.id')
                ->join('categories', 'categories.id', '=', 'category_product.category_id')
                ->where('categories.scope', Category::SCOPE_CATALOG)
                ->where('categories.is_active', true)
                ->whereIn('categories.id', $scopeIds);
            $this->applyCategoryScheduleToBaseQuery($query);
        }

        return $query
            ->selectRaw('option_values.id as option_value_id, COUNT(DISTINCT products.id) as product_count')
            ->groupBy('option_values.id')
            ->pluck('product_count', 'option_value_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    private function catalogOptionFilterKind(Option $option): string
    {
        $code = Str::lower(trim((string) $option->code));

        return Str::startsWith($code, ['color', 'colour', 'boja'])
            ? 'color'
            : 'default';
    }

    private function catalogOptionValueSwatchImageUrl(OptionValue $value): ?string
    {
        $path = trim((string) data_get($value->payload, 'swatch_image_path', ''));

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//', '/'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function cachedCatalogSizes(
        string $locale,
        string $fallbackLocale,
        ?array $categoryScopeIds = null,
        ?int $manufacturerId = null,
        bool $availableOnly = false
    ) {
        $scopeIds = $this->normalizedCatalogScopeIds($categoryScopeIds);
        if (($categoryScopeIds !== null && $scopeIds === []) || ($manufacturerId !== null && $manufacturerId <= 0)) {
            return collect();
        }

        $categoryKey = $categoryScopeIds === null ? 'all' : sha1(implode(',', $scopeIds));
        $manufacturerKey = $manufacturerId === null ? 'all' : (string) $manufacturerId;
        $hideOutOfStock = $this->hideOutOfStockProducts() || $availableOnly;
        $cacheKey = sprintf(
            'front:catalog:sizes:v4:%s:%s:%s:%s:%s',
            $locale,
            $fallbackLocale,
            $categoryKey,
            $manufacturerKey,
            $hideOutOfStock ? 'hide-oos' : 'all-stock'
        );

        return Cache::remember($cacheKey, now()->addMinutes(10), static function () use (
            $locale,
            $fallbackLocale,
            $scopeIds,
            $manufacturerId,
            $hideOutOfStock
        ) {
            return OptionValue::query()
                ->select(['id', 'option_id', 'code', 'sort_order'])
                ->where('is_active', true)
                ->whereHas('productOptionValues', function ($q) use ($scopeIds, $manufacturerId, $hideOutOfStock): void {
                    $q->where('is_active', true)
                        ->whereHas('product', function ($productQuery) use ($scopeIds, $manufacturerId, $hideOutOfStock): void {
                            $productQuery->visibleOnStorefront($hideOutOfStock);
                            if ($manufacturerId !== null) {
                                $productQuery->where('products.manufacturer_id', $manufacturerId);
                            }
                            if ($scopeIds !== []) {
                                $productQuery->whereHas('categories', function ($categoryQuery) use ($scopeIds): void {
                                    $categoryQuery
                                        ->where('scope', Category::SCOPE_CATALOG)
                                        ->currentlyVisible()
                                        ->whereIn('categories.id', $scopeIds);
                                });
                            }
                        });
                })
                ->with(['translations' => fn ($q) => $q
                    ->select(['id', 'option_value_id', 'locale', 'name'])
                    ->whereIn('locale', [$locale, $fallbackLocale])])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        });
    }

    private function productPerPage(Request $request, bool $manufacturerView): int
    {
        $variant = (string) $request->attributes->get('frontend_variant', 'desktop');
        $settings = app(SystemSettingsService::class);

        if ($manufacturerView) {
            return $settings->getInt(
                $variant === 'mobile'
                    ? 'front_manufacturer_products_per_page_mobile'
                    : 'front_manufacturer_products_per_page_desktop',
                (int) config(
                    $variant === 'mobile'
                        ? 'admin_ui.pagination.front_manufacturer_products_per_page_mobile'
                        : 'admin_ui.pagination.front_manufacturer_products_per_page_desktop',
                    12
                ),
                4,
                120
            );
        }

        return $settings->getInt(
            $variant === 'mobile'
                ? 'front_category_products_per_page_mobile'
                : 'front_category_products_per_page_desktop',
            (int) config(
                $variant === 'mobile'
                    ? 'admin_ui.pagination.front_category_products_per_page_mobile'
                    : 'admin_ui.pagination.front_category_products_per_page_desktop',
                12
            ),
            4,
            120
        );
    }

    private function shopPerPage(Request $request): int
    {
        return $this->productPerPage($request, false);
    }

    private function redirectWithoutCols(Request $request): RedirectResponse
    {
        $query = $request->query();
        unset($query['cols']);
        $target = $request->url();
        if ($query !== []) {
            $target .= '?'.http_build_query($query);
        }

        return redirect()->to($target);
    }

    private function applyProductSearch(Builder $query, string $locale, string $fallbackLocale, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $searchQuery) use ($locale, $fallbackLocale, $search): void {
            $searchQuery
                ->where('sku', 'like', '%'.$search.'%')
                ->orWhereHas('optionValues', function (Builder $optionValueQuery) use ($search): void {
                    $optionValueQuery
                        ->where('is_active', true)
                        ->where('sku', 'like', '%'.$search.'%');
                })
                ->orWhereHas('translations', function ($translationQuery) use ($locale, $fallbackLocale, $search): void {
                    $translationQuery
                        ->whereIn('locale', [$locale, $fallbackLocale])
                        ->where(function ($textQuery) use ($search): void {
                            $textQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('excerpt', 'like', '%'.$search.'%')
                                ->orWhere('description', 'like', '%'.$search.'%');
                        });
                });
        });
    }

    /**
     * @return array{0:?float,1:?float}
     */
    private function normalizedPriceRange(mixed $priceMinInput, mixed $priceMaxInput): array
    {
        $priceMin = $this->normalizePriceFilterValue($priceMinInput);
        $priceMax = $this->normalizePriceFilterValue($priceMaxInput);

        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            [$priceMin, $priceMax] = [$priceMax, $priceMin];
        }

        return [$priceMin, $priceMax];
    }

    private function normalizePriceFilterValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return max(0.0, round((float) $value, 2));
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $raw);
        if (! is_numeric($normalized)) {
            return null;
        }

        return max(0.0, round((float) $normalized, 2));
    }

    private function normalizeBooleanFilterValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function applyBasePriceFilter(Builder $query, ?float $priceMin, ?float $priceMax): void
    {
        if ($priceMin === null && $priceMax === null) {
            return;
        }

        $defaultRate = $this->defaultCatalogTaxRate();
        if ($this->usesStoredPriceColumn($defaultRate)) {
            if ($priceMin !== null) {
                $query->where('products.base_price', '>=', $priceMin);
            }

            if ($priceMax !== null) {
                $query->where('products.base_price', '<=', $priceMax);
            }

            return;
        }

        $expression = $this->displayedPriceSqlExpression($query);

        if ($priceMin !== null) {
            $query->whereRaw($expression.' >= ?', [$priceMin]);
        }

        if ($priceMax !== null) {
            $query->whereRaw($expression.' <= ?', [$priceMax]);
        }
    }

    private function applyPriceSort(Builder $query, string $direction = 'asc'): void
    {
        $defaultRate = $this->defaultCatalogTaxRate();
        if ($this->usesStoredPriceColumn($defaultRate)) {
            $query->orderBy('products.base_price', $direction === 'desc' ? 'desc' : 'asc');

            return;
        }

        $expression = $this->displayedPriceSqlExpression($query);
        $query->orderByRaw($expression.' '.($direction === 'desc' ? 'DESC' : 'ASC'));
    }

    /**
     * @param  array<int, int>  $categoryScopeIds
     */
    private function applyCategoryDefaultProductSort(Builder $query, array $categoryScopeIds, string $locale, string $fallbackLocale): void
    {
        $scopeIds = collect($categoryScopeIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($scopeIds !== []) {
            $categorySortSubquery = DB::table('category_product')
                ->selectRaw('product_id, MIN(sort_order) as category_sort_order')
                ->whereIn('category_id', $scopeIds)
                ->groupBy('product_id');

            $query->leftJoinSub($categorySortSubquery, 'category_product_sort', function ($join): void {
                $join->on('category_product_sort.product_id', '=', 'products.id');
            });
        }

        $colorSortSubquery = DB::table('catalog_product_option_values as product_colors')
            ->join('catalog_option_values as color_values', function ($join): void {
                $join->on('color_values.id', '=', 'product_colors.option_value_id')
                    ->orOn('color_values.id', '=', 'product_colors.parent_option_value_id');
            })
            ->join('catalog_options as color_options', 'color_options.id', '=', 'color_values.option_id')
            ->leftJoin('catalog_option_translations as color_option_translations', function ($join) use ($locale, $fallbackLocale): void {
                $join->on('color_option_translations.option_id', '=', 'color_options.id')
                    ->whereIn('color_option_translations.locale', [$locale, $fallbackLocale]);
            })
            ->leftJoin('catalog_option_value_translations as color_value_translations', function ($join) use ($locale, $fallbackLocale): void {
                $join->on('color_value_translations.option_value_id', '=', 'color_values.id')
                    ->whereIn('color_value_translations.locale', [$locale, $fallbackLocale]);
            })
            ->where('product_colors.is_active', true)
            ->where('color_values.is_active', true)
            ->where('color_options.is_active', true)
            ->where(function ($optionQuery): void {
                $optionText = $this->catalogNormalizedTextSql('color_options.code', 'color_option_translations.name');

                $optionQuery
                    ->whereRaw($optionText." LIKE 'color%'")
                    ->orWhereRaw($optionText." LIKE '% color%'")
                    ->orWhereRaw($optionText." LIKE 'colour%'")
                    ->orWhereRaw($optionText." LIKE '% colour%'")
                    ->orWhereRaw($optionText." LIKE 'boja%'")
                    ->orWhereRaw($optionText." LIKE '% boja%'");
            })
            ->selectRaw('product_colors.product_id, MIN('.$this->catalogColorSortCaseSql('color_values.code', 'color_value_translations.name').') as color_sort_order')
            ->groupBy('product_colors.product_id');

        $query
            ->leftJoinSub($colorSortSubquery, 'product_color_sort', function ($join): void {
                $join->on('product_color_sort.product_id', '=', 'products.id');
            })
            ->orderByRaw('COALESCE(product_color_sort.color_sort_order, 999) ASC');

        if ($scopeIds !== []) {
            $query->orderByRaw('COALESCE(category_product_sort.category_sort_order, 999999) ASC');
        }

        $query->orderByDesc('products.id');
    }

    private function catalogColorSortCaseSql(string $codeColumn, string $labelColumn): string
    {
        $text = $this->catalogNormalizedTextSql($codeColumn, $labelColumn);

        return "CASE
            WHEN {$text} LIKE '%karirano%' OR {$text} LIKE '%geometric%' OR {$text} LIKE '%squares%' OR {$text} LIKE '%web%' OR {$text} LIKE '%kokos%' OR {$text} LIKE '%flowers%' OR {$text} LIKE '%butterfly%' OR {$text} LIKE '%footprint%' OR {$text} LIKE '%roses%' OR {$text} LIKE '%stars%' OR {$text} LIKE '%uzor%' OR {$text} LIKE '%pattern%' THEN 70
            WHEN {$text} LIKE '%bijel%' OR {$text} LIKE '%white%' THEN 10
            WHEN {$text} LIKE '%boja-koze%' OR {$text} LIKE '%boja koze%' OR {$text} LIKE '%beige%' OR {$text} LIKE '%bez%' OR {$text} LIKE '%nude%' OR {$text} LIKE '%skin%' THEN 20
            WHEN {$text} LIKE '%siv%' OR {$text} LIKE '%gray%' OR {$text} LIKE '%grey%' THEN 30
            WHEN {$text} LIKE '%crven%' OR {$text} LIKE '%red%' THEN 40
            WHEN {$text} LIKE '%plav%' OR {$text} LIKE '%blue%' OR {$text} LIKE '%navy%' THEN 50
            WHEN {$text} LIKE '%crn%' OR {$text} LIKE '%black%' THEN 60
            ELSE 70
        END";
    }

    private function catalogNormalizedTextSql(string $codeColumn, string $labelColumn): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "LOWER(REPLACE(REPLACE(REPLACE(({$codeColumn} || ' ' || COALESCE({$labelColumn}, '')), '_', '-'), 'ž', 'z'), 'Ž', 'z'))";
        }

        return "LOWER(REPLACE(REPLACE(REPLACE(CONCAT_WS(' ', {$codeColumn}, COALESCE({$labelColumn}, '')), '_', '-'), 'ž', 'z'), 'Ž', 'z'))";
    }

    private function catalogColorSortRank(string $code, string $label = ''): int
    {
        $text = Str::of($code.' '.$label)
            ->ascii()
            ->lower()
            ->replace('_', '-')
            ->value();

        if (Str::contains($text, ['karirano', 'geometric', 'squares', 'web', 'kokos', 'flowers', 'butterfly', 'footprint', 'roses', 'stars', 'uzor', 'pattern'])) {
            return 70;
        }

        if (Str::contains($text, ['bijel', 'white'])) {
            return 10;
        }

        if (Str::contains($text, ['boja-koze', 'boja koze', 'beige', 'bez', 'nude', 'skin'])) {
            return 20;
        }

        if (Str::contains($text, ['siv', 'gray', 'grey'])) {
            return 30;
        }

        if (Str::contains($text, ['crven', 'red'])) {
            return 40;
        }

        if (Str::contains($text, ['plav', 'blue', 'navy'])) {
            return 50;
        }

        if (Str::contains($text, ['crn', 'black'])) {
            return 60;
        }

        return 70;
    }

    private function applyPromotionFilter(Builder $query, ?User $user): void
    {
        $promotionQuery = CatalogAction::query()
            ->selectRaw('1')
            ->active()
            ->where('scope', CatalogAction::SCOPE_PRODUCT)
            ->whereIn('type', [CatalogAction::TYPE_PERCENTAGE, CatalogAction::TYPE_FIXED])
            ->where('discount_value', '>', 0)
            ->availableForAudience($user)
            ->where(function (Builder $couponQuery): void {
                $couponQuery
                    ->whereNull('coupon_code')
                    ->orWhere('coupon_code', '');
            })
            ->where(function (Builder $matchQuery): void {
                $matchQuery
                    ->where('target_type', CatalogAction::TARGET_ALL)
                    ->orWhere(function (Builder $productActionQuery): void {
                        $productActionQuery
                            ->where('target_type', CatalogAction::TARGET_PRODUCT)
                            ->whereExists(function ($targetQuery): void {
                                $targetQuery
                                    ->selectRaw('1')
                                    ->from('catalog_action_targets')
                                    ->whereColumn('catalog_action_targets.action_id', 'catalog_actions.id')
                                    ->where('catalog_action_targets.target_type', CatalogAction::TARGET_PRODUCT)
                                    ->whereColumn('catalog_action_targets.target_id', 'products.id');
                            });
                    })
                    ->orWhere(function (Builder $categoryActionQuery): void {
                        $categoryActionQuery
                            ->where('target_type', CatalogAction::TARGET_CATEGORY)
                            ->whereExists(function ($targetQuery): void {
                                $targetQuery
                                    ->selectRaw('1')
                                    ->from('catalog_action_targets')
                                    ->join('category_product', 'category_product.category_id', '=', 'catalog_action_targets.target_id')
                                    ->whereColumn('catalog_action_targets.action_id', 'catalog_actions.id')
                                    ->where('catalog_action_targets.target_type', CatalogAction::TARGET_CATEGORY)
                                    ->whereColumn('category_product.product_id', 'products.id');
                            });
                    })
                    ->orWhere(function (Builder $manufacturerActionQuery): void {
                        $manufacturerActionQuery
                            ->where('target_type', CatalogAction::TARGET_MANUFACTURER)
                            ->whereExists(function ($targetQuery): void {
                                $targetQuery
                                    ->selectRaw('1')
                                    ->from('catalog_action_targets')
                                    ->whereColumn('catalog_action_targets.action_id', 'catalog_actions.id')
                                    ->where('catalog_action_targets.target_type', CatalogAction::TARGET_MANUFACTURER)
                                    ->whereColumn('catalog_action_targets.target_id', 'products.manufacturer_id');
                            });
                    });
            });

        $query->whereExists($promotionQuery->getQuery());
    }

    /**
     * @return array{min:?float,max:?float}
     */
    private function resolvePriceBounds(?Builder $query, ?float $fallbackMin = null, ?float $fallbackMax = null): array
    {
        $min = null;
        $max = null;

        if ($query !== null) {
            $defaultRate = $this->defaultCatalogTaxRate();
            $baseQuery = clone $query->getQuery();

            $minQuery = clone $baseQuery;
            if ($this->usesStoredPriceColumn($defaultRate)) {
                $resolvedMin = $minQuery->min('products.base_price');
            } else {
                $minExpression = $this->displayedPriceSqlExpression($minQuery);
                $resolvedMin = $minQuery->min(DB::raw($minExpression));
            }

            $maxQuery = clone $baseQuery;
            if ($this->usesStoredPriceColumn($defaultRate)) {
                $resolvedMax = $maxQuery->max('products.base_price');
            } else {
                $maxExpression = $this->displayedPriceSqlExpression($maxQuery);
                $resolvedMax = $maxQuery->max(DB::raw($maxExpression));
            }

            $min = is_numeric($resolvedMin) ? round((float) $resolvedMin, 2) : null;
            $max = is_numeric($resolvedMax) ? round((float) $resolvedMax, 2) : null;
        }

        return [
            'min' => $min ?? $fallbackMin,
            'max' => $max ?? $fallbackMax,
        ];
    }

    private function displayedPriceSqlExpression(Builder|QueryBuilder $query): string
    {
        $baseQuery = $query instanceof Builder ? $query->getQuery() : $query;
        $defaultRate = $this->defaultCatalogTaxRate();

        if ($this->usesStoredPriceColumn($defaultRate)) {
            return 'ROUND(CASE WHEN products.base_price < 0 THEN 0 ELSE products.base_price END, 2)';
        }

        $taxAlias = 'catalog_price_tax_rates';
        $joins = $baseQuery->joins ?? [];
        foreach ($joins as $join) {
            if (($join->table ?? null) === 'tax_rates as '.$taxAlias) {
                return $this->taxAdjustedPriceExpression($taxAlias, $defaultRate);
            }
        }

        $query->leftJoin('tax_rates as '.$taxAlias, function ($join) use ($taxAlias): void {
            $join->on('products.tax_rate_id', '=', $taxAlias.'.id')
                ->where($taxAlias.'.is_active', true);
        });

        return $this->taxAdjustedPriceExpression($taxAlias, $defaultRate);
    }

    private function taxAdjustedPriceExpression(string $taxAlias, object $defaultRate): string
    {
        $defaultRateType = (($defaultRate->rate_type ?? 'percent') === 'fixed') ? 'fixed' : 'percent';
        $defaultRateValue = round((float) ($defaultRate->rate ?? 0), 4);
        $fixedExpression = sprintf(
            'CASE WHEN (products.base_price + COALESCE(%s.rate, %F)) < 0 THEN 0 ELSE (products.base_price + COALESCE(%s.rate, %F)) END',
            $taxAlias,
            $defaultRateValue,
            $taxAlias,
            $defaultRateValue
        );
        $percentExpression = sprintf(
            'CASE WHEN (products.base_price * (1 + (COALESCE(%s.rate, %F) / 100))) < 0 THEN 0 ELSE (products.base_price * (1 + (COALESCE(%s.rate, %F) / 100))) END',
            $taxAlias,
            $defaultRateValue,
            $taxAlias,
            $defaultRateValue
        );

        return sprintf(
            "ROUND(CASE WHEN COALESCE(%s.rate_type, '%s') = 'fixed' THEN %s ELSE %s END, 2)",
            $taxAlias,
            $defaultRateType,
            $fixedExpression,
            $percentExpression
        );
    }

    private function defaultCatalogTaxRate(): ?object
    {
        return DB::table('tax_rates')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->select(['rate_type', 'rate'])
            ->first();
    }

    private function usesStoredPriceColumn(?object $defaultRate = null): bool
    {
        $pricesIncludeTax = (bool) filter_var(
            app(SystemSettingsService::class)->get('store_pricing_prices_include_tax', false),
            FILTER_VALIDATE_BOOL
        );

        return $pricesIncludeTax || ! $defaultRate;
    }

    private function withDesktopCacheHeaders(Request $request, Response $response, string $scope): Response
    {
        if ($this->frontendVariant($request) !== 'desktop') {
            return $response;
        }

        if (auth()->check()) {
            return $response->header('Cache-Control', 'private, no-cache, must-revalidate');
        }

        $lastModifiedTs = $this->catalogLastModifiedTimestamp();
        $etag = $this->catalogEtag($request, $scope, $lastModifiedTs);

        $response->header('Cache-Control', 'private, max-age=120, stale-while-revalidate=60');
        $response->setEtag($etag);
        if ($lastModifiedTs > 0) {
            $response->setLastModified(\Carbon\CarbonImmutable::createFromTimestampUTC($lastModifiedTs));
        }

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }

    private function catalogLastModifiedTimestamp(): int
    {
        return (int) Cache::remember('front:catalog:last-modified-ts', now()->addMinutes(2), static function (): int {
            $modelType = Product::class;

            $timestamps = [
                DB::table('products')->max('updated_at'),
                DB::table('product_translations')->max('updated_at'),
                DB::table('categories')->max('updated_at'),
                DB::table('category_translations')->max('updated_at'),
                DB::table('catalog_manufacturers')->max('updated_at'),
                DB::table('catalog_manufacturer_translations')->max('updated_at'),
                DB::table('catalog_option_values')->max('updated_at'),
                DB::table('catalog_option_value_translations')->max('updated_at'),
                DB::table('media')->where('model_type', $modelType)->max('updated_at'),
                DB::table('system_settings')
                    ->whereIn('key', ['catalog_hide_out_of_stock_products'])
                    ->max('updated_at'),
            ];

            $max = 0;
            foreach ($timestamps as $timestamp) {
                $unix = $timestamp ? strtotime((string) $timestamp) : 0;
                if ($unix > $max) {
                    $max = $unix;
                }
            }

            return $max;
        });
    }

    private function catalogEtag(Request $request, string $scope, int $lastModifiedTs): string
    {
        $wishlistHash = sha1(implode(',', app(WishlistService::class)->ids()));
        $gridCols = (string) $this->resolveGridCols($request, $this->defaultDesktopGridCols($request));

        return '"'.sha1(implode('|', [
            'desktop-catalog',
            $scope,
            app()->getLocale(),
            $request->getRequestUri(),
            $gridCols,
            $this->hideOutOfStockProducts() ? 'hide-oos' : 'all-stock',
            (string) $lastModifiedTs,
            (string) $this->catalogPresentationVersion(),
            $wishlistHash,
        ])).'"';
    }

    private function catalogPresentationVersion(): int
    {
        return collect([
            resource_path('views/front/desktop/catalog/index.blade.php'),
            resource_path('views/front/desktop/partials/product-card.blade.php'),
            resource_path('views/components/front/desktop/product-card.blade.php'),
            app_path('View/Components/Front/Desktop/ProductCard.php'),
            public_path('front-theme/styles/category-catalog.css'),
            public_path('front-theme/scripts/category-catalog.js'),
        ])->reduce(
            static fn (int $latest, string $path): int => max($latest, is_file($path) ? (int) filemtime($path) : 0),
            0
        );
    }
}
