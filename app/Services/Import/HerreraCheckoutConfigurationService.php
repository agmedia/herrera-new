<?php

namespace App\Services\Import;

use App\Models\Settings\Local\GeoZone;
use App\Models\Settings\Local\GeoZoneCountry;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Payments\WSPayFormService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HerreraCheckoutConfigurationService
{
    /** Import checkout settings from the isolated, SELECT-only OpenCart snapshot. */
    public function import(string $connection = 'herrera_source'): array
    {
        $source = DB::connection($connection);
        $target = DB::connection();
        if (! app()->environment(['local', 'testing']) || $source === $target
            || ($source->getDatabaseName() === $target->getDatabaseName() && $source->getDriverName() === $target->getDriverName())) {
            throw new RuntimeException('Checkout import requires separate local source and destination databases.');
        }
        foreach ([$source, $target] as $database) {
            if ($database->getDriverName() !== 'sqlite' && ! in_array($database->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true)) {
                throw new RuntimeException('Checkout import is restricted to local databases.');
            }
        }

        $settings = $source->table('oc_setting')->where('store_id', 0)->pluck('value', 'key');
        $languageId = (int) ($settings['config_language_id'] ?? $source->table('oc_language')->where('code', 'hr-hr')->value('language_id') ?? 3);
        $methods = [];
        foreach ($source->table('oc_xshippingpro')->orderBy('sort_order')->get() as $row) {
            $data = json_decode($row->method_data, true, flags: JSON_THROW_ON_ERROR);
            if (($data['rate_type'] ?? '') !== 'sub' || ($data['rate_final'] ?? '') !== 'single') {
                throw new RuntimeException('Unsupported legacy shipping calculation.');
            }
            $zones = array_map('intval', (array) ($data['geo_zone'] ?? []));
            $countries = $source->table('oc_zone_to_geo_zone as mapping')->join('oc_country as country', 'country.country_id', '=', 'mapping.country_id')
                ->whereIn('mapping.geo_zone_id', $zones)->distinct()->orderBy('country.iso_code_2')->pluck('country.iso_code_2')->all();
            if ($countries === []) {
                throw new RuntimeException('Legacy shipping destination is missing.');
            }
            $taxClass = (int) ($data['tax_class_id'] ?? 0);
            $tax = $source->table('oc_tax_rule as rule')->join('oc_tax_rate as rate', 'rate.tax_rate_id', '=', 'rule.tax_rate_id')
                ->where('rule.tax_class_id', $taxClass)->get(['rate.type', 'rate.rate']);
            if ($tax->contains(fn ($rate) => $rate->type !== 'P')) {
                throw new RuntimeException('Unsupported legacy shipping tax.');
            }
            $methods[] = [
                'source_id' => (int) $row->id,
                'name' => trim((string) ($data['name'][$languageId] ?? 'Dostava')),
                'description' => trim((string) ($data['desc'][$languageId] ?? '')),
                'countries' => $countries,
                'ranges' => $data['ranges'] ?? [],
                'tax_class_id' => $taxClass,
                'tax_rate_percent' => (float) $tax->sum('rate'),
                'is_active' => (bool) ($settings['shipping_xshippingpro_status'] ?? false) && (bool) ($data['status'] ?? false),
                'sort_order' => (int) $row->sort_order,
            ];
        }

        return $this->configure($methods, $settings->all(), $languageId);
    }

    /** @param array<int, array<string, mixed>> $methods */
    public function configure(array $methods, array $legacySettings, int $languageId = 3): array
    {
        return DB::transaction(function () use ($methods, $legacySettings, $languageId) {
            // Retain seeded records for historical orders, replacing their checkout activation.
            ShippingMethod::query()->update(['is_active' => false]);
            PaymentMethod::query()->update(['is_active' => false]);
            $shippingCount = 0;
            foreach ($methods as $method) {
                $countries = $method['countries'];
                $isCroatia = $countries === ['HR'];
                $zone = GeoZone::query()->updateOrCreate(['code' => 'herrera-shipping-'.$method['source_id']], [
                    'name' => $isCroatia ? 'Herrera – Hrvatska' : 'Herrera – inozemstvo',
                    'description' => 'Odredišta dostave iz starog Herrera checkouta.',
                    'is_active' => true, 'sort_order' => $method['sort_order'],
                ]);
                GeoZoneCountry::query()->where('geo_zone_id', $zone->id)->delete();
                foreach ($countries as $country) {
                    GeoZoneCountry::query()->create(['geo_zone_id' => $zone->id, 'country_code' => $country]);
                }
                foreach ($method['ranges'] as $index => $range) {
                    if (! is_numeric($range['start'] ?? null) || ! is_numeric($range['end'] ?? null) || ! is_numeric($range['cost'] ?? null)
                        || (float) $range['cost'] < 0 || (float) $range['start'] > (float) $range['end']) {
                        throw new RuntimeException('Invalid legacy shipping range.');
                    }
                    $free = (float) $range['cost'] === 0.0;
                    $code = $index === 0 ? ($isCroatia ? 'standard' : 'standard_eu') : 'herrera-shipping-'.$method['source_id'].'-'.($index + 1);
                    ShippingMethod::query()->updateOrCreate(['code' => $code], [
                        'name' => $method['name'], 'carrier' => 'manual', 'service_type' => 'home_delivery',
                        'pricing_type' => $free ? 'free' : 'flat', 'geo_zone_id' => $zone->id,
                        'description' => $method['description'], 'price' => $range['cost'], 'free_over' => null,
                        'min_subtotal' => $range['start'], 'max_subtotal' => $range['end'],
                        'min_weight_kg' => null, 'max_weight_kg' => null,
                        'max_length_cm' => null, 'max_width_cm' => null, 'max_height_cm' => null,
                        'allows_fragile' => true, 'allows_oversized' => true, 'allows_heavy' => true,
                        'fragile_surcharge' => 0, 'oversized_surcharge' => 0, 'heavy_surcharge' => 0,
                        'missing_measurements_policy' => 'allow', 'is_active' => $method['is_active'] && ! (bool) ($range['block'] ?? false),
                        'sort_order' => $method['sort_order'] * 10 + $index,
                        'settings' => ['configured_from' => 'herrera-opencart', 'source_method_id' => $method['source_id'],
                            'subtotal_basis' => 'before_discount', 'tax_class_id' => $method['tax_class_id'],
                            'tax_rate_percent' => $method['tax_rate_percent']],
                    ]);
                    $shippingCount++;
                }
            }

            $bankStatusId = $this->statusId((int) ($legacySettings['payment_bank_transfer_order_status_id'] ?? 1));
            $instructions = trim((string) ($legacySettings['payment_bank_transfer_bank'.$languageId] ?? ''));
            PaymentMethod::query()->updateOrCreate(['code' => 'bank'], [
                'name' => 'Bankovna transakcija (internet bankarstvo)', 'provider' => 'bank', 'geo_zone_id' => null,
                'description' => $instructions, 'fee_type' => 'fixed', 'fee_value' => 0,
                'min_subtotal' => is_numeric($legacySettings['payment_bank_transfer_total'] ?? null) ? $legacySettings['payment_bank_transfer_total'] : null,
                'max_subtotal' => null, 'is_active' => (bool) ($legacySettings['payment_bank_transfer_status'] ?? false),
                'sort_order' => (int) ($legacySettings['payment_bank_transfer_sort_order'] ?? 0),
                'settings' => ['configured_from' => 'herrera-opencart', 'bank_instructions' => $instructions, 'default_order_status_id' => $bankStatusId],
            ]);
            $mode = (bool) ($legacySettings['payment_wspay_test'] ?? true) ? WSPayFormService::MODE_TEST : WSPayFormService::MODE_LIVE;
            $wspaySettings = ['configured_from' => 'herrera-opencart', 'wspay_mode' => $mode,
                'wspay_form_url' => $mode === WSPayFormService::MODE_TEST ? WSPayFormService::FORM_URL_TEST : WSPayFormService::FORM_URL_LIVE,
                'wspay_shop_id' => trim((string) ($legacySettings['payment_wspay_merchant'] ?? '')),
                'wspay_secret_key' => trim((string) ($legacySettings['payment_wspay_password'] ?? '')),
                'wspay_return_method' => 'GET', 'default_order_status_id' => $bankStatusId,
                'paid_order_status_id' => $this->statusId((int) ($legacySettings['payment_wspay_order_status_id'] ?? 5))];
            PaymentMethod::query()->updateOrCreate(['code' => 'wspay'], [
                'name' => 'Kartično plaćanje (WSPay)', 'provider' => 'wspay', 'geo_zone_id' => null,
                'description' => 'Sigurno kartično plaćanje putem WSPay obrasca.', 'fee_type' => 'fixed', 'fee_value' => 0,
                'min_subtotal' => null, 'max_subtotal' => null,
                'is_active' => (bool) ($legacySettings['payment_wspay_status'] ?? false)
                    && $wspaySettings['wspay_shop_id'] !== '' && $wspaySettings['wspay_secret_key'] !== '',
                'sort_order' => (int) ($legacySettings['payment_wspay_sort_order'] ?? 1), 'settings' => $wspaySettings,
            ]);

            return ['shipping_ranges' => $shippingCount, 'active_payment_methods' => PaymentMethod::query()->where('is_active', true)->count(),
                'wspay_active' => (bool) PaymentMethod::query()->where('code', 'wspay')->value('is_active')];
        });
    }

    private function statusId(int $legacyId): ?int
    {
        return OrderStatus::query()->where('code', 'herrera-oc-status-'.$legacyId)->value('id')
            ?? OrderStatus::query()->where('is_default', true)->value('id');
    }
}
