<?php

namespace App\Models\Catalog\Product;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Option\Option;
use App\Models\Concerns\HasConfiguredMedia;
use App\Models\Content\Support\Comment;
use App\Models\Settings\Local\TaxRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use HasConfiguredMedia;

    /** @var array<string, array{count:int,avg:float}> */
    private static array $approvedCommentSummaryCache = [];

    protected $fillable = [
        'code',
        'sku',
        'barcode',
        'unit_of_measure',
        'minimum_order_quantity',
        'order_quantity_step',
        'is_active',
        'manufacturer_id',
        'tax_rate_id',
        'base_price',
        'erp_gross_list_price',
        'erp_cash_discount_percent',
        'erp_cash_selling_price',
        'stock_qty',
        'weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
        'shipping_labels',
        'technical_specifications',
        'energy_label_required',
        'energy_efficiency_class',
        'energy_efficiency_scale',
        'eprel_registration_number',
        'eprel_product_group',
        'eprel_lookup_product_group',
        'eprel_energy_label_image',
        'energy_label_url',
        'product_information_sheet_url',
        'energy_data_synced_at',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'manufacturer_id' => 'int',
        'tax_rate_id' => 'int',
        'base_price' => 'decimal:2',
        'erp_gross_list_price' => 'decimal:4',
        'erp_cash_discount_percent' => 'decimal:4',
        'erp_cash_selling_price' => 'decimal:4',
        'stock_qty' => 'int',
        'minimum_order_quantity' => 'int',
        'order_quantity_step' => 'int',
        'weight_kg' => 'decimal:3',
        'length_cm' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'shipping_labels' => 'array',
        'technical_specifications' => 'array',
        'energy_label_required' => 'bool',
        'energy_data_synced_at' => 'datetime',
        'payload' => 'array',
    ];

    public static function unitOptions(): array
    {
        return [
            'pcs' => 'kom',
            'kg' => 'kg',
            'g' => 'g',
            'l' => 'l',
            'ml' => 'ml',
            'm' => 'm',
            'cm' => 'cm',
            'm2' => 'm²',
            'm3' => 'm³',
            'pack' => 'paket',
            'box' => 'kutija',
            'pallet' => 'paleta',
        ];
    }

    public static function shippingLabelOptions(): array
    {
        return [
            'fragile' => 'Lomljivo',
            'oversized' => 'Vanstandardne dimenzije',
            'heavy' => 'Teški teret',
            'refrigerated' => 'Hladni lanac',
            'hazardous' => 'Opasna roba',
            'ships_separately' => 'Šalje se odvojeno',
            'free_shipping' => 'Besplatna dostava',
            'exclude_shipping_calculation' => 'Ne ulazi u obračun dostave',
            'no_parcel_locker' => 'Nije dopušten paketomat',
            'quote_shipping' => 'Individualni obračun dostave',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(ProductTranslation::class)->where('locale', $locale);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)
            ->withPivot(['sort_order', 'is_primary'])
            ->withTimestamps();
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(Option::class, 'catalog_option_product', 'product_id', 'option_id')
            ->withPivot(['sort_order', 'is_required'])
            ->withTimestamps();
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'catalog_attribute_product', 'product_id', 'attribute_id')
            ->withPivot(['sort_order'])
            ->withTimestamps();
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    public function optionValues(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class, 'product_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ProductPackage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function groupPrices(): HasMany
    {
        return $this->hasMany(ProductGroupPrice::class);
    }

    public function energyDeclarations(): HasMany
    {
        return $this->hasMany(ProductEnergyDeclaration::class)
            ->orderByDesc('is_primary')
            ->orderBy('id');
    }

    public function technicalSpecificationRows(): HasMany
    {
        return $this->hasMany(CatalogProductSpecification::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function energyMedia(): MorphMany
    {
        return $this->morphMany(Media::class, 'model')
            ->whereIn('collection_name', ['product_energy_label', 'product_information_sheet'])
            ->orderBy('order_column')
            ->orderBy('id');
    }

    /**
     * Eager-loads all locally persisted data needed to render the EU energy
     * label next to a storefront price. Rendering never calls a supplier API.
     */
    public function scopeWithStorefrontEnergyData(Builder $query): Builder
    {
        return $query->with([
            'energyDeclarations' => fn ($declarations) => $declarations->select([
                'id',
                'product_id',
                'context_code',
                'label',
                'energy_class',
                'scale_min',
                'scale_max',
                'eprel_registration_number',
                'eprel_product_group',
                'energy_label_image',
                'energy_label_url',
                'product_information_sheet_url',
                'is_primary',
                'source',
                'synced_at',
            ]),
            'energyMedia',
        ]);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class)
            ->latest('effective_at')
            ->latest('id');
    }

    public function directActions(): BelongsToMany
    {
        return $this->belongsToMany(CatalogAction::class, 'catalog_action_targets', 'target_id', 'action_id')
            ->wherePivot('target_type', CatalogAction::TARGET_PRODUCT)
            ->withPivot(['sort_order'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function scopeWithApprovedCommentSummary(Builder $query, array $locales = []): Builder
    {
        $normalizedLocales = collect($locales)
            ->map(fn ($locale): string => trim((string) $locale))
            ->filter(fn (string $locale): bool => $locale !== '')
            ->unique()
            ->values()
            ->all();

        $approvedComments = function (Builder $commentQuery) use ($normalizedLocales): void {
            $commentQuery
                ->whereNull('parent_id')
                ->where('status', Comment::STATUS_APPROVED);

            if ($normalizedLocales !== []) {
                $commentQuery->whereIn('locale', $normalizedLocales);
            }
        };

        $approvedRatings = function (Builder $commentQuery) use ($approvedComments): void {
            $approvedComments($commentQuery);
            $commentQuery->whereNotNull('rating');
        };

        return $query
            ->withCount([
                'comments as approved_comments_count' => $approvedComments,
            ])
            ->withAvg([
                'comments as approved_comments_avg_rating' => $approvedRatings,
            ], 'rating');
    }

    public function scopeVisibleOnStorefront(Builder $query, bool $hideOutOfStock = false): Builder
    {
        $query->where('products.is_active', true);

        if (! $hideOutOfStock) {
            return $query;
        }

        return $query->where(function (Builder $stockQuery): void {
            $stockQuery
                ->where('products.stock_qty', '>', 0)
                ->orWhereHas('optionValues', function (Builder $optionQuery): void {
                    $optionQuery
                        ->where('is_active', true)
                        ->where('stock_qty', '>', 0);
                });
        });
    }

    /**
     * @param  array<int, string>  $locales
     * @return array{count:int,avg:float}
     */
    public function approvedCommentSummary(array $locales = []): array
    {
        $attributes = $this->getAttributes();
        if (array_key_exists('approved_comments_count', $attributes) && array_key_exists('approved_comments_avg_rating', $attributes)) {
            return [
                'count' => (int) ($attributes['approved_comments_count'] ?? 0),
                'avg' => round((float) ($attributes['approved_comments_avg_rating'] ?? 0), 2),
            ];
        }

        $normalizedLocales = collect($locales)
            ->map(fn ($locale): string => trim((string) $locale))
            ->filter(fn (string $locale): bool => $locale !== '')
            ->unique()
            ->values()
            ->all();

        $cacheKey = (string) $this->getKey().'|'.implode(',', $normalizedLocales);
        if (! isset(self::$approvedCommentSummaryCache[$cacheKey])) {
            $statsQuery = $this->comments()
                ->whereNull('parent_id')
                ->where('status', Comment::STATUS_APPROVED);

            if ($normalizedLocales !== []) {
                $statsQuery->whereIn('locale', $normalizedLocales);
            }

            $stats = $statsQuery
                ->selectRaw('COUNT(*) as review_count, COALESCE(AVG(rating), 0) as avg_rating')
                ->first();

            self::$approvedCommentSummaryCache[$cacheKey] = [
                'count' => (int) ($stats?->review_count ?? 0),
                'avg' => round((float) ($stats?->avg_rating ?? 0), 2),
            ];
        }

        return self::$approvedCommentSummaryCache[$cacheKey];
    }

    /**
     * @return Collection<int, ProductOptionValue>
     */
    public function visibleOptionRows(bool $inStockOnly = false): Collection
    {
        $this->loadMissing([
            'optionValues.optionValue.option:id,payload',
            'optionValues.parentOptionValue.option:id,payload',
        ]);

        $rows = $this->optionValues
            ->where('is_active', true)
            ->filter(static fn (ProductOptionValue $row): bool => $row->showsOnProductPage())
            ->values();

        if (! $inStockOnly) {
            return $rows;
        }

        return $rows
            ->filter(static fn (ProductOptionValue $row): bool => (int) $row->stock_qty > 0)
            ->values();
    }

    /**
     * @return Collection<int, ProductOptionValue>
     */
    public function availableOptionRows(): Collection
    {
        return $this->visibleOptionRows(inStockOnly: true);
    }

    public function hasVisibleOptionRows(): bool
    {
        return $this->visibleOptionRows()->isNotEmpty();
    }

    public function hasAvailableOptionRows(): bool
    {
        return $this->availableOptionRows()->isNotEmpty();
    }

    public function storefrontIsPurchasable(): bool
    {
        if ($this->hasVisibleOptionRows()) {
            return $this->hasAvailableOptionRows();
        }

        return (int) $this->stock_qty > 0;
    }
}
