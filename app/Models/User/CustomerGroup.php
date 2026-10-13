<?php

namespace App\Models\User;

use App\Models\Catalog\Pricing\B2BPriceRule;
use App\Models\Catalog\Product\ProductGroupPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    public const CLASSIFICATION_GENERAL = 'general';

    public const CLASSIFICATION_INDUSTRY = 'industry';

    public const CLASSIFICATION_DIVISION = 'division';

    public static function classificationOptions(): array
    {
        return [
            self::CLASSIFICATION_GENERAL => 'Cjenovna / opća grupa',
            self::CLASSIFICATION_INDUSTRY => 'Djelatnost',
            self::CLASSIFICATION_DIVISION => 'Divizija',
        ];
    }

    public function classificationType(): string
    {
        return $this->payload['classification_type'] ?? self::CLASSIFICATION_GENERAL;
    }

    public function isBusinessSegment(): bool
    {
        return in_array($this->classificationType(), [self::CLASSIFICATION_INDUSTRY, self::CLASSIFICATION_DIVISION], true);
    }

    public function scopeBusinessSegments(Builder $query): Builder
    {
        return $query->whereIn('payload->classification_type', [self::CLASSIFICATION_INDUSTRY, self::CLASSIFICATION_DIVISION]);
    }

    public function scopePricingGroups(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull('payload->classification_type')
            ->orWhereNotIn('payload->classification_type', [self::CLASSIFICATION_INDUSTRY, self::CLASSIFICATION_DIVISION]));
    }

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'is_default',
        'sort_order',
        'payload',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'is_default' => 'bool',
        'sort_order' => 'int',
        'payload' => 'array',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\User::class, 'customer_group_user')
            ->withTimestamps();
    }

    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductGroupPrice::class);
    }

    public function b2bPriceRules(): HasMany
    {
        return $this->hasMany(B2BPriceRule::class);
    }
}
