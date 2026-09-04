<?php

namespace App\Models\Catalog\Product;

use App\Models\User;
use App\Models\User\CustomerGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceHistory extends Model
{
    protected $table = 'catalog_product_price_history';

    protected $fillable = [
        'product_id',
        'product_option_value_id',
        'customer_group_id',
        'product_package_id',
        'price_type',
        'old_price',
        'new_price',
        'currency_code',
        'effective_at',
        'source',
        'changed_by',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'old_price' => 'decimal:4',
            'new_price' => 'decimal:4',
            'effective_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productOptionValue(): BelongsTo
    {
        return $this->belongsTo(ProductOptionValue::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function productPackage(): BelongsTo
    {
        return $this->belongsTo(ProductPackage::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
