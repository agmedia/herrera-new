<?php

namespace Tests\Feature\Shipping;

use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\CroatianIslandDestinationClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CroatianIslandDestinationClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_road_connected_island_follows_the_admin_policy(): void
    {
        config()->set(
            'termol_shipping.islands.default_policy',
            CroatianIslandDestinationClassifier::POLICY_UNCONNECTED_ONLY,
        );

        $defaultResult = $this->classifier()->classify('HR', '51500', 'Krk');

        $this->assertSame('hr_mainland', $defaultResult->scope);
        $this->assertTrue($defaultResult->is_island);
        $this->assertTrue($defaultResult->road_connected_to_mainland);
        $this->assertSame('Krk', $defaultResult->island);
        $this->assertSame('island_postal_city', $defaultResult->matched_by);

        app(SystemSettingsService::class)->put(
            CroatianIslandDestinationClassifier::SETTING_KEY,
            CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS,
        );

        $allIslandsResult = $this->classifier()->classify('HR', '51500', 'Krk');

        $this->assertSame('hr_islands', $allIslandsResult->scope);
        $this->assertSame(CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS, $allIslandsResult->policy);
        $this->assertTrue($allIslandsResult->is_island);
        $this->assertTrue($allIslandsResult->road_connected_to_mainland);
    }

    public function test_non_connected_island_uses_island_scope_under_both_policies(): void
    {
        foreach (CroatianIslandDestinationClassifier::validPolicies() as $policy) {
            app(SystemSettingsService::class)->put(
                CroatianIslandDestinationClassifier::SETTING_KEY,
                $policy,
            );

            $result = $this->classifier()->classify('HR', '21480', 'Vis');

            $this->assertSame('hr_islands', $result->scope);
            $this->assertSame($policy, $result->policy);
            $this->assertTrue($result->is_island);
            $this->assertFalse($result->road_connected_to_mainland);
            $this->assertSame('Vis', $result->island);
        }
    }

    public function test_known_non_island_croatian_destination_uses_mainland_scope(): void
    {
        $result = $this->classifier()->classify('hr', '10000', 'Zagreb');

        $this->assertSame('hr_mainland', $result->scope);
        $this->assertFalse($result->is_island);
        $this->assertNull($result->road_connected_to_mainland);
        $this->assertNull($result->island);
        $this->assertSame('known_hr_postal_city', $result->matched_by);
    }

    public function test_unknown_incomplete_and_non_croatian_destinations_do_not_get_an_mbe_scope(): void
    {
        $unknown = $this->classifier()->classify('HR', '51500', 'Zagreb');
        $missingCity = $this->classifier()->classify('HR', '51500', null);
        $invalidPostalCode = $this->classifier()->classify('HR', '5150', 'Krk');
        $nonCroatian = $this->classifier()->classify('SI', '1000', 'Ljubljana');

        $this->assertNull($unknown->scope);
        $this->assertNull($unknown->is_island);
        $this->assertSame('unknown_postal_city', $unknown->matched_by);
        $this->assertNull($missingCity->scope);
        $this->assertSame('incomplete_address', $missingCity->matched_by);
        $this->assertNull($invalidPostalCode->scope);
        $this->assertNull($invalidPostalCode->postal_code);
        $this->assertSame('incomplete_address', $invalidPostalCode->matched_by);
        $this->assertNull($nonCroatian->scope);
        $this->assertSame('unsupported_country', $nonCroatian->matched_by);
    }

    public function test_city_matching_normalizes_case_diacritics_hyphens_and_spaces(): void
    {
        $result = $this->classifier()->classify(' HR ', '51550', '  MALI—LOSINJ  ');

        $this->assertSame('hr_islands', $result->scope);
        $this->assertSame('Lošinj', $result->island);
        $this->assertSame('MALI—LOSINJ', $result->city);
        $this->assertSame('mali losinj', CroatianIslandDestinationClassifier::normalizeCity('Mali-Lošinj'));
    }

    public function test_mixed_postal_office_pairs_follow_the_policy_conservatively(): void
    {
        foreach ([['21220', 'Trogir'], ['22240', 'Tisno']] as [$postalCode, $city]) {
            app(SystemSettingsService::class)->put(
                CroatianIslandDestinationClassifier::SETTING_KEY,
                CroatianIslandDestinationClassifier::POLICY_UNCONNECTED_ONLY,
            );
            $defaultResult = $this->classifier()->classify('HR', $postalCode, $city);

            $this->assertSame('hr_mainland', $defaultResult->scope);
            $this->assertNull($defaultResult->is_island);
            $this->assertTrue($defaultResult->road_connected_to_mainland);
            $this->assertSame('ambiguous_road_connected_postal_city', $defaultResult->matched_by);

            app(SystemSettingsService::class)->put(
                CroatianIslandDestinationClassifier::SETTING_KEY,
                CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS,
            );

            $allIslandsResult = $this->classifier()->classify('HR', $postalCode, $city);
            $this->assertSame('hr_islands', $allIslandsResult->scope);
            $this->assertNull($allIslandsResult->is_island);
        }
    }

    public function test_peljesac_uses_the_documented_statutory_island_treatment(): void
    {
        $defaultResult = $this->classifier()->classify('HR', '20250', 'Orebić');

        $this->assertSame('Pelješac', $defaultResult->island);
        $this->assertTrue($defaultResult->road_connected_to_mainland);
        $this->assertSame('hr_mainland', $defaultResult->scope);

        app(SystemSettingsService::class)->put(
            CroatianIslandDestinationClassifier::SETTING_KEY,
            CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS,
        );

        $this->assertSame(
            'hr_islands',
            $this->classifier()->classify('HR', '20250', 'Orebić')->scope,
        );
    }

    public function test_invalid_setting_falls_back_to_the_configured_default_policy(): void
    {
        config()->set(
            'termol_shipping.islands.default_policy',
            CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS,
        );
        app(SystemSettingsService::class)->put(
            CroatianIslandDestinationClassifier::SETTING_KEY,
            'unsupported-policy',
        );

        $result = $this->classifier()->classify('HR', '51500', 'Krk');

        $this->assertSame(CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS, $result->policy);
        $this->assertSame('hr_islands', $result->scope);
    }

    public function test_dataset_has_unique_exact_pairs_and_every_pair_exists_in_the_base_place_snapshot(): void
    {
        $dataset = json_decode(
            file_get_contents(resource_path('data/hr-island-destinations.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $places = json_decode(
            file_get_contents(public_path('front-theme/data/hr-places.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $knownPairs = [];

        foreach ($places['places'] as $place) {
            if ($place['country_code'] !== 'HR') {
                continue;
            }

            $knownPairs[$this->pairKey($place['postal_code'], $place['city'])] = true;
        }

        $classifiedPairs = [];
        $destinations = array_merge($dataset['destinations'], $dataset['ambiguous_destinations']);

        $this->assertSame(1, $dataset['schema_version']);
        $this->assertNotEmpty($dataset['dataset_version']);
        $this->assertNotEmpty($dataset['source']['postal_places']);
        $this->assertNotEmpty($dataset['source']['island_register']);
        $this->assertGreaterThanOrEqual(100, count($dataset['destinations']));

        foreach ($destinations as $destination) {
            $key = $this->pairKey($destination['postal_code'], $destination['city']);

            $this->assertArrayNotHasKey($key, $classifiedPairs, "Duplicate classification pair [{$key}].");
            $this->assertArrayHasKey($key, $knownPairs, "Missing base place pair [{$key}].");
            $classifiedPairs[$key] = true;
        }

        $roadConnected = collect($dataset['destinations'])
            ->where('road_connected', true)
            ->pluck('island')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $declaredRoadConnected = collect($dataset['road_connected_islands'])
            ->sort()
            ->values()
            ->all();

        $this->assertSame($declaredRoadConnected, $roadConnected);
        $this->assertArrayHasKey($this->pairKey('21220', 'Trogir'), $classifiedPairs);
        $this->assertArrayHasKey($this->pairKey('22240', 'Tisno'), $classifiedPairs);
    }

    public function test_result_exposes_audit_metadata_for_order_payloads(): void
    {
        $result = $this->classifier()->classify('HR', '21480', 'Vis')->toArray();

        $this->assertSame('21480', $result['postal_code']);
        $this->assertSame('Vis', $result['city']);
        $this->assertSame('Vis', $result['island']);
        $this->assertFalse($result['road_connected_to_mainland']);
        $this->assertFalse($result['is_road_connected']);
        $this->assertNotEmpty($result['dataset_version']);
        $this->assertStringContainsString('Hrvatska pošta', $result['source']['postal_places']);
    }

    private function classifier(): CroatianIslandDestinationClassifier
    {
        return app(CroatianIslandDestinationClassifier::class);
    }

    private function pairKey(string $postalCode, string $city): string
    {
        return $postalCode.'|'.CroatianIslandDestinationClassifier::normalizeCity($city);
    }
}
