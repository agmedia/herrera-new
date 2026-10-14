<?php

namespace App\Services\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogAudit;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Pricing\CatalogGroupDiscountService;
use App\Services\Pricing\PriceCatalogService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Prepare a reviewed native overlay without publishing or altering either input catalog. */
class HerreraNativePriceOverlayService
{
    private const FIELDS = ['product_id', 'kind', 'customer_group_id', 'user_id', 'minimum_quantity', 'price', 'priority', 'starts_at', 'ends_at', 'is_active'];

    private const SOURCE_KINDS = ['product' => PriceCatalogEntry::BASE, 'product_price_by_cigroup' => PriceCatalogEntry::GROUP,
        'product_price_by_customer_id' => PriceCatalogEntry::CUSTOMER, 'product_discount' => PriceCatalogEntry::QUANTITY, 'product_special' => PriceCatalogEntry::SPECIAL];

    public function plan(PriceCatalog $sourceDraft, User $actor, array $sourceAudit): array
    {
        app(PriceCatalogService::class)->authorize($actor, 'create');
        app(PriceCatalogService::class)->authorize($actor, 'update');
        $sourceDraft = $sourceDraft->fresh();
        $count = $sourceDraft->entries()->count();
        $activeId = DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id');
        $active = $activeId ? PriceCatalog::query()->find($activeId) : null;
        $plan = ['source_catalog_id' => $sourceDraft->id, 'active_catalog_id' => $active?->id,
            'source_entry_count' => $count, 'native_rule_ids' => [], 'native_entry_ids' => [], 'native_override_ids' => [], 'conflicts' => []];
        if ($sourceDraft->status !== PriceCatalog::DRAFT || $sourceDraft->source_system !== 'herrera-opencart'
            || $sourceDraft->currency_code !== 'EUR' || ($sourceDraft->metadata['import_complete'] ?? false) !== true
            || (int) ($sourceDraft->metadata['import_expected_entries'] ?? -1) !== $count || $count === 0
            || $sourceDraft->discountRules()->exists()) {
            $plan['conflicts'][] = ['code' => 'source_catalog_is_not_a_complete_source_only_draft'];
        }
        if (($sourceAudit['passed'] ?? false) !== true || (int) ($sourceAudit['catalog_id'] ?? 0) !== $sourceDraft->id
            || ! is_string($sourceDraft->source_checksum) || $sourceDraft->source_checksum === ''
            || ($sourceAudit['source_checksum'] ?? null) !== $sourceDraft->source_checksum
            || (int) ($sourceAudit['entry_count'] ?? -1) !== $count) {
            $plan['conflicts'][] = ['code' => 'source_audit_proof_mismatch'];
        }
        if (($activeId && ! $active) || ($active && ($active->status !== PriceCatalog::ACTIVE || $active->currency_code !== 'EUR' || $active->id === $sourceDraft->id))) {
            $plan['conflicts'][] = ['code' => 'active_catalog_state_mismatch'];

            return $this->finishPlan($plan);
        }
        $maps = [];
        foreach (['product', 'customer_group', 'customer'] as $entity) {
            $maps[$entity] = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', $entity)->pluck('target_id', 'source_id')->all();
        }
        // A draft can be edited without changing its stored source checksum or
        // entry count. Verify source provenance again before trusting the proof.
        foreach ($sourceDraft->entries()->orderBy('id')->lazyById(500) as $entry) {
            $baseline = $this->sourceBaseline($entry, $maps);
            if ($baseline === null || collect($baseline)->contains(fn ($value, $field) => ! $this->same($entry->getRawOriginal($field), $value))) {
                $plan['conflicts'][] = ['code' => 'source_draft_contains_unverified_or_edited_entry', 'entry_id' => $entry->id];
            }
        }
        if (! $active || $plan['conflicts'] !== []) {
            return $this->finishPlan($plan);
        }
        $lineage = $active;
        $visited = [];
        while ($lineage && ! isset($visited[$lineage->id])) {
            $visited[$lineage->id] = true;
            foreach ($lineage->audits()->where('event', 'entry_deleted')->get() as $audit) {
                $deleted = $audit->payload['entry'] ?? [];
                $key = $deleted['source_key'] ?? null;
                if (is_string($key) && ! str_starts_with($key, 'manual:') && ! str_starts_with($key, 'manual-group:')
                    && ! $active->entries()->where('source_key', $key)->exists()
                    && $sourceDraft->entries()->where('source_key', $key)->exists()) {
                    $plan['conflicts'][] = ['code' => 'native_source_entry_deletion_requires_review', 'audit_id' => $audit->id];
                }
            }
            $parentId = $lineage->metadata['cloned_from'] ?? null;
            if ($parentId && isset($visited[$parentId])) {
                $plan['conflicts'][] = ['code' => 'catalog_audit_lineage_cycle'];
                break;
            }
            $lineage = $parentId ? PriceCatalog::query()->find($parentId) : null;
            if ($parentId && ! $lineage) {
                $plan['conflicts'][] = ['code' => 'catalog_audit_lineage_missing'];
                break;
            }
            if (count($visited) >= 100) {
                $plan['conflicts'][] = ['code' => 'catalog_audit_lineage_too_deep'];
                break;
            }
        }
        foreach ($active->discountRules()->get() as $rule) {
            try {
                app(CatalogGroupDiscountService::class)->previewRule($sourceDraft, $rule->toArray(), $actor);
                $plan['native_rule_ids'][] = $rule->id;
                if ($rule->is_active && $this->ruleTouchesSourceProducts($sourceDraft, $rule->toArray())) {
                    $plan['conflicts'][] = ['code' => 'native_rule_changes_live_source_prices_requires_reconciliation', 'rule_id' => $rule->id];
                }
            } catch (ValidationException $exception) {
                $plan['conflicts'][] = ['code' => 'native_rule_reference_or_scope_invalid', 'rule_id' => $rule->id, 'fields' => array_keys($exception->errors())];
            }
        }
        foreach ($active->entries()->whereNull('discount_rule_id')->orderBy('id')->lazyById(500) as $entry) {
            $manual = str_starts_with($entry->source_key, 'manual:') || str_starts_with($entry->source_key, 'manual-group:');
            $modified = [];
            if (! $manual) {
                $baseline = $this->sourceBaseline($entry, $maps);
                if ($baseline === null) {
                    $plan['conflicts'][] = ['code' => 'entry_ownership_unverifiable', 'entry_id' => $entry->id];

                    continue;
                }
                foreach ($baseline as $field => $value) {
                    if (! $this->same($entry->getRawOriginal($field), $value)) {
                        $modified[] = $field;
                    }
                }
                if ($modified === []) {
                    continue;
                }
                if (array_intersect($modified, ['product_id', 'kind', 'customer_group_id', 'user_id'])) {
                    $plan['conflicts'][] = ['code' => 'native_override_source_scope_changed', 'entry_id' => $entry->id, 'fields' => $modified];

                    continue;
                }
            }
            if (! $this->validReferences($entry)) {
                $plan['conflicts'][] = ['code' => 'native_entry_reference_invalid', 'entry_id' => $entry->id];

                continue;
            }
            $incoming = $sourceDraft->entries()->where('source_key', $entry->source_key)->first();
            if ($manual ? $incoming !== null : $incoming === null) {
                $plan['conflicts'][] = ['code' => $manual ? 'native_source_key_collision' : 'native_override_source_entry_missing', 'entry_id' => $entry->id];

                continue;
            }
            if ($incoming && collect(['product_id', 'kind', 'customer_group_id', 'user_id'])->contains(fn ($field) => ! $this->same($incoming->getRawOriginal($field), $entry->getRawOriginal($field)))) {
                $plan['conflicts'][] = ['code' => 'native_override_incoming_scope_changed', 'entry_id' => $entry->id];

                continue;
            }
            $plan['native_entry_ids'][] = $entry->id;
            if (! $manual) {
                $plan['native_override_ids'][] = $entry->id;
                if (collect(self::FIELDS)->contains(fn ($field) => ! $this->same($incoming->getRawOriginal($field), $entry->getRawOriginal($field)))) {
                    $plan['conflicts'][] = ['code' => 'native_override_changes_live_source_prices_requires_reconciliation', 'entry_id' => $entry->id, 'source_entry_id' => $incoming->id];
                }
            } elseif ($entry->is_active && $sourceDraft->entries()->where('product_id', $entry->product_id)->where('is_active', true)->exists()) {
                // A different kind, audience, threshold, date or priority can
                // replace a live source price. Never silently choose one side.
                $plan['conflicts'][] = ['code' => 'native_entry_may_change_live_source_prices_requires_reconciliation', 'entry_id' => $entry->id, 'product_id' => $entry->product_id];
            }
        }

        return $this->finishPlan($plan);
    }

