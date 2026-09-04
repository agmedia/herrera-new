<?php

namespace App\Models\Catalog\Product;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductPackage extends Model
{
    protected $table = 'catalog_product_packages';

    protected $fillable = [
        'product_id',
        'code',
        'name',
        'barcode',
        'package_type',
        'unit_of_measure',
        'quantity',
        'weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
        'is_default',
        'is_active',
        'sort_order',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'weight_kg' => 'decimal:3',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'payload' => 'array',
        ];
    }

    public static function typeOptions(): array
    {
        return [
            'piece' => 'Komad',
            'pack' => 'Paket',
            'box' => 'Kutija',
            'bag' => 'Vreća',
            'roll' => 'Rola',
            'pallet' => 'Paleta',
            'container' => 'Spremnik',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function groupPrices(): HasMany
    {
        return $this->hasMany(ProductGroupPrice::class, 'product_package_id');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class, 'product_package_id');
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
