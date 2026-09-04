@foreach ($items as $item)
    @php
        $block = $item['block'];
        $translation = $item['translation'];
        $slot = $item['slot'];
        $locale = app()->getLocale();
        $fallbackLocale = config('app.locale');
        $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
        $wrapperClasses = trim((string) ($translationPayload['custom_classes'] ?? ''));
        $wrapperStyle = trim((string) ($translationPayload['bg_css'] ?? ''));
        $backgroundUrl = trim((string) $block->getFirstMediaUrl('block_background'));
        if ($backgroundUrl !== '') {
            $backgroundStyle = "background-image:url('{$backgroundUrl}');background-size:cover;background-position:center;";
            $wrapperStyle = trim($backgroundStyle.' '.$wrapperStyle);
        }
        $hideOutOfStockProducts = app(\App\Services\Catalog\CatalogFeatureService::class)->hideOutOfStockProducts();

        $overridePrefix = (string) config('content_blocks.view_overrides.prefix', 'front.content-blocks.instances.');
        $codeOverride = $overridePrefix.$block->code;

        $overrideView = '';
        if (view()->exists($codeOverride)) {
            $overrideView = $codeOverride;
        }

        $partial = 'front.content-blocks.types.'.$block->type;

        $blockItems = $block->relationLoaded('items')
            ? $block->items
            : $block->items()->orderBy('sort_order')->orderBy('id')->get();

        $productIds = $blockItems->where('item_type', 'product')->pluck('item_id')->map(fn ($id) => (int) $id)->all();
        $categoryIds = $blockItems->where('item_type', 'category')->pluck('item_id')->map(fn ($id) => (int) $id)->all();
        $manufacturerIds = $blockItems->where('item_type', 'manufacturer')->pluck('item_id')->map(fn ($id) => (int) $id)->all();
        $blogIds = $blockItems->where('item_type', 'blog')->pluck('item_id')->map(fn ($id) => (int) $id)->all();

        $products = collect();
        if ($productIds !== []) {
            $products = \App\Models\Catalog\Product\Product::query()
                ->withStorefrontEnergyData()
                ->visibleOnStorefront($hideOutOfStockProducts)
                ->whereIn('id', $productIds)
                ->with([
                    'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                    'attributes' => \App\Support\ProductMaterialLabel::eagerLoadAttributes($locale, $fallbackLocale),
                    'media' => fn ($q) => $q->whereIn('collection_name', ['product_main', 'product_gallery']),
                ])
                ->get()
                ->sortBy(fn ($row) => array_search((int) $row->id, $productIds, true))
                ->values();

            if ((string) $block->type === 'products_carousel') {
                $products = $products
                    ->filter(function ($product): bool {
                        return $product->media
                            ->contains(fn ($media): bool => \App\Support\Media\MediaUrl::hasUsableSource($media, ['card_720w', 'card_480w', 'card_320w']));
                    })
                    ->values();
            }
        }

        $categories = collect();
        if ($categoryIds !== []) {
            $isFeaturedCategories = (string) $block->type === 'featured_categories';
            $categoryRelations = [
                'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
            ];

            if ($isFeaturedCategories) {
                $categoryRelations['media'] = fn ($q) => $q
                    ->whereIn('collection_name', ['category_icon', 'category_banner'])
                    ->orderBy('order_column')
                    ->orderBy('id');
            }

            $categoryQuery = \App\Models\Catalog\Category\Category::query()
                ->currentlyVisible()
                ->whereIn('id', $categoryIds)
                ->when(
                    $isFeaturedCategories,
                    fn ($q) => $q->where('scope', \App\Models\Catalog\Category\Category::SCOPE_CATALOG)
                )
                ->with($categoryRelations);

            if ($isFeaturedCategories) {
                $categoryQuery->withCount([
                    'descendants as subcategories_count' => fn ($q) => $q
                        ->where('scope', \App\Models\Catalog\Category\Category::SCOPE_CATALOG)
                        ->currentlyVisible(),
                ]);
            }

            $categories = $categoryQuery->get()
                ->sortBy(fn ($row) => array_search((int) $row->id, $categoryIds, true))
                ->values();

            if ($isFeaturedCategories) {
                $categories->each(function ($category) use ($hideOutOfStockProducts): void {
                    $categoryScopeIds = \App\Models\Catalog\Category\Category::query()
                        ->descendantsAndSelf((int) $category->id)
                        ->filter(static fn ($scopeCategory): bool => (string) $scopeCategory->scope === \App\Models\Catalog\Category\Category::SCOPE_CATALOG
                            && $scopeCategory->isCurrentlyVisible())
                        ->pluck('id')
                        ->map(static fn ($id): int => (int) $id)
                        ->values();

                    $productsCount = $categoryScopeIds->isEmpty()
                        ? 0
                        : \App\Models\Catalog\Product\Product::query()
                            ->visibleOnStorefront($hideOutOfStockProducts)
                            ->whereHas('categories', function ($categoryQuery) use ($categoryScopeIds): void {
                                $categoryQuery
                                    ->where('scope', \App\Models\Catalog\Category\Category::SCOPE_CATALOG)
                                    ->currentlyVisible()
                                    ->whereIn('categories.id', $categoryScopeIds);
                            })
                            ->distinct()
                            ->count('products.id');

                    $category->setAttribute('products_count', $productsCount);
                });
            }
        }

        if ((string) $block->type === 'category_products_carousel' && $categories->isNotEmpty()) {
            $sourceCategory = $categories->first();
            $categoryScopeIds = \App\Models\Catalog\Category\Category::query()
                ->descendantsAndSelf((int) $sourceCategory->id)
                ->filter(static fn ($category): bool => (string) $category->scope === \App\Models\Catalog\Category\Category::SCOPE_CATALOG
                    && $category->isCurrentlyVisible())
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
            $mergedPayload = array_merge(
                is_array($block->payload ?? null) ? $block->payload : [],
                is_array($translation?->payload ?? null) ? $translation->payload : [],
            );
            $itemsLimit = max(1, min(50, (int) ($mergedPayload['items_limit'] ?? 12)));
            $productManufacturerIds = collect((array) ($mergedPayload['manufacturer_ids'] ?? []))
                ->map(static fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();
            $requestedProductSort = (string) ($mergedPayload['product_sort'] ?? 'category_order');
            $productSort = in_array(
                $requestedProductSort,
                ['category_order', 'price_asc', 'price_desc', 'date_desc', 'date_asc'],
                true
            )
                ? $requestedProductSort
                : 'category_order';

            if ($categoryScopeIds !== []) {
                $categorySortSubquery = \Illuminate\Support\Facades\DB::table('category_product')
                    ->selectRaw('product_id, MIN(sort_order) as category_sort_order')
                    ->whereIn('category_id', $categoryScopeIds)
                    ->groupBy('product_id');

                $productQuery = \App\Models\Catalog\Product\Product::query()
                    ->withStorefrontEnergyData()
                    ->visibleOnStorefront($hideOutOfStockProducts)
                    ->whereHas('categories', function ($categoryQuery) use ($categoryScopeIds): void {
                        $categoryQuery
                            ->where('scope', \App\Models\Catalog\Category\Category::SCOPE_CATALOG)
                            ->currentlyVisible()
                            ->whereIn('categories.id', $categoryScopeIds);
                    })
                    ->when(
                        $productManufacturerIds !== [],
                        fn ($query) => $query->whereIn('products.manufacturer_id', $productManufacturerIds)
                    )
                    ->whereHas('media', fn ($mediaQuery) => $mediaQuery->whereIn('collection_name', ['product_main', 'product_gallery']))
                    ->leftJoinSub($categorySortSubquery, 'category_product_sort', function ($join): void {
                        $join->on('category_product_sort.product_id', '=', 'products.id');
                    })
                    ->select('products.*')
                    ->withApprovedCommentSummary([$locale, $fallbackLocale])
                    ->with([
                        'taxRate:id,rate,rate_type,is_active',
                        'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                        'categories.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                        'manufacturer.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                        'attributes' => \App\Support\ProductMaterialLabel::eagerLoadAttributes($locale, $fallbackLocale),
                        'media' => fn ($q) => $q
                            ->whereIn('collection_name', ['product_main', 'product_gallery'])
                            ->orderBy('order_column')
                            ->orderBy('id'),
                        'optionValues' => fn ($q) => $q
                            ->where('is_active', true)
                            ->orderBy('sort_order')
                            ->orderBy('id')
                            ->with([
                                'optionValue.option:id,payload',
                                'optionValue.translations' => fn ($translationQuery) => $translationQuery
                                    ->whereIn('locale', [$locale, $fallbackLocale]),
                                'parentOptionValue.option:id,payload',
                                'parentOptionValue.translations' => fn ($translationQuery) => $translationQuery
                                    ->whereIn('locale', [$locale, $fallbackLocale]),
                            ]),
                    ]);

                match ($productSort) {
                    'price_asc' => $productQuery
                        ->orderBy('products.base_price')
                        ->orderByDesc('products.id'),
                    'price_desc' => $productQuery
                        ->orderByDesc('products.base_price')
                        ->orderByDesc('products.id'),
                    'date_desc' => $productQuery
                        ->orderByDesc('products.created_at')
                        ->orderByDesc('products.id'),
                    'date_asc' => $productQuery
                        ->orderBy('products.created_at')
                        ->orderBy('products.id'),
                    default => $productQuery
                        ->orderByRaw('COALESCE(category_product_sort.category_sort_order, 999999) ASC')
                        ->orderByDesc('products.id'),
                };

                $products = $productQuery
                    ->limit($itemsLimit)
                    ->get()
                    ->filter(function ($product): bool {
                        return $product->media
                            ->contains(fn ($media): bool => \App\Support\Media\MediaUrl::hasUsableSource($media, ['card_720w', 'card_480w', 'card_320w']));
                    })
                    ->values();
            }
        }

        $manufacturers = collect();
        if ($manufacturerIds !== []) {
            $isPopularBrands = (string) $block->type === 'popular_brands';
            $manufacturerRelations = [
                'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
            ];

            if ($isPopularBrands) {
                $manufacturerRelations['media'] = fn ($q) => $q
                    ->where('collection_name', 'manufacturer_logo')
                    ->orderBy('order_column')
                    ->orderBy('id');
            }

            $manufacturers = \App\Models\Catalog\Manufacturer\Manufacturer::query()
                ->whereIn('id', $manufacturerIds)
                ->when($isPopularBrands, fn ($q) => $q->where('is_active', true))
                ->with($manufacturerRelations)
                ->get()
                ->sortBy(fn ($row) => array_search((int) $row->id, $manufacturerIds, true))
                ->values();
        }

        $blogs = collect();
        if ($blogIds !== []) {
            $blogs = \App\Models\Content\Blog\BlogPost::query()
                ->whereIn('id', $blogIds)
                ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
                ->get()
                ->sortBy(fn ($row) => array_search((int) $row->id, $blogIds, true))
                ->values();
        }

        $comments = collect();
        if ((string) $block->type === 'five_star_reviews_carousel') {
            $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
            $blockPayload = is_array($block->payload ?? null) ? $block->payload : [];
            $mergedPayload = array_merge($blockPayload, $translationPayload);
            $limit = max(1, (int) ($mergedPayload['items_limit'] ?? 6));
            $featuredOnly = (bool) ($mergedPayload['reviews_featured_only'] ?? false);

            $commentsQuery = \App\Models\Content\Support\Comment::query()
                ->where('commentable_type', \App\Models\Catalog\Product\Product::class)
                ->where('status', \App\Models\Content\Support\Comment::STATUS_APPROVED)
                ->where('rating', 5)
                ->whereNull('parent_id')
                ->whereIn('locale', [$locale, $fallbackLocale])
                ->orderByDesc('is_featured')
                ->orderByDesc('reviewed_at')
                ->orderByDesc('id');

            if ($featuredOnly) {
                $commentsQuery->where('is_featured', true);
            }

            $comments = $commentsQuery->limit($limit)->get();
        }
    @endphp

    @if ($wrapperClasses !== '' || $wrapperStyle !== '')
        <div @if($wrapperClasses !== '') class="{{ $wrapperClasses }}" @endif @if($wrapperStyle !== '') style="{{ $wrapperStyle }}" @endif>
    @endif
        @if ($overrideView !== '')
            @include($overrideView, [
                'block' => $block,
                'translation' => $translation,
                'slot' => $slot,
                'blockItems' => $blockItems,
                'products' => $products,
                'categories' => $categories,
                'manufacturers' => $manufacturers,
                'blogs' => $blogs,
                'comments' => $comments,
            ])
        @elseif (view()->exists($partial))
            @include($partial, [
                'block' => $block,
                'translation' => $translation,
                'slot' => $slot,
                'blockItems' => $blockItems,
                'products' => $products,
                'categories' => $categories,
                'manufacturers' => $manufacturers,
                'blogs' => $blogs,
                'comments' => $comments,
            ])
        @else
            @include('front.content-blocks.types.custom', [
                'block' => $block,
                'translation' => $translation,
                'slot' => $slot,
                'blockItems' => $blockItems,
                'products' => $products,
                'categories' => $categories,
                'manufacturers' => $manufacturers,
                'blogs' => $blogs,
                'comments' => $comments,
            ])
        @endif
    @if ($wrapperClasses !== '' || $wrapperStyle !== '')
        </div>
    @endif
@endforeach
