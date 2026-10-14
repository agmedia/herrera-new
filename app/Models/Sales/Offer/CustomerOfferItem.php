<?php

namespace App\Models\Sales\Offer;

use App\Models\Catalog\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOfferItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'pricing_snapshot' => 'array', 'unit_net' => 'decimal:4', 'unit_gross' => 'decimal:4',
            'tax_rate' => 'decimal:4', 'line_net' => 'decimal:4', 'line_tax' => 'decimal:4', 'line_gross' => 'decimal:4'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(CustomerOffer::class, 'customer_offer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
