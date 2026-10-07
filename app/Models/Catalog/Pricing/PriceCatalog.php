<?php

namespace App\Models\Catalog\Pricing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceCatalog extends Model
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $table = 'catalog_price_catalogs';

    protected $fillable = ['name', 'status', 'currency_code', 'source_system', 'source_snapshot', 'source_checksum', 'metadata', 'activated_at', 'created_by', 'activated_by'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'activated_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PriceCatalogEntry::class, 'price_catalog_id');
    }

    public function discountRules(): HasMany
    {
        return $this->hasMany(CatalogGroupDiscountRule::class, 'price_catalog_id')->orderBy('priority')->orderBy('id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(PriceCatalogAudit::class, 'price_catalog_id')->orderByDesc('id');
    }
}
