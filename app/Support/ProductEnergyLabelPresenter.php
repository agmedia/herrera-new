<?php

namespace App\Support;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Services\Integrations\Msan\EprelDocumentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductEnergyLabelPresenter
{
    private const EPREL_NESTED_LABEL_BASE = 'https://ec.europa.eu/assets/move-ener/eprel/EPREL%20Public/Nested-labels%20thumbnails/';

    /** @var array<string, string> */
    private const CLASS_COLORS = [
        'A+++' => '#00845a',
        'A++' => '#00845a',
        'A+' => '#159447',
        'A' => '#00a651',
        'B' => '#50b848',
        'C' => '#bed62f',
        'D' => '#fff200',
        'E' => '#fdb913',
        'F' => '#f36f21',
        'G' => '#ed1c24',
    ];

    /**
     * @return array{
     *   id:int|null,context_code:string,label:string,energy_class:string,scale_min:string,scale_max:string,
     *   scale_label:string,color:string,text_color:string,energy_label_url:?string,energy_class_image_url:?string,product_information_sheet_url:?string,
     *   eprel_registration_number:?string,eprel_product_group:?string,source:string,is_primary:bool,has_arrow:bool,is_complete:bool,has_documents:bool
     * }|null
     */
    public function primary(Product $product): ?array
    {
        $primary = $this->primaryDeclaration($product);

        return $primary && (bool) $primary['is_complete'] ? $primary : null;
    }

    /**
     * Returns the selected declaration even when it is not complete enough to
     * render an energy arrow. This keeps an available PIS link visible.
     *
     * @return array<string, mixed>|null
     */
    public function primaryDeclaration(Product $product): ?array
    {
        return $this->declarations($product)->firstWhere('is_primary', true);
    }

