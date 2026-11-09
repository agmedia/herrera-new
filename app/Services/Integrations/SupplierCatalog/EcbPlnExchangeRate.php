<?php

namespace App\Services\Integrations\SupplierCatalog;

use App\Services\Settings\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class EcbPlnExchangeRate
{
    public const SOURCE = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    public function latest(): array
    {
        try {
            return Cache::remember('supplier-catalog:ecb-pln', now()->addHour(), function (): array {
                $response = Http::connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])->get(self::SOURCE);
                if (! $response->successful()) {
                    throw new RuntimeException;
                }
                $rate = $this->parse($response->body());
                app(SystemSettingsService::class)->put('supplier_catalog_ecb_pln_reference', $rate);

                return $rate;
            });
        } catch (Throwable) {
            $previous = app(SystemSettingsService::class)->get('supplier_catalog_ecb_pln_reference');
            if (is_array($previous) && $this->validDate($previous['date'] ?? '') && (float) ($previous['pln_per_eur'] ?? 0) > 0 && (float) $previous['pln_per_eur'] <= 1000) {
                return array_replace($previous, ['source' => self::SOURCE, 'eur_per_pln' => 1 / (float) $previous['pln_per_eur']]);
            }

            throw new RuntimeException('Službeni ECB tečaj PLN nije dostupan. Uvoz je zaustavljen; postojeće cijene ostaju sačuvane.');
        }
    }

    public function parse(string $body): array
    {
        if (strlen($body) > 1024 * 1024 || preg_match('/<!\s*(ENTITY|DOCTYPE)\b/i', $body)) {
            throw new RuntimeException('Nevaljan ECB odgovor.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            $days = $xml ? $xml->xpath('//*[local-name()="Cube"][@time]') : [];
            if (count($days ?: []) !== 1) {
                throw new RuntimeException('Nevaljan ECB odgovor.');
            }
            $date = (string) $days[0]['time'];
            $rates = $days[0]->xpath('./*[local-name()="Cube"][@currency="PLN"]');
            $rate = count($rates ?: []) === 1 ? (string) $rates[0]['rate'] : '';
            if (! $this->validDate($date) || ! preg_match('/^\d+(?:\.\d+)?$/D', $rate) || (float) $rate <= 0 || (float) $rate > 1000) {
                throw new RuntimeException('Nevaljan ECB tečaj ili datum.');
            }

            return ['date' => $date, 'source' => self::SOURCE, 'pln_per_eur' => (float) $rate, 'eur_per_pln' => 1 / (float) $rate, 'retrieved_at' => now()->toIso8601String()];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function validDate(string $date): bool
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Berlin');

            return $parsed && $parsed->format('Y-m-d') === $date && $parsed->betweenIncluded(CarbonImmutable::today('Europe/Berlin')->subDays(7), CarbonImmutable::today('Europe/Berlin'));
        } catch (Throwable) {
            return false;
        }
    }
}
