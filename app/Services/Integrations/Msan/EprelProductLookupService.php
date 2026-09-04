<?php

namespace App\Services\Integrations\Msan;

use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanProduct;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EprelProductLookupService
{
    public const SEARCH_AUTO = 'AUTO';

    private const MAX_REGISTRATION_LOOKUPS = 4;

    private const MAX_GTIN_LOOKUPS = 8;

    private const MAX_MODEL_GROUP_LOOKUPS = 12;

    public const STATUS_MATCHED = 'matched';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_NEEDS_GROUP = 'needs_group';

    public const STATUS_NEEDS_BRAND = 'needs_brand';

    public const STATUS_NO_IDENTIFIERS = 'no_identifiers';

    public function __construct(
        private readonly EprelClient $client,
        private readonly EprelDeclarationWriter $declarations,
        private readonly MsanSettingsService $settings,
    ) {}

    /**
     * @param  array{code?:mixed,sku?:mixed,barcode?:mixed,model?:mixed,brand?:mixed,eprel_product_group?:mixed,search_by?:mixed,search_query?:mixed}  $overrides
     * @return array{
     *   registrationNumbers:list<string>,
     *   gtins:list<string>,
     *   models:list<string>,
     *   brands:list<string>,
     *   groups:list<string>
     * }
     */
    public function criteria(Product $product, array $overrides = []): array
    {
        $product->loadMissing([
            'energyDeclarations',
            'manufacturer.translations',
            'categories',
            'packages',
        ]);
        $sources = $this->sources($product);

        $registrationNumbers = $this->uniqueStrings([
            $product->eprel_registration_number,
            ...$product->energyDeclarations->pluck('eprel_registration_number')->all(),
            ...$this->payloadValues($product->payload, [
                'eprel_registration_number',
                'eprelRegistrationNumber',
                'energy.eprel_registration_number',
            ]),
        ]);

        $sourceBarcodes = $sources
            ->flatMap(function (MsanProduct $source): array {
                return collect($source->barcodes ?? [])
                    ->map(static fn ($entry) => is_array($entry) ? ($entry['value'] ?? null) : $entry)
                    ->all();
            })
            ->all();
        $gtins = $this->uniqueStrings([
            $overrides['barcode'] ?? null,
            $product->barcode,
            ...$product->packages->pluck('barcode')->all(),
            ...$sourceBarcodes,
            ...$this->payloadValues($product->payload, [
                'gtin', 'ean', 'ean13', 'upc', 'barcode',
                'identifiers.gtin', 'identifiers.ean',
            ]),
        ]);

        $requestedModel = trim((string) ($overrides['model'] ?? ''));
        $models = $this->uniqueStrings($requestedModel !== ''
            ? [$requestedModel]
            : [
                ...$sources->pluck('model')->all(),
                ...$sources->pluck('part_number')->all(),
                ...$this->payloadValues($product->payload, [
                    'model', 'model_identifier', 'modelIdentifier', 'mpn', 'part_number',
                    'manufacturer_part_number', 'identifiers.model', 'identifiers.mpn',
                ]),
                $overrides['sku'] ?? null,
                $product->sku,
                $overrides['code'] ?? null,
                $product->code,
            ]);
        $models = array_values(array_filter($models, EprelClient::isValidModelIdentifier(...)));

        $requestedBrand = trim((string) ($overrides['brand'] ?? ''));
        $brands = $this->uniqueStrings($requestedBrand !== ''
            ? [$requestedBrand]
            : [
                ...$sources->pluck('brand')->all(),
                ...($product->manufacturer?->translations?->pluck('name')->all() ?? []),
                ...$this->payloadValues($product->payload, [
                    'brand', 'manufacturer', 'supplierOrTrademark', 'supplier.brand',
                ]),
            ]);
        $brands = array_values(array_filter($brands, EprelClient::isValidBrandCandidate(...)));

        $categoryIds = $product->categories->modelKeys();
        $localMappings = $categoryIds === []
            ? collect()
            : MsanCategoryMapping::query()
                ->whereIn('local_category_id', $categoryIds)
                ->where('energy_requirement', '!=', MsanCategoryMapping::ENERGY_REQUIREMENT_NOT_APPLICABLE)
                ->get(['eprel_product_group']);
        $mappedGroups = $sources
            ->flatMap(fn (MsanProduct $source) => $source->categories
                ->pluck('mapping')
                ->filter()
                ->reject(fn (MsanCategoryMapping $mapping): bool => $mapping->energy_requirement === MsanCategoryMapping::ENERGY_REQUIREMENT_NOT_APPLICABLE)
                ->pluck('eprel_product_group'))
            ->all();
        $hasRequestedGroup = array_key_exists('eprel_product_group', $overrides);
        $requestedGroup = trim((string) ($hasRequestedGroup
            ? $overrides['eprel_product_group']
            : $product->eprel_lookup_product_group));
        if ($requestedGroup !== '' && ! array_key_exists(strtolower($requestedGroup), EprelClient::productGroupOptions())) {
            throw new InvalidArgumentException('Odabrana EPREL grupa proizvoda nije podržana.');
        }
        $groups = $requestedGroup !== ''
            ? [strtolower($requestedGroup)]
            : $this->uniqueStrings([
                $product->eprel_product_group,
                ...$product->energyDeclarations->pluck('eprel_product_group')->all(),
                ...$mappedGroups,
                ...$localMappings->pluck('eprel_product_group')->all(),
            ], lowercase: true);
        $groups = array_values(array_filter(
            $groups,
            static fn (string $group): bool => array_key_exists($group, EprelClient::productGroupOptions()),
        ));

        $registrationNumbers = array_values(array_filter(
            $registrationNumbers,
            EprelClient::isValidRegistrationNumber(...),
        ));
        $gtins = array_values(array_filter($gtins, EprelClient::isValidGtinIdentifier(...)));

        return compact('registrationNumbers', 'gtins', 'models', 'brands', 'groups');
    }

    /**
     * @param  array{code?:mixed,sku?:mixed,barcode?:mixed,model?:mixed,brand?:mixed,eprel_product_group?:mixed,search_by?:mixed,search_query?:mixed}  $overrides
     * @return array{status:string,matched_by:?string,data:?array<string,mixed>,criteria:array<string,list<string>>}
     */
    public function lookup(Product $product, array $overrides = []): array
    {
        if (! $this->settings->eprelEnabled()) {
            throw new EprelException('EPREL dohvat nije uključen u postavkama integracije.');
        }
        // Validate the encrypted key before starting a sequence of requests.
        $this->settings->eprelApiKey();

        $criteria = $this->criteria($product, $overrides);
        $searchBy = strtoupper(trim((string) ($overrides['search_by'] ?? self::SEARCH_AUTO)));
        if ($searchBy !== self::SEARCH_AUTO) {
            if (! array_key_exists($searchBy, EprelClient::searchByOptions())) {
                throw new InvalidArgumentException('EPREL vrsta pretrage nije podržana.');
            }
            $searchQuery = trim((string) ($overrides['search_query'] ?? ''));
            if ($searchQuery === '') {
                throw new InvalidArgumentException('Unesite pojam za ručnu EPREL pretragu.');
            }
            if ($searchBy === EprelClient::SEARCH_REGISTRATION_NUMBER) {
                if (! EprelClient::isValidRegistrationNumber($searchQuery)) {
                    throw new InvalidArgumentException('EPREL registracijski broj nije ispravan.');
                }

                $registration = trim($searchQuery);
                $result = $this->client->findByRegistrationNumber($registration);
                if ($result === null
                    || ! isset($result['eprel_registration_number'])
                    || ! hash_equals($registration, (string) $result['eprel_registration_number'])) {
                    return $this->outcome(self::STATUS_NOT_FOUND, null, null, $criteria);
                }

                $this->storeMatch($product, $result, $criteria, $overrides);

                return $this->outcome(
                    self::STATUS_MATCHED,
                    strtolower($searchBy),
                    $result,
                    $criteria,
                );
            }
            $requestedGroup = strtolower(trim((string) ($overrides['eprel_product_group'] ?? '')));
            if ($requestedGroup === '') {
                return $this->outcome(self::STATUS_NEEDS_GROUP, null, null, $criteria);
            }

            $result = $this->client->findBySearchCriterion(
                $requestedGroup,
                $searchBy,
                $searchQuery,
                $searchBy === EprelClient::SEARCH_MODEL_IDENTIFIER ? $criteria['brands'] : [],
            );
            if ($result === null) {
                return $this->outcome(self::STATUS_NOT_FOUND, null, null, $criteria);
            }

            $this->storeMatch($product, $result, $criteria, $overrides);

            return $this->outcome(
                self::STATUS_MATCHED,
                strtolower($searchBy),
                $result,
                $criteria,
            );
        }

        if ($criteria['registrationNumbers'] === []
            && $criteria['gtins'] === []
            && $criteria['models'] === []) {
            return $this->outcome(self::STATUS_NO_IDENTIFIERS, null, null, $criteria);
        }
        if (count($criteria['registrationNumbers']) > self::MAX_REGISTRATION_LOOKUPS
            || count($criteria['gtins']) > self::MAX_GTIN_LOOKUPS) {
            throw new EprelMatchConflictException('Pronađeno je previše EPREL brojeva ili GTIN barkodova. Suzite identifikatore artikla prije automatskog dohvata.');
        }

        $strongMatches = [];
        $matchedBy = [];
        $registrationLookups = 0;
        foreach ($criteria['registrationNumbers'] as $registrationNumber) {
            if ($registrationLookups >= self::MAX_REGISTRATION_LOOKUPS) {
                break;
            }
            try {
                $result = $this->client->findByRegistrationNumber($registrationNumber);
            } catch (InvalidArgumentException) {
                continue;
            }
            $registrationLookups++;
            if ($result !== null) {
                $strongMatches[$result['eprel_registration_number']] = $result;
                $matchedBy[$result['eprel_registration_number']][] = 'eprel_registration_number';
            }
        }
        $gtinLookups = 0;
        foreach ($criteria['gtins'] as $gtin) {
            if ($gtinLookups >= self::MAX_GTIN_LOOKUPS) {
                break;
            }
            try {
                $result = $this->client->findByGtinIdentifier($gtin);
            } catch (InvalidArgumentException) {
                continue;
            }
            $gtinLookups++;
            if ($result !== null) {
                $strongMatches[$result['eprel_registration_number']] = $result;
                $matchedBy[$result['eprel_registration_number']][] = 'gtin';
            }
        }
        if (count($strongMatches) > 1) {
            throw new EprelMatchConflictException('EPREL broj i barkod upućuju na različite modele. Podaci nisu automatski spremljeni.');
        }
        if ($strongMatches !== []) {
            $registration = (string) array_key_first($strongMatches);
            $result = $strongMatches[$registration];
            $this->storeMatch($product, $result, $criteria, $overrides);

            return $this->outcome(
                self::STATUS_MATCHED,
                implode('+', array_unique($matchedBy[$registration] ?? [])),
                $result,
                $criteria,
            );
        }

        if ($criteria['groups'] === []) {
            return $this->outcome(self::STATUS_NEEDS_GROUP, null, null, $criteria);
        }
        if ($criteria['brands'] === []) {
            return $this->outcome(self::STATUS_NEEDS_BRAND, null, null, $criteria);
        }

        $modelMatches = [];
        $modelGroupLookups = 0;
        foreach ($criteria['models'] as $model) {
            foreach ($criteria['groups'] as $group) {
                foreach ($criteria['brands'] as $brand) {
                    if ($modelGroupLookups >= self::MAX_MODEL_GROUP_LOOKUPS) {
                        throw new EprelMatchConflictException('Pronađeno je previše kombinacija modela, marke i grupe. Unesite točan model, marku ili grupu proizvoda.');
                    }

                    try {
                        $result = $this->client->findByModelIdentifier($group, $model, [$brand]);
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                    $modelGroupLookups++;
                    if ($result !== null) {
                        $modelMatches[$result['eprel_registration_number']] = $result;
                    }
                    if (count($modelMatches) > 1) {
                        throw new EprelMatchConflictException('Različiti modeli ili marke upućuju na više službenih EPREL zapisa. Podaci nisu spremljeni.');
                    }
                }
            }
        }

        if ($modelMatches !== []) {
            $result = reset($modelMatches);
            $this->storeMatch($product, $result, $criteria, $overrides);

            return $this->outcome(self::STATUS_MATCHED, 'model_identifier', $result, $criteria);
        }

        return $this->outcome(self::STATUS_NOT_FOUND, null, null, $criteria);
    }

    /** @return Collection<int, MsanProduct> */
    private function sources(Product $product): Collection
    {
        return MsanProduct::query()
            ->where('local_product_id', $product->getKey())
            ->where('is_stale', false)
            ->with('categories.mapping')
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $criteria
     * @param  array<string, mixed>  $overrides
     */
    private function storeMatch(Product $product, array $data, array $criteria, array $overrides): void
    {
        $freshProduct = Product::query()->find($product->getKey());
        if (! $freshProduct) {
            throw new EprelMatchConflictException('Artikl više ne postoji pa EPREL zapis nije spremljen.');
        }
        if ($this->criteria($freshProduct, $overrides) !== $criteria) {
            throw new EprelMatchConflictException('Identifikacijski podaci artikla promijenjeni su tijekom EPREL dohvata. Pokrenite pretragu ponovno.');
        }
        if (! $this->lookupPreferenceMatches($freshProduct, $product, $overrides)) {
            throw new EprelMatchConflictException('Odabrana EPREL grupa promijenjena je tijekom dohvata. Pokrenite pretragu ponovno.');
        }

        $this->declarations->store(
            (int) $freshProduct->getKey(),
            $data,
            EprelDeclarationWriter::ORIGIN_ADMIN_LOOKUP,
            [
                'code' => $freshProduct->code,
                'sku' => $freshProduct->sku,
                'barcode' => $freshProduct->barcode,
                'manufacturer_id' => $freshProduct->manufacturer_id,
            ],
            fn (Product $lockedProduct): bool => $this->criteria($lockedProduct, $overrides) === $criteria
                && $this->lookupPreferenceMatches($lockedProduct, $product, $overrides),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function lookupPreferenceMatches(Product $candidate, Product $original, array $overrides): bool
    {
        $expected = array_key_exists('eprel_product_group', $overrides)
            ? strtolower(trim((string) $overrides['eprel_product_group']))
            : strtolower(trim((string) $original->eprel_lookup_product_group));
        $actual = strtolower(trim((string) $candidate->eprel_lookup_product_group));

        return hash_equals($expected, $actual);
    }

    /** @return list<mixed> */
    private function payloadValues(mixed $payload, array $paths): array
    {
        if (! is_array($payload)) {
            return [];
        }

        return array_map(static fn (string $path) => data_get($payload, $path), $paths);
    }

    /** @return list<string> */
    private function uniqueStrings(array $values, bool $lowercase = false): array
    {
        $unique = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $normalized = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
            if ($normalized === '' || mb_strlen($normalized) > 191) {
                continue;
            }
            if ($lowercase) {
                $normalized = mb_strtolower($normalized);
            }
            $key = mb_strtolower($normalized);
            $unique[$key] ??= $normalized;
        }

        return array_values($unique);
    }

    /**
     * @param  array<string,list<string>>  $criteria
     * @param  array<string,mixed>|null  $data
     * @return array{status:string,matched_by:?string,data:?array<string,mixed>,criteria:array<string,list<string>>}
     */
    private function outcome(string $status, ?string $matchedBy, ?array $data, array $criteria): array
    {
        return [
            'status' => $status,
            'matched_by' => $matchedBy,
            'data' => $data,
            'criteria' => $criteria,
        ];
    }
}
