<?php

namespace App\Models\Content\Reference;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ReferenceProject extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const GALLERY = 'reference_gallery';

    protected $table = 'content_reference_projects';

    protected $fillable = ['location', 'completed_year', 'project_type', 'is_active', 'published_at', 'sort_order', 'created_by', 'updated_by'];

    protected $casts = ['is_active' => 'boolean', 'published_at' => 'datetime', 'completed_year' => 'integer', 'sort_order' => 'integer'];

    public function translations(): HasMany
    {
        return $this->hasMany(ReferenceProjectTranslation::class, 'project_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $visible) => $visible->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->whereHas('media', fn (Builder $media) => $media->where('collection_name', self::GALLERY));
    }

    public function localizedTranslation(?string $locale = null, ?string $fallback = null): ?ReferenceProjectTranslation
    {
        $locale ??= app()->getLocale();
        $fallback ??= (string) config('app.locale', 'hr');

        return $this->translations->firstWhere('locale', $locale) ?? $this->translations->firstWhere('locale', $fallback);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::GALLERY)->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])->useDisk('local');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (['reference_card' => [800, 600], 'reference_large' => [1800, 1200]] as $name => [$width, $height]) {
            $this->addMediaConversion($name)->performOnCollections(self::GALLERY)->fit(Fit::Max, $width, $height)->quality(85)->nonQueued();
            $this->addMediaConversion($name.'_webp')->performOnCollections(self::GALLERY)->fit(Fit::Max, $width, $height)->format('webp')->quality(85)->nonQueued();
        }
    }

    public function photoUrl(Media $media, string $size = 'card'): string
    {
        return route('references.photo', ['project' => $this->id, 'photo' => $media->id, 'size' => $size]);
    }
}