    public function prepare(PriceCatalog $sourceDraft, User $actor, array $sourceAudit): array
    {
        return DB::transaction(function () use ($sourceDraft, $actor, $sourceAudit): array {
            $state = DB::table('catalog_price_catalog_state')->where('id', 1)->lockForUpdate()->first();
            if ($state?->price_catalog_id) {
                PriceCatalog::query()->whereKey($state->price_catalog_id)->lockForUpdate()->firstOrFail();
            }
            $sourceDraft = PriceCatalog::query()->lockForUpdate()->findOrFail($sourceDraft->id);
            $plan = $this->plan($sourceDraft, $actor, $sourceAudit);
            if (! $plan['ready']) {
                throw ValidationException::withMessages(['native_overlay' => 'Native pricing conflicts or a source audit mismatch require review before preparing a working copy.']);
            }
            $pricing = app(PriceCatalogService::class);
            $draft = $pricing->cloneToDraft($sourceDraft, $actor);
            $carriedEntries = [];
            foreach (PriceCatalogEntry::query()->whereIn('id', $plan['native_entry_ids'])->orderBy('id')->get() as $entry) {
                $existing = $draft->entries()->where('source_key', $entry->source_key)->first();
                $data = $entry->only(self::FIELDS);
                foreach (['starts_at', 'ends_at'] as $field) {
                    $data[$field] = $entry->{$field}?->format('Y-m-d H:i:s');
                }
                $copy = $pricing->saveEntry($draft, $data, $actor, $existing?->id);
                $copy->update(['source_key' => $entry->source_key, 'payload' => array_replace($entry->payload ?? [], ['native_overlay' => ['source_catalog_id' => $entry->price_catalog_id, 'source_entry_id' => $entry->id]])]);
                $carriedEntries[$entry->id] = $copy->id;
            }
            $carriedRules = [];
            $active = $plan['active_catalog_id'] ? PriceCatalog::query()->findOrFail($plan['active_catalog_id']) : null;
            foreach ($active?->discountRules()->whereIn('id', $plan['native_rule_ids'])->get() ?? [] as $rule) {
                $copy = app(CatalogGroupDiscountService::class)->saveRule($draft, $rule->toArray(), $actor);
                $carriedRules[$rule->id] = $copy->id;
            }
            $proof = ['source_catalog_id' => $sourceDraft->id, 'active_catalog_id' => $active?->id, 'source_audit' => $sourceAudit,
                'native_rule_map' => $carriedRules, 'native_entry_map' => $carriedEntries,
                'native_rule_count' => count($carriedRules), 'native_entry_count' => count($carriedEntries),
                'native_override_count' => $plan['native_override_count'], 'prepared_at' => now()->toIso8601String(), 'prepared_by' => $actor->id];
            $draft->update(['metadata' => array_replace($draft->metadata ?? [], ['native_overlay' => $proof])]);
            PriceCatalogAudit::query()->create(['price_catalog_id' => $draft->id, 'actor_id' => $actor->id, 'event' => 'native_overlay_prepared', 'payload' => $proof, 'created_at' => now()]);

            return ['draft_catalog_id' => $draft->id, 'status' => $draft->status, 'source_catalog_id' => $sourceDraft->id,
                'active_catalog_id' => $active?->id, 'native_rule_count' => count($carriedRules),
                'native_entry_count' => count($carriedEntries), 'native_override_count' => $plan['native_override_count']];
        });
    }

