<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Category\Category;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Models\Content\Page\InfoPage;
use App\Services\Content\ContentBlockResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    use ResolvesFrontendView;

    public function category(Request $request, string $slug): View
    {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $variant = $this->frontendVariant($request);

        $category = Category::query()
            ->where('scope', Category::SCOPE_PAGE)
            ->currentlyVisible()
            ->whereHas('translations', function ($q) use ($locale, $fallbackLocale, $slug): void {
                $q->where('scope', Category::SCOPE_PAGE)
                    ->whereIn('locale', [$locale, $fallbackLocale])
                    ->where('slug', $slug);
            })
            ->with(['translations' => fn ($q) => $q
                ->where('scope', Category::SCOPE_PAGE)
                ->whereIn('locale', [$locale, $fallbackLocale])])
            ->firstOrFail();

        $pages = InfoPage::query()
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->whereHas('categories', fn ($q) => $q->where('categories.id', $category->id))
            ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        $topBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'page-category.top',
            locale: $locale,
            targetType: 'page-category',
            targetRef: $slug,
            frontendVariant: $variant
        );

        $bottomBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'page-category.bottom',
            locale: $locale,
            targetType: 'page-category',
            targetRef: $slug,
            frontendVariant: $variant
        );

        return view($this->frontendView($request, 'pages.category'), [
            'category' => $category,
            'pages' => $pages,
            'topBlocks' => $topBlocks,
            'bottomBlocks' => $bottomBlocks,
            'locale' => $locale,
            'fallbackLocale' => $fallbackLocale,
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $variant = $this->frontendVariant($request);

        $pages = InfoPage::query()
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->whereHas('translations', function ($q) use ($slug): void {
                $q->where('slug', $slug);
            })
            ->with('translations')
            ->get();

        $page = $pages
            ->sortBy(fn (InfoPage $candidate): int => $this->pageMatchScore($candidate, $slug, (string) $locale, $fallbackLocale))
            ->first();

        abort_if(!$page, 404);

        $selectedTranslation = $this->pickTranslation($page, $slug, (string) $locale, $fallbackLocale);

        $topBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'page.top',
            locale: $locale,
            targetType: 'page',
            targetRef: $slug,
            frontendVariant: $variant
        );

        $bottomBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'page.bottom',
            locale: $locale,
            targetType: 'page',
            targetRef: $slug,
            frontendVariant: $variant
        );

        return view($this->frontendView($request, 'pages.show'), [
            'page' => $page,
            'selectedTranslation' => $selectedTranslation,
            'topBlocks' => $topBlocks,
            'bottomBlocks' => $bottomBlocks,
            'locale' => $locale,
            'fallbackLocale' => $fallbackLocale,
        ]);
    }

    private function pickTranslation(InfoPage $page, string $slug, string $locale, string $fallbackLocale)
    {
        return $page->translations
            ->sortBy(function ($translation) use ($slug, $locale, $fallbackLocale): int {
                $tLocale = (string) ($translation->locale ?? '');
                $tSlug = (string) ($translation->slug ?? '');

                return match (true) {
                    $tLocale === $locale && $tSlug === $slug => 0,
                    $tLocale === $fallbackLocale && $tSlug === $slug => 1,
                    $tSlug === $slug => 2,
                    $tLocale === $locale => 3,
                    $tLocale === $fallbackLocale => 4,
                    default => 5,
                };
            })
            ->first();
    }

    private function pageMatchScore(InfoPage $page, string $slug, string $locale, string $fallbackLocale): int
    {
        $translation = $this->pickTranslation($page, $slug, $locale, $fallbackLocale);
        if (!$translation) {
            return 99;
        }

        $tLocale = (string) ($translation->locale ?? '');
        $tSlug = (string) ($translation->slug ?? '');

        return match (true) {
            $tLocale === $locale && $tSlug === $slug => 0,
            $tLocale === $fallbackLocale && $tSlug === $slug => 1,
            $tSlug === $slug => 2,
            default => 10,
        };
    }
}
