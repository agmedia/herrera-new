<?php

namespace App\Services\Integrations\Msan;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Category\CategoryTranslation;
use App\Models\Integrations\Msan\MsanCategory;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MsanCategoryBranchImportService
{
    /**
     * @param  array<int, string>  $preferredLocales
     * @return array<string, int|bool|null>
     */
    public function preview(
        MsanCategory|int $root,
        string $locale = 'hr',
        array $preferredLocales = ['hr'],
        ?int $destinationLocalParentId = null,
    ): array {
        $branch = $this->branch($root);
        $rootCategory = $branch->first();
        $localMatches = $this->localCategories($preferredLocales)
            ->filter(fn (Category $category): bool => $this->categoryHasName($category, (string) $rootCategory->name));
        $branchIds = $branch->pluck('id');
        $rootMappingBlocksImport = $rootCategory->mapping?->status === MsanCategoryMapping::STATUS_IGNORED
            || ($rootCategory->mapping?->status === MsanCategoryMapping::STATUS_MAPPED
                && $rootCategory->mapping?->local_category_id);

        return [
            'can_import' => $localMatches->isEmpty() && ! $rootMappingBlocksImport,
            'exact_local_category_count' => $localMatches->count(),
            'exact_local_category_id' => $localMatches->count() === 1 ? (int) $localMatches->first()->getKey() : null,
            'category_count' => $branch->count(),
            'descendant_count' => max(0, $branch->count() - 1),
            'product_count' => DB::table('msan_product_categories')
                ->whereIn('msan_category_id', $branchIds)
                ->distinct()
                ->count('msan_product_id'),
            'destination_local_parent_id' => $destinationLocalParentId,
            'source_is_root' => blank($rootCategory->parent_external_id),
        ];
    }

    /**
     * @param  array<int, string>  $preferredLocales
     * @return array<string, int>
     */
    public function import(
        MsanCategory|int $root,
        ?int $userId,
        string $locale = 'hr',
        array $preferredLocales = ['hr'],
        ?int $destinationLocalParentId = null,
    ): array {
        return DB::transaction(function () use ($root, $userId, $locale, $preferredLocales, $destinationLocalParentId): array {
            $branch = $this->branch($root);
            $rootCategory = $branch->first();
            $locals = $this->localCategories($preferredLocales);
            $destinationParent = $destinationLocalParentId === null
                ? null
                : $locals->firstWhere('id', $destinationLocalParentId);

            if ($destinationLocalParentId !== null && (! $destinationParent || $destinationParent->scope !== Category::SCOPE_CATALOG)) {
                throw new RuntimeException('Invalid destination category.');
            }

            $sourceToLocal = [];
            $skippedSources = [];
            $createdCount = 0;
            $reusedCount = 0;
            $mappedCount = 0;

            foreach ($branch as $source) {
                $sourceParent = (string) ($source->parent_external_id ?? '');
                $isBranchRoot = (int) $source->getKey() === (int) $rootCategory->getKey();

                if (! $isBranchRoot && isset($skippedSources[$sourceParent])) {
                    $skippedSources[(string) $source->external_id] = true;

                    continue;
                }

                $existingMapping = $source->mapping;
                $mappedLocal = $existingMapping?->status === MsanCategoryMapping::STATUS_MAPPED
                    ? $locals->firstWhere('id', (int) $existingMapping->local_category_id)
                    : null;
                if ($mappedLocal?->scope === Category::SCOPE_CATALOG) {
                    $sourceToLocal[(string) $source->external_id] = $mappedLocal;
                    $mappedCount++;

                    continue;
                }

                if ($existingMapping?->status === MsanCategoryMapping::STATUS_IGNORED) {
                    $skippedSources[(string) $source->external_id] = true;

                    continue;
                }

                $localParent = $isBranchRoot
                    ? $destinationParent
                    : ($sourceToLocal[$sourceParent] ?? null);
                if (! $isBranchRoot && ! $localParent) {
                    throw new RuntimeException('Missing local parent while importing the M SAN category branch.');
                }

                $local = $locals->first(fn (Category $category): bool => (string) data_get($category->payload, 'supplier_sources.msan.external_id') === (string) $source->external_id
                );

                if (! $local) {
                    if ($isBranchRoot) {
                        $sameNameAnywhere = $locals->filter(fn (Category $category): bool => $this->categoryHasName($category, (string) $source->name)
                        );
                        $sameNameAtDestination = $sameNameAnywhere->filter(fn (Category $category): bool => $this->sameParent($category, $localParent)
                        );

                        if ($sameNameAnywhere->count() !== $sameNameAtDestination->count()) {
                            throw new RuntimeException('An exact local category already exists elsewhere.');
                        }
                    }

                    $matches = $locals->filter(fn (Category $category): bool => $this->sameParent($category, $localParent)
                        && $this->categoryHasName($category, (string) $source->name)
                    );

                    if ($matches->count() > 1) {
                        throw new RuntimeException('Ambiguous local category name.');
                    }

                    $local = $matches->first();
                }

                if (! $local) {
                    $local = $this->createLocalCategory($source, $localParent, $locale, $userId, $locals);
                    $locals->push($local);
                    $createdCount++;
                } else {
                    $reusedCount++;
                }

                MsanCategoryMapping::query()->updateOrCreate(
                    ['msan_category_id' => (int) $source->getKey()],
                    [
                        'local_category_id' => (int) $local->getKey(),
                        'status' => MsanCategoryMapping::STATUS_MAPPED,
                        'updated_by' => $userId,
                    ],
                );
                $sourceToLocal[(string) $source->external_id] = $local;
                $mappedCount++;
            }

            $rootLocal = $sourceToLocal[(string) $rootCategory->external_id] ?? null;
            if (! $rootLocal) {
                throw new RuntimeException('The selected M SAN category was not imported.');
            }

            return [
                'root_local_category_id' => (int) $rootLocal->getKey(),
                'category_count' => $branch->count(),
                'descendant_count' => max(0, $branch->count() - 1),
                'created_count' => $createdCount,
                'reused_count' => $reusedCount,
                'mapped_count' => $mappedCount,
                'skipped_count' => count($skippedSources),
            ];
        }, 3);
    }

    /** @return Collection<int, MsanCategory> */
    private function branch(MsanCategory|int $root): Collection
    {
        $rootId = $root instanceof MsanCategory ? (int) $root->getKey() : (int) $root;
        $categories = MsanCategory::query()
            ->select(['id', 'external_id', 'parent_external_id', 'name', 'path', 'is_stale'])
            ->where('is_stale', false)
            ->with('mapping.localCategory')
            ->inTreeOrder()
            ->get();
        $rootCategory = $categories->firstWhere('id', $rootId);
        if (! $rootCategory) {
            throw new RuntimeException('The selected M SAN category is unavailable.');
        }

        $children = $categories->groupBy(fn (MsanCategory $category): string => (string) ($category->parent_external_id ?? ''));
        $result = collect();
        $visiting = [];
        $visited = [];

        $walk = function (MsanCategory $category) use (&$walk, &$visiting, &$visited, $children, $result): void {
            $key = (string) $category->external_id;
            if (isset($visiting[$key])) {
                throw new RuntimeException('Cycle detected in the M SAN category tree.');
            }
            if (isset($visited[$key])) {
                return;
            }

            $visiting[$key] = true;
            $result->push($category);
            foreach ($children->get($key, collect()) as $child) {
                $walk($child);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
        };
        $walk($rootCategory);

        return $result;
    }

    /** @param array<int, string> $preferredLocales */
    private function localCategories(array $preferredLocales): Collection
    {
        return Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->with(['translations' => fn ($query) => $query->whereIn('locale', $preferredLocales)])
            ->get();
    }

    private function sameParent(Category $category, ?Category $parent): bool
    {
        return (int) ($category->parent_id ?? 0) === (int) ($parent?->getKey() ?? 0);
    }

    private function categoryHasName(Category $category, string $name): bool
    {
        $normalized = $this->normalizedName($name);

        return $category->translations->contains(
            fn (CategoryTranslation $translation): bool => $this->normalizedName((string) $translation->name) === $normalized,
        );
    }

    private function createLocalCategory(
        MsanCategory $source,
        ?Category $parent,
        string $locale,
        ?int $userId,
        Collection $locals,
    ): Category {
        $payload = [
            Category::PAYLOAD_SHOW_FILTERS => true,
            Category::PAYLOAD_SHOW_PRODUCTS => true,
            'supplier_sources' => [
                'msan' => ['external_id' => (string) $source->external_id],
            ],
        ];
        $baseCode = Str::limit(Str::slug('msan-'.(string) $source->external_id), 180, '');
        $category = new Category([
            'scope' => Category::SCOPE_CATALOG,
            'code' => $this->uniqueCode($baseCode !== '' ? $baseCode : 'msan-category', $locals),
            'is_active' => false,
            'show_in_menu' => false,
            'sort_order' => $locals
                ->filter(fn (Category $candidate): bool => $this->sameParent($candidate, $parent))
                ->max('sort_order') + 1,
            'payload' => $payload,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        if ($parent) {
            $category->appendToNode($parent)->save();
        } else {
            $category->saveAsRoot();
        }

        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => $locale,
            'name' => (string) $source->name,
            'slug' => $this->uniqueSlug(Str::slug((string) $source->name), $locale),
            'description' => null,
            'meta_title' => (string) $source->name,
            'meta_description' => null,
            'payload' => ['supplier_sources' => ['msan' => ['external_id' => (string) $source->external_id]]],
        ]);
        $category->load('translations');

        return $category;
    }

    private function uniqueCode(string $base, Collection $locals): string
    {
        $candidate = $base;
        $suffix = 2;
        while ($locals->contains(fn (Category $category): bool => (string) $category->code === $candidate)) {
            $candidate = Str::limit($base, 175, '').'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueSlug(string $base, string $locale): string
    {
        $base = Str::limit($base !== '' ? $base : 'kategorija', 180, '');
        $candidate = $base;
        $suffix = 2;
        while (CategoryTranslation::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->where('locale', $locale)
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = Str::limit($base, 175, '').'-'.$suffix++;
        }

        return $candidate;
    }

    private function normalizedName(string $name): string
    {
        return (string) Str::of($name)->squish()->lower();
    }
}
