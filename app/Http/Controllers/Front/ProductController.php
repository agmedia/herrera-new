<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Http\Controllers\Front\Concerns\ResolvesGridColumns;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Comment;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User\UserProfile;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\ProductColorVariantService;
use App\Services\Front\WishlistService;
use App\Services\Payments\CorvusPayFormService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Pricing\TaxPricingService;
use App\Support\ProductMaterialLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    use ResolvesFrontendView;
    use ResolvesGridColumns;

    private const FIT_FINDER_SESSION_KEY = 'front_fit_finder_profile';

    private const FIT_FINDER_COOKIE_KEY = 'front_fit_finder_profile';

    private const FIT_FINDER_COOKIE_MINUTES = 525600; // 365 days

    private const RECENTLY_VIEWED_SESSION_KEY = 'front_recently_viewed_products';

    private const RECENTLY_VIEWED_MAX = 24;

    private ?bool $hideOutOfStockProductsCache = null;

    public function storeComment(Request $request, string $slug)
    {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');

        $product = Product::query()
            ->where('is_active', true)
            ->whereHas('translations', function ($q) use ($locale, $fallbackLocale, $slug): void {
                $q->whereIn('locale', [$locale, $fallbackLocale])
                    ->where('slug', $slug);
            })
            ->firstOrFail();

        $user = $request->user();

        $rules = [
            'body' => ['required', 'string', 'min:3', 'max:2000'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];

        if (! $user) {
            $rules['author_name'] = ['required', 'string', 'max:120'];
            $rules['author_email'] = ['required', 'email', 'max:190'];
        } else {
            $rules['author_name'] = ['nullable', 'string', 'max:120'];
            $rules['author_email'] = ['nullable', 'email', 'max:190'];
        }

        $validated = $request->validate($rules, [
            'author_name.required' => __('ui.product.comment_form.validation.name_required'),
            'author_email.required' => __('ui.product.comment_form.validation.email_required'),
            'author_email.email' => __('ui.product.comment_form.validation.email_invalid'),
            'body.required' => __('ui.product.comment_form.validation.body_required'),
        ]);

        $product->comments()->create([
            'user_id' => $user?->id,
            'parent_id' => null,
            'author_name' => $user?->name ?: trim((string) ($validated['author_name'] ?? '')),
            'author_email' => $user?->email ?: trim((string) ($validated['author_email'] ?? '')),
            'locale' => $locale,
            'body' => trim((string) $validated['body']),
            'rating' => isset($validated['rating']) ? (int) $validated['rating'] : null,
            'status' => Comment::STATUS_PENDING,
            'is_featured' => false,
        ]);

        return redirect()
            ->to(route('products.show', ['slug' => $slug]).'#product-comments')
            ->with('success', __('ui.product.comment_form.status_submitted'));
    }

    public function show(Request $request, string $slug): Response
    {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $variant = $this->frontendVariant($request);

        $product = Product::query()
            ->withStorefrontEnergyData()
            ->select(['id', 'code', 'sku', 'base_price', 'stock_qty', 'tax_rate_id', 'manufacturer_id', 'is_active', 'payload'])
            ->withApprovedCommentSummary([$locale, $fallbackLocale])
            ->where('is_active', true)
            ->whereHas('translations', function ($q) use ($locale, $fallbackLocale, $slug): void {
                $q->whereIn('locale', [$locale, $fallbackLocale])
                    ->where('slug', $slug);
            })
            ->with([
                'taxRate:id,rate,rate_type,is_active',
                'media' => fn ($q) => $q
                    ->whereIn('collection_name', ['product_main', 'product_gallery'])
                    ->orderBy('order_column')
                    ->orderBy('id'),
                'translations' => fn ($q) => $q
                    ->select(['id', 'product_id', 'locale', 'slug', 'name', 'excerpt', 'description', 'payload'])
                    ->whereIn('locale', [$locale, $fallbackLocale]),
                'categories.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'manufacturer.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'attributes' => fn ($q) => $q
                    ->where('catalog_attributes.is_active', true)
                    ->orderBy('catalog_attribute_product.sort_order')
                    ->orderBy('catalog_attributes.sort_order')
                    ->orderBy('catalog_attributes.id')
                    ->with([
                        'translations' => fn ($tq) => $tq
                            ->select(['id', 'attribute_id', 'locale', 'group_name', 'name'])
                            ->whereIn('locale', [$locale, $fallbackLocale]),
                    ]),
                'technicalSpecificationRows' => fn ($q) => $q->select([
                    'id',
                    'product_id',
                    'group_name',
                    'item_name',
                    'values',
                    'measure',
                    'sort_order',
                ]),
                'optionValues' => fn ($q) => $q
                    ->select(['id', 'product_id', 'option_value_id', 'parent_option_value_id', 'sku', 'stock_qty', 'price_override', 'is_active', 'sort_order'])
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with([
                        'optionValue.option' => fn ($optionQuery) => $optionQuery
                            ->select(['id', 'code', 'payload'])
                            ->with([
                                'translations' => fn ($oq) => $oq
                                    ->select(['id', 'option_id', 'locale', 'name'])
                                    ->whereIn('locale', [$locale, $fallbackLocale]),
                            ]),
                        'optionValue.translations' => fn ($tq) => $tq
                            ->select(['id', 'option_value_id', 'locale', 'name'])
                            ->whereIn('locale', [$locale, $fallbackLocale]),
                        'parentOptionValue.option' => fn ($optionQuery) => $optionQuery
                            ->select(['id', 'code', 'payload'])
                            ->with([
                                'translations' => fn ($oq) => $oq
                                    ->select(['id', 'option_id', 'locale', 'name'])
                                    ->whereIn('locale', [$locale, $fallbackLocale]),
                            ]),
                        'parentOptionValue.translations' => fn ($tq) => $tq
                            ->select(['id', 'option_value_id', 'locale', 'name'])
                            ->whereIn('locale', [$locale, $fallbackLocale]),
                    ]),
            ])
            ->firstOrFail();

        $primaryCategory = $product->categories
            ->first(fn (Category $category): bool => (bool) ($category->pivot?->is_primary ?? false))
            ?? $product->categories
                ->sortBy(fn (Category $category): int => (int) ($category->pivot?->sort_order ?? 0))
                ->first();
        $productBreadcrumbCategories = collect();

        if ($primaryCategory) {
            $productBreadcrumbCategories = $primaryCategory->ancestors()
                ->where('scope', Category::SCOPE_CATALOG)
                ->currentlyVisible()
                ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
                ->orderBy('_lft')
                ->get()
                ->push($primaryCategory);
        }

        $categoryIds = $product->categories->pluck('id')->map(fn ($id) => (int) $id)->all();
        $relatedLimit = max(4, $this->resolveGridCols($request, $this->defaultDesktopGridCols($request)));

        $relatedBaseQuery = Product::query()
            ->withStorefrontEnergyData()
            ->select(['id', 'code', 'sku', 'base_price', 'stock_qty', 'tax_rate_id', 'manufacturer_id', 'is_active'])
            ->withApprovedCommentSummary([$locale, $fallbackLocale])
            ->visibleOnStorefront($this->hideOutOfStockProducts())
            ->where('id', '!=', $product->id)
            ->with([
                'taxRate:id,rate,rate_type,is_active',
                'media' => fn ($q) => $q
                    ->whereIn('collection_name', ['product_main', 'product_gallery'])
                    ->orderBy('order_column')
                    ->orderBy('id'),
                'translations' => fn ($q) => $q
                    ->select(['id', 'product_id', 'locale', 'slug', 'name', 'excerpt'])
                    ->whereIn('locale', [$locale, $fallbackLocale]),
                'manufacturer.translations' => fn ($q) => $q
                    ->select(['id', 'manufacturer_id', 'locale', 'slug', 'name'])
                    ->whereIn('locale', [$locale, $fallbackLocale]),
                'attributes' => ProductMaterialLabel::eagerLoadAttributes($locale, $fallbackLocale),
                'optionValues' => fn ($q) => $q
                    ->select(['id', 'product_id', 'option_value_id', 'parent_option_value_id', 'sku', 'stock_qty', 'price_override', 'is_active', 'sort_order'])
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with([
                        'optionValue.option' => fn ($optionQuery) => $optionQuery
                            ->select(['id', 'code', 'payload'])
                            ->with([
                                'translations' => fn ($oq) => $oq
                                    ->select(['id', 'option_id', 'locale', 'name'])
                                    ->whereIn('locale', [$locale, $fallbackLocale]),
                            ]),
                        'optionValue.translations' => fn ($tq) => $tq
                            ->select(['id', 'option_value_id', 'locale', 'name'])
                            ->whereIn('locale', [$locale, $fallbackLocale]),
                        'parentOptionValue.option' => fn ($optionQuery) => $optionQuery
                            ->select(['id', 'code', 'payload'])
                            ->with([
                                'translations' => fn ($oq) => $oq
                                    ->select(['id', 'option_id', 'locale', 'name'])
                                    ->whereIn('locale', [$locale, $fallbackLocale]),
                            ]),
                        'parentOptionValue.translations' => fn ($tq) => $tq
                            ->select(['id', 'option_value_id', 'locale', 'name'])
                            ->whereIn('locale', [$locale, $fallbackLocale]),
                    ]),
            ])
            ->orderByDesc('id');

        $related = collect();
        $excludeIds = [(int) $product->id];

        if ($categoryIds !== []) {
            $productCategories = Category::query()
                ->select(['id', 'parent_id', '_lft', '_rgt'])
                ->where('scope', Category::SCOPE_CATALOG)
                ->currentlyVisible()
                ->whereIn('id', $categoryIds)
                ->withDepth()
                ->get();

            $maxDepth = (int) $productCategories->max('depth');
            $deepestCategories = $productCategories
                ->filter(static fn (Category $category): bool => (int) ($category->depth ?? 0) === $maxDepth)
                ->values();
            $deepestCategoryIds = $deepestCategories->pluck('id')->map(fn ($id): int => (int) $id)->all();

            if ($deepestCategoryIds !== []) {
                $sameSubcategory = (clone $relatedBaseQuery)
                    ->whereHas('categories', fn ($categoryQuery) => $categoryQuery->whereIn('categories.id', $deepestCategoryIds))
                    ->limit($relatedLimit)
                    ->get();

                $related = $related->concat($sameSubcategory);
                $excludeIds = array_values(array_unique(array_merge(
                    $excludeIds,
                    $sameSubcategory->pluck('id')->map(fn ($id): int => (int) $id)->all()
                )));
            }

            if ($related->count() < $relatedLimit) {
                $rootCategories = Category::query()
                    ->select(['id', '_lft', '_rgt'])
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->currentlyVisible()
                    ->whereNull('parent_id')
                    ->orderBy('_lft')
                    ->get();

                $rootBoundaries = [];
                foreach ($deepestCategories as $deepestCategory) {
                    /** @var Category|null $root */
                    $root = $rootCategories->first(static fn (Category $candidate): bool => (int) $candidate->_lft <= (int) $deepestCategory->_lft
                        && (int) $candidate->_rgt >= (int) $deepestCategory->_rgt);
                    if (! $root) {
                        continue;
                    }

                    $rootBoundaries[(int) $root->id] = [
                        'lft' => (int) $root->_lft,
                        'rgt' => (int) $root->_rgt,
                    ];
                }

                if ($rootBoundaries !== []) {
                    $fallback = (clone $relatedBaseQuery)
                        ->whereNotIn('id', $excludeIds)
                        ->whereHas('categories', function ($categoryQuery) use ($rootBoundaries): void {
                            $categoryQuery->where(function ($or) use ($rootBoundaries): void {
                                foreach ($rootBoundaries as $boundary) {
                                    $or->orWhere(function ($nested) use ($boundary): void {
                                        $nested
                                            ->where('categories._lft', '>=', (int) $boundary['lft'])
                                            ->where('categories._rgt', '<=', (int) $boundary['rgt']);
                                    });
                                }
                            });
                        })
                        ->limit($relatedLimit - $related->count())
                        ->get();

                    $related = $related->concat($fallback);
                    $excludeIds = array_values(array_unique(array_merge(
                        $excludeIds,
                        $fallback->pluck('id')->map(fn ($id): int => (int) $id)->all()
                    )));
                }
            }
        }

        if ($related->count() < $relatedLimit) {
            $latestFallback = (clone $relatedBaseQuery)
                ->whereNotIn('id', $excludeIds)
                ->limit($relatedLimit - $related->count())
                ->get();
            $related = $related->concat($latestFallback);
        }

        $related = $related->take($relatedLimit)->values();
        $recentlyViewedIds = collect((array) $request->session()->get(self::RECENTLY_VIEWED_SESSION_KEY, []))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->reject(fn (int $id): bool => $id === (int) $product->id)
            ->values();

        $recentlyViewed = collect();
        if ($recentlyViewedIds->isNotEmpty()) {
            $recentlyViewedLookupIds = $recentlyViewedIds
                ->take(12)
                ->values()
                ->all();

            $recentlyViewed = (clone $relatedBaseQuery)
                ->whereIn('id', $recentlyViewedLookupIds)
                ->get()
                ->sortBy(function (Product $row) use ($recentlyViewedLookupIds): int {
                    $position = array_search((int) $row->id, $recentlyViewedLookupIds, true);

                    return $position === false ? PHP_INT_MAX : (int) $position;
                })
                ->values();
        }

        $updatedRecentlyViewedIds = collect([(int) $product->id])
            ->concat($recentlyViewedIds)
            ->unique()
            ->take(self::RECENTLY_VIEWED_MAX)
            ->values()
            ->all();
        $request->session()->put(self::RECENTLY_VIEWED_SESSION_KEY, $updatedRecentlyViewedIds);

        $topBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'product.top',
            locale: $locale,
            targetType: 'product',
            targetRef: $slug,
            frontendVariant: $variant
        );

        $bottomBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'product.bottom',
            locale: $locale,
            targetType: 'product',
            targetRef: $slug,
            frontendVariant: $variant
        );

        $comments = $product->comments()
            ->whereNull('parent_id')
            ->status(Comment::STATUS_APPROVED)
            ->whereIn('locale', [$locale, $fallbackLocale])
            ->with('user:id,name')
            ->orderByDesc('is_featured')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $sizeGuide = $this->resolveSizeGuide($product, $locale, $fallbackLocale);
        $fitFinderSelection = $this->resolveFitFinderSelection($request, $product);
        $colorVariants = app(ProductColorVariantService::class)->variantsFor($product, $locale, $fallbackLocale);
        $shippingMethods = ShippingMethod::query()
            ->select(['id', 'code', 'name', 'description', 'pricing_type', 'price', 'free_over', 'sort_order'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $paymentMethods = PaymentMethod::query()
            ->select(['id', 'code', 'name', 'provider', 'description', 'fee_type', 'fee_value', 'settings', 'sort_order'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (PaymentMethod $method): bool => app(CorvusPayFormService::class)->canBeOffered($method))
            ->reject(fn (PaymentMethod $method): bool => in_array(
                strtolower(trim((string) $method->code)),
                ['quote_request', 'manual_quote'],
                true,
            ) || in_array(
                strtolower(trim((string) $method->provider)),
                ['quote_request', 'manual_quote'],
                true,
            ))
            ->values();
        $taxRate = app(TaxPricingService::class)->resolveRateForProduct($product);

        $response = response()->view($this->frontendView($request, 'products.show'), [
            'product' => $product,
            'related' => $related,
            'recentlyViewed' => $recentlyViewed,
            'comments' => $comments,
            'sizeGuide' => $sizeGuide,
            'fitFinderSelection' => $fitFinderSelection,
            'colorVariants' => $colorVariants,
            'shippingMethods' => $shippingMethods,
            'paymentMethods' => $paymentMethods,
            'productBreadcrumbCategories' => $productBreadcrumbCategories,
            'topBlocks' => $topBlocks,
            'bottomBlocks' => $bottomBlocks,
            'pricePresentation' => app(ProductPricePresentationService::class)->forProduct($product, auth()->user()),
            'vatRate' => $taxRate && $taxRate->rate_type === 'percent'
                ? max(0.0, (float) $taxRate->rate)
                : null,
            'locale' => $locale,
            'fallbackLocale' => $fallbackLocale,
        ]);

        return $this->withDesktopCacheHeaders($request, $response, (int) $product->id, $slug);
    }

    public function storeFitFinderPreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'size_label' => ['nullable', 'string', 'max:30'],
            'size_signature' => ['nullable', 'string', 'max:255'],
            'height' => ['nullable', 'integer', 'min:130', 'max:230'],
            'weight' => ['nullable', 'integer', 'min:35', 'max:220'],
            'age' => ['nullable', 'integer', 'min:12', 'max:100'],
            'fit' => ['nullable', 'string', 'in:tighter,average,looser'],
            'chest' => ['nullable', 'string', 'in:slimmer,average,broader'],
            'belly' => ['nullable', 'string', 'in:flatter,average,rounder'],
        ]);

        $selection = [
            'product_id' => (int) $validated['product_id'],
            'size_label' => trim((string) ($validated['size_label'] ?? '')),
            'size_signature' => trim((string) ($validated['size_signature'] ?? '')),
            'height' => isset($validated['height']) ? (int) $validated['height'] : null,
            'weight' => isset($validated['weight']) ? (int) $validated['weight'] : null,
            'age' => isset($validated['age']) ? (int) $validated['age'] : null,
            'fit' => trim((string) ($validated['fit'] ?? 'average')),
            'chest' => trim((string) ($validated['chest'] ?? 'average')),
            'belly' => trim((string) ($validated['belly'] ?? 'average')),
            'updated_at' => now()->toIso8601String(),
        ];

        $request->session()->put(self::FIT_FINDER_SESSION_KEY, $selection);

        if ($request->user()) {
            $profile = UserProfile::query()->firstOrCreate(['user_id' => (int) $request->user()->id]);
            $payload = is_array($profile->payload) ? $profile->payload : [];
            $payload['fit_finder'] = $selection;

            $profile->forceFill(['payload' => $payload])->save();
        }

        return response()
            ->json(['ok' => true])
            ->cookie(
                self::FIT_FINDER_COOKIE_KEY,
                json_encode($selection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                self::FIT_FINDER_COOKIE_MINUTES
            );
    }

    private function hideOutOfStockProducts(): bool
    {
        if ($this->hideOutOfStockProductsCache === null) {
            $this->hideOutOfStockProductsCache = app(CatalogFeatureService::class)->hideOutOfStockProducts();
        }

        return $this->hideOutOfStockProductsCache;
    }

    private function withDesktopCacheHeaders(Request $request, Response $response, int $productId, string $slug): Response
    {
        if ($this->frontendVariant($request) !== 'desktop') {
            return $response;
        }

        if (auth()->check()) {
            return $response->header('Cache-Control', 'private, no-cache, must-revalidate');
        }

        $lastModifiedTs = $this->productLastModifiedTimestamp($productId);
        $etag = $this->productEtag($request, $slug, $productId, $lastModifiedTs);

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

    /**
     * @return array{code: string, title: string, body_html: string}|null
     */
    private function resolveSizeGuide(Product $product, string $locale, string $fallbackLocale): ?array
    {
        $localeTranslation = $product->translations->firstWhere('locale', $locale);
        $fallbackTranslation = $product->translations->firstWhere('locale', $fallbackLocale);
        $sizeGuideCode = trim((string) (
            data_get($localeTranslation?->payload, 'size_guide_code')
            ?: data_get($fallbackTranslation?->payload, 'size_guide_code')
            ?: data_get($product->payload, 'size_guide_code')
            ?: $this->defaultSizeGuideCode($product, $locale, $fallbackLocale)
        ));

        if ($sizeGuideCode === '') {
            return null;
        }

        $candidateCodes = collect([$sizeGuideCode]);
        if ($sizeGuideCode !== 'size-guide-women') {
            $candidateCodes->push('size-guide-women');
        }

        if ($sizeGuideCode !== 'size-guide-man') {
            $candidateCodes->push('size-guide-man');
        }

        $page = InfoPage::query()
            ->whereIn('code', $candidateCodes->all())
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
            ->get()
            ->sortBy(function (InfoPage $page) use ($candidateCodes): int {
                $index = $candidateCodes->search((string) $page->code);

                return $index === false ? PHP_INT_MAX : (int) $index;
            })
            ->first();

        if (! $page) {
            return null;
        }

        $translation = $page->translations->firstWhere('locale', $locale)
            ?? $page->translations->firstWhere('locale', $fallbackLocale)
            ?? $page->translations->first();

        $bodyHtml = trim((string) ($translation?->body_html ?? ''));
        if ($bodyHtml === '') {
            return null;
        }

        return [
            'code' => (string) $page->code,
            'title' => trim((string) ($translation?->title ?? __('ui.product.size_guide'))),
            'body_html' => $bodyHtml,
        ];
    }

    private function defaultSizeGuideCode(Product $product, string $locale, string $fallbackLocale): string
    {
        return $this->isMaleProduct($product, $locale, $fallbackLocale)
            ? 'size-guide-man'
            : 'size-guide-women';
    }

    private function isMaleProduct(Product $product, string $locale, string $fallbackLocale): bool
    {
        $localeTranslation = $product->translations->firstWhere('locale', $locale);
        $fallbackTranslation = $product->translations->firstWhere('locale', $fallbackLocale);

        if ($this->payloadSuggestsMale($localeTranslation?->payload)
            || $this->payloadSuggestsMale($fallbackTranslation?->payload)
            || $this->payloadSuggestsMale($product->payload)) {
            return true;
        }

        $tokens = ['men', 'man', 'male', 'muskar', 'muski', 'muški'];
        foreach ($product->categories as $category) {
            foreach ($category->translations ?? [] as $categoryTranslation) {
                $slug = (string) ($categoryTranslation->slug ?? '');
                $name = (string) ($categoryTranslation->name ?? '');
                $value = Str::lower($slug.' '.$name);
                if ($value !== '' && Str::contains($value, $tokens)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function payloadSuggestsMale($payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        $tokens = ['men', 'man', 'male', 'muskar', 'muski', 'muški'];
        $candidates = [
            data_get($payload, 'gender'),
            data_get($payload, 'gender_code'),
            data_get($payload, 'target_gender'),
            data_get($payload, 'audience'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value === '') {
                continue;
            }

            if (Str::contains(Str::lower($value), $tokens)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{product_id:int,size_label:string,height:int|null,weight:int|null,age:int|null,fit:string,chest:string,belly:string,updated_at:string}|null
     */
    private function resolveFitFinderSelection(Request $request, Product $product): ?array
    {
        $sessionSelection = $request->session()->get(self::FIT_FINDER_SESSION_KEY);
        if (is_array($sessionSelection)
            && (int) ($sessionSelection['product_id'] ?? 0) === (int) $product->id) {
            return $this->normalizeFitFinderSelection($sessionSelection);
        }

        $cookieSelectionRaw = $request->cookie(self::FIT_FINDER_COOKIE_KEY);
        $cookieSelection = is_string($cookieSelectionRaw)
            ? json_decode($cookieSelectionRaw, true)
            : null;

        $user = $request->user();
        if (! $user) {
            if (! is_array($cookieSelection)
                || (int) ($cookieSelection['product_id'] ?? 0) !== (int) $product->id) {
                if (is_array($sessionSelection)) {
                    return $this->normalizeFitFinderSelection($sessionSelection);
                }

                if (is_array($cookieSelection)) {
                    return $this->normalizeFitFinderSelection($cookieSelection);
                }

                return null;
            }

            return $this->normalizeFitFinderSelection($cookieSelection);
        }

        $profile = $user->relationLoaded('profile') ? $user->profile : $user->profile()->first();
        $profilePayload = is_array($profile?->payload) ? $profile->payload : [];
        $selection = is_array($profilePayload['fit_finder'] ?? null)
            ? $profilePayload['fit_finder']
            : null;

        if (! is_array($selection)
            || (int) ($selection['product_id'] ?? 0) !== (int) $product->id) {
            if (is_array($sessionSelection)) {
                return $this->normalizeFitFinderSelection($sessionSelection);
            }

            if (is_array($selection)) {
                return $this->normalizeFitFinderSelection($selection);
            }

            if (is_array($cookieSelection)) {
                return $this->normalizeFitFinderSelection($cookieSelection);
            }

            return null;
        }

        return $this->normalizeFitFinderSelection($selection);
    }

    /**
     * @param  array<string,mixed>  $selection
     * @return array{product_id:int,size_label:string,size_signature:string,height:int|null,weight:int|null,age:int|null,fit:string,chest:string,belly:string,updated_at:string}
     */
    private function normalizeFitFinderSelection(array $selection): array
    {
        return [
            'product_id' => (int) $selection['product_id'],
            'size_label' => trim((string) ($selection['size_label'] ?? '')),
            'size_signature' => trim((string) ($selection['size_signature'] ?? '')),
            'height' => isset($selection['height']) ? (int) $selection['height'] : null,
            'weight' => isset($selection['weight']) ? (int) $selection['weight'] : null,
            'age' => isset($selection['age']) ? (int) $selection['age'] : null,
            'fit' => trim((string) ($selection['fit'] ?? 'average')),
            'chest' => trim((string) ($selection['chest'] ?? 'average')),
            'belly' => trim((string) ($selection['belly'] ?? 'average')),
            'updated_at' => trim((string) ($selection['updated_at'] ?? now()->toIso8601String())),
        ];
    }

    private function productLastModifiedTimestamp(int $productId): int
    {
        return (int) Cache::remember('front:product:last-modified:'.$productId, now()->addMinutes(2), static function () use ($productId): int {
            $modelType = Product::class;
            $timestamps = [
                DB::table('products')->where('id', $productId)->max('updated_at'),
                DB::table('product_translations')->where('product_id', $productId)->max('updated_at'),
                DB::table('catalog_product_option_values')->where('product_id', $productId)->max('updated_at'),
                DB::table('media')->where('model_type', $modelType)->where('model_id', $productId)->max('updated_at'),
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

    private function productEtag(Request $request, string $slug, int $productId, int $lastModifiedTs): string
    {
        $wishlistHash = sha1(implode(',', app(WishlistService::class)->ids()));
        $fitFinderHash = $this->fitFinderEtagFragment($request);
        $gridCols = (string) $this->resolveGridCols($request, $this->defaultDesktopGridCols($request));

        return '"'.sha1(implode('|', [
            'desktop-product',
            $slug,
            (string) $productId,
            app()->getLocale(),
            $request->getRequestUri(),
            $gridCols,
            $this->hideOutOfStockProducts() ? 'hide-oos' : 'all-stock',
            (string) $lastModifiedTs,
            (string) $this->productPresentationVersion(),
            $wishlistHash,
            $fitFinderHash,
        ])).'"';
    }

    private function productPresentationVersion(): int
    {
        return collect([
            resource_path('views/front/desktop/products/show.blade.php'),
            resource_path('views/components/front/desktop/product-card.blade.php'),
            resource_path('views/front/partials/cart-modal-script.blade.php'),
            public_path('front-theme/styles/product-detail.css'),
            public_path('front-theme/styles/termol-overrides.css'),
            public_path('front-theme/scripts/cart-modal.js'),
            public_path('front-theme/scripts/product-detail.js'),
            public_path('front-theme/scripts/product-card-cart-modal.js'),
            public_path('front-theme/scripts/product-page.js'),
        ])->reduce(
            static fn (int $latest, string $path): int => max($latest, is_file($path) ? (int) filemtime($path) : 0),
            0
        );
    }

    private function fitFinderEtagFragment(Request $request): string
    {
        $selection = $request->session()->get(self::FIT_FINDER_SESSION_KEY);
        if (! is_array($selection)) {
            $cookieSelectionRaw = $request->cookie(self::FIT_FINDER_COOKIE_KEY);
            $selection = is_string($cookieSelectionRaw)
                ? json_decode($cookieSelectionRaw, true)
                : null;
        }

        if (! is_array($selection)) {
            return 'fit:none';
        }

        return sha1(json_encode([
            'product_id' => (int) ($selection['product_id'] ?? 0),
            'size_label' => trim((string) ($selection['size_label'] ?? '')),
            'size_signature' => trim((string) ($selection['size_signature'] ?? '')),
            'height' => isset($selection['height']) ? (int) $selection['height'] : null,
            'weight' => isset($selection['weight']) ? (int) $selection['weight'] : null,
            'age' => isset($selection['age']) ? (int) $selection['age'] : null,
            'fit' => trim((string) ($selection['fit'] ?? '')),
            'chest' => trim((string) ($selection['chest'] ?? '')),
            'belly' => trim((string) ($selection['belly'] ?? '')),
            'updated_at' => trim((string) ($selection['updated_at'] ?? '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'fit:none');
    }
}
