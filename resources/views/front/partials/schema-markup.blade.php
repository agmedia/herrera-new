@php
    $schemaSettings = $storeSettings['schema'] ?? [];
    $schemaEnabled = (bool) ($schemaSettings['enabled'] ?? true);
    if (! $schemaEnabled) {
        $schemaSettings = array_merge($schemaSettings, [
            'org_enabled' => false,
            'website_enabled' => false,
            'breadcrumbs_enabled' => false,
            'itemlist_enabled' => false,
            'home_enabled' => false,
            'category_enabled' => false,
            'product_enabled' => false,
            'blog_enabled' => false,
            'page_enabled' => false,
            'faq_enabled' => false,
        ]);
    }

    $locale = (string) ($locale ?? app()->getLocale());
    $fallbackLocale = (string) ($fallbackLocale ?? config('app.locale'));
    $siteUrl = url('/');
    $currentUrl = url()->current();
    $brand = $storeSettings['branding'] ?? [];
    $footer = $storeSettings['footer'] ?? [];
    $og = $storeSettings['og'] ?? [];
    $seo = $storeSettings['seo'] ?? [];

    $text = static function (mixed $value, int $limit = 300): string {
        $plain = trim((string) strip_tags((string) $value));
        if ($plain === '') {
            return '';
        }

        $plain = preg_replace('/\s+/u', ' ', $plain) ?: $plain;

        return \Illuminate\Support\Str::limit($plain, $limit, '');
    };

    $nonEmpty = static fn (mixed $value): bool => trim((string) $value) !== '';
    $absolute = static fn (string $url): string => \Illuminate\Support\Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);

    $businessName = trim((string) ($schemaSettings['business_name'] ?? ''));
    if ($businessName === '') {
        $businessName = trim((string) ($brand['store_name'] ?? ''));
    }
    if ($businessName === '') {
        $businessName = (string) config('app.name', 'AG Shop');
    }

    $defaultDescription = $text($seo['default_description'] ?? '', 320);
    $defaultImage = (string) ($og['default_image_url'] ?? '');
    $sameAs = collect(preg_split('/\r\n|\r|\n/', (string) ($schemaSettings['same_as'] ?? '')) ?: [])
        ->map(static fn (string $url): string => trim($url))
        ->filter(static fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false)
        ->values()
        ->all();
    if ($sameAs === []) {
        $sameAs = collect($brand['social'] ?? [])
            ->pluck('url')
            ->map(static fn ($url): string => trim((string) $url))
            ->filter(static fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->values()
            ->all();
    }
    $itemListLimit = max(1, min(48, (int) ($schemaSettings['itemlist_limit'] ?? 12)));

    $schemas = [];

    if ((bool) ($schemaSettings['org_enabled'] ?? true)) {
        $organization = [
            '@context' => 'https://schema.org',
            '@type' => (string) ($schemaSettings['org_type'] ?? 'Organization'),
            '@id' => $siteUrl.'#organization',
            'name' => $businessName,
            'url' => $siteUrl,
        ];

        if ($nonEmpty($brand['logo_url'] ?? '')) {
            $organization['logo'] = $absolute((string) $brand['logo_url']);
        }
        if ($sameAs !== []) {
            $organization['sameAs'] = $sameAs;
        }

        $phone = trim((string) ($schemaSettings['business_phone'] ?? ($footer['phone'] ?? '')));
        $email = trim((string) ($schemaSettings['business_email'] ?? ($footer['email_support'] ?? $footer['email_sales'] ?? '')));
        if ($phone !== '' || $email !== '') {
            $contactPoint = ['@type' => 'ContactPoint', 'contactType' => 'customer support'];
            if ($phone !== '') {
                $contactPoint['telephone'] = $phone;
            }
            if ($email !== '') {
                $contactPoint['email'] = $email;
            }
            $organization['contactPoint'] = [$contactPoint];
        }

        $addressStreet = trim((string) ($schemaSettings['address_street'] ?? ''));
        $addressCity = trim((string) ($schemaSettings['address_city'] ?? ''));
        $addressRegion = trim((string) ($schemaSettings['address_region'] ?? ''));
        $addressPostalCode = trim((string) ($schemaSettings['address_postal_code'] ?? ''));
        $addressCountry = strtoupper(trim((string) ($schemaSettings['address_country'] ?? 'HR')));
        if ($addressStreet !== '' || $addressCity !== '' || $addressRegion !== '' || $addressPostalCode !== '') {
            $address = ['@type' => 'PostalAddress'];
            if ($addressStreet !== '') {
                $address['streetAddress'] = $addressStreet;
            }
            if ($addressCity !== '') {
                $address['addressLocality'] = $addressCity;
            }
            if ($addressRegion !== '') {
                $address['addressRegion'] = $addressRegion;
            }
            if ($addressPostalCode !== '') {
                $address['postalCode'] = $addressPostalCode;
            }
            if ($addressCountry !== '') {
                $address['addressCountry'] = $addressCountry;
            }
            $organization['address'] = $address;
        }

        $schemas[] = $organization;
    }

    if ((bool) ($schemaSettings['website_enabled'] ?? true)) {
        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $siteUrl.'#website',
            'url' => $siteUrl,
            'name' => $businessName,
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => route('shop.index').'?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    if ((bool) ($schemaSettings['breadcrumbs_enabled'] ?? true)) {
        $breadcrumbItems = [];
        $position = 1;
        $breadcrumbItems[] = [
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => __('ui.front.desktop.footer.home'),
            'item' => route('home'),
        ];

        if (request()->routeIs('shop.index') || request()->routeIs('categories.*') || request()->routeIs('products.show')) {
            $breadcrumbItems[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => __('ui.shop.page_title'),
                'item' => route('shop.index'),
            ];
        }

        if (request()->routeIs('categories.show') && isset($breadcrumbCategories) && $breadcrumbCategories instanceof \Illuminate\Support\Collection) {
            foreach ($breadcrumbCategories as $crumbCategory) {
                $crumbTranslation = $crumbCategory->translations->firstWhere('locale', $locale)
                    ?? $crumbCategory->translations->firstWhere('locale', $fallbackLocale);
                $crumbName = (string) ($crumbTranslation?->name ?? $crumbCategory->code);
                $crumbUrl = route('categories.show', ['slug' => $crumbTranslation?->slug ?? $crumbCategory->id]);
                $breadcrumbItems[] = ['@type' => 'ListItem', 'position' => $position++, 'name' => $crumbName, 'item' => $crumbUrl];
            }
        }

        if (request()->routeIs('products.show') && isset($firstCategory) && $firstCategory) {
            $catTranslation = $firstCategory->translations?->firstWhere('locale', $locale)
                ?? $firstCategory->translations?->firstWhere('locale', $fallbackLocale);
            $catName = (string) ($catTranslation?->name ?? $firstCategory->code);
            $catUrl = route('categories.show', ['slug' => $catTranslation?->slug ?? $firstCategory->id]);
            $breadcrumbItems[] = ['@type' => 'ListItem', 'position' => $position++, 'name' => $catName, 'item' => $catUrl];
        }

        if (request()->routeIs('blog.*')) {
            $breadcrumbItems[] = ['@type' => 'ListItem', 'position' => $position++, 'name' => 'Blog', 'item' => route('blog.index')];
        }

        if (request()->routeIs('pages.show') && isset($page)) {
            $pageTranslation = $selectedTranslation
                ?? $page->translations->firstWhere('locale', $locale)
                ?? $page->translations->firstWhere('locale', $fallbackLocale);
            $breadcrumbItems[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => (string) ($pageTranslation?->title ?? $page->code),
                'item' => $currentUrl,
            ];
        }

        if (request()->routeIs('products.show') && isset($product)) {
            $productTranslation = $product->translations->firstWhere('locale', $locale)
                ?? $product->translations->firstWhere('locale', $fallbackLocale);
            $breadcrumbItems[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => (string) ($productTranslation?->name ?? $product->code),
                'item' => $currentUrl,
            ];
        }

        if (request()->routeIs('blog.show') && isset($post)) {
            $postTranslation = $post->translations->firstWhere('locale', $locale)
                ?? $post->translations->firstWhere('locale', $fallbackLocale);
            $breadcrumbItems[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => (string) ($postTranslation?->title ?? $post->code),
                'item' => $currentUrl,
            ];
        }

        if (count($breadcrumbItems) > 1) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => $breadcrumbItems,
            ];
        }
    }

    if (request()->routeIs('home') && (bool) ($schemaSettings['home_enabled'] ?? true)) {
        $homeTitle = trim((string) \Illuminate\Support\Facades\View::yieldContent('title'));
        if ($homeTitle === '') {
            $homeTitle = $businessName;
        }
        $homeImage = (string) ($og['home_image_url'] ?? $defaultImage);
        $homeSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $homeTitle,
            'url' => $currentUrl,
            'description' => $defaultDescription,
        ];
        if ($homeImage !== '') {
            $homeSchema['primaryImageOfPage'] = $absolute($homeImage);
        }
        $schemas[] = $homeSchema;
    }

    if (request()->routeIs('categories.show') && isset($category) && (bool) ($schemaSettings['category_enabled'] ?? true)) {
        $translation = $category->translations->firstWhere('locale', $locale)
            ?? $category->translations->firstWhere('locale', $fallbackLocale);
        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $text($translation?->meta_title ?: $translation?->name ?: $category->code, 191),
            'url' => $currentUrl,
            'description' => $text($translation?->meta_description ?: $translation?->description ?: $defaultDescription, 320),
            'image' => (string) ($og['category_image_url'] ?? $defaultImage),
        ];
    }

    if (request()->routeIs('categories.index') && (bool) ($schemaSettings['category_enabled'] ?? true)) {
        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => __('ui.shop.filters.all_categories'),
            'url' => $currentUrl,
            'description' => $defaultDescription,
            'image' => (string) ($og['category_image_url'] ?? $defaultImage),
        ];
    }

    if (request()->routeIs('shop.index') && (bool) ($schemaSettings['category_enabled'] ?? true)) {
        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => __('ui.shop.page_title'),
            'url' => $currentUrl,
            'description' => $defaultDescription,
            'image' => (string) ($og['category_image_url'] ?? $defaultImage),
        ];
    }

    if ((bool) ($schemaSettings['itemlist_enabled'] ?? true) && request()->routeIs('shop.index') && isset($products) && $products instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
        $items = collect($products->items())
            ->take($itemListLimit)
            ->map(function ($row, int $index) use ($locale, $fallbackLocale) {
                $tr = $row->translations->firstWhere('locale', $locale)
                    ?? $row->translations->firstWhere('locale', $fallbackLocale);
                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'url' => route('products.show', ['slug' => $tr?->slug ?? $row->id]),
                    'name' => (string) ($tr?->name ?? $row->code),
                ];
            })
            ->values()
            ->all();

        if ($items !== []) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => __('ui.shop.page_title'),
                'itemListElement' => $items,
            ];
        }
    }

    if ((bool) ($schemaSettings['itemlist_enabled'] ?? true) && request()->routeIs('categories.show') && isset($products) && $products instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
        $categoryTranslation = isset($category)
            ? ($category->translations->firstWhere('locale', $locale) ?? $category->translations->firstWhere('locale', $fallbackLocale))
            : null;
        $items = collect($products->items())
            ->take($itemListLimit)
            ->map(function ($row, int $index) use ($locale, $fallbackLocale) {
                $tr = $row->translations->firstWhere('locale', $locale)
                    ?? $row->translations->firstWhere('locale', $fallbackLocale);
                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'url' => route('products.show', ['slug' => $tr?->slug ?? $row->id]),
                    'name' => (string) ($tr?->name ?? $row->code),
                ];
            })
            ->values()
            ->all();

        if ($items !== []) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => (string) ($categoryTranslation?->name ?? __('ui.shop.page_title')),
                'itemListElement' => $items,
            ];
        }
    }

    if (request()->routeIs('products.show') && isset($product) && (bool) ($schemaSettings['product_enabled'] ?? true)) {
        $translation = $product->translations->firstWhere('locale', $locale)
            ?? $product->translations->firstWhere('locale', $fallbackLocale);
        $manufacturerName = $product->manufacturer?->translations?->firstWhere('locale', $locale)?->name
            ?? $product->manufacturer?->translations?->firstWhere('locale', $fallbackLocale)?->name;

        $productImages = collect();
        if ($product->relationLoaded('media')) {
            $productImages = $product->media
                ->whereIn('collection_name', ['product_main', 'product_gallery'])
                ->sortBy(static fn ($m) => (int) ($m->order_column ?? 0))
                ->map(static fn ($m) => (string) $m->getUrl())
                ->filter(static fn (string $url): bool => $url !== '')
                ->values();
        }
        if ($productImages->isEmpty() && method_exists($product, 'getFirstMediaUrl')) {
            $fallbackImage = (string) ($product->getFirstMediaUrl('product_main') ?: $product->getFirstMediaUrl('product_gallery') ?: '');
            if ($fallbackImage !== '') {
                $productImages->push($fallbackImage);
            }
        }

        $productSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $text($translation?->name ?: $product->code, 191),
            'sku' => (string) ($product->sku ?: $product->code),
            'description' => $text($translation?->meta_description ?: $translation?->excerpt ?: $translation?->description ?: $defaultDescription, 500),
            'url' => $currentUrl,
            'offers' => [
                '@type' => 'Offer',
                'url' => $currentUrl,
                'priceCurrency' => strtoupper((string) ($schemaSettings['product_currency'] ?? 'EUR')),
                'price' => number_format((float) app(\App\Services\Pricing\TaxPricingService::class)->grossFromStored((float) $product->base_price, $product), 2, '.', ''),
                'availability' => (int) $product->stock_qty > 0
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ],
        ];
        if ($manufacturerName) {
            $productSchema['brand'] = ['@type' => 'Brand', 'name' => $manufacturerName];
        }
        if ($productImages->isNotEmpty()) {
            $productSchema['image'] = $productImages->map($absolute)->take(8)->values()->all();
        } elseif ($defaultImage !== '') {
            $productSchema['image'] = [$absolute($defaultImage)];
        }

        try {
            $ratingStats = $product->comments()
                ->status(\App\Models\Content\Support\Comment::STATUS_APPROVED)
                ->whereNotNull('rating')
                ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as rating_count')
                ->first();
            $ratingCount = (int) ($ratingStats->rating_count ?? 0);
            $avgRating = (float) ($ratingStats->avg_rating ?? 0);
            if ($ratingCount > 0 && $avgRating > 0) {
                $productSchema['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => number_format($avgRating, 2, '.', ''),
                    'reviewCount' => $ratingCount,
                ];
            }
        } catch (\Throwable) {
            // Keep schema output even if comments table is unavailable.
        }

        $schemas[] = $productSchema;
    }

    if (request()->routeIs('blog.show') && isset($post) && (bool) ($schemaSettings['blog_enabled'] ?? true)) {
        $translation = $post->translations->firstWhere('locale', $locale)
            ?? $post->translations->firstWhere('locale', $fallbackLocale);
        $authorName = trim((string) ($schemaSettings['blog_author_name'] ?? ''));
        if ($authorName === '') {
            $authorName = trim((string) ($post->creator?->name ?? ''));
        }
        if ($authorName === '') {
            $authorName = $businessName;
        }
        $authorUrl = trim((string) ($schemaSettings['blog_author_url'] ?? ''));
        $blogSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $text($translation?->meta_title ?: $translation?->title ?: $post->code, 191),
            'description' => $text($translation?->meta_description ?: $translation?->excerpt ?: $defaultDescription, 320),
            'datePublished' => optional($post->published_at)->toIso8601String(),
            'dateModified' => optional($post->updated_at)->toIso8601String(),
            'mainEntityOfPage' => $currentUrl,
            'author' => ['@type' => 'Person', 'name' => $authorName],
            'publisher' => ['@type' => 'Organization', 'name' => $businessName],
        ];
        if ($authorUrl !== '') {
            $blogSchema['author']['url'] = $authorUrl;
        }
        $blogImage = (string) ($og['blog_image_url'] ?? '');
        $blogImageMedia = null;
        if ($blogImage === '' && method_exists($post, 'getFirstMedia')) {
            $blogImageMedia = $post->getFirstMedia('blog_main') ?: $post->getFirstMedia();
            $blogImage = (string) ($blogImageMedia?->getUrl() ?? '');
        }
        if ($blogImage === '' && $defaultImage !== '') {
            $blogImage = $defaultImage;
        }
        if ($blogImage !== '') {
            $imageObject = ['@type' => 'ImageObject', 'url' => $absolute($blogImage)];
            if ($blogImageMedia) {
                $width = (int) ($blogImageMedia->width ?? 0);
                $height = (int) ($blogImageMedia->height ?? 0);
                if ($width > 0) {
                    $imageObject['width'] = $width;
                }
                if ($height > 0) {
                    $imageObject['height'] = $height;
                }
            }
            $blogSchema['image'] = [$imageObject];
        }
        $schemas[] = $blogSchema;
    }

    if (request()->routeIs('blog.index') && (bool) ($schemaSettings['blog_enabled'] ?? true)) {
        $blogIndex = [
            '@context' => 'https://schema.org',
            '@type' => 'Blog',
            'name' => 'Blog',
            'url' => $currentUrl,
            'publisher' => ['@type' => 'Organization', 'name' => $businessName],
        ];
        if (isset($posts) && $posts instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
            $items = collect($posts->items())
                ->map(function ($item) use ($locale, $fallbackLocale, $text) {
                    $tr = $item->translations->firstWhere('locale', $locale)
                        ?? $item->translations->firstWhere('locale', $fallbackLocale);
                    if (! $tr) {
                        return null;
                    }

                    return [
                        '@type' => 'ListItem',
                        'position' => null,
                        'url' => route('blog.show', ['slug' => $tr->slug ?? $item->id]),
                        'name' => $text($tr->title, 191),
                    ];
                })
                ->filter()
                ->values();

            if ($items->isNotEmpty()) {
                $list = $items->map(function (array $entry, int $index): array {
                    $entry['position'] = $index + 1;
                    return $entry;
                })->all();
                $blogIndex['blogPost'] = $list;
            }
        }
        $schemas[] = $blogIndex;
    }

    if ((bool) ($schemaSettings['itemlist_enabled'] ?? true) && request()->routeIs('blog.index') && isset($posts) && $posts instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
        $listItems = collect($posts->items())
            ->take($itemListLimit)
            ->map(function ($item, int $index) use ($locale, $fallbackLocale, $text) {
                $tr = $item->translations->firstWhere('locale', $locale)
                    ?? $item->translations->firstWhere('locale', $fallbackLocale);
                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'url' => route('blog.show', ['slug' => $tr?->slug ?? $item->id]),
                    'name' => $text($tr?->title ?: $item->code, 191),
                ];
            })
            ->values()
            ->all();
        if ($listItems !== []) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => 'Blog posts',
                'itemListElement' => $listItems,
            ];
        }
    }

    if (request()->routeIs('pages.show') && isset($page) && (bool) ($schemaSettings['page_enabled'] ?? true)) {
        $translation = $selectedTranslation
            ?? $page->translations->firstWhere('locale', $locale)
            ?? $page->translations->firstWhere('locale', $fallbackLocale);
        $pageSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $text($translation?->meta_title ?: $translation?->title ?: $page->code, 191),
            'url' => $currentUrl,
            'description' => $text($translation?->meta_description ?: $translation?->excerpt ?: $defaultDescription, 320),
        ];
        $pageImage = (string) ($og['page_image_url'] ?? $defaultImage);
        if ($pageImage !== '') {
            $pageSchema['primaryImageOfPage'] = $absolute($pageImage);
        }
        $schemas[] = $pageSchema;
    }

    if ((request()->routeIs('home') || request()->routeIs('faq.index')) && (bool) ($schemaSettings['faq_enabled'] ?? true)) {
        try {
            $faqLimit = max(1, min(20, (int) ($schemaSettings['faq_limit'] ?? 8)));
            $faqGroup = trim((string) ($schemaSettings['faq_group'] ?? ''));

            $faqs = \App\Models\Content\Support\Faq::query()
                ->where('is_active', true)
                ->when($faqGroup !== '', fn ($q) => $q->where('group_code', $faqGroup))
                ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
                ->orderByDesc('is_featured')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit($faqLimit)
                ->get();

            $faqEntities = $faqs->map(function ($faq) use ($locale, $fallbackLocale, $text) {
                $tr = $faq->translations->firstWhere('locale', $locale)
                    ?? $faq->translations->firstWhere('locale', $fallbackLocale);
                $q = $text($tr?->question, 280);
                $a = $text($tr?->answer_html, 2000);
                if ($q === '' || $a === '') {
                    return null;
                }

                return [
                    '@type' => 'Question',
                    'name' => $q,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $a,
                    ],
                ];
            })->filter()->values()->all();

            if ($faqEntities !== []) {
                $schemas[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => $faqEntities,
                ];
            }
        } catch (\Throwable) {
            // Skip FAQ schema if data unavailable.
        }
    }
@endphp

@foreach ($schemas as $schema)
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endforeach
