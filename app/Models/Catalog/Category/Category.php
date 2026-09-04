<?php

namespace App\Models\Catalog\Category;

use App\Models\Concerns\HasConfiguredMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kalnoy\Nestedset\NodeTrait;
use Spatie\MediaLibrary\HasMedia;

class Category extends Model implements HasMedia
{
    use HasConfiguredMedia;
    use NodeTrait;

    public const SCOPE_CATALOG = 'catalog';

    public const SCOPE_BLOG = 'blog';

    public const SCOPE_PAGE = 'page';

    public const PAYLOAD_SHOW_FILTERS = 'show_filters';

    public const PAYLOAD_SHOW_PRODUCTS = 'show_products';

    public const PAYLOAD_SHIPPING_LABELS = 'shipping_labels';

    /**
     * @return array<int, string>
     */
    public static function availableScopes(): array
    {
        return [
            self::SCOPE_CATALOG,
            self::SCOPE_BLOG,
            self::SCOPE_PAGE,
        ];
    }

    protected $fillable = [
        'scope',
        'code',
        'is_active',
        'show_in_menu',
        'sort_order',
        'starts_at',
        'ends_at',
        'payload',
        'created_by',
        'updated_by',
        'parent_id',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'show_in_menu' => 'bool',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'payload' => 'array',
    ];

    public function scopeCurrentlyVisible(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $scheduleQuery): void {
                $scheduleQuery
                    ->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $scheduleQuery): void {
                $scheduleQuery
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            });
    }

    public function isCurrentlyVisible(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $this->starts_at->gt($now)) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->lt($now)) {
            return false;
        }

        return true;
    }

    public function catalogPageShowsProducts(): bool
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        return (bool) ($payload[self::PAYLOAD_SHOW_PRODUCTS] ?? true);
    }

    public function catalogPageShowsFilters(): bool
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        return $this->catalogPageShowsProducts()
            && (bool) ($payload[self::PAYLOAD_SHOW_FILTERS] ?? true);
    }

    /**
     * @return array<int, string>
     */
    public function shippingLabels(): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];
        $labels = $payload[self::PAYLOAD_SHIPPING_LABELS] ?? [];

        return is_array($labels) ? array_values($labels) : [];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(CategoryTranslation::class)->where('locale', $locale);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Catalog\Product\Product::class)
            ->withPivot(['sort_order', 'is_primary'])
            ->withTimestamps();
    }

    public function blogPosts(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Content\Blog\BlogPost::class, 'content_blog_post_category', 'category_id', 'post_id')
            ->withPivot(['sort_order', 'is_primary'])
            ->withTimestamps();
    }

    public function infoPages(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Content\Page\InfoPage::class, 'content_info_page_category', 'category_id', 'page_id')
            ->withPivot(['sort_order', 'is_primary'])
            ->withTimestamps();
    }
}
