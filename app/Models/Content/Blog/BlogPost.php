<?php

namespace App\Models\Content\Blog;

use App\Models\Concerns\HasConfiguredMedia;
use App\Models\Content\Support\Comment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;

class BlogPost extends Model implements HasMedia
{
    use HasConfiguredMedia;

    protected $table = 'content_blog_posts';

    protected $fillable = [
        'code',
        'is_active',
        'is_featured',
        'published_at',
        'sort_order',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'is_featured' => 'bool',
        'published_at' => 'datetime',
        'sort_order' => 'int',
        'payload' => 'array',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(BlogPostTranslation::class, 'post_id');
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(BlogPostTranslation::class, 'post_id')->where('locale', $locale);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Catalog\Category\Category::class, 'content_blog_post_category', 'post_id', 'category_id')
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
