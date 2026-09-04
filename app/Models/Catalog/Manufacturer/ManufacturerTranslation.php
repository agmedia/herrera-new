<?php

namespace App\Models\Catalog\Manufacturer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManufacturerTranslation extends Model
{
    protected $table = 'catalog_manufacturer_translations';

    protected $fillable = [
        'manufacturer_id',
        'locale',
        'name',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }
}
