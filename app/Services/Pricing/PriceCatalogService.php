<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogAudit;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PriceCatalogService
{
    public function authorize(User $actor, string $operation = 'view'): void
    {
        abort_unless($actor->isA('superadmin') || ($actor->can('admin.access') && $actor->can('catalog.b2b_prices.'.$operation)), 403);
    }

    public function createDraft(string $name, User $actor): PriceCatalog
    {
        $this->authorize($actor, 'create');
        Validator::make(['name' => $name], ['name' => 'required|string|max:191'])->validate();

        return DB::transaction(function () use ($name, $actor): PriceCatalog {
            $catalog = PriceCatalog::query()->create(['name' => trim($name), 'status' => PriceCatalog::DRAFT, 'currency_code' => 'EUR', 'created_by' => $actor->id]);
            $this->audit($catalog, $actor, 'created');

            return $catalog;
        });
    }

    public function cloneToDraft(PriceCatalog $source, User $actor): PriceCatalog
    {
        $this->authorize($actor, 'create');

        return DB::transaction(function () use ($source, $actor): PriceCatalog {
            $source = PriceCatalog::query()->lockForUpdate()->findOrFail($source->id);
            $sourceMetadata = $source->metadata ?? [];
            $imported = $source->source_system !== null && $source->source_system !== 'manual';
            $expected = $sourceMetadata['import_expected_entries'] ?? null;
            if (($imported && ($sourceMetadata['import_complete'] ?? false) !== true)
                || ($expected !== null && (int) $expected !== $source->entries()->count())) {
                throw ValidationException::withMessages(['catalog' => 'Nedovršeni uvoz se ne može kopirati niti objaviti.']);
            }
            // Copy only a verified source. The new draft can deliberately add/remove entries.
            unset($sourceMetadata['import_expected_entries']);
            $sourceMetadata['cloned_from'] = $source->id;
            if ($imported) {
                $sourceMetadata['import_complete'] = true;
            }
            $draft = PriceCatalog::query()->create([
                'name' => Str::limit($source->name.' — radna kopija', 191, ''), 'status' => PriceCatalog::DRAFT,
                'currency_code' => $source->currency_code, 'created_by' => $actor->id,
                'source_system' => $source->source_system, 'source_snapshot' => $source->source_snapshot,
                'source_checksum' => $source->source_checksum, 'metadata' => $sourceMetadata,
            ]);
            $ruleIds = app(CatalogGroupDiscountService::class)->cloneRules($source, $draft, $actor);
            $columns = ['source_key', 'product_id', 'kind', 'customer_group_id', 'user_id', 'minimum_quantity', 'price', 'priority', 'starts_at', 'ends_at', 'is_active', 'payload', 'created_at', 'updated_at'];
            $ruleCase = $ruleIds === [] ? 'NULL' : 'CASE discount_rule_id '.implode(' ', array_map(fn ($old, $new) => 'WHEN '.(int) $old.' THEN '.(int) $new, array_keys($ruleIds), $ruleIds)).' ELSE NULL END';
            DB::table('catalog_price_entries')->insertUsing(
                ['price_catalog_id', 'discount_rule_id', ...$columns],
                DB::table('catalog_price_entries')->where('price_catalog_id', $source->id)->selectRaw((int) $draft->id.' as price_catalog_id, '.$ruleCase.' as discount_rule_id')->addSelect($columns),
            );
            $this->audit($draft, $actor, 'cloned', ['source_catalog_id' => $source->id, 'entry_count' => $draft->entries()->count(), 'discount_rule_count' => count($ruleIds)]);

            return $draft;
        });
    }

    public function saveEntry(PriceCatalog $catalog, array $data, User $actor, ?int $entryId = null): PriceCatalogEntry
    {
        $this->authorize($actor, 'update');
        foreach (['customer_group_id', 'user_id', 'starts_at', 'ends_at'] as $key) {
            $data[$key] = ($data[$key] ?? '') === '' ? null : $data[$key];
        }
        $validated = Validator::make($data, [
            'product_id' => 'required|integer|exists:products,id',
            'kind' => ['required', Rule::in(array_keys(PriceCatalogEntry::manualKindOptions()))],
            'customer_group_id' => 'nullable|integer|exists:customer_groups,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'minimum_quantity' => 'required|integer|min:1|max:999999',
            'price' => ['required', 'numeric', 'min:0', 'max:999999999999999.9999', 'regex:/^\d{1,16}(\.\d{1,4})?$/'],
            'priority' => 'required|integer|min:-2147483648|max:2147483647',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date',
            'is_active' => 'required|boolean',
        ])->validate();
        $individual = $validated['kind'] === PriceCatalogEntry::CUSTOMER;
        $base = $validated['kind'] === PriceCatalogEntry::BASE;
        if ($base ? ! empty($validated['user_id']) || ! empty($validated['customer_group_id']) : ($individual ? empty($validated['user_id']) || ! empty($validated['customer_group_id']) : empty($validated['customer_group_id']) || ! empty($validated['user_id']))) {
            throw ValidationException::withMessages(['audience' => 'Odaberite samo kupca za individualnu cijenu, odnosno samo grupu za ostale cijene.']);
        }
        if (! empty($validated['starts_at']) && ! empty($validated['ends_at']) && Carbon::parse($validated['ends_at'])->lte(Carbon::parse($validated['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'Završetak mora biti nakon početka.']);
        }

        return DB::transaction(function () use ($catalog, $validated, $actor, $entryId): PriceCatalogEntry {
            $catalog = $this->lockedDraft($catalog);
            $entry = $entryId ? $catalog->entries()->findOrFail($entryId) : new PriceCatalogEntry(['price_catalog_id' => $catalog->id, 'source_key' => 'manual:'.Str::uuid()]);
            if ($entry->discount_rule_id) {
                throw ValidationException::withMessages(['entry' => 'Cijena je izračunata iz popusta grupe. Uredite pravilo popusta.']);
            }
            $before = $entry->exists ? $entry->only(array_keys($validated)) : null;
            $entry->fill($validated)->save();
            $this->audit($catalog, $actor, $before ? 'entry_updated' : 'entry_created', ['entry_id' => $entry->id, 'before' => $before, 'after' => $entry->only(array_keys($validated))]);

            return $entry;
        });
    }

    public function deleteEntry(PriceCatalog $catalog, int $entryId, User $actor): void
    {
        $this->authorize($actor, 'delete');
        DB::transaction(function () use ($catalog, $entryId, $actor): void {
            $catalog = $this->lockedDraft($catalog);
            $entry = $catalog->entries()->findOrFail($entryId);
            if ($entry->discount_rule_id) {
                throw ValidationException::withMessages(['entry' => 'Cijena je izračunata iz popusta grupe. Uklonite ili uredite pravilo popusta.']);
            }
            $this->audit($catalog, $actor, 'entry_deleted', ['entry' => $entry->toArray()]);
            $entry->delete();
        });
    }

    /** A compact per-product table; empty cells intentionally leave existing prices unchanged. */
    public function saveGroupPrices(PriceCatalog $catalog, int $productId, array $pricesByGroup, User $actor): int
    {
        $this->authorize($actor, 'update');
        $rows = [];
        foreach ($pricesByGroup as $groupId => $price) {
            if ($price === null || (is_string($price) && trim($price) === '')) {
                continue;
            }
            $rows[] = ['customer_group_id' => $groupId, 'price' => $price];
        }
        $validated = Validator::make(['product_id' => $productId, 'rows' => $rows], [
            'product_id' => 'required|integer|exists:products,id', 'rows' => 'array|max:1000',
            'rows.*.customer_group_id' => ['required', 'integer', 'distinct', Rule::exists('customer_groups', 'id')->where('is_active', true)],
            'rows.*.price' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,16}(\.\d{1,4})?$/'],
        ])->validate();

        return DB::transaction(function () use ($catalog, $productId, $validated, $actor): int {
            $catalog = $this->lockedDraft($catalog);
            $changes = [];
            foreach ($validated['rows'] as $row) {
                $sourceKey = 'manual-group:'.$productId.':'.$row['customer_group_id'];
                $entry = $catalog->entries()->firstOrNew(['source_key' => $sourceKey]);
                $before = $entry->exists ? $entry->price : null;
                $entry->fill(['price_catalog_id' => $catalog->id, 'discount_rule_id' => null, 'product_id' => $productId, 'kind' => PriceCatalogEntry::GROUP, 'customer_group_id' => $row['customer_group_id'], 'user_id' => null, 'minimum_quantity' => 1, 'price' => $row['price'], 'priority' => 0, 'starts_at' => null, 'ends_at' => null, 'is_active' => true])->save();
                $changes[] = ['entry_id' => $entry->id, 'customer_group_id' => (int) $row['customer_group_id'], 'before' => $before, 'after' => $entry->price];
            }
            if ($changes !== []) {
                $this->audit($catalog, $actor, 'product_group_prices_saved', ['product_id' => $productId, 'changes' => $changes]);
            }

            return count($changes);
        });
    }

    public function activate(PriceCatalog $catalog, User $actor): void
    {
        $this->authorize($actor, 'update');
        DB::transaction(function () use ($catalog, $actor): void {
            $state = DB::table('catalog_price_catalog_state')->where('id', 1)->lockForUpdate()->first();
            if (! $state) {
                throw ValidationException::withMessages(['catalog' => 'Nedostaje kontrolni zapis aktivnog cjenika. Objavljivanje je zaustavljeno.']);
            }
            $catalog = $this->lockedDraft($catalog);
            app(CatalogGroupDiscountService::class)->rebuildForPublication($catalog, $actor);
            $count = $catalog->entries()->count();
            $invalid = $catalog->entries()->where(function ($q): void {
                $q->where('price', '<', 0)->orWhere('minimum_quantity', '<', 1)
                    ->orWhereNotIn('kind', array_keys(PriceCatalogEntry::kindOptions()))
                    ->orWhere(fn ($q) => $q->where('kind', PriceCatalogEntry::CUSTOMER)->where(fn ($q) => $q->whereNull('user_id')->orWhereNotNull('customer_group_id')))
                    ->orWhere(fn ($q) => $q->whereNotIn('kind', [PriceCatalogEntry::CUSTOMER, PriceCatalogEntry::BASE])->where(fn ($q) => $q->whereNull('customer_group_id')->orWhereNotNull('user_id')))
                    ->orWhere(fn ($q) => $q->where('kind', PriceCatalogEntry::BASE)->where(fn ($q) => $q->whereNotNull('customer_group_id')->orWhereNotNull('user_id')))
                    ->orWhere(fn ($q) => $q->where('kind', PriceCatalogEntry::GROUP_DISCOUNT)->whereNull('discount_rule_id'))
                    ->orWhere(fn ($q) => $q->where('kind', PriceCatalogEntry::GROUP_DISCOUNT)->whereDoesntHave('discountRule', fn ($rule) => $rule->whereColumn('catalog_group_discount_rules.price_catalog_id', 'catalog_price_entries.price_catalog_id')))
                    ->orWhere(fn ($q) => $q->where('kind', '!=', PriceCatalogEntry::GROUP_DISCOUNT)->whereNotNull('discount_rule_id'))
                    ->orWhere(fn ($q) => $q->whereNotNull('starts_at')->whereNotNull('ends_at')->whereColumn('ends_at', '<=', 'starts_at'));
            })->exists();
            $expected = $catalog->metadata['import_expected_entries'] ?? null;
            $imported = $catalog->source_system !== null && $catalog->source_system !== 'manual';
            $complete = $catalog->metadata['import_complete'] ?? ! $imported;
            if ($count === 0 || $invalid || $catalog->currency_code !== 'EUR' || ($expected !== null && (int) $expected !== $count) || $complete !== true) {
                throw ValidationException::withMessages(['catalog' => 'Cjenik nije spreman: provjerite broj stavki, publiku, datume, iznose, valutu i završen uvoz.']);
            }
            if ($state?->price_catalog_id) {
                PriceCatalog::query()->whereKey($state->price_catalog_id)->update(['status' => PriceCatalog::RETIRED, 'updated_at' => now()]);
            }
            $catalog->update(['status' => PriceCatalog::ACTIVE, 'activated_at' => now(), 'activated_by' => $actor->id]);
            DB::table('catalog_price_catalog_state')->where('id', 1)->update(['price_catalog_id' => $catalog->id]);
            $this->audit($catalog, $actor, 'activated', ['previous_catalog_id' => $state?->price_catalog_id, 'entry_count' => $count, 'source_checksum' => $catalog->source_checksum]);
        });
        app(PriceCatalogResolver::class)->forgetActiveCatalog();
    }

    private function lockedDraft(PriceCatalog $catalog): PriceCatalog
    {
        $catalog = PriceCatalog::query()->lockForUpdate()->findOrFail($catalog->id);
        if ($catalog->status !== PriceCatalog::DRAFT) {
            throw ValidationException::withMessages(['catalog' => 'Objavljeni cjenik je zaključan. Napravite radnu kopiju.']);
        }

        return $catalog;
    }

    private function audit(PriceCatalog $catalog, User $actor, string $event, array $payload = []): void
    {
        PriceCatalogAudit::query()->create(['price_catalog_id' => $catalog->id, 'actor_id' => $actor->id, 'event' => $event, 'payload' => $payload, 'created_at' => now()]);
    }
}
