<?php

namespace App\Models\Catalog\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogProductSpecification extends Model
{
    protected $fillable = [
        'product_id',
        'source',
        'source_key',
        'group_name',
        'item_name',
        'values',
        'measure',
        'sort_order',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'int',
            'values' => 'array',
            'sort_order' => 'int',
            'payload' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
