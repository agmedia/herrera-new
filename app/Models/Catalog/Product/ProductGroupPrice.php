<?php

namespace App\Models\Catalog\Product;

use App\Models\User;
use App\Models\User\CustomerGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ProductGroupPrice extends Model
{
    protected $table = 'catalog_product_group_prices';

    protected $fillable = [
        'product_id',
        'customer_group_id',
        'product_package_id',
        'minimum_quantity',
        'price',
        'currency_code',
        'starts_at',
        'ends_at',
        'is_active',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'minimum_quantity' => 'integer',
            'price' => 'decimal:4',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function scopeActiveAt(Builder $query, Carbon|string|null $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>=', $at));
    }

    public function scopeForQuantity(Builder $query, int $quantity): Builder
    {
        return $query->where('minimum_quantity', '<=', max(1, $quantity));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function productPackage(): BelongsTo
    {
        return $this->belongsTo(ProductPackage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