    /**
     * @return Collection<int, array{
     *   id:int|null,context_code:string,label:string,energy_class:string,scale_min:string,scale_max:string,
     *   scale_label:string,color:string,text_color:string,energy_label_url:?string,energy_class_image_url:?string,product_information_sheet_url:?string,
     *   eprel_registration_number:?string,eprel_product_group:?string,source:string,is_primary:bool,has_arrow:bool,is_complete:bool,has_documents:bool
     * }>
     */
    public function declarations(Product $product): Collection
    {
        $declarations = $product->relationLoaded('energyDeclarations')
            ? $product->energyDeclarations
            : collect();

        if ($declarations->isEmpty() && $this->hasLegacyProjection($product)) {
            $scale = preg_split('/\s*[-–—]\s*/u', trim((string) $product->energy_efficiency_scale), 2) ?: [];
            $declarations = collect([new ProductEnergyDeclaration([
                'context_code' => 'primary',
                'label' => __('ui.product.energy_label'),
                'energy_class' => $product->energy_efficiency_class,
                'scale_min' => $scale[0] ?? null,
                'scale_max' => $scale[1] ?? null,
                'eprel_registration_number' => $product->eprel_registration_number,
                'eprel_product_group' => $product->eprel_product_group,
                'energy_label_image' => $product->eprel_energy_label_image,
                'energy_label_url' => $product->energy_label_url,
                'product_information_sheet_url' => $product->product_information_sheet_url,
                'is_primary' => true,
                'source' => trim((string) $product->eprel_registration_number) !== '' && ! $this->hasManualLegacyDocument($product)
                    ? ProductEnergyDeclaration::SOURCE_EPREL
                    : ProductEnergyDeclaration::SOURCE_MANUAL,
            ])]);
        }

        if ($declarations->isEmpty() && ($importedClass = $this->importedEnergyClass($product))) {
            $declarations = collect([new ProductEnergyDeclaration([
                'context_code' => 'herrera-imported-attribute',
                'label' => __('ui.product.energy_label'),
                'energy_class' => $importedClass,
                'scale_min' => 'A',
                'scale_max' => 'G',
                'is_primary' => true,
                'source' => 'herrera-opencart',
            ])]);
        }

        $labelMedia = $this->loadedMedia($product, 'product_energy_label');
        $sheetMedia = $this->loadedMedia($product, 'product_information_sheet');
        $hasExplicitPrimary = $declarations->contains(
            static fn (ProductEnergyDeclaration $declaration): bool => (bool) $declaration->is_primary,
        );

        return $declarations
            ->map(function (ProductEnergyDeclaration $declaration, int $index) use ($product, $hasExplicitPrimary, $labelMedia, $sheetMedia): array {
                $energyClass = $this->energyClass($declaration->energy_class);
                $scaleMin = $this->energyClass($declaration->scale_min);
                $scaleMax = $this->energyClass($declaration->scale_max);
                $isPrimary = $hasExplicitPrimary
                    ? (bool) $declaration->is_primary
                    : $index === 0;
                $source = trim((string) $declaration->source) ?: ProductEnergyDeclaration::SOURCE_MANUAL;
                $eprelGroup = $this->eprelGroup($declaration->eprel_product_group);
                $eprelRegistration = $this->eprelRegistration($declaration->eprel_registration_number);
                $energyClassImageUrl = $this->eprelNestedLabelUrl($declaration->energy_label_image);
                $officialDeclaration = $source === ProductEnergyDeclaration::SOURCE_EPREL;
                $energyLabelUrl = ($officialDeclaration
                    ? app(EprelDocumentService::class)->url($product, $declaration, 'label')
                    : $this->safeAssetUrl($declaration->energy_label_url))
                    ?? ($isPrimary ? $this->mediaUrl($labelMedia) : null);
                $sheetUrl = ($officialDeclaration
                    ? app(EprelDocumentService::class)->url($product, $declaration, 'sheet')
                    : $this->safeAssetUrl($declaration->product_information_sheet_url))
                    ?? ($isPrimary ? $this->mediaUrl($sheetMedia) : null);
                $hasArrow = $energyClass !== null
                    && $scaleMin !== null
                    && $scaleMax !== null
                    && $this->isValidScaleRange($energyClass, $scaleMin, $scaleMax);
                $isComplete = $hasArrow && $energyLabelUrl !== null;

                return [
                    'id' => $declaration->exists ? (int) $declaration->getKey() : null,
                    'context_code' => trim((string) $declaration->context_code),
                    'label' => trim((string) $declaration->label) ?: __('ui.product.energy_label'),
                    'energy_class' => $energyClass ?? '',
                    'scale_min' => $scaleMin ?? '',
                    'scale_max' => $scaleMax ?? '',
                    'scale_label' => $scaleMin && $scaleMax ? $scaleMin.'–'.$scaleMax : '',
                    'color' => self::CLASS_COLORS[$energyClass] ?? '#475569',
                    'text_color' => in_array($energyClass, ['C', 'D'], true) ? '#111827' : '#ffffff',
                    'energy_label_url' => $energyLabelUrl,
                    'energy_class_image_url' => $energyClassImageUrl,
                    'product_information_sheet_url' => $sheetUrl,
                    'eprel_registration_number' => $eprelRegistration,
                    'eprel_product_group' => $eprelGroup,
                    'source' => $source,
                    'is_primary' => $isPrimary,
                    'has_arrow' => $hasArrow,
                    'is_complete' => $isComplete,
                    'has_documents' => $energyLabelUrl !== null || $sheetUrl !== null,
                ];
            })
            ->sortByDesc('is_primary')
            ->values();
    }

    private function hasLegacyProjection(Product $product): bool
    {
        return trim((string) $product->energy_efficiency_class) !== ''
            || trim((string) $product->energy_label_url) !== ''
            || trim((string) $product->product_information_sheet_url) !== '';
    }

    private function hasManualLegacyDocument(Product $product): bool
    {
        foreach ([$product->energy_label_url, $product->product_information_sheet_url] as $value) {
            $url = $this->safeAssetUrl($value);
            if ($url !== null && strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'eprel.ec.europa.eu') {
                return true;
            }
        }

        return false;
    }

