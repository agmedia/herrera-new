<?php

namespace App\Services\Integrations\Msan;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Attribute\AttributeTranslation;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSpecificationDefinition;
use App\Models\Integrations\Msan\MsanSpecificationSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MsanSpecificationPublisher
{
    public const PUBLISH_LOCK_KEY = 'integrations:msan:specification-publish';

    public const PUBLISH_LOCK_SECONDS = 10800;

    private const SOURCE = 'msan';

    /**
     * Candidate validation limits each product to at most 4 MiB of encoded
     * values. Keeping the source batch small bounds the largest result set
     * materialized by the publisher well below the queue worker memory limit.
     */
    private const BATCH_SIZE = 5;

    private const MAX_FILTER_VALUES_PER_DEFINITION = 100;

    /** @var array<string, Attribute|null> */
    private array $filterAttributeCache = [];

    /** @var array<string, int> */
    private array $filterValueCountCache = [];

    /** @var array<string, true> */
    private array $preparedFilterTranslations = [];

    /**
     * @return array{products:int,specifications:int,energy_declarations:int,filter_attributes:int}
     */
    public function publishSnapshot(MsanSpecificationSnapshot $snapshot): array
    {
        $this->filterAttributeCache = [];
        $this->filterValueCountCache = [];
        $this->preparedFilterTranslations = [];

        $counts = [
            'products' => 0,
            'specifications' => 0,
            'energy_declarations' => 0,
            'filter_attributes' => 0,
        ];

        MsanProduct::query()
            ->whereNotNull('local_product_id')
            ->with(['categories.mapping'])
            ->select(['id', 'external_code', 'local_product_id'])
            ->chunkById(self::BATCH_SIZE, function ($sources) use ($snapshot, &$counts): void {
                $sourceIds = $sources->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                $specifications = DB::table('msan_product_specifications as product_specs')
                    ->join('msan_specification_definitions as definitions', 'definitions.id', '=', 'product_specs.definition_id')
                    ->where('product_specs.snapshot_id', $snapshot->id)
                    ->whereIn('product_specs.msan_product_id', $sourceIds)
                    ->where('definitions.import_enabled', true)
                    ->where('definitions.is_stale', false)
                    ->orderBy('product_specs.msan_product_id')
                    ->orderBy('product_specs.item_order')
                    ->orderBy('product_specs.id')
                    ->get([
                        'product_specs.msan_product_id',
                        'product_specs.values',
                        'product_specs.item_order',
                        'product_specs.checksum',
                        'definitions.id as definition_id',
                        'definitions.source_key',
                        'definitions.group_name',
                        'definitions.item_name',
                        'definitions.display_group_name',
                        'definitions.display_item_name',
                        'definitions.display_measure',
                        'definitions.measure',
                        'definitions.use_as_filter',
                        'definitions.data_role',
                    ])
                    ->groupBy('msan_product_id');
                $products = Product::query()
                    ->whereIn('id', $sources->pluck('local_product_id')->filter()->all())
                    ->with('energyDeclarations')
                    ->get()
                    ->keyBy('id');

                foreach ($sources as $source) {
                    /** @var Product|null $product */
                    $product = $products->get((int) $source->local_product_id);
                    $rows = $specifications->get((int) $source->id, collect());
                    if (! $product) {
                        continue;
                    }

                    $result = $this->publishProduct($product, $source, $rows, $snapshot);
                    $counts['products']++;
                    $counts['specifications'] += $result['specifications'];
                    $counts['energy_declarations'] += $result['energy_declarations'];
                    $counts['filter_attributes'] += $result['filter_attributes'];
                }
            });

        return $counts;
    }

    public function clearPublishedProjection(): void
    {
        $this->filterAttributeCache = [];
        $this->filterValueCountCache = [];
        $this->preparedFilterTranslations = [];

        MsanProduct::query()
            ->whereNotNull('local_product_id')
            ->with(['categories.mapping'])
            ->select(['id', 'external_code', 'local_product_id'])
            ->chunkById(self::BATCH_SIZE, function ($sources): void {
                $products = Product::query()
                    ->whereIn('id', $sources->pluck('local_product_id')->filter()->all())
                    ->with('energyDeclarations')
                    ->get()
                    ->keyBy('id');

                foreach ($sources as $source) {
                    /** @var Product|null $product */
                    $product = $products->get((int) $source->local_product_id);
                    if (! $product) {
                        continue;
                    }

                    DB::transaction(function () use ($product, $source): void {
                        DB::table('catalog_product_specifications')
                            ->where('product_id', $product->id)
                            ->where('source', self::SOURCE)
                            ->delete();
                        $this->publishEnergy($product, $source, collect(), null);
                        $this->publishFilterAttributes($product, collect());
                        $source->forceFill([
                            'specifications_checksum' => null,
                            'specifications_synced_at' => null,
                        ])->save();
                    }, 3);
                }
            });
    }

    /** @return array{specifications:int,energy_declarations:int,filter_attributes:int} */
    public function publishProductFromActiveSnapshot(MsanProduct $source): array
    {
        $snapshot = MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->latest('id')
            ->first();
        if (! $snapshot || ! $source->local_product_id) {
            return ['specifications' => 0, 'energy_declarations' => 0, 'filter_attributes' => 0];
        }

        return $this->publishProductFromSnapshot($source, $snapshot);
    }

    /** @return array{specifications:int,energy_declarations:int,filter_attributes:int} */
    public function publishProductFromSnapshot(
        MsanProduct $source,
        MsanSpecificationSnapshot $snapshot,
    ): array {
        if (! $source->local_product_id || $snapshot->status !== MsanSpecificationSnapshot::STATUS_ACTIVE) {
            return ['specifications' => 0, 'energy_declarations' => 0, 'filter_attributes' => 0];
        }

        $rows = DB::table('msan_product_specifications as product_specs')
            ->join('msan_specification_definitions as definitions', 'definitions.id', '=', 'product_specs.definition_id')
            ->where('product_specs.snapshot_id', $snapshot->id)
            ->where('product_specs.msan_product_id', $source->id)
            ->where('definitions.import_enabled', true)
            ->where('definitions.is_stale', false)
            ->orderBy('product_specs.item_order')
            ->orderBy('product_specs.id')
            ->get([
                'product_specs.values', 'product_specs.item_order', 'product_specs.checksum',
                'definitions.id as definition_id', 'definitions.source_key', 'definitions.group_name',
                'definitions.item_name', 'definitions.display_group_name', 'definitions.display_item_name',
                'definitions.display_measure', 'definitions.measure', 'definitions.use_as_filter', 'definitions.data_role',
            ]);
        $product = Product::query()->with('energyDeclarations')->find($source->local_product_id);

        return $product
            ? $this->publishProduct($product, $source->loadMissing('categories.mapping'), $rows, $snapshot)
            : ['specifications' => 0, 'energy_declarations' => 0, 'filter_attributes' => 0];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{specifications:int,energy_declarations:int,filter_attributes:int}
     */
    private function publishProduct(
        Product $product,
        MsanProduct $source,
        Collection $rows,
        MsanSpecificationSnapshot $snapshot,
    ): array {
        return DB::transaction(function () use ($product, $source, $rows, $snapshot): array {
            $specificationRows = [];
            foreach ($rows as $row) {
                if ((string) $row->data_role !== MsanSpecificationDefinition::ROLE_SPECIFICATION) {
                    continue;
                }
                $values = $this->decodedValues($row->values);
                if ($values === []) {
                    continue;
                }
                $specificationRows[] = [
                    'product_id' => $product->id,
                    'source' => self::SOURCE,
                    'source_key' => (string) $row->source_key,
                    'group_name' => trim((string) ($row->display_group_name ?: $row->group_name)),
                    'item_name' => trim((string) ($row->display_item_name ?: $row->item_name)),
                    'values' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'measure' => $row->display_measure ?: ($row->measure ?: null),
                    'sort_order' => (int) $row->item_order,
                    'payload' => json_encode(['definition_id' => (int) $row->definition_id], JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('catalog_product_specifications')
                ->where('product_id', $product->id)
                ->where('source', self::SOURCE)
                ->delete();
            if ($specificationRows !== []) {
                DB::table('catalog_product_specifications')->insert($specificationRows);
            }

            $energyCount = $this->publishEnergy($product, $source, $rows, $snapshot);
            $filterCount = $this->publishFilterAttributes($product, $rows);
            $checksum = hash('sha256', $rows->pluck('checksum')->map(fn ($value): string => (string) $value)->sort()->implode('|'));
            $source->forceFill([
                'specifications_checksum' => $checksum,
                'specifications_synced_at' => $snapshot->activated_at ?: now(),
            ])->save();

            return [
                'specifications' => count($specificationRows),
                'energy_declarations' => $energyCount,
                'filter_attributes' => $filterCount,
            ];
        }, 3);
    }

    /** @param Collection<int, object> $rows */
    private function publishEnergy(
        Product $product,
        MsanProduct $source,
        Collection $rows,
        ?MsanSpecificationSnapshot $snapshot,
    ): int {
        $previousEprelIdentity = [
            trim((string) $product->eprel_registration_number),
            trim((string) $product->eprel_product_group),
        ];
        $byRole = $rows->groupBy(fn ($row): string => (string) $row->data_role);
        $classRows = $byRole->get(MsanSpecificationDefinition::ROLE_ENERGY_CLASS, collect());
        $scale = $this->firstValue($byRole->get(MsanSpecificationDefinition::ROLE_ENERGY_SCALE, collect()));
        [$scaleMin, $scaleMax] = $this->parseScale($scale);
        $eprelNumber = $this->digits($this->firstValue($byRole->get(MsanSpecificationDefinition::ROLE_EPREL_NUMBER, collect())));
        $labelUrl = $this->httpsUrl($this->firstValue($byRole->get(MsanSpecificationDefinition::ROLE_ENERGY_LABEL_URL, collect())));
        $sheetUrl = $this->httpsUrl($this->firstValue($byRole->get(MsanSpecificationDefinition::ROLE_PRODUCT_INFORMATION_SHEET_URL, collect())));
        [$eprelGroup, $requirement] = $this->energyCategoryPolicy($source);

        ProductEnergyDeclaration::query()
            ->where('product_id', $product->id)
            ->where('source', ProductEnergyDeclaration::SOURCE_MSAN)
            ->delete();

        $protectedPrimaryExists = $product->energyDeclarations
            ->contains(fn (ProductEnergyDeclaration $declaration): bool => $declaration->source !== ProductEnergyDeclaration::SOURCE_MSAN
                && $declaration->is_primary);
        if ($requirement === 'not_applicable') {
            if (! $protectedPrimaryExists) {
                $product->forceFill([
                    'energy_label_required' => false,
                    'energy_efficiency_class' => null,
                    'energy_efficiency_scale' => null,
                    'eprel_registration_number' => null,
                    'eprel_product_group' => null,
                    'eprel_energy_label_image' => null,
                    'energy_label_url' => null,
                    'product_information_sheet_url' => null,
                    'energy_data_synced_at' => null,
                ])->save();
                $this->resetEprelStateIfIdentityChanged($source, $previousEprelIdentity, $product);
            }

            return 0;
        }

        $created = [];
        foreach ($classRows->values() as $row) {
            $class = $this->energyClass($this->firstValue(collect([$row])));
            if ($class === null) {
                continue;
            }
            [$rowScaleMin, $rowScaleMax] = $this->parseScale($this->firstValue(collect([$row])));
            $created[] = ProductEnergyDeclaration::query()->create([
                'product_id' => $product->id,
                'context_code' => 'msan-'.substr((string) $row->source_key, 0, 24),
                'label' => trim((string) ($row->display_item_name ?: $row->item_name)),
                'energy_class' => $class,
                'scale_min' => $rowScaleMin ?: $scaleMin,
                'scale_max' => $rowScaleMax ?: $scaleMax,
                'eprel_registration_number' => $eprelNumber,
                'eprel_product_group' => $eprelGroup,
                'energy_label_url' => $labelUrl,
                'product_information_sheet_url' => $sheetUrl,
                'is_primary' => ! $protectedPrimaryExists && $created === [],
                'source' => ProductEnergyDeclaration::SOURCE_MSAN,
                'payload' => ['definition_id' => (int) $row->definition_id],
                'synced_at' => $snapshot?->activated_at ?: now(),
            ]);
        }

        if ($created === [] && ($eprelNumber || $labelUrl || $sheetUrl)) {
            $created[] = ProductEnergyDeclaration::query()->create([
                'product_id' => $product->id,
                'context_code' => 'msan-primary',
                'label' => 'Energetska oznaka',
                'scale_min' => $scaleMin,
                'scale_max' => $scaleMax,
                'eprel_registration_number' => $eprelNumber,
                'eprel_product_group' => $eprelGroup,
                'energy_label_url' => $labelUrl,
                'product_information_sheet_url' => $sheetUrl,
                'is_primary' => ! $protectedPrimaryExists,
                'source' => ProductEnergyDeclaration::SOURCE_MSAN,
                'synced_at' => $snapshot?->activated_at ?: now(),
            ]);
        }

        $required = match ($requirement) {
            'required' => true,
            default => $created !== [],
        };
        if (! $protectedPrimaryExists) {
            /** @var ProductEnergyDeclaration|null $primary */
            $primary = collect($created)->first(fn (ProductEnergyDeclaration $declaration): bool => $declaration->is_primary)
                ?? collect($created)->first();
            $product->forceFill([
                'energy_label_required' => $required,
                'energy_efficiency_class' => $primary?->energy_class,
                'energy_efficiency_scale' => $primary && ($primary->scale_min || $primary->scale_max)
                    ? trim((string) $primary->scale_min.'-'.(string) $primary->scale_max, '-')
                    : null,
                'eprel_registration_number' => $primary?->eprel_registration_number,
                'eprel_product_group' => $primary?->eprel_product_group,
                'energy_label_url' => $primary?->energy_label_url,
                'product_information_sheet_url' => $primary?->product_information_sheet_url,
                'energy_data_synced_at' => $created !== [] ? ($snapshot?->activated_at ?: now()) : null,
            ])->save();
            $this->resetEprelStateIfIdentityChanged($source, $previousEprelIdentity, $product);
        }

        return count($created);
    }

    /** @param array{0:string,1:string} $previousIdentity */
    private function resetEprelStateIfIdentityChanged(
        MsanProduct $source,
        array $previousIdentity,
        Product $product,
    ): void {
        $currentIdentity = [
            trim((string) $product->eprel_registration_number),
            trim((string) $product->eprel_product_group),
        ];
        if ($previousIdentity === $currentIdentity) {
            return;
        }

        MsanProduct::query()->whereKey($source->id)->update([
            'eprel_match_status' => MsanProduct::EPREL_PENDING,
            'eprel_identifier_checksum' => null,
            'eprel_checked_at' => null,
        ]);
    }

    /** @param Collection<int, object> $rows */
    private function publishFilterAttributes(Product $product, Collection $rows): int
    {
        $desired = [];
        $created = 0;
        foreach ($rows->where('use_as_filter', true) as $row) {
            foreach ($this->decodedValues($row->values) as $value) {
                if (mb_strlen($value) > 191) {
                    continue;
                }
                $attribute = $this->filterAttribute($row, $value);
                if (! $attribute) {
                    continue;
                }
                $desired[(int) $attribute->id] = ['sort_order' => (int) $row->item_order];
                $created++;
            }
        }

        $managedIds = Attribute::query()
            ->where('payload->source', 'msan_specification')
            ->whereHas('products', fn ($query) => $query->whereKey($product->id))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $detach = array_values(array_diff($managedIds, array_keys($desired)));
        if ($detach !== []) {
            $product->attributes()->detach($detach);
        }
        if ($desired !== []) {
            $product->attributes()->syncWithoutDetaching($desired);
        }

        return $created;
    }

    private function filterAttribute(object $row, string $value): ?Attribute
    {
        $sourceKey = (string) $row->source_key;
        $code = 'msan-spec-'.substr($sourceKey, 0, 16).'-'.substr(hash('sha256', $value), 0, 16);
        $groupCode = 'msan-'.substr($sourceKey, 0, 24);
        if (array_key_exists($code, $this->filterAttributeCache)) {
            return $this->filterAttributeCache[$code];
        }

        $attribute = Attribute::query()->where('code', $code)->first();
        if (! $attribute) {
            $existingCount = $this->filterValueCountCache[$sourceKey] ??= Attribute::query()
                ->where('payload->source', 'msan_specification')
                ->where('payload->source_key', $sourceKey)
                ->count();
            if ($existingCount >= self::MAX_FILTER_VALUES_PER_DEFINITION) {
                $this->filterAttributeCache[$code] = null;

                return null;
            }
            $attribute = Attribute::query()->create([
                'code' => $code,
                'group_code' => $groupCode,
                'type' => Attribute::TYPE_SELECT,
                'is_active' => true,
                'sort_order' => (int) $row->item_order,
                'payload' => [
                    'source' => Attribute::SOURCE_MSAN_SPECIFICATION,
                    'source_key' => $sourceKey,
                ],
            ]);
            $this->filterValueCountCache[$sourceKey] = $existingCount + 1;
        }

        $payload = is_array($attribute->payload) ? $attribute->payload : [];
        $payload['source'] = Attribute::SOURCE_MSAN_SPECIFICATION;
        $payload['source_key'] = $sourceKey;
        $attribute->fill([
            'group_code' => $groupCode,
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => (int) $row->item_order,
            'payload' => $payload,
        ]);
        if ($attribute->isDirty()) {
            $attribute->save();
        }

        if (! isset($this->preparedFilterTranslations[$code])) {
            $groupName = trim((string) ($row->display_item_name ?: $row->item_name));
            $measure = trim((string) ($row->display_measure ?: $row->measure));
            $name = $value.($measure !== '' ? ' '.$measure : '');
            AttributeTranslation::query()->updateOrCreate(
                ['attribute_id' => $attribute->id, 'locale' => 'hr'],
                [
                    'group_name' => $groupName,
                    'name' => $name,
                    'slug' => 'msan-'.substr($sourceKey, 0, 12).'-'.substr(hash('sha256', $value), 0, 16),
                    'payload' => ['source' => 'msan_specification'],
                ],
            );
            $this->preparedFilterTranslations[$code] = true;
        }

        $this->filterAttributeCache[$code] = $attribute;

        return $attribute;
    }

    /** @return list<string> */
    private function decodedValues(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return collect(is_array($values) ? $values : [])
            ->map(static fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param Collection<int, object> $rows */
    private function firstValue(Collection $rows): ?string
    {
        foreach ($rows as $row) {
            $value = $this->decodedValues($row->values)[0] ?? null;
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @return array{0:?string,1:?string} */
    private function parseScale(?string $value): array
    {
        if (! $value || preg_match('/(?<![A-Z0-9])((?:A\+{0,3})|[B-G])\s*[-–—]\s*((?:A\+{0,3})|[B-G])(?![A-Z0-9+])/i', $value, $matches) !== 1) {
            return [null, null];
        }

        return [strtoupper($matches[1]), strtoupper($matches[2])];
    }

    private function energyClass(?string $value): ?string
    {
        return $value && preg_match('/(?<![A-Z0-9])((?:A\+{0,3})|[B-G])(?![A-Z0-9+])/i', $value, $matches) === 1
            ? strtoupper($matches[1])
            : null;
    }

    private function digits(?string $value): ?string
    {
        return $value && preg_match('/\b\d{3,20}\b/', $value, $matches) === 1 ? $matches[0] : null;
    }

    private function httpsUrl(?string $value): ?string
    {
        if (! $value || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);

        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! isset($parts['user'], $parts['pass'])
            ? $value
            : null;
    }

    private function eprelGroup(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-z0-9-]{2,100}$/', $value) === 1 ? $value : null;
    }

    /** @return array{0:?string,1:string} */
    private function energyCategoryPolicy(MsanProduct $source): array
    {
        $mappings = $source->categories
            ->pluck('mapping')
            ->filter();
        $groups = $mappings
            ->map(fn ($mapping): ?string => $this->eprelGroup($mapping->eprel_product_group ?? null))
            ->filter()
            ->unique()
            ->values();
        $requirements = $mappings
            ->map(static fn ($mapping): string => (string) ($mapping->energy_requirement
                ?? MsanCategoryMapping::ENERGY_REQUIREMENT_INHERIT))
            ->filter(static fn (string $requirement): bool => $requirement !== MsanCategoryMapping::ENERGY_REQUIREMENT_INHERIT)
            ->unique()
            ->values();

        // A product can belong to several supplier categories. Never let
        // relation order select an arbitrary EPREL group. Conflicting explicit
        // requirements are treated as required (the compliance-safe outcome),
        // while the conflicting group is withheld for administrator review.
        $requirement = $requirements->contains(MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED)
            ? MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED
            : ($requirements->contains(MsanCategoryMapping::ENERGY_REQUIREMENT_NOT_APPLICABLE)
                ? MsanCategoryMapping::ENERGY_REQUIREMENT_NOT_APPLICABLE
                : MsanCategoryMapping::ENERGY_REQUIREMENT_INHERIT);

        return [
            $groups->count() === 1 ? (string) $groups->first() : null,
            $requirement,
        ];
    }
}
