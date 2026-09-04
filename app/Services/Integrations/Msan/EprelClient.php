<?php

namespace App\Services\Integrations\Msan;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class EprelClient
{
    public const BASE_URL = 'https://eprel.ec.europa.eu';

    public const SEARCH_MODEL_IDENTIFIER = 'MODEL_IDENTIFIER';

    public const SEARCH_BRAND_OR_TRADEMARK = 'BRAND_OR_TRADEMARK';

    public const SEARCH_COMMERCIAL_NAME = 'COMMERCIAL_NAME';

    public const SEARCH_GTIN_IDENTIFIER = 'GTIN_IDENTIFIER';

    public const SEARCH_REGISTRATION_NUMBER = 'REGISTRATION_NUMBER';

    /** @var array<string, string> Official EPREL genericField value => query parameter. */
    private const SEARCH_FIELDS = [
        self::SEARCH_MODEL_IDENTIFIER => 'modelIdentifier',
        self::SEARCH_BRAND_OR_TRADEMARK => 'supplierOrTrademark',
        self::SEARCH_COMMERCIAL_NAME => 'commercialName',
        self::SEARCH_GTIN_IDENTIFIER => 'gtinIdentifier',
    ];

    /**
     * EPREL uses stable URL slugs in its public product API while responses use
     * enum-style product-group codes. Keeping the map local also prevents an
     * administrator value from becoming an arbitrary request path.
     *
     * @var array<string, string>
     */
    private const PRODUCT_GROUPS = [
        'airconditioners' => 'AIR_CONDITIONER',
        'lamps' => 'LAMP',
        'televisions' => 'TELEVISION',
        'dishwashers' => 'HOUSEHOLD_DISHWASHER',
        'washingmachines' => 'HOUSEHOLD_WASHING_MACHINE',
        'localspaceheaters' => 'LOCAL_SPACE_HEATER',
        'ovens' => 'DOMESTIC_OVEN',
        'rangehoods' => 'RANGE_HOOD',
        'residentialventilationunits' => 'RESIDENTIAL_VENTILATION_UNIT',
        'solidfuelboilerpackages' => 'SOLID_FUEL_BOILER_PACKAGE',
        'solidfuelboilers' => 'SOLID_FUEL_BOILER',
        'professionalrefrigeratedstoragecabinets' => 'PROFESSIONAL_REFRIGERATOR',
        'waterheaters' => 'WATER_HEATERS',
        'washerdriers' => 'HOUSEHOLD_COMBINED_WASHER_DRIER',
        'refrigeratingappliances' => 'HOUSEHOLD_REFRIGERATING_APPLIANCE',
        'spaceheaters' => 'SPACE_HEATER',
        'spaceheatersolardevice' => 'SPACE_HEATER_SOLAR_DEVICE',
        'tumbledriers' => 'HOUSEHOLD_TUMBLE_DRIER',
        'spaceheatertemperaturecontrol' => 'SPACE_HEATER_TEMPERATURE_CONTROL',
        'spaceheaterpackages' => 'SPACE_HEATER_PACKAGE',
        'waterheaterpackages' => 'WATER_HEATER_PACKAGE',
        'hotwaterstoragetanks' => 'HOT_WATER_STORAGE_TANK',
        'waterheatersolardevices' => 'WATER_HEATER_SOLAR_DEVICE',
        'refrigeratingappliancesdirectsalesfunction' => 'DIRECT_SALES_REFRIGERATION_APPLIANCE',
        'washerdriers2019' => 'HOUSEHOLD_WASHER_DRYER_2019',
        'refrigeratingappliances2019' => 'HOUSEHOLD_REFRIGERATING_APPLIANCE_2019',
        'washingmachines2019' => 'HOUSEHOLD_WASHING_MACHINE_2019',
        'electronicdisplays' => 'ELECTRONIC_DISPLAY',
        'dishwashers2019' => 'HOUSEHOLD_DISHWASHER_2019',
        'lightsources' => 'LIGHT_SOURCE',
        'tyres' => 'TYRES',
        'smartphonestablets20231669' => 'SMARTPHONE_TABLET',
        'tumbledryers20232534' => 'HOUSEHOLD_TUMBLE_DRIER_EU_2023_2534',
    ];

