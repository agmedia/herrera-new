<?php

namespace App\Models\Content;

use App\Support\SupplierCatalogUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SupplierCatalog extends Model
{
    protected $fillable = [
        'code', 'brand', 'title', 'description', 'year', 'language', 'source_type', 'format',
        'external_url', 'source_url', 'document_path', 'document_name', 'document_size',
        'cover_path', 'cover_url', 'is_active', 'sort_order', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'year' => 'integer', 'sort_order' => 'integer', 'document_size' => 'integer'];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)->where(function (Builder $query): void {
            $query->where(fn (Builder $q) => $q->where('source_type', 'external')->whereNotNull('external_url'))
                ->orWhere(fn (Builder $q) => $q->where('source_type', 'upload')->whereNotNull('document_path'));
        });
    }

    public function publicUrl(): ?string
    {
        if (! $this->is_active) {
            return null;
        }
        if ($this->source_type === 'external') {
            return SupplierCatalogUrl::isSafe($this->external_url) ? $this->external_url : null;
        }

        return $this->ownsPath($this->document_path, 'documents') && Storage::disk('local')->exists($this->document_path)
            ? route('supplier-catalogs.document', $this) : null;
    }

    public function ownsPath(?string $path, string $collection): bool
    {
        $extensions = $collection === 'documents' ? 'pdf' : 'jpg|jpeg|png|webp';
        $pattern = '#^supplier-catalogs/'.preg_quote((string) $this->id, '#').'/'.preg_quote($collection, '#').'/[a-zA-Z0-9-]+\.(?:'.$extensions.')$#D';

        return is_string($path) && (bool) preg_match($pattern, $path);
    }

    public function coverUrl(): ?string
    {
        if ($this->ownsPath($this->cover_path, 'covers') && Storage::disk('local')->exists($this->cover_path)) {
            return route('supplier-catalogs.cover', $this);
        }
        if (preg_match('#^/assets/supplier-catalogs/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$#D', (string) $this->cover_url)) {
            $portrait = config('supplier_catalogs.portrait_covers', [])[$this->cover_url] ?? null;
            if (is_string($portrait) && is_file(public_path(ltrim($portrait, '/')))) {
                return asset(ltrim($portrait, '/'));
            }

            return is_file(public_path(ltrim($this->cover_url, '/'))) ? asset(ltrim($this->cover_url, '/')) : null;
        }

        return SupplierCatalogUrl::isSafe($this->cover_url) ? $this->cover_url : null;
    }
}
