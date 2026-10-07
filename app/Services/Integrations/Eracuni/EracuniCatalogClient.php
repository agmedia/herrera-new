<?php

namespace App\Services\Integrations\Eracuni;

use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class EracuniCatalogClient
{
    private array $fetchMetadata = [];

    public function __construct(private readonly StockSyncSettingsService $settings) {}

    /** @return array<int, array<string, mixed>> */
    public function products(?string $codeFrom = null, ?string $codeTo = null): array
    {
        $this->fetchMetadata = [];
        $filters = [];
        foreach (['productCodeFrom' => $codeFrom, 'productCodeTo' => $codeTo] as $key => $bound) {
            if ($bound === null || trim($bound) === '') {
                continue;
            }
            if (mb_strlen($bound) > 120 || preg_match('/[\x00-\x1f\x7f"\\\\]/', $bound)) {
                throw new RuntimeException('Raspon ERP šifri nije valjan.');
            }
            // Matches the quoted values used by the original form API.
            $filters[$key] = '"'.trim($bound).'"';
        }
        if (! $this->settings->configured('eracuni')) {
            throw new RuntimeException('Pristupni podaci za e-Računi nisu konfigurirani.');
        }
        $connection = $this->settings->connection('eracuni');
        $url = trim((string) ($connection['url'] ?? ''));
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('API URL za e-Računi nije valjan.');
        }
        $url = preg_replace('~/(?:WarehouseGetArticleStockQuantity|ProductList)/?$~', '', rtrim($url, '/')).'/ProductList';
        try {
            $response = Cache::lock('herrera-eracuni-api', 180)->block(10, function () use ($connection, $url, $filters) {
                if (! app()->runningUnitTests()) {
                    $delay = max(0, (float) Cache::get('herrera-eracuni-api-next-request', 0) - microtime(true));
                    if ($delay > 0) {
                        usleep((int) min(1000000, $delay * 1000000));
                    }
                }
                try {
                    return Http::connectTimeout(15)->timeout(120)
                        ->withOptions(['allow_redirects' => false, 'decode_content' => false])
                        ->withHeaders(['Accept-Encoding' => 'identity'])
                        ->acceptJson()->asForm()
                        ->withBasicAuth((string) $connection['username'], $connection['token'].'_'.$connection['password'])
                        ->post($url, $filters);
                } finally {
                    Cache::put('herrera-eracuni-api-next-request', microtime(true) + 1, 180);
                }
            });
            if (! $response->successful() || trim($response->body()) === '' || strlen($response->body()) > 50 * 1024 * 1024) {
                throw new RuntimeException;
            }
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Never chain a transport exception containing credentials or URLs.
            throw new RuntimeException('Dohvat popisa proizvoda iz e-Računi nije uspio.');
        }
        if (! is_array($data)) {
            throw new RuntimeException('e-Računi nije vratio valjan popis proizvoda.');
        }
        $envelope = $data['response'] ?? $data;
        if (! is_array($envelope)) {
            throw new RuntimeException('e-Računi nije vratio valjan popis proizvoda.');
        }
        foreach ([$data, $envelope] as $state) {
            if (! empty($state['error']) || ! empty($state['errors']) || ! empty($state['fault'])
                || ($state['success'] ?? null) === false || ($state['complete'] ?? null) === false
                || ($state['hasMore'] ?? null) === true
                || in_array(strtolower((string) ($state['status'] ?? '')), ['error', 'failed', 'failure'], true)) {
                throw new RuntimeException('e-Računi nije vratio potpun i uspješan popis proizvoda.');
            }
        }
        $rows = $envelope['result'] ?? $envelope;
        if (! is_array($rows) || ! array_is_list($rows) || ($rows === [] && $filters === []) || count($rows) > 10000) {
            throw new RuntimeException('e-Računi nije vratio potpuni popis proizvoda.');
        }
        $this->fetchMetadata = [
            'scope' => $filters === [] ? 'all' : 'range', 'code_from' => $codeFrom, 'code_to' => $codeTo,
            'response_limit' => 10000, 'limit_reached' => count($rows) === 10000,
            'source_row_count' => count($rows),
            'warning' => count($rows) === 10000
                ? 'ERP je vratio najviše 10.000 artikala. Prikazan je dio kataloga. Sužite raspon šifri i dohvatite novi pregled prije uvoza ili primjene cijena i naziva.'
                : null,
        ];
        $products = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_scalar($row['productCode'] ?? null) || is_bool($row['productCode'])
                || trim((string) $row['productCode']) === '' || mb_strlen((string) $row['productCode']) > 120) {
                throw new RuntimeException('Popis e-Računi sadrži neispravnu šifru proizvoda.');
            }
            $key = 'id:'.trim((string) $row['productCode']);
            if (isset($products[$key]) && $products[$key] !== $row) {
                throw new RuntimeException('Popis e-Računi sadrži proturječne zapise proizvoda.');
            }
            $products[$key] = $row;
        }

        return array_values($products);
    }

    public function fetchMetadata(): array
    {
        return $this->fetchMetadata;
    }
}