    public function __construct(
        private readonly MsanSettingsService $settings,
    ) {}

    /** @return array<string, string> URL slug => EPREL API product-group code. */
    public static function productGroupOptions(): array
    {
        return self::PRODUCT_GROUPS;
    }

    /** @return array<string, string> Admin search mode => Croatian label. */
    public static function searchByOptions(): array
    {
        return [
            self::SEARCH_REGISTRATION_NUMBER => 'EPREL registracijski broj',
            self::SEARCH_MODEL_IDENTIFIER => 'Identifikator modela',
            self::SEARCH_BRAND_OR_TRADEMARK => 'Marka ili zaštitni znak',
            self::SEARCH_COMMERCIAL_NAME => 'Komercijalni naziv modela',
            self::SEARCH_GTIN_IDENTIFIER => 'GTIN identifikator',
        ];
    }

    public static function isValidRegistrationNumber(string $value): bool
    {
        return preg_match('/^[0-9]{1,20}$/D', trim($value)) === 1;
    }

    public static function isValidModelIdentifier(string $value): bool
    {
        $model = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $model !== ''
            && mb_strlen($model) <= 191
            && preg_match('/^[\pL\pN][\pL\pN ._+\-\/()#]{0,190}$/uD', $model) === 1;
    }

    public static function isValidGtinIdentifier(string $value): bool
    {
        $gtin = trim($value);
        if (! preg_match('/^(?:[0-9]{8}|[0-9]{12}|[0-9]{13}|[0-9]{14})$/D', $gtin)) {
            return false;
        }

        $sum = 0;
        $weight = 3;
        for ($index = strlen($gtin) - 2; $index >= 0; $index--) {
            $sum += ((int) $gtin[$index]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10 === (int) $gtin[strlen($gtin) - 1];
    }

    public static function isValidBrandCandidate(string $value): bool
    {
        $brand = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $brand !== '' && mb_strlen($brand) <= 191 && preg_match('/\pC/u', $brand) !== 1;
    }

    /**
     * @return array{
     *   eprel_registration_number:string,
     *   eprel_product_group:string,
     *   model_identifier:?string,
     *   energy_class:?string,
     *   scale_min:?string,
     *   scale_max:?string,
     *   energy_label_image:?string,
     *   energy_label_url:string,
     *   product_information_sheet_url:?string
     * }|null
     */
    public function findByRegistrationNumber(
        string $productGroup,
        ?string $registrationNumber = null,
    ): ?array {
        if ($registrationNumber === null) {
            $registration = $this->validatedRegistrationNumber($productGroup);
            $response = $this->get('/api/product/'.$registration);

            if ($response === null) {
                return null;
            }

            $record = $this->singleRecord($response);
            $group = $record ? $this->groupFromRecord($record) : null;
            if (! $record || $group === null || ! $this->registrationMatches($record, $registration)) {
                return null;
            }

            return $this->normalizeRecord($record, $group, $registration);
        }

        $group = $this->validatedProductGroup($productGroup);
        $registration = $this->validatedRegistrationNumber($registrationNumber);
        $response = $this->get('/api/products/'.$group.'/'.$registration);

        if ($response === null) {
            return null;
        }

        $record = $this->singleRecord($response);
        if (! $record || ! $this->registrationMatches($record, $registration)
            || ! $this->groupMatches($record, $group)) {
            return null;
        }

        return $this->normalizeRecord($record, $group, $registration);
    }

    /**
     * Resolves an exact GTIN without trusting a caller-provided product group.
     * The public resolver may repeat the same registration, but it must not
     * resolve one GTIN to more than one unique EPREL product.
     *
     * @return array{
     *   eprel_registration_number:string,
     *   eprel_product_group:string,
     *   model_identifier:?string,
     *   energy_class:?string,
     *   scale_min:?string,
     *   scale_max:?string,
     *   energy_label_image:?string,
     *   energy_label_url:string,
     *   product_information_sheet_url:?string
     * }|null
     */
    public function findByGtinIdentifier(string $gtinIdentifier): ?array
    {
        $gtin = $this->validatedGtinIdentifier($gtinIdentifier);
        $response = $this->get('/api/product/gtin/'.$gtin);

        if ($response === null) {
            return null;
        }

        $records = $this->records($response);
        if ($this->totalRecords($response) > count($records)) {
            throw new EprelMatchConflictException('EPREL GTIN pretraga vratila je više rezultata nego što se može sigurno provjeriti jednim dohvatom.');
        }

        $matches = collect($records)
            ->filter(fn (array $record): bool => $this->gtinMatches($record, $gtin))
            ->map(function (array $record): ?array {
                $registration = $this->registrationFromRecord($record);
                $group = $this->groupFromRecord($record);

                return $registration !== null && $group !== null
                    ? compact('record', 'registration', 'group')
                    : null;
            })
            ->filter()
            ->unique(fn (array $match): string => $match['group'].'|'.$match['registration'])
            ->values();

        if ($matches->isEmpty()) {
            return null;
        }
        if ($matches->count() !== 1) {
            throw new EprelMatchConflictException('EPREL je vratio više artikala s istim GTIN identifikatorom.');
        }

        $match = $matches->first();

        return $this->normalizeRecord($match['record'], $match['group'], $match['registration']);
    }

    /**
     * Search results are never trusted as an approximate match. An exact model
     * identifier must resolve to exactly one EPREL registration before its full
     * detail record is fetched.
     *
     * @return array{
     *   eprel_registration_number:string,
     *   eprel_product_group:string,
     *   model_identifier:?string,
     *   energy_class:?string,
     *   scale_min:?string,
     *   scale_max:?string,
     *   energy_label_image:?string,
     *   energy_label_url:string,
     *   product_information_sheet_url:?string
     * }|null
     */
    public function findByModelIdentifier(
        string $productGroup,
        string $modelIdentifier,
        array $brandCandidates = [],
    ): ?array {
        $group = $this->validatedProductGroup($productGroup);
        $model = $this->validatedModelIdentifier($modelIdentifier);
        $brands = $this->normalizedBrandCandidates($brandCandidates);
        if ($brandCandidates !== [] && $brands === []) {
            throw new InvalidArgumentException('EPREL marka nije ispravna.');
        }
        $query = [
            // EPREL's public search is one-based. `_page=0` currently returns
            // HTTP 500 even for otherwise valid searches.
            '_page' => 1,
            '_limit' => 100,
            'genericField' => self::SEARCH_MODEL_IDENTIFIER,
            'modelIdentifier' => $model,
            'applianceType' => 'ANY',
        ];
        if (count($brands) === 1) {
            $query['supplierOrTrademark'] = $brands[0];
        }
        $response = $this->searchProducts($group, $query);

        if ($response === null) {
            return null;
        }

        $records = $this->records($response);
        if ($this->totalRecords($response) > count($records)) {
            throw new EprelMatchConflictException('EPREL je vratio više rezultata nego što se može sigurno provjeriti jednim dohvatom. Unesite uži model i marku.');
        }

        $matches = collect($records)
            ->filter(fn (array $record): bool => $this->modelMatches($record, $model)
                && $this->groupMatches($record, $group)
                && ($brands === [] || $this->brandMatches($record, $brands)))
            ->unique(fn (array $record): string => (string) $this->registrationFromRecord($record))
            ->values();

        if ($matches->isEmpty()) {
            return null;
        }
        if ($matches->count() !== 1) {
            throw new EprelMatchConflictException('EPREL je vratio više artikala s istim identifikatorom modela.');
        }

        $registration = $this->registrationFromRecord($matches->first());
        if ($registration === null) {
            return null;
        }

        $detail = $this->get('/api/products/'.$group.'/'.$registration);
        if ($detail === null) {
            return null;
        }
        $record = $this->singleRecord($detail);
        if (! $record || ! $this->registrationMatches($record, $registration)
            || ! $this->groupMatches($record, $group)
            || ! $this->modelMatches($record, $model)
            || ($brands !== [] && ! $this->brandMatches($record, $brands))) {
            return null;
        }

        return $this->normalizeRecord($record, $group, $registration);
    }

    /**
     * Runs one of the four official EPREL "Search by" modes. Search-list
     * records are treated only as candidates: the selected field, group and
     * unique registration are revalidated before detail data is normalized.
     *
     * @param  list<mixed>  $brandCandidates
     * @return array<string, mixed>|null
     */
    public function findBySearchCriterion(
        string $productGroup,
        string $searchBy,
        string $searchQuery,
        array $brandCandidates = [],
    ): ?array {
        $group = $this->validatedProductGroup($productGroup);
        $criterion = strtoupper(trim($searchBy));
        if (! isset(self::SEARCH_FIELDS[$criterion])) {
            throw new InvalidArgumentException('EPREL vrsta pretrage nije podržana.');
        }

        if ($criterion === self::SEARCH_MODEL_IDENTIFIER) {
            return $this->findByModelIdentifier($group, $searchQuery, $brandCandidates);
        }

        $value = match ($criterion) {
            self::SEARCH_GTIN_IDENTIFIER => $this->validatedGtinIdentifier($searchQuery),
            self::SEARCH_BRAND_OR_TRADEMARK => $this->validatedBrandSearch($searchQuery),
            self::SEARCH_COMMERCIAL_NAME => $this->validatedSearchText(
                $searchQuery,
                'EPREL komercijalni naziv modela nije ispravan.',
            ),
        };
        $field = self::SEARCH_FIELDS[$criterion];
        $response = $this->get('/api/products/'.$group, [
            '_page' => 1,
            '_limit' => 100,
            'genericField' => $criterion,
            $field => $value,
            'applianceType' => 'ANY',
        ]);
        if ($response === null) {
            return null;
        }

        $records = $this->records($response);
        if ($this->totalRecords($response) > count($records)) {
            throw new EprelMatchConflictException('EPREL pretraga vratila je više rezultata nego što se može sigurno provjeriti jednim dohvatom. Unesite uži pojam.');
        }

        $matches = collect($records)
            ->filter(fn (array $record): bool => $this->groupMatches($record, $group)
                && $this->searchCriterionMatches($record, $criterion, $value))
            ->map(function (array $record): ?array {
                $registration = $this->registrationFromRecord($record);

                return $registration === null ? null : compact('record', 'registration');
            })
            ->filter()
            ->unique('registration')
            ->values();

        if ($matches->isEmpty()) {
            return null;
        }
        if ($matches->count() !== 1) {
            throw new EprelMatchConflictException('EPREL pretraga vratila je više točnih artikala. Suzite pojam ili upotrijebite model odnosno GTIN.');
        }

        $match = $matches->first();
        $detail = $this->get('/api/products/'.$group.'/'.$match['registration']);
        if ($detail === null) {
            return null;
        }
        $record = $this->singleRecord($detail);
        if (! $record
            || ! $this->registrationMatches($record, $match['registration'])
            || ! $this->groupMatches($record, $group)
            || ! $this->detailCriterionMatches($record, $criterion, $value)) {
            return null;
        }

        return $this->normalizeRecord($record, $group, $match['registration']);
    }

    /** @param array<string, mixed> $query */
    private function searchProducts(string $group, array $query): ?array
    {
        try {
            return $this->get('/api/products/'.$group, $query);
        } catch (EprelHttpException $exception) {
            // EPREL occasionally rejects the optional combined brand filter.
            // Retrying the same official model search without that server-side
            // filter is safe because exact brand matching still happens below.
            if (! $exception->isServerError() || ! isset($query['supplierOrTrademark'])) {
                throw $exception;
            }

            unset($query['supplierOrTrademark']);

            return $this->get('/api/products/'.$group, $query);
        }
    }

    /** @return array<string, mixed>|null */
    private function get(string $path, array $query = []): ?array
    {
        if (! $this->settings->eprelEnabled()) {
            throw new EprelException('EPREL dohvat nije uključen.');
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-api-key' => $this->settings->eprelApiKey()])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout($this->settings->eprelConnectTimeout())
                ->timeout($this->settings->eprelTimeout())
                ->get(self::BASE_URL.$path, $query);
        } catch (ConnectionException) {
            throw new EprelException('Povezivanje sa službenim EPREL servisom nije uspjelo.');
        }

        if ($response->status() === 404) {
            return null;
        }
        $this->assertSuccessful($response);

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new EprelException('EPREL servis vratio je neispravan odgovor.');
        }

        return $payload;
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw match ($response->status()) {
            401, 403 => new EprelException('EPREL je odbio API ključ ili pristup.'),
            429 => new EprelException('EPREL je privremeno ograničio broj zahtjeva.'),
            default => new EprelHttpException(
                'EPREL servis trenutačno nije dostupan (HTTP '.$response->status().').',
                $response->status(),
            ),
        };
    }

    private function validatedProductGroup(string $value): string
    {
        $group = strtolower(trim($value));
        if (! array_key_exists($group, self::PRODUCT_GROUPS)) {
            throw new InvalidArgumentException('EPREL grupa proizvoda nije podržana.');
        }

        return $group;
    }

    private function validatedRegistrationNumber(string $value): string
    {
        $number = trim($value);
        if (! self::isValidRegistrationNumber($number)) {
            throw new InvalidArgumentException('EPREL registracijski broj nije ispravan.');
        }

        return $number;
    }

    private function validatedModelIdentifier(string $value): string
    {
        $model = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if (! self::isValidModelIdentifier($model)) {
            throw new InvalidArgumentException('EPREL identifikator modela nije ispravan.');
        }

        return $model;
    }

    private function validatedGtinIdentifier(string $value): string
    {
        $gtin = trim($value);
        if (! preg_match('/^(?:[0-9]{8}|[0-9]{12}|[0-9]{13}|[0-9]{14})$/D', $gtin)) {
            throw new InvalidArgumentException('EPREL GTIN identifikator nije ispravan.');
        }
        if (! self::isValidGtinIdentifier($gtin)) {
            throw new InvalidArgumentException('EPREL GTIN identifikator nema ispravnu kontrolnu znamenku.');
        }

        return $gtin;
    }

    private function validatedBrandSearch(string $value): string
    {
        $brand = $this->validatedSearchText($value, 'EPREL marka nije ispravna.');
        if (! self::isValidBrandCandidate($brand)) {
            throw new InvalidArgumentException('EPREL marka nije ispravna.');
        }

        return $brand;
    }

    private function validatedSearchText(string $value, string $message): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($text === '' || mb_strlen($text) > 191 || preg_match('/\pC/u', $text) === 1) {
            throw new InvalidArgumentException($message);
        }

        return $text;
    }

