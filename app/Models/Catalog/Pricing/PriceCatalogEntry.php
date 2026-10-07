<?php

namespace App\Models\Catalog\Pricing;

use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PriceCatalogEntry extends Model
{
    public const GROUP = 'group';

    public const BASE = 'base';

    public const CUSTOMER = 'customer';

    public const QUANTITY = 'quantity';

    public const SPECIAL = 'special';

    public const GROUP_DISCOUNT = 'group_discount';

    protected $table = 'catalog_price_entries';

    protected $fillable = ['price_catalog_id', 'discount_rule_id', 'source_key', 'product_id', 'kind', 'customer_group_id', 'user_id', 'minimum_quantity', 'price', 'priority', 'starts_at', 'ends_at', 'is_active', 'payload'];

    protected function casts(): array
    {
        return ['minimum_quantity' => 'integer', 'priority' => 'integer', 'price' => 'decimal:4', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'payload' => 'array'];
    }

    protected static function booted(): void
    {
        $ensureDraft = static function (self $entry): void {
            $ids = array_unique(array_filter([$entry->price_catalog_id, $entry->getOriginal('price_catalog_id')]));
            if (PriceCatalog::query()->whereIn('id', $ids)->where('status', '!=', PriceCatalog::DRAFT)->exists()) {
                throw ValidationException::withMessages(['catalog' => 'Objavljeni cjenik je zaključan. Najprije napravite novu radnu kopiju.']);
            }
        };
        static::saving($ensureDraft);
        static::deleting($ensureDraft);
    }

    public static function kindOptions(): array
    {
        return [self::GROUP => 'Cijena grupe', self::CUSTOMER => 'Individualna cijena', self::QUANTITY => 'Količinska cijena', self::SPECIAL => 'Akcijska cijena', self::BASE => 'Osnovna cijena', self::GROUP_DISCOUNT => 'Popust grupe'];
    }

    public static function manualKindOptions(): array
    {
        return array_diff_key(self::kindOptions(), [self::GROUP_DISCOUNT => true]);
    }

    /** Kept identical in individual resolution and SQL price filtering/sorting. */
    public static function precedenceSql(string $prefix = ''): string
    {
        return "CASE {$prefix}kind WHEN 'group_discount' THEN 6 WHEN 'special' THEN 5 WHEN 'quantity' THEN 4 WHEN 'customer' THEN 3 WHEN 'group' THEN 2 ELSE 1 END DESC";
    }

    public static function manualGroupPrecedenceSql(string $prefix = ''): string
    {
        return "CASE WHEN {$prefix}kind = 'group' AND {$prefix}source_key LIKE 'manual-group:%' THEN 0 ELSE 1 END";
    }

    public function discountRule(): BelongsTo
    {
        return $this->belongsTo(CatalogGroupDiscountRule::class, 'discount_rule_id');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(PriceCatalog::class, 'price_catalog_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
