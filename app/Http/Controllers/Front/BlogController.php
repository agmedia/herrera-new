<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Content\ContentBlockResolver;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\MediaUrl;
use App\Support\ProductMaterialLabel;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BlogController extends Controller
{
    use ResolvesFrontendView;

    public function __construct(
        private readonly CatalogFeatureService $catalogFeatures
    ) {}

    public function index(Request $request): View
    {
        $this->ensureEnabled();

        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $variant = $this->frontendVariant($request);

        $posts = BlogPost::query()
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'media',
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12);

        $topBlocks = app(ContentBlockResolver::class)->forPlacement('blog.top', $locale, null, null, $variant);
        $bottomBlocks = app(ContentBlockResolver::class)->forPlacement('blog.bottom', $locale, null, null, $variant);

        return view($this->frontendView($request, 'blog.index'), [
            'posts' => $posts,
            'topBlocks' => $topBlocks,
            'bottomBlocks' => $bottomBlocks,
            'locale' => $locale,
            'fallbackLocale' => $fallbackLocale,
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $this->ensureEnabled();

        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');

        $post = BlogPost::query()
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->whereHas('translations', function ($q) use ($locale, $fallbackLocale, $slug): void {
                $q->whereIn('locale', [$locale, $fallbackLocale])
                    ->where('slug', $slug);
            })
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'categories.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'creator:id,name',
                'media',
            ])
            ->firstOrFail();

        $related = BlogPost::query()
            ->where('is_active', true)
            ->where('id', '!=', $post->id)
            ->where(function ($q): void {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'media',
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        $relatedProductIds = collect($post->payload['related_product_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        $relatedProducts = collect();
        if ($relatedProductIds !== []) {
            $relatedProducts = Product::query()
                ->withStorefrontEnergyData()
                ->visibleOnStorefront($this->catalogFeatures->hideOutOfStockProducts())
                ->whereIn('id', $relatedProductIds)
                ->with([
                    'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                    'media',
                    'optionValues.optionValue.translations',
                    'optionValues.parentOptionValue.translations',
                    'manufacturer.translations',
                    'categories.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                    'attributes' => ProductMaterialLabel::eagerLoadAttributes($locale, $fallbackLocale),
                ])
                ->get()
                ->sortBy(fn ($row) => array_search((int) $row->id, $relatedProductIds, true))
                ->values();
        }

        $hotspotProductIds = collect($post->media)
            ->where('collection_name', 'blog_gallery')
            ->flatMap(function ($media): array {
                return collect((array) data_get($media->custom_properties, 'product_hotspots', []))
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $hotspotProducts = collect();
        if ($hotspotProductIds !== []) {
            $preferWebp = (bool) app(SystemSettingsService::class)->get('store_images_use_webp', true);
            $pricing = app(ProductPricePresentationService::class);
            $viewer = auth()->user();

            $hotspotProducts = Product::query()
                ->visibleOnStorefront($this->catalogFeatures->hideOutOfStockProducts())
                ->whereIn('id', $hotspotProductIds)
                ->with([
                    'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                    'media',
                ])
                ->get()
                ->mapWithKeys(function (Product $product) use ($locale, $fallbackLocale, $preferWebp, $pricing, $viewer): array {
                    $translation = $product->translations->firstWhere('locale', $locale)
                        ?? $product->translations->firstWhere('locale', $fallbackLocale);

                    if ($translation === null) {
                        return [];
                    }

                    $mainMedia = $product->media->firstWhere('collection_name', 'product_main')
                        ?? $product->media->firstWhere('collection_name', 'product_gallery')
                        ?? $product->getFirstMedia('product_main')
                        ?? $product->getFirstMedia('product_gallery');

                    $price = $pricing->forProduct($product, $viewer);
                    $imageUrl = MediaUrl::conversionOrNull($mainMedia, 'card_320w', $preferWebp)
                        ?? MediaUrl::conversionOrNull($mainMedia, 'card_480w', $preferWebp)
                        ?? ($mainMedia ? (string) $mainMedia->getUrl() : null);

                    return [
                        (int) $product->id => [
                            'id' => (int) $product->id,
                            'name' => (string) $translation->name,
                            'slug' => (string) $translation->slug,
                            'url' => route('products.show', ['slug' => $translation->slug]),
                            'price' => number_format((float) ($price['current_gross'] ?? 0), 2).' €',
                            'image_url' => $imageUrl,
                        ],
                    ];
                });
        }

        return view($this->frontendView($request, 'blog.show'), [
            'post' => $post,
            'related' => $related,
            'relatedProducts' => $relatedProducts,
            'hotspotProducts' => $hotspotProducts,
            'locale' => $locale,
            'fallbackLocale' => $fallbackLocale,
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless($this->catalogFeatures->useBlog(), 404);
    }
}