    /** @param list<mixed> $candidates */
    private function normalizedBrandCandidates(array $candidates): array
    {
        $brands = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $brand = trim(preg_replace('/\s+/u', ' ', (string) $candidate) ?? '');
            if (! self::isValidBrandCandidate($brand)) {
                continue;
            }

            $normalized = $this->normalizedBrand($brand);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $brands[] = $brand;
        }

        return $brands;
    }

    /** @param array<string, mixed> $record */
    private function registrationMatches(array $record, string $registration): bool
    {
        $candidate = $this->registrationFromRecord($record);

        return $candidate !== null && hash_equals($registration, $candidate);
    }

    /** @param array<string, mixed> $record */
    private function modelMatches(array $record, string $model): bool
    {
        $candidate = $this->stringValue($record, [
            'modelIdentifier', 'model_identifier', 'model.identifier', 'product.modelIdentifier',
        ]);

        return $candidate !== null && hash_equals($model, $candidate);
    }

    /** @param list<string> $brands */
    private function brandMatches(array $record, array $brands): bool
    {
        $candidate = $this->stringValue($record, [
            'supplierOrTrademark', 'supplier_or_trademark', 'trademark', 'brand',
            'product.supplierOrTrademark', 'product.trademark',
        ]);
        if ($candidate === null) {
            return false;
        }

        $normalizedCandidate = $this->normalizedBrand($candidate);

        return collect($brands)->contains(
            fn (string $brand): bool => hash_equals($this->normalizedBrand($brand), $normalizedCandidate),
        );
    }

    private function normalizedBrand(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    }

    /** @param array<string, mixed> $record */
    private function searchCriterionMatches(array $record, string $criterion, string $value): bool
    {
        return match ($criterion) {
            self::SEARCH_BRAND_OR_TRADEMARK => $this->brandMatches($record, [$value]),
            self::SEARCH_COMMERCIAL_NAME => $this->normalizedText(
                $this->stringValue($record, [
                    'commercialName', 'commercial_name', 'additionalDetails.commercialName',
                    'product.commercialName',
                ]),
            ) === $this->normalizedText($value),
            self::SEARCH_GTIN_IDENTIFIER => ($candidate = $this->stringValue($record, [
                'gtinIdentifier', 'gtin_identifier', 'gtin',
                'additionalDetails.gtinIdentifier', 'product.gtinIdentifier',
            ])) !== null && hash_equals($value, $candidate),
            default => false,
        };
    }

    /** @param array<string, mixed> $record */
    private function detailCriterionMatches(array $record, string $criterion, string $value): bool
    {
        if ($criterion === self::SEARCH_GTIN_IDENTIFIER) {
            // The list candidate was required to repeat the exact GTIN. Some
            // EPREL detail representations omit it, but an explicit mismatch
            // must still be rejected.
            return $this->gtinMatches($record, $value);
        }

        return $this->searchCriterionMatches($record, $criterion, $value);
    }

    private function normalizedText(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
    }

    /** @param array<string, mixed> $record */
    private function gtinMatches(array $record, string $gtin): bool
    {
        $candidate = $this->stringValue($record, [
            'gtinIdentifier', 'gtin_identifier', 'gtin',
            'additionalDetails.gtinIdentifier', 'product.gtinIdentifier',
        ]);

        // This endpoint is already scoped to the exact GTIN and some EPREL
        // representations omit the repeated identifier. If it is present,
        // however, never accept a conflicting value.
        return $candidate === null || hash_equals($gtin, $candidate);
    }

    /** @param array<string, mixed> $record */
    private function groupMatches(array $record, string $group): bool
    {
        $candidate = $this->stringValue($record, [
            'productGroup', 'product_group', 'productGroupCode', 'product.productGroup',
        ]);
        if ($candidate === null) {
            // A product-group-specific endpoint already scopes records to this
            // group; some API representations omit the repeated group field.
            return true;
        }

        return hash_equals($group, $this->groupSlugFromResponse($candidate) ?? '');
    }

    /** @param array<string, mixed> $record */
    private function groupFromRecord(array $record): ?string
    {
        $candidate = $this->stringValue($record, [
            'productGroup', 'product_group', 'productGroupCode', 'product.productGroup',
        ]);

        return $candidate === null ? null : $this->groupSlugFromResponse($candidate);
    }

    /** @param array<string, mixed> $record */
    private function registrationFromRecord(array $record): ?string
    {
        $candidate = $this->stringValue($record, [
            'eprelRegistrationNumber', 'registrationNumber', 'eprel_registration_number',
            'product.eprelRegistrationNumber',
        ]);

        return $candidate !== null && preg_match('/^[0-9]{1,20}$/D', $candidate)
            ? $candidate
            : null;
    }

    private function groupSlugFromResponse(string $value): ?string
    {
        $candidate = trim($value);
        $slug = strtolower($candidate);
        if (isset(self::PRODUCT_GROUPS[$slug])) {
            return $slug;
        }

        foreach (self::PRODUCT_GROUPS as $group => $code) {
            if (strcasecmp($code, $candidate) === 0) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{
     *   eprel_registration_number:string,
     *   eprel_product_group:string,
     *   model_identifier:?string,
     *   energy_class:?string,
     *   scale_min:?string,
     *   scale_max:?string,
     *   energy_label_image:?string,
     *   energy_label_url:string,
     *   product_information_sheet_url:?string
     * }
     */
    private function normalizeRecord(array $record, string $group, string $registration): array
    {
        $energyClass = $this->energyClass($this->stringValue($record, [
            'energyClass', 'energyEfficiencyClass', 'energy_class',
            'energyLabel.energyClass', 'productData.energyClass',
        ]));
        $scaleMin = $this->energyClass($this->stringValue($record, [
            'scaleMin', 'energyScaleMin', 'energyScale.minimum', 'energyLabel.scaleMin',
        ]));
        $scaleMax = $this->energyClass($this->stringValue($record, [
            'scaleMax', 'energyScaleMax', 'energyScale.maximum', 'energyLabel.scaleMax',
        ]));
        if ($scaleMin === null || $scaleMax === null) {
            [$parsedMin, $parsedMax] = $this->parseScale($this->stringValue($record, [
                'energyScale', 'energyEfficiencyScale', 'energyClassRange', 'energyLabel.scale',
            ]));
            $scaleMin ??= $parsedMin;
            $scaleMax ??= $parsedMax;
        }

        $labelUrl = self::BASE_URL.'/api/products/'.$group.'/'.$registration.'/labels?format=PDF';
        // API document endpoints require the integration key and therefore
        // cannot be opened by a customer browser. EPREL exposes the fiche
        // under this public URL instead.
        $sheetUrl = self::BASE_URL.'/fiches/'.$group.'/Fiche_'.$registration.'_HR.pdf';

        return [
            'eprel_registration_number' => $registration,
            'eprel_product_group' => $group,
            'model_identifier' => $this->stringValue($record, [
                'modelIdentifier', 'model_identifier', 'model.identifier', 'product.modelIdentifier',
            ]),
            'energy_class' => $energyClass,
            'scale_min' => $scaleMin,
            'scale_max' => $scaleMax,
            'energy_label_image' => $this->safeImageName($this->stringValue($record, [
                'energyClassImageWithScale', 'energyClassImage',
                'energyLabelImage', 'energy_label_image', 'energyLabel.image',
            ])),
            'energy_label_url' => $labelUrl,
            'product_information_sheet_url' => $sheetUrl,
        ];
    }

    /** @return array{?string, ?string} */
    private function parseScale(?string $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        preg_match_all('/(?:A\+{1,3}|[A-G])/i', strtoupper($value), $matches);
        $classes = array_values(array_filter(array_map(
            fn (string $class): ?string => $this->energyClass($class),
            $matches[0] ?? [],
        )));

        return count($classes) >= 2
            ? [$classes[0], $classes[array_key_last($classes)]]
            : [null, null];
    }

    private function energyClass(?string $value): ?string
    {
        $class = strtoupper(trim((string) $value));

        return preg_match('/^(?:A\+{0,3}|[B-G])$/D', $class) ? $class : null;
    }

    private function safeImageName(?string $value): ?string
    {
        $name = trim((string) $value);
        if ($name === '' || basename($name) !== $name || mb_strlen($name) > 255) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:svg|png|jpe?g|webp)$/iD', $name)
            ? $name
            : null;
    }

    /** @param array<string, mixed> $record */
    private function stringValue(array $record, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($record, $path);
            if (is_array($value)) {
                $value = $value['value'] ?? $value['code'] ?? $value['label'] ?? null;
            }
            if (! is_scalar($value)) {
                continue;
            }
            $normalized = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function records(array $payload): array
    {
        foreach (['hits', 'results', 'items', 'content'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            return array_is_list($payload['data'])
                ? array_values(array_filter($payload['data'], 'is_array'))
                : [$payload['data']];
        }
        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        return [$payload];
    }

    /** @return array<string, mixed>|null */
    private function singleRecord(array $payload): ?array
    {
        $records = $this->records($payload);

        return count($records) === 1 ? $records[0] : null;
    }

    private function totalRecords(array $payload): int
    {
        $size = $payload['size'] ?? null;

        return is_numeric($size) && (int) $size >= 0
            ? (int) $size
            : count($this->records($payload));
    }
}
