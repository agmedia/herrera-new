<?php

namespace App\Models\Catalog\Pricing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CatalogGroupDiscountRule extends Model
{
    protected $table = 'catalog_group_discount_rules';

    protected $fillable = ['price_catalog_id', 'name', 'percent', 'customer_group_ids', 'manufacturer_ids', 'category_ids', 'include_descendants', 'excluded_product_ids', 'starts_at', 'ends_at', 'priority', 'is_active', 'materialized_product_count', 'materialized_entry_count', 'materialized_at', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['percent' => 'decimal:4', 'customer_group_ids' => 'array', 'manufacturer_ids' => 'array', 'category_ids' => 'array', 'include_descendants' => 'boolean', 'excluded_product_ids' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'priority' => 'integer', 'is_active' => 'boolean', 'materialized_product_count' => 'integer', 'materialized_entry_count' => 'integer', 'materialized_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        $ensureDraft = static function (self $rule): void {
            $ids = array_unique(array_filter([$rule->price_catalog_id, $rule->getOriginal('price_catalog_id')]));
            if (PriceCatalog::query()->whereIn('id', $ids)->where('status', '!=', PriceCatalog::DRAFT)->exists()) {
                throw ValidationException::withMessages(['catalog' => 'Objavljeni cjenik je zaključan. Najprije napravite novu radnu kopiju.']);
            }
        };
        static::saving($ensureDraft);
        static::deleting($ensureDraft);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(PriceCatalog::class, 'price_catalog_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PriceCatalogEntry::class, 'discount_rule_id');
    }
}
