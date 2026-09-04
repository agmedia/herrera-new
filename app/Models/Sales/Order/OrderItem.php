<?php

namespace App\Models\Sales\Order;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_option_value_id',
        'sku',
        'code',
        'name',
        'unit_price',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'quantity',
        'line_total',
        'sort_order',
        'payload',
    ];

    protected $casts = [
        'order_id' => 'int',
        'product_id' => 'int',
        'product_option_value_id' => 'int',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'quantity' => 'int',
        'line_total' => 'decimal:2',
        'sort_order' => 'int',
        'payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productOptionValue(): BelongsTo
    {
        return $this->belongsTo(ProductOptionValue::class, 'product_option_value_id');
    }
}
