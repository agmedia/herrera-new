<?php

namespace App\Models\Catalog\Category;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CategoryTranslation extends Model
{
    protected $fillable = [
        'category_id',
        'scope',
        'locale',
        'name',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getCleanDescriptionAttribute(): string
    {
        $description = trim((string) ($this->description ?? ''));
        if ($description === '') {
            return '';
        }

        $decoded = $description;
        for ($i = 0; $i < 2; $i++) {
            $nextPass = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($nextPass === $decoded) {
                break;
            }
            $decoded = $nextPass;
        }
        $withoutStyleTags = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $decoded) ?? $decoded;
        $withoutStyleAttributes = preg_replace('/\sstyle=("|\').*?\1/iu', '', $withoutStyleTags) ?? $withoutStyleTags;
        $plainText = strip_tags($withoutStyleAttributes);

        return (string) Str::of($plainText)->squish();
    }
}
