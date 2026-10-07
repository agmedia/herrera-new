<?php

namespace App\Services\Integrations\Stock;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class BraytronFeedClient
{
    public function __construct(private readonly StockSyncSettingsService $settings) {}

    /** The supplier allows one download in three hours; stocks and photos share it. */
    public function body(?array $connection = null): string
    {
        $connection ??= $this->settings->connection('braytron');
        $url = trim((string) ($connection['url'] ?? ''));
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Izvor Braytron nije konfiguriran.');
        }
        $key = 'herrera-braytron-feed:'.hash('sha256', $url);
        try {
            return Cache::lock($key.':download', 90)->block(10, function () use ($key, $url): string {
                $cached = Cache::get($key);
                if (is_string($cached) && $cached !== '') {
                    return $cached;
                }
                $response = Http::connectTimeout(10)->timeout(60)->withOptions(['allow_redirects' => false])->get($url);
                if (! $response->successful()) {
                    throw new RuntimeException;
                }
                $body = $response->body();
                $this->validate($body);
                Cache::put($key, $body, now()->addMinutes(180));

                return $body;
            });
        } catch (Throwable) {
            throw new RuntimeException('Dohvat Braytron nije uspio. Dobavljač dopušta novi dohvat svaka tri sata.');
        }
    }

    private function validate(string $body): void
    {
        if (trim($body) === '' || strlen($body) > 50 * 1024 * 1024
            || preg_match('/<!\s*ENTITY\b/i', $body) || preg_match('/<!\s*DOCTYPE\b[^>]*\[/i', $body)) {
            throw new RuntimeException;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA);
            if ($xml === false || $xml->getName() !== 'Stoklar' || ($xml->xpath('./Stok') ?: []) === []) {
                throw new RuntimeException;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
