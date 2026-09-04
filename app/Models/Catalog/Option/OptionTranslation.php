<?php

namespace App\Models\Catalog\Option;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionTranslation extends Model
{
    protected $table = 'catalog_option_translations';

    protected $fillable = [
        'option_id',
        'locale',
        'name',
        'slug',
        'description',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class, 'option_id');
    }
}
