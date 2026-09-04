<?php

namespace App\Services\Shipping;

use App\Data\Shipping\DestinationClassification;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class CroatianIslandDestinationClassifier
{
    public const SETTING_KEY = 'shipping_hr_island_policy';

    public const POLICY_ALL_ISLANDS = 'all_islands';

    public const POLICY_UNCONNECTED_ONLY = 'unconnected_only';

    /** @var array<string, array{island:string,road_connected:bool}>|null */
    private ?array $islandDestinations = null;

    /** @var array<string, array{island:string,road_connected:bool}>|null */
    private ?array $ambiguousDestinations = null;

    /** @var array<string, true>|null */
    private ?array $knownCroatianDestinations = null;

    /** @var array<string, mixed>|null */
    private ?array $metadata = null;

    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    /** @return list<string> */
    public static function validPolicies(): array
    {
        return [
            self::POLICY_ALL_ISLANDS,
            self::POLICY_UNCONNECTED_ONLY,
        ];
    }

    public function classify(?string $countryCode, ?string $postalCode, ?string $city): DestinationClassification
    {
        $policy = $this->policy();
        $this->loadDataset();

        if (Str::upper(trim((string) $countryCode)) !== 'HR') {
            return $this->result(
                policy: $policy,
                matchedBy: 'unsupported_country',
                postalCode: self::validPostalCode($postalCode),
                city: self::presentCity($city),
            );
        }

        $postalCode = trim((string) $postalCode);
        $normalizedCity = self::normalizeCity($city);

        if (! preg_match('/^\d{5}$/', $postalCode) || $normalizedCity === '') {
            return $this->result(
                policy: $policy,
                matchedBy: 'incomplete_address',
                postalCode: self::validPostalCode($postalCode),
                city: self::presentCity($city),
            );
        }

        $key = self::destinationKey($postalCode, $normalizedCity);

        $ambiguousDestination = $this->ambiguousDestinations[$key] ?? null;

        if ($ambiguousDestination !== null) {
            $scope = $policy === self::POLICY_ALL_ISLANDS
                ? 'hr_islands'
                : 'hr_mainland';

            return $this->result(
                policy: $policy,
                scope: $scope,
                isRoadConnected: $ambiguousDestination['road_connected'],
                matchedBy: 'ambiguous_road_connected_postal_city',
                postalCode: $postalCode,
                city: self::presentCity($city),
            );
        }

        $islandDestination = $this->islandDestinations[$key] ?? null;

        if ($islandDestination !== null) {
            $roadConnected = $islandDestination['road_connected'];
            $scope = $policy === self::POLICY_ALL_ISLANDS || ! $roadConnected
                ? 'hr_islands'
                : 'hr_mainland';

            return $this->result(
                policy: $policy,
                scope: $scope,
                isIsland: true,
                isRoadConnected: $roadConnected,
                matchedBy: 'island_postal_city',
                postalCode: $postalCode,
                city: self::presentCity($city),
                island: $islandDestination['island'],
            );
        }

        if (isset($this->knownCroatianDestinations[$key])) {
            return $this->result(
                policy: $policy,
                scope: 'hr_mainland',
                isIsland: false,
                matchedBy: 'known_hr_postal_city',
                postalCode: $postalCode,
                city: self::presentCity($city),
            );
        }

        return $this->result(
            policy: $policy,
            matchedBy: 'unknown_postal_city',
            postalCode: $postalCode,
            city: self::presentCity($city),
        );
    }

    public function policy(): string
    {
        $configuredDefault = config('termol_shipping.islands.default_policy', self::POLICY_UNCONNECTED_ONLY);
        $default = in_array($configuredDefault, self::validPolicies(), true)
            ? $configuredDefault
            : self::POLICY_UNCONNECTED_ONLY;
        $stored = $this->settings->get(self::SETTING_KEY, $default);

        return is_string($stored) && in_array($stored, self::validPolicies(), true)
            ? $stored
            : $default;
    }

    public static function normalizeCity(?string $city): string
    {
        $normalized = Str::lower(Str::ascii(trim((string) $city)));
        $normalized = preg_replace('/[\x{2010}-\x{2015}\x{2212}_-]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }

    /**
     * @throws JsonException
     */
    private function loadDataset(): void
    {
        if ($this->metadata !== null) {
            return;
        }

        $dataset = $this->readJson(resource_path('data/hr-island-destinations.json'));
        $places = $this->readJson(public_path('front-theme/data/hr-places.json'));
        $version = $dataset['dataset_version'] ?? null;
        $source = $dataset['source'] ?? null;

        if (! is_string($version) || $version === '' || ! is_array($source)) {
            throw new RuntimeException('Croatian island destination metadata is invalid.');
        }

        $this->metadata = [
            'dataset_version' => $version,
            'source' => array_filter(
                $source,
                static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
                ARRAY_FILTER_USE_BOTH,
            ),
        ];
        $this->knownCroatianDestinations = [];

        foreach ($places['places'] ?? [] as $place) {
            if (! is_array($place) || ($place['country_code'] ?? null) !== 'HR') {
                continue;
            }

            $postalCode = $place['postal_code'] ?? null;
            $city = $place['city'] ?? null;

            if (! is_string($postalCode) || ! is_string($city)) {
                continue;
            }

            $this->knownCroatianDestinations[self::destinationKey($postalCode, self::normalizeCity($city))] = true;
        }

        $this->islandDestinations = [];

        foreach ($dataset['destinations'] ?? [] as $destination) {
            if (! is_array($destination)) {
                throw new RuntimeException('Croatian island destination entry is invalid.');
            }

            $postalCode = $destination['postal_code'] ?? null;
            $city = $destination['city'] ?? null;
            $island = $destination['island'] ?? null;
            $roadConnected = $destination['road_connected'] ?? null;

            if (
                ! is_string($postalCode)
                || ! preg_match('/^\d{5}$/', $postalCode)
                || ! is_string($city)
                || trim($city) === ''
                || ! is_string($island)
                || trim($island) === ''
                || ! is_bool($roadConnected)
            ) {
                throw new RuntimeException('Croatian island destination entry is invalid.');
            }

            $key = self::destinationKey($postalCode, self::normalizeCity($city));

            if (isset($this->islandDestinations[$key])) {
                throw new RuntimeException("Duplicate Croatian island destination [{$postalCode} {$city}].");
            }

            if (! isset($this->knownCroatianDestinations[$key])) {
                throw new RuntimeException("Croatian island destination [{$postalCode} {$city}] is missing from the place dataset.");
            }

            $this->islandDestinations[$key] = [
                'island' => $island,
                'road_connected' => $roadConnected,
            ];
        }

        $this->ambiguousDestinations = [];

        foreach ($dataset['ambiguous_destinations'] ?? [] as $destination) {
            if (! is_array($destination)) {
                throw new RuntimeException('Ambiguous Croatian destination entry is invalid.');
            }

            $postalCode = $destination['postal_code'] ?? null;
            $city = $destination['city'] ?? null;
            $island = $destination['island'] ?? null;
            $roadConnected = $destination['road_connected'] ?? null;

            if (
                ! is_string($postalCode)
                || ! is_string($city)
                || ! is_string($island)
                || trim($island) === ''
                || $roadConnected !== true
            ) {
                throw new RuntimeException('Ambiguous Croatian destination entry is invalid.');
            }

            $key = self::destinationKey($postalCode, self::normalizeCity($city));

            if (isset($this->ambiguousDestinations[$key]) || isset($this->islandDestinations[$key])) {
                throw new RuntimeException("Duplicate Croatian destination classification [{$postalCode} {$city}].");
            }

            if (! isset($this->knownCroatianDestinations[$key])) {
                throw new RuntimeException("Ambiguous Croatian destination [{$postalCode} {$city}] is missing from the place dataset.");
            }

            $this->ambiguousDestinations[$key] = [
                'island' => $island,
                'road_connected' => $roadConnected,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read Croatian destination data [{$path}].");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Croatian destination data [{$path}] is invalid.");
        }

        return $decoded;
    }

    private static function destinationKey(string $postalCode, string $normalizedCity): string
    {
        return trim($postalCode).'|'.$normalizedCity;
    }

    private static function validPostalCode(?string $postalCode): ?string
    {
        $postalCode = trim((string) $postalCode);

        return preg_match('/^\d{5}$/', $postalCode) ? $postalCode : null;
    }

    private static function presentCity(?string $city): ?string
    {
        $city = trim((string) $city);

        return $city === '' ? null : $city;
    }

    /**
     * @param  array<string, string>|null  $source
     */
    private function result(
        string $policy,
        ?string $scope = null,
        ?bool $isIsland = null,
        ?bool $isRoadConnected = null,
        string $matchedBy = 'unknown_postal_city',
        ?string $postalCode = null,
        ?string $city = null,
        ?string $island = null,
        ?array $source = null,
    ): DestinationClassification {
        return new DestinationClassification(
            scope: $scope,
            is_island: $isIsland,
            road_connected_to_mainland: $isRoadConnected,
            policy: $policy,
            matched_by: $matchedBy,
            dataset_version: (string) ($this->metadata['dataset_version'] ?? ''),
            source: $source ?? (array) ($this->metadata['source'] ?? []),
            postal_code: $postalCode,
            city: $city,
            island: $island,
        );
    }
}
