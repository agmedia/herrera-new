<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Content\ContentBlockResolver;
use App\Support\Media\MediaUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ManufacturerController extends Controller
{
    use ResolvesFrontendView;

    private const ALPHABET = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M',
        'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
    ];

    public function __construct(
        private readonly CatalogFeatureService $catalogFeatures
    ) {}

    public function index(Request $request): View
    {
        $this->ensureEnabled();

        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');

        $manufacturers = Manufacturer::query()
            ->where('is_active', true)
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'media',
            ])
            ->withCount(['products' => fn ($q) => $q->visibleOnStorefront($this->catalogFeatures->hideOutOfStockProducts())])
            ->orderBy('id')
            ->get();

        $directoryItems = $this->manufacturerDirectoryItems($manufacturers, $locale, $fallbackLocale);
        $manufacturerGroups = $directoryItems->groupBy('letter');

        return view($this->frontendView($request, 'manufacturers.index'), [
            'manufacturers' => $manufacturers,
            'manufacturerGroups' => $manufacturerGroups,
            'manufacturerAlphabet' => self::ALPHABET,
            'availableManufacturerLetters' => $manufacturerGroups->keys()->all(),
            'locale' => $locale,
            'fallbackLocale' => $fallbackLocale,
        ]);
    }

    public function show(Request $request, string $slug): Response|RedirectResponse
    {
        $this->ensureEnabled();

        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $variant = $this->frontendVariant($request);

        $manufacturer = Manufacturer::query()
            ->where('is_active', true)
            ->whereHas('translations', function ($q) use ($locale, $fallbackLocale, $slug): void {
                $q->whereIn('locale', [$locale, $fallbackLocale])
                    ->where('slug', $slug);
            })
            ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
            ->firstOrFail();

        $topBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'manufacturer.top',
            locale: $locale,
            targetType: 'manufacturer',
            targetRef: $slug,
            frontendVariant: $variant
        );

        $bottomBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'manufacturer.bottom',
            locale: $locale,
            targetType: 'manufacturer',
            targetRef: $slug,
            frontendVariant: $variant
        );

        $request->attributes->set('catalog_manufacturer', $manufacturer);
        $request->attributes->set('catalog_top_blocks', $topBlocks);
        $request->attributes->set('catalog_bottom_blocks', $bottomBlocks);

        return app(CatalogController::class)->index($request);
    }

    private function ensureEnabled(): void
    {
        abort_unless($this->catalogFeatures->useManufacturers(), 404);
    }

    /**
     * @param  Collection<int, Manufacturer>  $manufacturers
     * @return Collection<int, array<string, mixed>>
     */
    private function manufacturerDirectoryItems(
        Collection $manufacturers,
        string $locale,
        string $fallbackLocale
    ): Collection {
        $items = $manufacturers->map(function (Manufacturer $manufacturer) use ($locale, $fallbackLocale): array {
            $translation = $manufacturer->translations->firstWhere('locale', $locale)
                ?? $manufacturer->translations->firstWhere('locale', $fallbackLocale)
                ?? $manufacturer->translations->first();
            $name = trim((string) ($translation?->name ?: $manufacturer->code));
            $logo = $manufacturer->getFirstMedia('manufacturer_logo');
            $uploadedLogoUrl = MediaUrl::hasUsableOriginal($logo) ? (string) $logo->getUrl() : null;
            $knownLogoUrl = trim((string) config('manufacturer_logos.'.$manufacturer->code, ''));
            $logoUrl = $uploadedLogoUrl ?: ($knownLogoUrl !== '' ? $knownLogoUrl : null);
            $nameParts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $initials = collect($nameParts)
                ->take(2)
                ->map(fn (string $part): string => mb_substr($part, 0, 1))
                ->implode('');

            return [
                'manufacturer' => $manufacturer,
                'name' => $name,
                'slug' => $translation?->slug ?? (string) $manufacturer->id,
                'letter' => $this->manufacturerInitial($name),
                'initials' => Str::upper($initials !== '' ? $initials : mb_substr($name, 0, 2)),
                'logo_url' => $logoUrl,
                'products_count' => (int) $manufacturer->products_count,
            ];
        });

        if (class_exists(\Collator::class)) {
            $collator = new \Collator('en_US');

            return $items
                ->sort(fn (array $left, array $right): int => $collator->compare($left['name'], $right['name']))
                ->values();
        }

        return $items
            ->sortBy(fn (array $item): string => Str::lower(Str::ascii($item['name'])), SORT_NATURAL)
            ->values();
    }

    private function manufacturerInitial(string $name): string
    {
        $initial = mb_substr(Str::upper(Str::ascii(trim($name))), 0, 1);

        return in_array($initial, self::ALPHABET, true) ? $initial : '#';
    }
}
