<?php

namespace App\Http\Resources\Api\V1\Wholesale;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = strtolower((string) $request->query('locale', config('app.locale', 'en')));
        $fallbackLocale = strtolower((string) config('app.fallback_locale', config('app.locale', 'en')));

        $translation = $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', $fallbackLocale)
            ?? $this->translations->first();

        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'is_active' => (bool) $this->is_active,
            'show_in_menu' => (bool) $this->show_in_menu,
            'sort_order' => (int) $this->sort_order,
            'locale' => $translation?->locale,
            'name' => $translation?->name,
            'slug' => $translation?->slug,
            'description' => $translation?->description,
            'meta_title' => $translation?->meta_title,
            'meta_description' => $translation?->meta_description,
            'payload' => $this->payload,
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
