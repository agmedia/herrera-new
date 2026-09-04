<?php

namespace App\Models\Content;

use App\Models\Concerns\HasConfiguredMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;

class ContentBlock extends Model implements HasMedia
{
    use HasConfiguredMedia;

    protected $fillable = [
        'code',
        'name',
        'type',
        'is_active',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'payload' => 'array',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(ContentBlockTranslation::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ContentBlockSlot::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContentBlockItem::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(ContentBlockTranslation::class)->where('locale', $locale);
    }
}
