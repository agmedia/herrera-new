<?php

namespace App\Models\Content\Page;

use App\Models\Content\Support\Comment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InfoPage extends Model
{
    protected $table = 'content_info_pages';

    protected $fillable = [
        'code',
        'layout',
        'is_active',
        'show_in_footer',
        'published_at',
        'sort_order',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'show_in_footer' => 'bool',
        'published_at' => 'datetime',
        'sort_order' => 'int',
        'payload' => 'array',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(InfoPageTranslation::class, 'page_id');
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(InfoPageTranslation::class, 'page_id')->where('locale', $locale);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Catalog\Category\Category::class, 'content_info_page_category', 'page_id', 'category_id')
            ->withPivot(['sort_order', 'is_primary'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
