<?php

namespace App\Models\Catalog\Option;

use App\Models\Catalog\Product\ProductOptionValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OptionValue extends Model
{
    protected $table = 'catalog_option_values';

    protected $fillable = [
        'option_id',
        'code',
        'is_active',
        'sort_order',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'sort_order' => 'int',
        'payload' => 'array',
    ];

    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class, 'option_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(OptionValueTranslation::class, 'option_value_id');
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(OptionValueTranslation::class, 'option_value_id')
            ->where('locale', $locale);
    }

    public function productOptionValues(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class, 'option_value_id');
    }
}
