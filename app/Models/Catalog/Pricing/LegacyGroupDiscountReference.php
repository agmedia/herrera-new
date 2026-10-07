<?php

namespace App\Models\Catalog\Pricing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Dokumentacija izvornog pravila, nikada izvršivo pravilo novog cjenika. */
class LegacyGroupDiscountReference extends Model
{
    protected $table = 'catalog_legacy_group_discount_references';

    protected $guarded = [];

    protected $appends = ['is_active'];

    protected function casts(): array
    {
        return ['percent' => 'decimal:4', 'customer_group_ids' => 'array', 'manufacturer_ids' => 'array', 'category_ids' => 'array', 'excluded_product_ids' => 'array', 'include_descendants' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'priority' => 'integer', 'is_supported' => 'boolean', 'warnings' => 'array', 'source_flags' => 'array', 'definition' => 'array'];
    }

    protected static function booted(): void
    {
        $deny = static function (): void {
            throw ValidationException::withMessages(['legacyReference' => 'Izvorna definicija je samo za čitanje. Pripremite novo pravilo u radnoj kopiji.']);
        };
        static::updating($deny);
        static::deleting($deny);
    }

    /** Aktivno je vremensko razdoblje reference, a ne novo pravilo koje mijenja cijene. */
    public function getIsActiveAttribute(): bool
    {
        if ($this->source_type === 'cigroup_template') {
            return false;
        }

        return (! $this->starts_at || $this->starts_at->lt(now())) && (! $this->ends_at || $this->ends_at->gt(now()));
    }
}
