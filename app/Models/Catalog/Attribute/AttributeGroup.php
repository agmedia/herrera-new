<?php

namespace App\Models\Catalog\Attribute;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttributeGroup extends Model
{
    protected $table = 'catalog_attribute_groups';

    protected $fillable = [
        'code',
        'type',
        'sort_order',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sort_order' => 'int',
        'payload' => 'array',
    ];

    public function isMsanManaged(): bool
    {
        if (Attribute::normalizeSource(data_get($this->payload, 'source'))
            === Attribute::SOURCE_MSAN_SPECIFICATION) {
            return true;
        }

        if ($this->relationLoaded('attributes')) {
            return $this->attributes->contains(
                fn (Attribute $attribute): bool => $attribute->isMsanManaged(),
            );
        }

        return $this->attributes()
            ->where('payload->source', Attribute::SOURCE_MSAN_SPECIFICATION)
            ->exists();
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AttributeGroupTranslation::class, 'attribute_group_id');
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(AttributeGroupTranslation::class, 'attribute_group_id')
            ->where('locale', $locale);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class, 'attribute_group_id')
            ->orderBy('sort_order')
            ->orderBy('id');
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