    private function sourceBaseline(PriceCatalogEntry $entry, array $maps): ?array
    {
        $row = $entry->payload['opencart'] ?? null;
        $table = explode(':', $entry->source_key)[0];
        $kind = self::SOURCE_KINDS[$table] ?? null;
        if (! is_array($row) || $kind === null || ! isset($row['price'], $row['product_id'])) {
            return null;
        }
        $productId = $maps['product'][$row['product_id']] ?? null;
        $groupSource = $kind === PriceCatalogEntry::GROUP ? ($row['group_id'] ?? null)
            : (in_array($kind, [PriceCatalogEntry::QUANTITY, PriceCatalogEntry::SPECIAL], true) ? ($row['customer_group_id'] ?? null) : null);
        $groupId = $groupSource !== null ? ($maps['customer_group'][$groupSource] ?? null) : null;
        $userId = $kind === PriceCatalogEntry::CUSTOMER ? ($maps['customer'][$row['customer_id'] ?? ''] ?? null) : null;
        if (! $productId || ($groupSource !== null && ! $groupId) || ($kind === PriceCatalogEntry::CUSTOMER && ! $userId)) {
            return null;
        }

        return ['product_id' => $productId, 'kind' => $kind, 'customer_group_id' => $groupId, 'user_id' => $userId,
            'minimum_quantity' => $kind === PriceCatalogEntry::BASE ? 1 : max(1, $row['quantity'] ?? 1),
            'price' => $row['price'], 'priority' => $row['priority'] ?? 0, 'starts_at' => $this->date($row['date_start'] ?? null),
            'ends_at' => $this->date($row['date_end'] ?? null), 'is_active' => true];
    }

