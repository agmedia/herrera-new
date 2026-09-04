<?php

namespace App\Models\Settings\Local;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingMethodRate extends Model
{
    protected $fillable = [
        'shipping_method_id',
        'min_weight_kg',
        'max_weight_kg',
        'price',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'shipping_method_id' => 'integer',
            'min_weight_kg' => 'decimal:3',
            'max_weight_kg' => 'decimal:3',
            'price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
