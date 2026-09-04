<?php

namespace App\Services\AdminAi;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Category\CategoryTranslation;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminAgentService
{
    private const INTENT_CATEGORY_MANAGEMENT = 'category_management';

    private const TOOL_ENSURE_CATEGORY_PATH = 'ensure_category_path';
    private const TOOL_UPSERT_CATEGORY_TRANSLATION = 'upsert_category_translation';
    private const TOOL_ATTACH_PRODUCTS_BY_FILTER = 'attach_products_by_filter';
    private const TOOL_SET_CATEGORY_STATE = 'set_category_state';

    /**
     * @return array<string, mixed>
     */
    public function buildPlan(string $prompt, User $user): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return [
                'ok' => false,
                'message' => 'Prompt is empty.',
            ];
        }

        $parsed = $this->parsePrompt($prompt);
        if (!$parsed['ok']) {
            return $parsed;
        }

        /** @var array<int, string> $path */
        $path = $parsed['path_segments'];
        $scope = (string) $parsed['scope'];
        $locale = (string) $parsed['locale'];
        $description = (string) $parsed['description'];
        $attachTodayProducts = (bool) $parsed['attach_today_products'];
        $setActive = $parsed['set_active'];
        $createMissing = (bool) $parsed['create_missing'];

        $warnings = [];
        $actions = [];
        $tools = [];

        $tools[] = [
            'name' => self::TOOL_ENSURE_CATEGORY_PATH,
            'input' => [
                'scope' => $scope,
                'locale' => $locale,
                'path_segments' => $path,
                'create_missing' => $createMissing,
            ],
        ];
        $actions[] = "Ensure category path exists: ".implode(' > ', $path);

        if ($description !== '' || (bool) $parsed['ensure_description']) {
            $tools[] = [
                'name' => self::TOOL_UPSERT_CATEGORY_TRANSLATION,
                'input' => [
                    'scope' => $scope,
                    'locale' => $locale,
                    'name' => $path[array_key_last($path)],
                    'description' => $description !== '' ? $description : $this->defaultDescription($path[array_key_last($path)]),
                ],
            ];
            $actions[] = 'Upsert category translation and description';
        }

        if (is_bool($setActive)) {
            $tools[] = [
                'name' => self::TOOL_SET_CATEGORY_STATE,
                'input' => [
                    'is_active' => $setActive,
                ],
            ];
            $actions[] = $setActive ? 'Set category state: active' : 'Set category state: inactive';
        }

        if ($attachTodayProducts) {
            $todayProductsCount = Product::query()
                ->whereDate('created_at', now(config('app.timezone'))->toDateString())
                ->count();

            if ($todayProductsCount === 0) {
                $warnings[] = 'No products were created today, so attachment count is expected to be 0.';
            }

            $tools[] = [
                'name' => self::TOOL_ATTACH_PRODUCTS_BY_FILTER,
                'input' => [
                    'filter' => 'created_today',
                ],
            ];
            $actions[] = "Attach products created today ({$todayProductsCount} currently found)";
        }

        $targetName = (string) $path[array_key_last($path)];

        $planTools = array_values(array_filter($tools, fn ($tool) => $this->isToolAllowed((string) ($tool['name'] ?? ''))));

        $plan = [
            'version' => 2,
            'intent' => self::INTENT_CATEGORY_MANAGEMENT,
            'scope' => $scope,
            'locale' => $locale,
            'tools' => $planTools,
            'target_name' => $targetName,
            'requested_by_user_id' => (int) $user->id,
        ];

        if (empty($plan['tools'])) {
            return [
                'ok' => false,
                'message' => 'No enabled tools available for this request.',
            ];
        }

        $domain = $this->domainDefinition((string) $plan['intent']);
        $functionSteps = array_values(array_filter(array_map(function (array $tool): array {
            $toolName = (string) ($tool['name'] ?? '');
            $meta = $this->toolDefinition($toolName);
            if ($meta === []) {
                return [];
            }

            return [
                'name' => $toolName,
                'title' => (string) ($meta['title'] ?? $toolName),
                'description' => (string) ($meta['description'] ?? ''),
                'params' => (array) ($meta['params'] ?? []),
            ];
        }, $planTools)));

        return [
            'ok' => true,
            'plan_id' => (string) Str::ulid(),
            'summary' => "Will process category '{$targetName}' in path '".implode(' > ', $path)."' (scope: {$scope}, locale: {$locale}).",
            'actions' => $actions,
            'warnings' => $warnings,
            'domain_key' => (string) ($plan['intent'] ?? self::INTENT_CATEGORY_MANAGEMENT),
            'domain_title' => (string) ($domain['title'] ?? 'Category Management'),
            'function_steps' => $functionSteps,
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function executePlan(array $plan, User $user): array
    {
        if (($plan['intent'] ?? null) !== 'category_management') {
            return [
                'ok' => false,
                'message' => 'Unsupported plan intent.',
            ];
        }

        if ((int) ($plan['requested_by_user_id'] ?? 0) !== (int) $user->id) {
            return [
                'ok' => false,
                'message' => 'Plan owner mismatch. Please create a new preview.',
            ];
        }

        $scope = (string) ($plan['scope'] ?? Category::SCOPE_CATALOG);
        $locale = (string) ($plan['locale'] ?? config('app.locale', 'en'));
        $tools = (array) ($plan['tools'] ?? []);

        if (empty($tools)) {
            return [
                'ok' => false,
                'message' => 'Plan contains no executable tools.',
            ];
        }

        $result = DB::transaction(function () use ($scope, $locale, $tools, $user): array {
            $context = [
                'scope' => $scope,
                'locale' => $locale,
                'target_category_id' => null,
                'target_category_name' => null,
                'created_categories' => [],
                'attached_products' => 0,
            ];

            foreach ($tools as $tool) {
                if (!is_array($tool)) {
                    return ['ok' => false, 'message' => 'Invalid tool payload.'];
                }

                $name = (string) ($tool['name'] ?? '');
                $input = (array) ($tool['input'] ?? []);

                if (!$this->isToolAllowed($name)) {
                    return ['ok' => false, 'message' => "Tool '{$name}' is disabled."];
                }

                $exec = match ($name) {
                    self::TOOL_ENSURE_CATEGORY_PATH => $this->executeEnsureCategoryPath($input, $user, $context),
                    self::TOOL_UPSERT_CATEGORY_TRANSLATION => $this->executeUpsertCategoryTranslation($input, $user, $context),
                    self::TOOL_ATTACH_PRODUCTS_BY_FILTER => $this->executeAttachProductsByFilter($input, $user, $context),
                    self::TOOL_SET_CATEGORY_STATE => $this->executeSetCategoryState($input, $user, $context),
                    default => ['ok' => false, 'message' => "Unknown tool: {$name}"],
                };

                if (!$exec['ok']) {
                    return $exec;
                }
            }

            $targetCategoryId = (int) ($context['target_category_id'] ?? 0);
            if ($targetCategoryId <= 0) {
                return [
                    'ok' => false,
                    'message' => 'Execution finished without target category.',
                ];
            }

            $targetCategory = Category::query()->find($targetCategoryId);
            if (!$targetCategory) {
                return [
                    'ok' => false,
                    'message' => 'Target category was not found after execution.',
                ];
            }

            activity('admin_ai')
                ->performedOn($targetCategory)
                ->causedBy($user)
                ->event('executed')
                ->withProperties([
                    'scope' => $scope,
                    'locale' => $locale,
                    'tools' => array_map(fn ($tool) => (string) ($tool['name'] ?? 'unknown'), $tools),
                    'created_categories' => $context['created_categories'],
                    'attached_products' => $context['attached_products'],
                ])
                ->log('Admin AI executed tool plan');

            return [
                'ok' => true,
                'category_id' => $targetCategory->id,
                'category_name' => (string) ($context['target_category_name'] ?: $plan['target_name'] ?? 'Category'),
                'created_categories' => $context['created_categories'],
                'attached_products' => (int) $context['attached_products'],
                'redirect_url' => route('admin.categories.edit', ['category' => $targetCategory->id]),
            ];
        });

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeEnsureCategoryPath(array $input, User $user, array &$context): array
    {
        $scope = (string) ($input['scope'] ?? $context['scope'] ?? Category::SCOPE_CATALOG);
        $locale = (string) ($input['locale'] ?? $context['locale'] ?? config('app.locale', 'en'));
        $createMissing = (bool) ($input['create_missing'] ?? true);
        $rawSegments = (array) ($input['path_segments'] ?? []);

        $segments = [];
        foreach ($rawSegments as $segment) {
            $value = trim((string) $segment);
            if ($value !== '') {
                $segments[] = $value;
            }
        }

        if (empty($segments)) {
            return ['ok' => false, 'message' => 'Path segments are empty.'];
        }

        $parentId = null;
        $current = null;

        foreach ($segments as $segmentName) {
            $category = $this->findCategoryByNameAndParent($segmentName, $scope, $locale, $parentId);

            if (!$category) {
                if (!$createMissing) {
                    return ['ok' => false, 'message' => "Category segment '{$segmentName}' does not exist."];
                }

                $slugBase = Str::slug($segmentName);
                $category = new Category([
                    'scope' => $scope,
                    'code' => $this->makeUniqueCategoryCode('ai-'.$slugBase, $scope),
                    'is_active' => true,
                    'show_in_menu' => true,
                    'sort_order' => 0,
                    'payload' => null,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                if ($parentId) {
                    $parent = Category::query()->where('scope', $scope)->find($parentId);
                    if (!$parent) {
                        return ['ok' => false, 'message' => 'Parent category missing while building path.'];
                    }
                    $category->appendToNode($parent)->save();
                } else {
                    $category->saveAsRoot();
                }

                $category->translations()->create([
                    'scope' => $scope,
                    'locale' => $locale,
                    'name' => $segmentName,
                    'slug' => $this->makeUniqueCategorySlug($slugBase, $locale, $scope),
                    'description' => null,
                    'meta_title' => $segmentName,
                    'meta_description' => null,
                    'payload' => null,
                ]);

                $context['created_categories'][] = $segmentName;
            } else {
                $translation = $category->translations()
                    ->where('scope', $scope)
                    ->where('locale', $locale)
                    ->first();

                if (!$translation) {
                    $category->translations()->create([
                        'scope' => $scope,
                        'locale' => $locale,
                        'name' => $segmentName,
                        'slug' => $this->makeUniqueCategorySlug(Str::slug($segmentName), $locale, $scope),
                        'description' => null,
                        'meta_title' => $segmentName,
                        'meta_description' => null,
                        'payload' => null,
                    ]);
                }
            }

            $current = $category;
            $parentId = $category->id;
        }

        if (!$current) {
            return ['ok' => false, 'message' => 'Could not resolve final category.'];
        }

        $context['target_category_id'] = (int) $current->id;
        $context['target_category_name'] = (string) $segments[array_key_last($segments)];

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeUpsertCategoryTranslation(array $input, User $user, array &$context): array
    {
        $scope = (string) ($input['scope'] ?? $context['scope'] ?? Category::SCOPE_CATALOG);
        $locale = (string) ($input['locale'] ?? $context['locale'] ?? config('app.locale', 'en'));
        $name = trim((string) ($input['name'] ?? $context['target_category_name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        $targetCategoryId = (int) ($context['target_category_id'] ?? 0);
        if ($targetCategoryId <= 0) {
            return ['ok' => false, 'message' => 'No target category selected for translation update.'];
        }

        $category = Category::query()->where('scope', $scope)->find($targetCategoryId);
        if (!$category) {
            return ['ok' => false, 'message' => 'Target category not found for translation update.'];
        }

        $translation = CategoryTranslation::query()
            ->where('category_id', $category->id)
            ->where('scope', $scope)
            ->where('locale', $locale)
            ->first();

        if (!$translation) {
            $baseName = $name !== '' ? $name : ('Category '.$category->id);
            $translation = CategoryTranslation::query()->create([
                'category_id' => $category->id,
                'scope' => $scope,
                'locale' => $locale,
                'name' => $baseName,
                'slug' => $this->makeUniqueCategorySlug(Str::slug($baseName), $locale, $scope),
                'description' => $description !== '' ? $description : null,
                'meta_title' => $baseName,
                'meta_description' => null,
                'payload' => null,
            ]);
        } else {
            $updates = [];
            if ($name !== '' && $translation->name !== $name) {
                $updates['name'] = $name;
            }
            if ($description !== '' && $translation->description !== $description) {
                $updates['description'] = $description;
            }
            if (!empty($updates)) {
                $translation->update($updates);
            }
        }

        $category->update(['updated_by' => $user->id]);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeAttachProductsByFilter(array $input, User $user, array &$context): array
    {
        $filter = (string) ($input['filter'] ?? '');
        if ($filter !== 'created_today') {
            return ['ok' => false, 'message' => "Unsupported product filter '{$filter}'."];
        }

        $targetCategoryId = (int) ($context['target_category_id'] ?? 0);
        if ($targetCategoryId <= 0) {
            return ['ok' => false, 'message' => 'No target category selected for product attachment.'];
        }

        $category = Category::query()->find($targetCategoryId);
        if (!$category) {
            return ['ok' => false, 'message' => 'Target category not found for product attachment.'];
        }

        $todayProductIds = Product::query()
            ->whereDate('created_at', now(config('app.timezone'))->toDateString())
            ->orderByDesc('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $existingIds = $category->products()->pluck('products.id')->map(fn ($id) => (int) $id)->all();
        $newIds = array_values(array_diff($todayProductIds, $existingIds));

        if (!empty($newIds)) {
            $payload = [];
            $maxSort = (int) ($category->products()->max('category_product.sort_order') ?? -1);
            $hasPrimary = $category->products()->wherePivot('is_primary', true)->exists();

            foreach ($newIds as $index => $productId) {
                $payload[$productId] = [
                    'sort_order' => ++$maxSort,
                    'is_primary' => !$hasPrimary && $index === 0,
                ];
            }

            $category->products()->syncWithoutDetaching($payload);
            $context['attached_products'] = (int) $context['attached_products'] + count($newIds);
        }

        $category->update(['updated_by' => $user->id]);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeSetCategoryState(array $input, User $user, array &$context): array
    {
        $isActive = (bool) ($input['is_active'] ?? true);
        $targetCategoryId = (int) ($context['target_category_id'] ?? 0);

        if ($targetCategoryId <= 0) {
            return ['ok' => false, 'message' => 'No target category selected for state update.'];
        }

        $category = Category::query()->find($targetCategoryId);
        if (!$category) {
            return ['ok' => false, 'message' => 'Target category not found for state update.'];
        }

        $category->update([
            'is_active' => $isActive,
            'updated_by' => $user->id,
        ]);

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePrompt(string $prompt): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($prompt)) ?? '';

        $locale = config('app.locale', 'en');
        if (preg_match('/\b(locale|jezik)\s*[:=]?\s*(hr|en|de|it|sl)\b/i', $normalized, $m)) {
            $locale = strtolower((string) $m[2]);
        }

        $scope = Category::SCOPE_CATALOG;
        if (preg_match('/\b(blog)\b/iu', $normalized)) {
            $scope = Category::SCOPE_BLOG;
        } elseif (preg_match('/\b(page|stranic)\b/iu', $normalized)) {
            $scope = Category::SCOPE_PAGE;
        }

        $categoryName = null;
        $parentRaw = '';

        if (preg_match('/kategorij[au]\s+["“]?([^"”]+?)["”]?\s+unutar\s+["“]?([^"”,.!?]+)["”]?/iu', $normalized, $m)) {
            $categoryName = trim((string) $m[1]);
            $parentRaw = trim((string) ($m[2] ?? ''));
        } elseif (preg_match('/create\s+category\s+["“]?([^"”]+?)["”]?\s+(?:under|inside)\s+["“]?([^"”,.!?]+)["”]?/iu', $normalized, $m)) {
            $categoryName = trim((string) $m[1]);
            $parentRaw = trim((string) ($m[2] ?? ''));
        } elseif (preg_match('/kategorij[au]\s+["“]?([^"”,.!?]+)["”]?/iu', $normalized, $m)) {
            $categoryName = trim((string) $m[1]);
        } elseif (preg_match('/create\s+category\s+["“]?([^"”,.!?]+)["”]?/iu', $normalized, $m)) {
            $categoryName = trim((string) $m[1]);
        }

        if (!$categoryName) {
            return [
                'ok' => false,
                'message' => 'Could not parse command. Use format like: "Napravi mi kategoriju X unutar Y ...".',
            ];
        }

        $path = [];
        if (str_contains($categoryName, '>')) {
            $path = $this->splitPath($categoryName);
        } else {
            $parentSegments = $this->splitPath($parentRaw);
            $path = array_merge($parentSegments, [$categoryName]);
        }

        $path = array_values(array_filter($path, fn ($part) => trim((string) $part) !== ''));
        if (empty($path)) {
            return [
                'ok' => false,
                'message' => 'Unable to resolve category path from prompt.',
            ];
        }

        $attachTodayProducts =
            (bool) preg_match('/\b(danas|today)\b.*\b(artikl|artikle|proizvod|proizvode|product|products)\b/iu', $normalized) &&
            !preg_match('/\b(bez proizvoda|without products|no products)\b/iu', $normalized);

        $description = '';
        if (preg_match('/opis\s*[:=]\s*"([^"]+)"/iu', $normalized, $m)) {
            $description = trim((string) $m[1]);
        } elseif (preg_match('/description\s*[:=]\s*"([^"]+)"/iu', $normalized, $m)) {
            $description = trim((string) $m[1]);
        }

        $ensureDescription = false;
        if ($description === '' && preg_match('/\b(dodaj opis|add description)\b/iu', $normalized)) {
            $ensureDescription = true;
        }

        $setActive = null;
        if (preg_match('/\b(neaktiv|inactive|deactivate)\b/iu', $normalized)) {
            $setActive = false;
        } elseif (preg_match('/\b(aktiv|active|activate)\b/iu', $normalized)) {
            $setActive = true;
        }

        $createMissing = !preg_match('/\b(only if exists|samo ako postoji)\b/iu', $normalized);

        return [
            'ok' => true,
            'scope' => $scope,
            'locale' => $locale,
            'path_segments' => $path,
            'description' => $description,
            'ensure_description' => $ensureDescription,
            'attach_today_products' => $attachTodayProducts,
            'set_active' => $setActive,
            'create_missing' => $createMissing,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function splitPath(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:>|\/|->)\s*/u', $trimmed) ?: [];

        return array_values(array_filter(array_map(
            fn ($part) => trim((string) $part, " \t\n\r\0\x0B\"“”'"),
            $parts
        )));
    }

    private function defaultDescription(string $categoryName): string
    {
        return "Automatski opis kategorije {$categoryName}.";
    }

    private function findCategoryByNameAndParent(string $name, string $scope, string $locale, ?int $parentId): ?Category
    {
        $candidate = trim($name);

        $query = Category::query()
            ->where('scope', $scope)
            ->when(
                $parentId === null,
                fn ($q) => $q->whereNull('parent_id'),
                fn ($q) => $q->where('parent_id', $parentId)
            )
            ->whereHas('translations', function ($translationQuery) use ($candidate, $locale, $scope): void {
                $translationQuery->where('scope', $scope)
                    ->where('locale', $locale)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)]);
            });

        $category = $query->first();
        if ($category) {
            return $category;
        }

        return Category::query()
            ->where('scope', $scope)
            ->when(
                $parentId === null,
                fn ($q) => $q->whereNull('parent_id'),
                fn ($q) => $q->where('parent_id', $parentId)
            )
            ->whereHas('translations', function ($translationQuery) use ($candidate, $scope): void {
                $translationQuery->where('scope', $scope)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)]);
            })
            ->first();
    }

    private function makeUniqueCategorySlug(string $base, string $locale, string $scope): string
    {
        $base = $base !== '' ? $base : 'category';
        $slug = $base;
        $i = 2;

        while (
            DB::table('category_translations')
                ->where('scope', $scope)
                ->where('locale', $locale)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    private function makeUniqueCategoryCode(string $base, string $scope): string
    {
        $base = Str::lower(trim($base)) ?: 'ai-category';
        $code = $base;
        $i = 2;

        while (
            DB::table('categories')
                ->where('scope', $scope)
                ->where('code', $code)
                ->exists()
        ) {
            $code = $base.'-'.$i;
            $i++;
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function domainDefinition(string $domainKey): array
    {
        /** @var array<string, array<string, mixed>> $domains */
        $domains = config('admin_ai.domains', []);

        return $domains[$domainKey] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolDefinition(string $toolName): array
    {
        /** @var array<string, array<string, mixed>> $functions */
        $functions = config('admin_ai.functions', []);

        return $functions[$toolName] ?? [];
    }

    private function isToolAllowed(string $toolName): bool
    {
        /** @var array<string, bool> $tools */
        $tools = config('admin_ai.tools', []);

        return (bool) ($tools[$toolName] ?? false);
    }
}
