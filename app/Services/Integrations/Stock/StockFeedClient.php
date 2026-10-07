<?php

namespace App\Services\Integrations\Stock;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class StockFeedClient
{
    private const MAX_RESPONSE_BYTES = 50 * 1024 * 1024;

    private const MAX_QUANTITY = 2147483647;

    public function __construct(private readonly StockSyncSettingsService $settings) {}

    /**
     * Downloads and validates the whole snapshot before returning any stock rows.
     * Secret feed URLs and credentials must never become report error messages.
     *
     * @return array{rows: array<int, array{identifier: string, quantity: int}>, invalid_count: int, skipped_count: int, clamped_count: int, complete: bool}
     */
    public function fetch(string $supplier): array
    {
        $format = match ($supplier) {
            'braytron', 'brytron' => 'braytron',
            'videx', 'allegro' => 'videx',
            'master', 'dpm', 'vayox', 'enovalite', 'eracuni', 'brock' => $supplier,
            default => throw new RuntimeException('Nepoznat izvor zalihe.'),
        };
        $connection = $this->settings->connection($supplier);
        $url = trim((string) ($connection['url'] ?? ''));
        $parts = parse_url($url);
        if ($url === '' || ! is_array($parts) || ! isset($parts['host'])
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('URL izvora zalihe nije konfiguriran ili nije valjan.');
        }

        if ($format === 'braytron') {
            return $this->parseSnapshot(app(BraytronFeedClient::class)->body($connection), $format);
        }

        $apiLock = $format === 'eracuni' ? Cache::lock('herrera-eracuni-api', 600) : null;
        if ($apiLock && ! $apiLock->get()) {
            throw new RuntimeException('Dohvat e-Računi je već u tijeku.');
        }
        try {
            $request = Http::connectTimeout(10)->timeout(60)->withOptions(['allow_redirects' => false]);
            if ($format === 'eracuni') {
                foreach (['username', 'password', 'token'] as $key) {
                    if (trim((string) ($connection[$key] ?? '')) === '') {
                        throw new RuntimeException('Pristupni podaci za e-Računi nisu konfigurirani.');
                    }
                }
                $endpoint = 'WarehouseGetArticleStockQuantity';
                if (! str_ends_with(rtrim((string) ($parts['path'] ?? ''), '/'), '/'.$endpoint)) {
                    if (isset($parts['query']) || isset($parts['fragment'])) {
                        throw new RuntimeException('API URL za e-Računi nije valjan.');
                    }
                    $url = rtrim($url, '/').'/'.$endpoint;
                }
                // The legacy API labels raw JSON as Content-Encoding: 8-bit.
                // Keep its bytes intact instead of asking cURL to decompress it.
                if (! app()->runningUnitTests()) {
                    $delay = max(0, (float) Cache::get('herrera-eracuni-api-next-request', 0) - microtime(true));
                    if ($delay > 0) {
                        usleep((int) min(1000000, $delay * 1000000));
                    }
                }
                $response = $request->withOptions(['decode_content' => false])
                    ->withHeaders(['Accept-Encoding' => 'identity'])->acceptJson()->asForm()
                    ->withBasicAuth((string) $connection['username'], $connection['token'].'_'.$connection['password'])
                    ->post($url, []);
            } else {
                $response = $request->get($url);
            }
        } catch (RuntimeException $exception) {
            // Only these locally generated configuration errors can be exposed.
            if (in_array($exception->getMessage(), [
                'Pristupni podaci za e-Računi nisu konfigurirani.',
                'API URL za e-Računi nije valjan.',
            ], true)) {
                throw $exception;
            }

            throw new RuntimeException('Dohvat izvora zalihe nije uspio.');
        } catch (Throwable) {
            throw new RuntimeException('Dohvat izvora zalihe nije uspio.');
        } finally {
            if ($apiLock) {
                Cache::put('herrera-eracuni-api-next-request', microtime(true) + 1, 180);
            }
            $apiLock?->release();
        }

        if (! $response->successful()) {
            throw new RuntimeException('Izvor zalihe nije vratio uspješan HTTP odgovor.');
        }
        $body = $response->body();
        if (trim($body) === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Odgovor izvora zalihe je prazan ili prevelik.');
        }

        return $this->parseSnapshot($body, $format);
    }

    private function parseSnapshot(string $body, string $format): array
    {
        $sourceRows = match ($format) {
            'eracuni' => $this->erpRows($body),
            'enovalite' => $this->csvRows($body),
            default => $this->xmlRows($body, $format),
        };
        $rows = [];
        $invalid = 0;
        $skipped = 0;
        $clamped = 0;
        $seen = [];
        $duplicates = 0;
        foreach ($sourceRows as $item) {
            $identifier = is_scalar($item['identifier'] ?? null) ? trim((string) $item['identifier']) : '';
            // The legacy Vayox and Videx imports deliberately ignore blank identifiers.
            if ($identifier === '') {
                if ($format === 'eracuni') {
                    // A warehouse snapshot cannot silently lose an article,
                    // since missing articles are reset after the full import.
                    $invalid++;
                } else {
                    $skipped++;
                }

                continue;
            }
            $quantity = $this->quantity($item['quantity'] ?? null);
            if (strlen($identifier) > 255 || $quantity === null) {
                $invalid++;

                continue;
            }
            if ($quantity < 0) {
                if ($format !== 'vayox') {
                    $invalid++;

                    continue;
                }
                // Vayox reports backordered articles as negative availability.
                $quantity = 0;
                $clamped++;
            }
            if ($format === 'dpm' && isset($seen['id:'.$identifier])) {
                // The legacy DPM import keeps the first pack variant per EAN.
                $duplicates++;

                continue;
            }
            $seen['id:'.$identifier] = true;
            $rows[] = ['identifier' => $identifier, 'quantity' => (int) $quantity];
        }
        if ($rows === []) {
            throw new RuntimeException('Izvor zalihe nije vratio nijedan valjan redak.');
        }

        return ['rows' => $rows, 'invalid_count' => $invalid, 'skipped_count' => $skipped, 'clamped_count' => $clamped, 'duplicate_count' => $duplicates, 'complete' => true];
    }

