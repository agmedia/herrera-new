@php
    $seoSettings = $storeSettings['seo'] ?? [];
    $ogSettings = $storeSettings['og'] ?? [];
    $brandSettings = $storeSettings['branding'] ?? [];

    $locale = (string) ($locale ?? app()->getLocale());
    $fallbackLocale = (string) ($fallbackLocale ?? config('app.locale'));

    $cleanupText = static function (mixed $value, int $limit = 320): string {
        $plain = trim((string) strip_tags((string) $value));
        if ($plain === '') {
            return '';
        }

        $plain = preg_replace('/\s+/u', ' ', $plain) ?: $plain;

        return \Illuminate\Support\Str::limit($plain, $limit, '');
    };

    $defaultTitle = trim((string) ($seoSettings['default_title'] ?? ''));
    $defaultDescription = $cleanupText($seoSettings['default_description'] ?? '', 320);
    $sectionTitle = trim((string) \Illuminate\Support\Facades\View::yieldContent('title'));

    $title = $sectionTitle !== ''
        ? $sectionTitle
        : ($defaultTitle !== '' ? $defaultTitle : (string) config('app.name', 'AG Shop'));
    $description = $defaultDescription;
    $robotsOverride = trim((string) \Illuminate\Support\Facades\View::yieldContent('robots'));
    $robots = $robotsOverride !== ''
        ? $robotsOverride
        : trim((string) ($seoSettings['robots'] ?? 'index,follow'));
    $canonicalPolicy = (string) ($seoSettings['canonical_policy'] ?? 'self');
    $canonicalUrl = $canonicalPolicy === 'self' ? url()->current() : '';

    $siteName = trim((string) ($brandSettings['store_name'] ?? ''));
    if ($siteName === '') {
        $siteName = (string) config('app.name', 'AG Shop');
    }
    if ($defaultDescription === '') {
        $defaultDescription = $cleanupText(
            (string) __('ui.seo.default_description', ['store' => $siteName]),
            320
        );
        if ($description === '') {
            $description = $defaultDescription;
        }
    }

    $ogType = 'website';
    $ogImage = (string) ($ogSettings['default_image_url'] ?? '');

    if (request()->routeIs('home')) {
        $title = $defaultTitle !== '' ? $defaultTitle : $title;
        $description = $defaultDescription;
        $ogImage = (string) ($ogSettings['home_image_url'] ?? $ogImage);
    }

    if (request()->routeIs('categories.show') && isset($category)) {
        $categoryTranslation = $category->translations->firstWhere('locale', $locale)
            ?? $category->translations->firstWhere('locale', $fallbackLocale);
        $title = $cleanupText($categoryTranslation?->meta_title ?: $categoryTranslation?->name ?: $title, 191);
        $description = $cleanupText($categoryTranslation?->meta_description ?: $categoryTranslation?->description ?: $description, 320);

        if (trim((string) ($ogSettings['category_image_url'] ?? '')) !== '') {
            $ogImage = (string) $ogSettings['category_image_url'];
        } elseif (method_exists($category, 'getFirstMediaUrl')) {
            $categoryImage = (string) ($category->getFirstMediaUrl('category_main') ?: $category->getFirstMediaUrl());
            if ($categoryImage !== '') {
                $ogImage = $categoryImage;
            }
        }
    }

    if (request()->routeIs('products.show') && isset($product)) {
        $productTranslation = $product->translations->firstWhere('locale', $locale)
            ?? $product->translations->firstWhere('locale', $fallbackLocale);
        $title = $cleanupText($productTranslation?->meta_title ?: $productTranslation?->name ?: $title, 191);
        $description = $cleanupText($productTranslation?->meta_description ?: $productTranslation?->excerpt ?: $productTranslation?->description ?: $description, 320);
        $ogType = 'product';

        if (trim((string) ($ogSettings['product_image_url'] ?? '')) !== '') {
            $ogImage = (string) $ogSettings['product_image_url'];
        } elseif (method_exists($product, 'getFirstMediaUrl')) {
            $productImage = (string) ($product->getFirstMediaUrl('product_main') ?: $product->getFirstMediaUrl('product_gallery') ?: $product->getFirstMediaUrl());
            if ($productImage !== '') {
                $ogImage = $productImage;
            }
        }
    }

    if (request()->routeIs('pages.show') && isset($page)) {
        $pageTranslation = $selectedTranslation
            ?? $page->translations->firstWhere('locale', $locale)
            ?? $page->translations->firstWhere('locale', $fallbackLocale)
            ?? (isset($slug) ? $page->translations->firstWhere('slug', (string) $slug) : null);
        $title = $cleanupText($pageTranslation?->meta_title ?: $pageTranslation?->title ?: $title, 191);
        $description = $cleanupText($pageTranslation?->meta_description ?: $pageTranslation?->excerpt ?: $description, 320);

        if (trim((string) ($ogSettings['page_image_url'] ?? '')) !== '') {
            $ogImage = (string) $ogSettings['page_image_url'];
        }
    }

    if (request()->routeIs('blog.*')) {
        $ogType = request()->routeIs('blog.show') ? 'article' : 'website';

        if (isset($post)) {
            $postTranslation = $post->translations->firstWhere('locale', $locale)
                ?? $post->translations->firstWhere('locale', $fallbackLocale);
            $title = $cleanupText($postTranslation?->meta_title ?: $postTranslation?->title ?: $title, 191);
            $description = $cleanupText($postTranslation?->meta_description ?: $postTranslation?->excerpt ?: $description, 320);

            if (trim((string) ($ogSettings['blog_image_url'] ?? '')) !== '') {
                $ogImage = (string) $ogSettings['blog_image_url'];
            } elseif (method_exists($post, 'getFirstMediaUrl')) {
                $postImage = (string) ($post->getFirstMediaUrl('blog_main') ?: $post->getFirstMediaUrl());
                if ($postImage !== '') {
                    $ogImage = $postImage;
                }
            }
        } elseif (trim((string) ($ogSettings['blog_image_url'] ?? '')) !== '') {
            $ogImage = (string) $ogSettings['blog_image_url'];
        }
    }

    if (request()->routeIs('faq.index')) {
        $title = $cleanupText((string) __('ui.faq.page_title'), 191);
        $description = $cleanupText((string) __('ui.faq.subtitle'), 320);
    }

    if ($description === '') {
        $description = $defaultDescription;
    }

    if ($title === '') {
        $title = (string) config('app.name', 'AG Shop');
    }
@endphp

<title>{{ $title }}</title>
@if ($description !== '')
    <meta name="description" content="{{ $description }}">
@endif
@if ($robots !== '')
    <meta name="robots" content="{{ $robots }}">
@endif
@if ($canonicalUrl !== '')
    <link rel="canonical" href="{{ $canonicalUrl }}">
@endif

<meta property="og:locale" content="{{ str_replace('_', '-', app()->getLocale()) }}">
<meta property="og:type" content="{{ $ogType }}">
<meta property="og:title" content="{{ $title }}">
@if ($description !== '')
    <meta property="og:description" content="{{ $description }}">
@endif
<meta property="og:url" content="{{ $canonicalUrl !== '' ? $canonicalUrl : request()->fullUrl() }}">
<meta property="og:site_name" content="{{ $siteName }}">
@if ($ogImage !== '')
    <meta property="og:image" content="{{ $ogImage }}">
@endif

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
@if ($description !== '')
    <meta name="twitter:description" content="{{ $description }}">
@endif
@if ($ogImage !== '')
    <meta name="twitter:image" content="{{ $ogImage }}">
@endif