    private function validReferences(PriceCatalogEntry $entry): bool
    {
        return DB::table('products')->where('id', $entry->product_id)->exists()
            && (! $entry->customer_group_id || DB::table('customer_groups')->where('id', $entry->customer_group_id)->where('is_active', true)->exists())
            && (! $entry->user_id || DB::table('users')->where('id', $entry->user_id)->where('account_type', 'customer')->exists());
    }

    private function ruleTouchesSourceProducts(PriceCatalog $source, array $rule): bool
    {
        $products = Product::query()->whereIn('products.id', $source->entries()->select('product_id')->where('is_active', true));
        if ($rule['manufacturer_ids'] !== []) {
            $products->whereIn('manufacturer_id', $rule['manufacturer_ids']);
        }
        if ($rule['category_ids'] !== []) {
            $ids = $rule['category_ids'];
            if ($rule['include_descendants']) {
                $tree = Category::query()->where('scope', Category::SCOPE_CATALOG)->get(['id', 'parent_id']);
                do {
                    $before = count($ids);
                    $ids = array_values(array_unique([...$ids, ...$tree->whereIn('parent_id', $ids)->pluck('id')->all()]));
                } while (count($ids) > $before);
            }
            $products->whereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $ids));
        }
        if ($rule['excluded_product_ids'] !== []) {
            $products->whereNotIn('products.id', $rule['excluded_product_ids']);
        }

        return $products->exists();
    }

    private function date(?string $value): ?string
    {
        return ! $value || str_starts_with($value, '0000-') || str_starts_with($value, '1970-01-01') ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function same(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return is_numeric($left) && is_numeric($right)
            ? BigDecimal::of((string) $left)->isEqualTo((string) $right)
            : (string) $left === (string) $right;
    }

    private function finishPlan(array $plan): array
    {
        return $plan + ['native_rule_count' => count($plan['native_rule_ids']), 'native_entry_count' => count($plan['native_entry_ids']),
            'native_override_count' => count($plan['native_override_ids']),
            'reconciliation_required_count' => count(array_filter($plan['conflicts'], fn ($conflict) => str_ends_with($conflict['code'], '_requires_reconciliation'))),
            'ready' => $plan['conflicts'] === []];
    }
}