    private function xmlRows(string $body, string $format): array
    {
        if (preg_match('/<!\s*ENTITY\b/i', $body) || preg_match('/<!\s*DOCTYPE\b[^>]*\[/i', $body)) {
            throw new RuntimeException('XML izvora zalihe sadrži nepodržane deklaracije.');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA);
            if ($xml === false) {
                throw new RuntimeException('Odgovor izvora zalihe nije valjan XML dokument.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        [$root, $path, $identifierKey, $quantityKey] = match ($format) {
            'braytron' => ['Stoklar', './Stok', 'ProductCode', 'Quantity'],
            'master' => ['products', './product', 'code', 'stock'],
            'videx' => ['yml_catalog', './shop/offers/offer', 'vendorCode', 'stock_quantity'],
            'dpm' => ['offers', './PRODUCT', 'ean', 'BOX_QTY'],
            'vayox' => ['products', './product', 'ean', 'quantity'],
            'brock' => [null, './products_inner/product_inner', 'ean_code', 'stock'],
        };
        $items = $xml->xpath($path);
        if (($root !== null && $xml->getName() !== $root) || ! is_array($items) || $items === []) {
            throw new RuntimeException('XML izvora zalihe nema očekivanu strukturu ili je prazan.');
        }
        $rows = [];
        foreach ($items as $item) {
            $quantity = isset($item->{$quantityKey}) ? (string) $item->{$quantityKey} : null;
            if ($format === 'brock') {
                $quantity = isset($item->stock['value']) ? (string) $item->stock['value'] : null;
            }
            // DPM historically treats an empty BOX_QTY as zero.
            if ($format === 'dpm' && $quantity !== null && trim($quantity) === '') {
                $quantity = '0';
            }
            $rows[] = ['identifier' => (string) $item->{$identifierKey}, 'quantity' => $quantity];
        }

        return $rows;
    }

    private function csvRows(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $firstLine = strtok($body, "\r\n");
        if ($firstLine === false) {
            throw new RuntimeException('CSV izvora zalihe je prazan.');
        }
        $delimiter = ';';
        $fieldCount = 0;
        foreach ([';', ',', "\t", '|'] as $candidate) {
            $count = count(str_getcsv($firstLine, $candidate, '"', ''));
            if ($count > $fieldCount) {
                $fieldCount = $count;
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);
        try {
            $header = fgetcsv($stream, null, $delimiter, '"', '');
            if (! is_array($header) || count($header) < 2 || trim((string) $header[0]) === ''
                || trim((string) $header[1]) === '' || is_numeric($header[0])) {
                throw new RuntimeException('CSV izvora zalihe nema očekivano zaglavlje.');
            }
            $rows = [];
            while (($item = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if ($item === [null]) {
                    continue;
                }
                $rows[] = ['identifier' => $item[0] ?? null, 'quantity' => $item[1] ?? null];
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function erpRows(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Odgovor e-Računi nije valjan JSON dokument.');
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Odgovor e-Računi nema očekivanu strukturu.');
        }
        $envelope = $decoded['response'] ?? $decoded;
        if (! is_array($envelope)) {
            throw new RuntimeException('Odgovor e-Računi nema očekivanu strukturu.');
        }
        foreach ([$decoded, $envelope] as $state) {
            if (! empty($state['error']) || ! empty($state['errors']) || ! empty($state['fault'])
                || ($state['success'] ?? null) === false || ($state['complete'] ?? null) === false
                || ($state['hasMore'] ?? null) === true
                || in_array(strtolower((string) ($state['status'] ?? '')), ['error', 'failed', 'failure'], true)) {
                throw new RuntimeException('e-Računi nije vratio potpun i uspješan odgovor zalihe.');
            }
        }
        $items = $envelope['result'] ?? $envelope;
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            throw new RuntimeException('Odgovor e-Računi nema potpuni popis zalihe.');
        }
        // The unfiltered warehouse method can silently stop at 10,000 rows.
        // Missing products may be reset only from a demonstrably complete feed.
        if (count($items) >= 10000) {
            throw new RuntimeException('e-Računi je dosegnuo ograničenje popisa zalihe. Zalihe nisu promijenjene.');
        }
        $rows = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_array($item['StockQuantityInfo'] ?? null)) {
                throw new RuntimeException('Odgovor e-Računi sadrži nepotpun zapis zalihe.');
            }
            $stock = $item['StockQuantityInfo'];
            if (! isset($stock['productCode']) || ! array_key_exists('quantityOnStock', $stock)) {
                throw new RuntimeException('Odgovor e-Računi sadrži nepotpun zapis zalihe.');
            }
            $rows[] = ['identifier' => $stock['productCode'], 'quantity' => $stock['quantityOnStock']];
        }

        return $rows;
    }

    private function quantity(mixed $value): ?float
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }
        $text = trim((string) $value);
        if (! preg_match('/^[+-]?\d+(?:[.,]\d+)?$/D', $text)) {
            return null;
        }
        $number = (float) str_replace(',', '.', $text);
        if (! is_finite($number) || abs($number) > self::MAX_QUANTITY) {
            return null;
        }

        return $number;
    }
}
