<?php

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Http\Resources\Api\V1\Wholesale\ManufacturerResource;
use App\Models\Catalog\Manufacturer\Manufacturer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ManufacturerController extends BaseWholesaleController
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'max:12'],
            'q' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'in:active,inactive,all'],
            'updated_since' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'string', 'in:name_asc,name_desc,newest,oldest,sort_asc,sort_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);

        [$locale, $fallbackLocale] = $this->resolveLocale($request);
        $updatedSince = $this->resolveUpdatedSince($validated['updated_since'] ?? null);
        $perPage = $this->resolvePerPage($request, 50, 250);
        $sort = (string) ($validated['sort'] ?? 'sort_asc');
        $search = trim((string) ($validated['q'] ?? ''));
        $state = (string) ($validated['state'] ?? 'active');

        $query = Manufacturer::query()
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', array_unique([$locale, $fallbackLocale])),
            ]);

        if ($state === 'active') {
            $query->where('is_active', true);
        } elseif ($state === 'inactive') {
            $query->where('is_active', false);
        }

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('code', 'like', '%'.$search.'%')
                    ->orWhereHas('translations', function (Builder $tq) use ($search): void {
                        $tq->where('name', 'like', '%'.$search.'%')
                            ->orWhere('slug', 'like', '%'.$search.'%');
                    });
            });
        }

        if ($updatedSince) {
            $query->where(function (Builder $q) use ($updatedSince): void {
                $q->where('catalog_manufacturers.updated_at', '>=', $updatedSince)
                    ->orWhereHas('translations', fn (Builder $tq) => $tq->where('updated_at', '>=', $updatedSince));
            });
        }

        match ($sort) {
            'name_asc' => $query->orderBy('catalog_manufacturers.code'),
            'name_desc' => $query->orderByDesc('catalog_manufacturers.code'),
            'newest' => $query->orderByDesc('catalog_manufacturers.created_at'),
            'oldest' => $query->orderBy('catalog_manufacturers.created_at'),
            'sort_desc' => $query->orderByDesc('catalog_manufacturers.sort_order'),
            default => $query->orderBy('catalog_manufacturers.sort_order')->orderBy('catalog_manufacturers.id'),
        };

        $rows = $query->paginate($perPage)->withQueryString();

        return ManufacturerResource::collection($rows);
    }

    public function show(Request $request, string $identifier): ManufacturerResource
    {
        [$locale, $fallbackLocale] = $this->resolveLocale($request);

        $manufacturer = Manufacturer::query()
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', array_unique([$locale, $fallbackLocale])),
            ])
            ->where(function (Builder $q) use ($identifier): void {
                $q->where('catalog_manufacturers.code', $identifier)
                    ->orWhereHas('translations', fn (Builder $tq) => $tq->where('slug', $identifier));

                if (ctype_digit($identifier)) {
                    $q->orWhere('catalog_manufacturers.id', (int) $identifier);
                }
            })
            ->firstOrFail();

        return new ManufacturerResource($manufacturer);
    }
}
