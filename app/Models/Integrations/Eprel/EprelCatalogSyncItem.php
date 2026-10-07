<?php

namespace App\Models\Integrations\Eprel;

use App\Models\Catalog\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EprelCatalogSyncItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'checked_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EprelCatalogSyncRun::class, 'run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
