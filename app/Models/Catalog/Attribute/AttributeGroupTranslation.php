<?php

namespace App\Models\Catalog\Attribute;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeGroupTranslation extends Model
{
    protected $table = 'catalog_attribute_group_translations';

    protected $fillable = [
        'attribute_group_id',
        'locale',
        'name',
        'description',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(AttributeGroup::class, 'attribute_group_id');
    }
}
