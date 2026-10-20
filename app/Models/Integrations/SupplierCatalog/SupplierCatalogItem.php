<?php

namespace App\Models\Integrations\SupplierCatalog;

use Illuminate\Database\Eloquent\Model;

class SupplierCatalogItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['images' => 'array', 'specifications' => 'array', 'is_current' => 'boolean', 'synced_at' => 'datetime', 'source_price' => 'decimal:4'];
    }
}
