<?php

namespace App\Models\Catalog\Product;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOptionValue extends Model
{
    protected $table = 'catalog_product_option_values';

    protected $fillable = [
        'product_id',
        'option_value_id',
        'parent_option_value_id',
        'mode',
        'sku',
        'stock_qty',
        'price_override',
        'sort_order',
        'is_active',
        'combination_hash',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'stock_qty' => 'int',
        'price_override' => 'decimal:2',
        'sort_order' => 'int',
        'is_active' => 'bool',
        'payload' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function optionValue(): BelongsTo
    {
        return $this->belongsTo(OptionValue::class, 'option_value_id');
    }

    public function parentOptionValue(): BelongsTo
    {
        return $this->belongsTo(OptionValue::class, 'parent_option_value_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class, 'product_option_value_id');
    }

    public function childOption(): ?Option
    {
        return $this->optionValue?->option;
    }

    public function parentOption(): ?Option
    {
        return $this->parentOptionValue?->option;
    }

    public function showsOnProductPage(): bool
    {
        $childVisible = $this->childOption()?->showsOnProductPage();
        $parentVisible = $this->parentOption()?->showsOnProductPage();

        if ((int) ($this->parent_option_value_id ?? 0) > 0) {
            return ($parentVisible ?? true) && ($childVisible ?? true);
        }

        return $childVisible ?? $parentVisible ?? true;
    }
}
