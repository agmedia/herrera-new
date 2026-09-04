<?php

namespace App\Models\Catalog\Attribute;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeTranslation extends Model
{
    protected $table = 'catalog_attribute_translations';

    protected $fillable = [
        'attribute_id',
        'locale',
        'group_name',
        'name',
        'slug',
        'description',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(function (AttributeTranslation $translation): void {
            $attribute = $translation->attribute()->first();
            if (! $attribute) {
                return;
            }

            $group = $attribute->group
                ?? AttributeGroup::query()->where('code', $attribute->group_code)->first();
            if (! $group) {
                return;
            }

            $groupTranslation = $group->translations()
                ->where('locale', $translation->locale)
                ->first();
            if ((bool) data_get($groupTranslation?->payload, 'manual_override', false)) {
                return;
            }

            $source = Attribute::normalizeSource(data_get($translation->payload, 'source'));
            if ($source === '') {
                $source = $attribute->sourceCode();
            }

            $group->translations()->updateOrCreate(
                ['locale' => $translation->locale],
                [
                    'name' => $translation->group_name ?: $group->code,
                    'payload' => ['source' => $source],
                ]
            );
        });
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }
}
