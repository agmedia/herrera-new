<?php

namespace App\Data\Import;

use InvalidArgumentException;

final readonly class CatalogProductData
{
    public string $sourceId;

    public CatalogLifecycleStatus $status;

    public ?string $code;

    public ?string $sku;

    /** @var list<CatalogTranslationData> */
    public array $translations;

    public ?string $basePrice;

    public int $stockQty;

    /** @var list<string> */
    public array $categorySourceIds;

    /** @var list<string> */
    public array $attributeSourceIds;

    public ?string $barcode;

    public string $unitOfMeasure;

    public int $minimumOrderQuantity;

    public int $orderQuantityStep;

    public ?string $weightKg;

    public ?string $lengthCm;

    public ?string $widthCm;

    public ?string $heightCm;

    /** @var list<string> */
    public array $shippingLabels;

    public ?string $erpGrossListPrice;

    public ?string $erpCashDiscountPercent;

    public ?string $erpCashSellingPrice;

    /** @var array<string, mixed> */
    public array $payload;

    /**
     * Deleted records may contain only sourceId and status. All other lifecycle
     * states are complete normalized snapshots.
     *
     * @param  list<CatalogTranslationData>  $translations
     * @param  list<string>  $categorySourceIds
     * @param  list<string>  $attributeSourceIds
     * @param  list<string>  $shippingLabels
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $sourceId,
        CatalogLifecycleStatus|string $status = CatalogLifecycleStatus::Web,
        ?string $code = null,
        ?string $sku = null,
        array $translations = [],
        string|int|float|null $basePrice = null,
        int $stockQty = 0,
        array $categorySourceIds = [],
        array $attributeSourceIds = [],
        ?string $barcode = null,
        string $unitOfMeasure = 'pcs',
        int $minimumOrderQuantity = 1,
        int $orderQuantityStep = 1,
        string|int|float|null $weightKg = null,
        string|int|float|null $lengthCm = null,
        string|int|float|null $widthCm = null,
        string|int|float|null $heightCm = null,
        array $shippingLabels = [],
        string|int|float|null $erpGrossListPrice = null,
        string|int|float|null $erpCashDiscountPercent = null,
        string|int|float|null $erpCashSellingPrice = null,
        array $payload = [],
    ) {
        $sourceId = trim($sourceId);
        $status = CatalogLifecycleStatus::normalize($status);
        $code = self::nullableTrim($code);
        $sku = self::nullableTrim($sku);
        $barcode = self::nullableTrim($barcode);
        $unitOfMeasure = strtolower(trim($unitOfMeasure));
        $basePrice = self::decimal($basePrice, 2, 'base price');
        $erpGrossListPrice = self::decimal($erpGrossListPrice, 4, 'ERP gross list price');
        $erpCashDiscountPercent = self::decimal($erpCashDiscountPercent, 4, 'ERP cash discount percent');
        $erpCashSellingPrice = self::decimal($erpCashSellingPrice, 4, 'ERP cash selling price');

        if ($sourceId === '' || strlen($sourceId) > 191) {
            throw new InvalidArgumentException('A normalized product requires a source ID of at most 191 characters.');
        }

        if (! $status->isTombstone() && ($code === null || $translations === [] || $basePrice === null)) {
            throw new InvalidArgumentException('A non-deleted product requires a code, base price, and at least one translation.');
        }

        if ($code !== null && mb_strlen($code) > 120) {
            throw new InvalidArgumentException('A product code may contain at most 120 characters.');
        }

        if ($sku !== null && mb_strlen($sku) > 120) {
            throw new InvalidArgumentException('A product SKU may contain at most 120 characters.');
        }

        if ($barcode !== null && mb_strlen($barcode) > 80) {
            throw new InvalidArgumentException('A product barcode may contain at most 80 characters.');
        }

        if ($unitOfMeasure === '' || strlen($unitOfMeasure) > 24) {
            throw new InvalidArgumentException('A product unit of measure is required and may contain at most 24 characters.');
        }

        if ($minimumOrderQuantity < 1 || $orderQuantityStep < 1) {
            throw new InvalidArgumentException('Minimum order quantity and order quantity step must be positive integers.');
        }

        if ($erpCashDiscountPercent !== null && (float) $erpCashDiscountPercent > 100) {
            throw new InvalidArgumentException('ERP cash discount percent cannot exceed 100.');
        }

        self::assertTranslations($translations);

        $this->sourceId = $sourceId;
        $this->status = $status;
        $this->code = $code;
        $this->sku = $sku;
        $this->translations = array_values($translations);
        $this->basePrice = $basePrice;
        $this->stockQty = $stockQty;
        $this->categorySourceIds = self::uniqueStrings($categorySourceIds, 'product category source ID');
        $this->attributeSourceIds = self::uniqueStrings($attributeSourceIds, 'product attribute source ID');
        $this->barcode = $barcode;
        $this->unitOfMeasure = $unitOfMeasure;
        $this->minimumOrderQuantity = $minimumOrderQuantity;
        $this->orderQuantityStep = $orderQuantityStep;
        $this->weightKg = self::decimal($weightKg, 3, 'weight');
        $this->lengthCm = self::decimal($lengthCm, 2, 'length');
        $this->widthCm = self::decimal($widthCm, 2, 'width');
        $this->heightCm = self::decimal($heightCm, 2, 'height');
        $this->shippingLabels = self::uniqueStrings($shippingLabels, 'shipping label');
        $this->erpGrossListPrice = $erpGrossListPrice;
        $this->erpCashDiscountPercent = $erpCashDiscountPercent;
        $this->erpCashSellingPrice = $erpCashSellingPrice;
        $this->payload = $payload;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $translations = array_map(
            static fn (mixed $translation): CatalogTranslationData => $translation instanceof CatalogTranslationData
                ? $translation
                : CatalogTranslationData::fromArray(is_array($translation) ? $translation : []),
            is_array($data['translations'] ?? null) ? $data['translations'] : [],
        );

        return new self(
            sourceId: (string) ($data['source_id'] ?? ''),
            status: (string) ($data['status'] ?? CatalogLifecycleStatus::Web->value),
            code: isset($data['code']) ? (string) $data['code'] : null,
            sku: isset($data['sku']) ? (string) $data['sku'] : null,
            translations: $translations,
            basePrice: $data['base_price'] ?? null,
            stockQty: (int) ($data['stock_qty'] ?? 0),
            categorySourceIds: is_array($data['category_source_ids'] ?? null) ? $data['category_source_ids'] : [],
            attributeSourceIds: is_array($data['attribute_source_ids'] ?? null) ? $data['attribute_source_ids'] : [],
            barcode: isset($data['barcode']) ? (string) $data['barcode'] : null,
            unitOfMeasure: (string) ($data['unit_of_measure'] ?? 'pcs'),
            minimumOrderQuantity: (int) ($data['minimum_order_quantity'] ?? 1),
            orderQuantityStep: (int) ($data['order_quantity_step'] ?? 1),
            weightKg: $data['weight_kg'] ?? null,
            lengthCm: $data['length_cm'] ?? null,
            widthCm: $data['width_cm'] ?? null,
            heightCm: $data['height_cm'] ?? null,
            shippingLabels: is_array($data['shipping_labels'] ?? null) ? $data['shipping_labels'] : [],
            erpGrossListPrice: $data['erp_gross_list_price'] ?? null,
            erpCashDiscountPercent: $data['erp_cash_discount_percent'] ?? null,
            erpCashSellingPrice: $data['erp_cash_selling_price'] ?? null,
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'status' => $this->status->value,
            'code' => $this->code,
            'sku' => $this->sku,
            'translations' => array_map(
                static fn (CatalogTranslationData $translation): array => $translation->toArray(),
                $this->translations,
            ),
            'base_price' => $this->basePrice,
            'stock_qty' => $this->stockQty,
            'category_source_ids' => $this->categorySourceIds,
            'attribute_source_ids' => $this->attributeSourceIds,
            'barcode' => $this->barcode,
            'unit_of_measure' => $this->unitOfMeasure,
            'minimum_order_quantity' => $this->minimumOrderQuantity,
            'order_quantity_step' => $this->orderQuantityStep,
            'weight_kg' => $this->weightKg,
            'length_cm' => $this->lengthCm,
            'width_cm' => $this->widthCm,
            'height_cm' => $this->heightCm,
            'shipping_labels' => $this->shippingLabels,
            'erp_gross_list_price' => $this->erpGrossListPrice,
            'erp_cash_discount_percent' => $this->erpCashDiscountPercent,
            'erp_cash_selling_price' => $this->erpCashSellingPrice,
            'payload' => $this->payload,
        ];
    }

    /** @param list<CatalogTranslationData> $translations */
    private static function assertTranslations(array $translations): void
    {
        $locales = [];

        foreach ($translations as $translation) {
            if (! $translation instanceof CatalogTranslationData) {
                throw new InvalidArgumentException('Product translations must be CatalogTranslationData values.');
            }

            if (isset($locales[$translation->locale])) {
                throw new InvalidArgumentException("Duplicate product translation locale [{$translation->locale}].");
            }

            $locales[$translation->locale] = true;
        }
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function uniqueStrings(array $values, string $label): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                throw new InvalidArgumentException("A {$label} cannot be blank.");
            }

            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private static function decimal(string|int|float|null $value, int $scale, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException("The {$label} must be a non-negative number.");
        }

        return number_format((float) $value, $scale, '.', '');
    }

    private static function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
