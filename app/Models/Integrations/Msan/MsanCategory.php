<?php

namespace App\Models\Integrations\Msan;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MsanCategory extends Model
{
    protected $fillable = [
        'external_id',
        'name',
        'parent_external_id',
        'path',
        'product_count',
        'last_seen_at',
        'is_stale',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'product_count' => 'int',
            'last_seen_at' => 'datetime',
            'is_stale' => 'bool',
            'payload' => 'array',
        ];
    }

    public function scopeInTreeOrder(Builder $query): Builder
    {
        $query->orderByRaw('CASE WHEN path IS NULL OR path = ? THEN 1 ELSE 0 END', ['']);

        if ($query->getConnection()->getDriverName() === 'mysql') {
            return $query
                ->orderByRaw('path COLLATE utf8mb4_croatian_ci')
                ->orderByRaw('name COLLATE utf8mb4_croatian_ci')
                ->orderBy('id');
        }

        return $query
            ->orderBy('path')
            ->orderBy('name')
            ->orderBy('id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_external_id', 'external_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_external_id', 'external_id');
    }

    public function mapping(): HasOne
    {
        return $this->hasOne(MsanCategoryMapping::class, 'msan_category_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            MsanProduct::class,
            'msan_product_categories',
            'msan_category_id',
            'msan_product_id'
        )->withPivot('last_seen_at')->withTimestamps();
    }
}
