<?php

namespace App\View\Components\Front\Desktop;

use App\Models\Catalog\Product\Product;
use App\Services\Front\WishlistService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\LegacyCatalogImage;
use App\Support\Media\MediaUrl;
use App\Support\ProductEnergyLabelPresenter;
use App\Support\ProductMaterialLabel;
use Illuminate\View\Component;
use Illuminate\View\View;

class ProductCard extends Component
{
    /** @var array{display_current:float,current_price:string,old_price:?string,discount_percent:?int,lowest_30_days_price:?string,is_b2b_price:bool,display_includes_tax:bool}|null */
    private ?array $priceData = null;

    public function __construct(
        public Product $product,
        public ?string $locale = null,
        public ?string $fallbackLocale = null,
        public bool $flat = false,
        public bool $lined = false,
        public bool $priorityImage = false,
        public int $headingLevel = 3,
    ) {}

    public function render(): View
    {
        $locale = $this->locale ?: app()->getLocale();
        $fallbackLocale = $this->fallbackLocale ?: (string) config('app.locale');

        $translation = $this->product->translations->firstWhere('locale', $locale)
            ?? $this->product->translations->firstWhere('locale', $fallbackLocale);

        $mediaItems = $this->product->relationLoaded('media')
            ? $this->product->media->whereIn('collection_name', ['product_main', 'product_gallery'])->values()
            : collect();
        $usableOriginalMediaItems = $mediaItems
            ->filter(fn ($media) => MediaUrl::hasUsableOriginal($media))
            ->values();
        $usableMediaItems = $mediaItems
            ->filter(fn ($media) => MediaUrl::hasUsableSource($media, ['card_720w', 'card_480w', 'card_320w']))
            ->values();

        $mainMedia = $usableOriginalMediaItems->firstWhere('collection_name', 'product_main')
            ?? $usableOriginalMediaItems->firstWhere('collection_name', 'product_gallery')
            ?? $usableMediaItems->firstWhere('collection_name', 'product_main')
            ?? $usableMediaItems->firstWhere('collection_name', 'product_gallery')
            ?? $this->product->getMedia('*')
                ->whereIn('collection_name', ['product_main', 'product_gallery'])
                ->first(fn ($media) => MediaUrl::hasUsableSource($media, ['card_720w', 'card_480w', 'card_320w']));

        $hoverMedia = $usableMediaItems->first(
            static fn ($media): bool => $media->collection_name === 'product_gallery'
                && (! $mainMedia || (int) $media->id !== (int) $mainMedia->id)
        );
        if (! $hoverMedia) {
            $hoverMedia = $this->product->getMedia('product_gallery')->first(
                static fn ($media): bool => MediaUrl::hasUsableSource($media, ['card_720w', 'card_480w', 'card_320w'])
                    && (! $mainMedia || (int) $media->id !== (int) $mainMedia->id)
            );
        }
        if (! $hoverMedia) {
            $hoverMedia = $this->product
                ->getMedia('*')
                ->whereIn('collection_name', ['product_main', 'product_gallery'])
                ->first(static fn ($media): bool => MediaUrl::hasUsableSource($media, ['card_720w', 'card_480w', 'card_320w'])
                    && (! $mainMedia || (int) $media->id !== (int) $mainMedia->id));
        }
        $settings = app(SystemSettingsService::class);
        $preferWebp = (bool) $settings->get('store_images_use_webp', true);
        $mobileColumns = $settings->getInt('store_product_mobile_default_cols', 2, 1, 2);
        $imageSizes = $mobileColumns === 1
            ? '(max-width: 767px) 88vw, (max-width: 1279px) 30vw, 24vw'
            : '(max-width: 767px) 42vw, (max-width: 1279px) 30vw, 24vw';

        $imageUrl720 = MediaUrl::conversionOrNull($mainMedia, 'card_720w', $preferWebp);
        $imageUrl480 = MediaUrl::conversionOrNull($mainMedia, 'card_480w', $preferWebp);
        $imageUrl320 = MediaUrl::conversionOrNull($mainMedia, 'card_320w', $preferWebp);
        $hoverImageUrl720 = MediaUrl::conversionOrNull($hoverMedia, 'card_720w', $preferWebp);
        $hoverImageUrl480 = MediaUrl::conversionOrNull($hoverMedia, 'card_480w', $preferWebp);
        $hoverImageUrl320 = MediaUrl::conversionOrNull($hoverMedia, 'card_320w', $preferWebp);

        $imageUrl = $imageUrl720 ?? $imageUrl480 ?? $imageUrl320 ?? ($mainMedia ? (string) $mainMedia->getUrl() : null);
        $hoverImageUrl = $hoverImageUrl720 ?? $hoverImageUrl480 ?? $hoverImageUrl320 ?? ($hoverMedia ? (string) $hoverMedia->getUrl() : null);
        $imageOriginalUrl = $mainMedia ? (string) $mainMedia->getUrl() : null;
        $hoverImageOriginalUrl = $hoverMedia ? (string) $hoverMedia->getUrl() : null;

        if (! $imageUrl) {
            $legacyImages = LegacyCatalogImage::gallery($this->product);
            $imageUrl = $imageOriginalUrl = $legacyImages->first();
            $hoverImageUrl = $hoverImageOriginalUrl = $legacyImages->get(1);
        }

        $imageSrcset = collect([
            $imageUrl320 ? $imageUrl320.' 320w' : null,
            $imageUrl480 ? $imageUrl480.' 480w' : null,
            $imageUrl720 ? $imageUrl720.' 720w' : null,
            $imageOriginalUrl ? $imageOriginalUrl.' '.max(1, (int) ($mainMedia?->width ?? 1000)).'w' : null,
        ])->filter()->unique()->implode(', ');

        $hoverImageSrcset = collect([
            $hoverImageUrl320 ? $hoverImageUrl320.' 320w' : null,
            $hoverImageUrl480 ? $hoverImageUrl480.' 480w' : null,
            $hoverImageUrl720 ? $hoverImageUrl720.' 720w' : null,
            $hoverImageOriginalUrl ? $hoverImageOriginalUrl.' '.max(1, (int) ($hoverMedia?->width ?? 1000)).'w' : null,
        ])->filter()->unique()->implode(', ');
        $imageWidth = max(1, (int) ($mainMedia?->width ?? 480));
        $imageHeight = max(1, (int) ($mainMedia?->height ?? 640));
        $hoverImageWidth = max(1, (int) ($hoverMedia?->width ?? $imageWidth));
        $hoverImageHeight = max(1, (int) ($hoverMedia?->height ?? $imageHeight));

        $visibleOptionRows = $this->product->visibleOptionRows();
        $availableOptionRows = $this->product->availableOptionRows();

        $optionRows = $availableOptionRows
            ->values()
            ->map(function ($row) use ($locale, $fallbackLocale): array {
                $rowId = (int) $row->id;
                $valueTranslation = $row->optionValue?->translations?->firstWhere('locale', $locale)
                    ?? $row->optionValue?->translations?->firstWhere('locale', $fallbackLocale)
                    ?? $row->optionValue?->translations?->first();
                $parentTranslation = $row->parentOptionValue?->translations?->firstWhere('locale', $locale)
                    ?? $row->parentOptionValue?->translations?->firstWhere('locale', $fallbackLocale)
                    ?? $row->parentOptionValue?->translations?->first();
                $valueLabel = trim((string) ($valueTranslation?->name ?? $row->optionValue?->code ?? ''));
                $parentLabel = trim((string) ($parentTranslation?->name ?? $row->parentOptionValue?->code ?? ''));
                $label = $parentLabel !== '' && $valueLabel !== ''
                    ? $parentLabel.' / '.$valueLabel
                    : ($valueLabel !== '' ? $valueLabel : $parentLabel);

                return [
                    'id' => $rowId,
                    'input_id' => 'pov-'.$this->product->id.'-'.$rowId,
                    'label' => $label,
                ];
            })
            ->values()
            ->all();

        $authUser = auth()->user();
        if ($this->priceData === null) {
            $priceData = app(ProductPricePresentationService::class)->forProduct($this->product, $authUser);
            $displayCurrent = $priceData['display_current'] ?? $priceData['current_gross'];
            $displayOld = $priceData['display_old'] ?? $priceData['old_gross'];
            $displayLowest = $priceData['display_lowest_30_days'] ?? $priceData['lowest_30_days_gross'];

            $this->priceData = [
                'display_current' => (float) $displayCurrent,
                'current_price' => number_format((float) $displayCurrent, 2).' €',
                'old_price' => $displayOld !== null ? number_format((float) $displayOld, 2).' €' : null,
                'discount_percent' => $priceData['discount_percent'],
                'lowest_30_days_price' => $displayLowest !== null
                    ? number_format((float) $displayLowest, 2).' €'
                    : null,
                'is_b2b_price' => (bool) ($priceData['is_b2b_price'] ?? false),
                'display_includes_tax' => (bool) ($priceData['display_includes_tax'] ?? true),
            ];
        }

        $priceData = $this->priceData;
        $manufacturerName = '';
        if ($this->product->relationLoaded('manufacturer')) {
            $manufacturerName = (string) ($this->product->manufacturer?->translations?->firstWhere('locale', $locale)?->name
                ?? $this->product->manufacturer?->translations?->firstWhere('locale', $fallbackLocale)?->name
                ?? '');
        }

        $categoryName = '';
        if ($this->product->relationLoaded('categories')) {
            $categoryName = (string) ($this->product->categories?->first()?->translations?->firstWhere('locale', $locale)?->name
                ?? $this->product->categories?->first()?->translations?->firstWhere('locale', $fallbackLocale)?->name
                ?? '');
        }

        $energyDeclaration = app(ProductEnergyLabelPresenter::class)->primaryDeclaration($this->product);

        return view('components.front.desktop.product-card', [
            'cardDomId' => 'pc-'.(int) $this->product->id.'-'.substr(str_replace('.', '', uniqid('', true)), -10),
            'productId' => (int) $this->product->id,
            'productUrl' => route('products.show', ['slug' => $translation?->slug ?? $this->product->id]),
            'productName' => $translation?->name ?? $this->product->code,
            'materialLabel' => ProductMaterialLabel::resolve($this->product, $locale, $fallbackLocale),
            'productSku' => (string) ($this->product->sku ?: $this->product->id),
            'productDisplayCode' => trim((string) data_get($this->product->payload, 'opencart.model')) ?: (string) ($this->product->sku ?: $this->product->code),
            'productEanCode' => trim((string) $this->product->barcode) ?: trim((string) data_get($this->product->payload, 'opencart.ean')),
            'localStockQuantity' => max(0, (int) $this->product->stock_qty),
            'supplierStockQuantity' => max(0, (int) ($this->product->supplier_stock_qty ?? 0)),
            'productPriceValue' => round((float) ($priceData['display_current'] ?? 0), 2),
            'productBrand' => $manufacturerName,
            'productCategory' => $categoryName,
            'imageUrl' => $imageUrl,
            'cartImageUrl' => $imageOriginalUrl ?? $imageUrl,
            'imageUrl320' => $imageUrl320,
            'imageSrcset' => $imageSrcset,
            'imageSizes' => $imageSizes,
            'hoverImageUrl' => $hoverImageUrl,
            'hoverImageUrl320' => $hoverImageUrl320,
            'hoverImageSrcset' => $hoverImageSrcset,
            'imageWidth' => $imageWidth,
            'imageHeight' => $imageHeight,
            'hoverImageWidth' => $hoverImageWidth,
            'hoverImageHeight' => $hoverImageHeight,
            'optionRows' => $optionRows,
            'hasVisibleOptionRows' => $visibleOptionRows->isNotEmpty(),
            'hasAvailableOptionRows' => $availableOptionRows->isNotEmpty(),
            'isPurchasable' => $this->product->storefrontIsPurchasable(),
            'isWishlisted' => app(WishlistService::class)->has((int) $this->product->id),
            'price' => $priceData['current_price'],
            'oldPrice' => $priceData['old_price'],
            'discountPercent' => $priceData['discount_percent'],
            'lowest30DaysPrice' => $priceData['lowest_30_days_price'],
            'isB2BPrice' => $priceData['is_b2b_price'],
            'displayIncludesTax' => $priceData['display_includes_tax'],
            'reviewSummary' => $this->product->approvedCommentSummary([$locale, $fallbackLocale]),
            'energyDeclaration' => $energyDeclaration,
            'flat' => $this->flat,
            'lined' => $this->lined,
            'priorityImage' => $this->priorityImage,
            'headingLevel' => $this->headingLevel === 2 ? 2 : 3,
        ]);
    }
}
