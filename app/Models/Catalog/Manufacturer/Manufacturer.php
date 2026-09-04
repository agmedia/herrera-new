<?php

namespace App\Models\Catalog\Manufacturer;

use App\Models\Concerns\HasConfiguredMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;

class Manufacturer extends Model implements HasMedia
{
    use HasConfiguredMedia;

    protected $table = 'catalog_manufacturers';

    protected $fillable = [
        'code',
        'is_active',
        'is_featured',
        'sort_order',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'is_featured' => 'bool',
        'sort_order' => 'int',
        'payload' => 'array',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(ManufacturerTranslation::class, 'manufacturer_id');
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(ManufacturerTranslation::class, 'manufacturer_id')
            ->where('locale', $locale);
    }

    public function products(): HasMany
    {
        return $this->hasMany(\App\Models\Catalog\Product\Product::class, 'manufacturer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }
}
