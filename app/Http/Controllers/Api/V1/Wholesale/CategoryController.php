<?php

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Http\Resources\Api\V1\Wholesale\CategoryResource;
use App\Models\Catalog\Category\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CategoryController extends BaseWholesaleController
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'max:12'],
            'scope' => ['nullable', 'string', 'in:catalog,blog,page,all'],
            'q' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'in:active,inactive,all'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'updated_since' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'string', 'in:tree,name_asc,name_desc,newest,oldest'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);

        [$locale, $fallbackLocale] = $this->resolveLocale($request);
        $updatedSince = $this->resolveUpdatedSince($validated['updated_since'] ?? null);
        $perPage = $this->resolvePerPage($request, 100, 250);
        $sort = (string) ($validated['sort'] ?? 'tree');
        $search = trim((string) ($validated['q'] ?? ''));
        $state = (string) ($validated['state'] ?? 'active');
        $scope = (string) ($validated['scope'] ?? Category::SCOPE_CATALOG);
        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;

        $query = Category::query()
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', array_unique([$locale, $fallbackLocale])),
            ]);

        if ($scope !== 'all') {
            $query->where('scope', $scope);
        }

        if ($state === 'active') {
            $query->where('is_active', true);
        } elseif ($state === 'inactive') {
            $query->where('is_active', false);
        }

        if ($parentId) {
            $query->where('parent_id', $parentId);
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
                $q->where('categories.updated_at', '>=', $updatedSince)
                    ->orWhereHas('translations', fn (Builder $tq) => $tq->where('updated_at', '>=', $updatedSince));
            });
        }

        match ($sort) {
            'name_asc' => $query->orderBy('categories.code'),
            'name_desc' => $query->orderByDesc('categories.code'),
            'newest' => $query->orderByDesc('categories.created_at'),
            'oldest' => $query->orderBy('categories.created_at'),
            default => $query->orderBy('categories._lft')->orderBy('categories.sort_order')->orderBy('categories.id'),
        };

        $rows = $query->paginate($perPage)->withQueryString();

        return CategoryResource::collection($rows);
    }

    public function show(Request $request, string $identifier): CategoryResource
    {
        [$locale, $fallbackLocale] = $this->resolveLocale($request);
        $scope = strtolower(trim((string) $request->query('scope', Category::SCOPE_CATALOG)));

        $category = Category::query()
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', array_unique([$locale, $fallbackLocale])),
            ])
            ->when($scope !== 'all' && $scope !== '', fn (Builder $q) => $q->where('scope', $scope))
            ->where(function (Builder $q) use ($identifier): void {
                $q->where('categories.code', $identifier)
                    ->orWhereHas('translations', fn (Builder $tq) => $tq->where('slug', $identifier));

                if (ctype_digit($identifier)) {
                    $q->orWhere('categories.id', (int) $identifier);
                }
            })
            ->firstOrFail();

        return new CategoryResource($category);
    }
}