    private function importedEnergyClass(Product $product): ?string
    {
        $labels = ['razina energetske učinkovitosti', 'klasa energetske učinkovitosti eei'];
        $classes = [];
        $collectClass = function (mixed $label, mixed $value) use ($labels, &$classes): void {
            $label = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $label) ?? ''));
            $class = is_scalar($value) ? $this->energyClass($value) : null;
            if (in_array($label, $labels, true) && $class !== null && preg_match('/^[A-G]$/', $class)) {
                $classes[$class] = true;
            }
        };

        if ($product->relationLoaded('technicalSpecificationRows')) {
            foreach ($product->technicalSpecificationRows as $row) {
                if ($row->source !== 'herrera-opencart') {
                    continue;
                }
                foreach ((array) $row->values as $value) {
                    $collectClass($row->item_name, $value);
                }
            }
        }
        if ($product->relationLoaded('attributes')) {
            foreach ($product->attributes as $attribute) {
                if (data_get($attribute->payload, 'source') !== 'herrera-opencart' || ! $attribute->relationLoaded('translations')) {
                    continue;
                }
                foreach ($attribute->translations as $translation) {
                    $collectClass($translation->group_name, $translation->name);
                }
            }
        }

        return count($classes) === 1 ? array_key_first($classes) : null;
    }

    private function energyClass(mixed $value): ?string
    {
        $normalized = Str::upper(preg_replace('/\s+/u', '', trim((string) $value)) ?? '');

        return in_array($normalized, ProductEnergyDeclaration::ENERGY_CLASSES, true)
            ? $normalized
            : null;
    }

    private function isValidScaleRange(string $energyClass, string $scaleMin, string $scaleMax): bool
    {
        $classIndex = array_search($energyClass, ProductEnergyDeclaration::ENERGY_CLASSES, true);
        $minimumIndex = array_search($scaleMin, ProductEnergyDeclaration::ENERGY_CLASSES, true);
        $maximumIndex = array_search($scaleMax, ProductEnergyDeclaration::ENERGY_CLASSES, true);

        return $classIndex !== false
            && $minimumIndex !== false
            && $maximumIndex !== false
            && $minimumIndex <= $classIndex
            && $classIndex <= $maximumIndex;
    }

    private function safeAssetUrl(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '..')) {
            return null;
        }

        if (Str::startsWith($value, '/')
            && ! Str::startsWith($value, '//')
            && preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]+$#', $value) === 1) {
            return url($value);
        }

        if (! Str::contains($value, '://') && preg_match('#^(?:storage|media)/[A-Za-z0-9._/-]+$#', $value) === 1) {
            return asset($value);
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! isset($parts['user'], $parts['pass'])
            ? $value
            : null;
    }

    private function eprelNestedLabelUrl(mixed $value): ?string
    {
        $fileName = trim((string) $value);
        if ($fileName === '' || mb_strlen($fileName) > 191) {
            return null;
        }

        if (str_contains($fileName, '..')
            || str_contains($fileName, '/')
            || str_contains($fileName, '\\')
            || str_contains($fileName, '?')
            || str_contains($fileName, '#')
            || preg_match('/\.(?:png|svg|jpe?g|webp)$/i', $fileName) !== 1
            || preg_match('/^[A-Za-z0-9 _()+.,-]+$/', $fileName) !== 1) {
            return null;
        }

        return self::EPREL_NESTED_LABEL_BASE.rawurlencode($fileName);
    }

    private function eprelGroup(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-z0-9-]{2,100}$/', $value) === 1 ? $value : null;
    }

    private function eprelRegistration(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{3,20}$/', $value) === 1 ? $value : null;
    }

    private function loadedMedia(Product $product, string $collection): ?Media
    {
        if (! $product->relationLoaded('energyMedia')) {
            return null;
        }

        return $product->energyMedia->firstWhere('collection_name', $collection);
    }

    private function mediaUrl(?Media $media): ?string
    {
        return $media ? (string) $media->getUrl() : null;
    }
}
