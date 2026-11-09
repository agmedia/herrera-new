<?php

namespace App\Services\Import;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Settings\System\SystemSetting;
use App\Services\Integrations\Stock\FixedSupplierAvailabilityService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HerreraClientDefaults
{
    public const APPLIED_KEY = 'herrera_client_defaults_2026_11_applied';

    private const CONFIRMED_MANUFACTURERS = ['nopallux', 'asschwabe', 'knauf'];

    public function __construct(private readonly SystemSettingsService $settings) {}

    /** Apply the confirmed existing-data repairs once, preserving subsequent manual edits. */
    public function apply(): array
    {
        $this->settings->flush();
        if (! str_contains(mb_strtolower((string) $this->settings->get('store_brand_name', '')), 'herrera')) {
            return ['applied' => false, 'reason' => 'other_store'];
        }

        $result = DB::transaction(function (): array {
            // The unique marker also serializes concurrent attempts. It commits
            // only with the repaired data; a failure rolls everything back.
            $claimed = SystemSetting::query()->insertOrIgnore([
                'key' => self::APPLIED_KEY, 'value' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $claimed) {
                return ['applied' => false, 'reason' => 'already_applied'];
            }

            $quantities = app(LegacyOrderQuantityBackfill::class)->run(true);
            $manufacturerIds = [];
            $preservedIds = [];
            foreach (Manufacturer::query()->with('translations')->get() as $manufacturer) {
                $confirmed = $manufacturer->translations->contains(function ($translation): bool {
                    $normalized = preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii((string) $translation->name)));

                    return in_array($normalized, self::CONFIRMED_MANUFACTURERS, true);
                });
                if (! $confirmed) {
                    continue;
                }
                if (data_get($manufacturer->payload, 'supplier_availability.mode') === 'fixed'
                    || $manufacturer->products()->where('payload->supplier_availability->mode', 'fixed')->exists()) {
                    $preservedIds[] = $manufacturer->id;

                    continue;
                }
                $manufacturerIds[] = $manufacturer->id;
            }
            $availability = $manufacturerIds === [] ? ['products' => 0, 'synchronized_preserved' => 0, 'applied' => true]
                : app(FixedSupplierAvailabilityService::class)->run($manufacturerIds, true);
            $summary = [
                'applied' => true, 'reason' => 'applied', 'applied_at' => now()->toIso8601String(),
                'quantities' => $quantities, 'availability' => $availability,
                'manufacturer_ids' => $manufacturerIds, 'preserved_manufacturer_ids' => $preservedIds,
            ];
            SystemSetting::query()->where('key', self::APPLIED_KEY)
                ->update(['value' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $summary;
        });
        $this->settings->flush();

        return $result;
    }
}
