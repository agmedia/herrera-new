<?php

namespace App\Models\Catalog\Option;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionValueTranslation extends Model
{
    protected $table = 'catalog_option_value_translations';

    protected $fillable = [
        'option_value_id',
        'locale',
        'name',
        'slug',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function optionValue(): BelongsTo
    {
        return $this->belongsTo(OptionValue::class, 'option_value_id');
    }
}
