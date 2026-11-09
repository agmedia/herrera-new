<?php

namespace App\Services\Integrations\Eracuni;

use App\Services\Integrations\Stock\StockSyncSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Read-only company sales evidence. No warehouse, customer or cost-centre filter. */
class EracuniCompanySalesClient
{
    public const SOURCES = ['SalesInvoiceList', 'POSSalesInvoiceList', 'DeliveryNoteList'];

    private const LIMIT = 500;

    private const MAX_REQUESTS = 2048;

    private const MAX_FETCH_SECONDS = 5400;

    private int $requestCount = 0;

    private int $deadline = 0;

    private array $lastRequestContext = [];

    private array $hydratedDeliveryNotes = [];

    /**
     * Positive lines from any pass conservatively exclude an article from clearance,
     * including an unbilled delivery or a cancelled/draft sale. No PII is returned.
     *
     * @return array{complete: true, coverage_from: string, as_of: string, fetched_at: string, recent_product_codes: array<int, string>, document_count: int, unlinked_line_count: int, request_count: int, sources: array<int, string>, fingerprint: string}
     */
    public function fetch(string $coverageFrom, string $asOf): array
    {
        $from = $this->date($coverageFrom);
        $until = $this->date($asOf);
        if ($from->gt($until) || $until->gt(CarbonImmutable::today()) || $from->diffInDays($until) > 366) {
            throw new RuntimeException('Razdoblje provjere prodaje nije valjano.');
        }
        if (! $this->settings->configured('eracuni')) {
            throw new RuntimeException('Pristup e-Računima nije konfiguriran.');
        }
        $connection = $this->settings->connection('eracuni');
        $base = $this->baseUrl($connection);
        $this->requestCount = 0;
        $this->lastRequestContext = [];
        $this->hydratedDeliveryNotes = [];
        $this->deadline = hrtime(true) + self::MAX_FETCH_SECONDS * 1_000_000_000;
        $codes = $documents = [];

        try {
            foreach (self::SOURCES as $method) {
                $passes = ['document', 'supply'];
                if ($method !== 'DeliveryNoteList') {
                    $passes[] = 'issued';
                }
                foreach ($passes as $pass) {
                    $this->collect($method, $pass, $from, $until, $base, $connection, $codes, $documents);
                }
            }
        } catch (RuntimeException $exception) {
            if (get_class($exception) !== RuntimeException::class) {
                throw $exception;
            }
            throw new EracuniCompanySalesException($exception->getMessage(), $this->lastRequestContext);
        }

        $productCodes = array_values($codes);
        sort($productCodes, SORT_STRING);
        $result = [
            'complete' => true,
            'coverage_from' => $coverageFrom,
            'as_of' => $asOf,
            'fetched_at' => now()->toIso8601String(),
            'recent_product_codes' => $productCodes,
            'document_count' => count($documents),
            'unlinked_line_count' => array_sum($documents),
            'request_count' => $this->requestCount,
            'sources' => self::SOURCES,
        ];
        $result['fingerprint'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    public function __construct(private readonly StockSyncSettingsService $settings) {}

    private function collect(string $method, string $pass, CarbonImmutable $from, CarbonImmutable $until, string $base, array $connection, array &$codes, array &$documents): void
    {
        $this->lastRequestContext = [
            'method' => $method, 'pass' => $pass,
            'from' => $from->toDateString(), 'to' => $until->toDateString(),
        ];
        $parameters = match ($pass) {
            'document' => ['dateFrom' => $from->toDateString(), 'dateTo' => $until->toDateString()],
            'supply' => ['dateOfSupplyFrom' => $from->toDateString(), 'dateOfSupplyTo' => $until->toDateString()],
            'issued' => ['issuedTimestampFrom' => $from->startOfDay()->format('Y-m-d H:i:s'), 'issuedTimestampTo' => $until->endOfDay()->format('Y-m-d H:i:s')],
        };
        [$rows, $truncated] = $this->request($base.'/'.$method, $parameters, $connection);
        foreach ($rows as $row) {
            unset($this->lastRequestContext['document_id'], $this->lastRequestContext['line_position']);
            if (is_array($row) && $this->validIdentifier($row['documentID'] ?? null, 160)) {
                $this->lastRequestContext['document_id'] = (string) $row['documentID'];
            }
            if (! is_array($row) || ! $this->validIdentifier($row['documentID'] ?? null, 160)
                || ! isset($row['date']) || ! is_string($row['date'])) {
                throw new RuntimeException('e-Računi je vratio dokument bez valjanog identifikatora ili datuma.');
            }
            $this->date($row['date']);
            if ($method === 'DeliveryNoteList' && (! isset($row['Items']) || ! is_array($row['Items']) || ! array_is_list($row['Items']))) {
                $row = $this->hydrateDeliveryNote($row, $base, $connection);
            }
            foreach (['dateOfSupply', 'dateOfSupplyFrom', 'dateOfSupplyUntil'] as $field) {
                if (isset($row[$field]) && $row[$field] !== '') {
                    if (! is_string($row[$field])) {
                        throw new RuntimeException('e-Računi je vratio nevaljan datum isporuke.');
                    }
                    $this->date($row[$field]);
                }
            }
            $this->validateFilter($row, $pass, $from, $until);
            if (! isset($row['Items']) || ! is_array($row['Items']) || ! array_is_list($row['Items'])) {
                throw new RuntimeException('e-Računi nije vratio potpune stavke dokumenata.');
            }
            $unlinkedLineCount = 0;
            foreach ($row['Items'] as $index => $item) {
                $position = is_array($item) ? ($item['position'] ?? null) : null;
                $this->lastRequestContext['line_position'] = (is_int($position) || (is_string($position) && ctype_digit($position)))
                    && (int) $position > 0 && (int) $position <= 1000000 ? (int) $position : $index + 1;
                if (! is_array($item) || ! isset($item['quantity']) || is_bool($item['quantity'])
                    || ! is_scalar($item['quantity']) || ! is_numeric($item['quantity'])
                    || ! is_finite((float) $item['quantity'])) {
                    throw new RuntimeException('e-Računi je vratio nevaljanu količinu prodajne stavke.');
                }
                if ((float) $item['quantity'] <= 0) {
                    continue;
                }
                if (! $this->validIdentifier($item['productCode'] ?? null, 120)) {
                    if ($this->unlinkedFreeTextLine($item)) {
                        $unlinkedLineCount++;

                        continue;
                    }
                    throw new RuntimeException('Pozitivna prodajna stavka nema valjanu šifru artikla. Potpuni obuhvat nije dokazan.');
                }
                $code = trim((string) $item['productCode']);
                $codes['code:'.$code] = $code;
            }
            $documentKey = $method.':'.(string) $row['documentID'];
            $documents[$documentKey] = max($documents[$documentKey] ?? 0, $unlinkedLineCount);
        }
        if ($truncated || count($rows) >= self::LIMIT) {
            unset($this->lastRequestContext['document_id'], $this->lastRequestContext['line_position']);
            if ($from->isSameDay($until)) {
                throw new RuntimeException('e-Računi je dosegnuo dnevno ograničenje dokumenata. Potpuni obuhvat nije dokazan.');
            }
            // Retain known activity from a truncated parent too: a supply period
            // can span a child boundary. A parent never proves the missing rows.
            $middle = $from->addDays((int) floor($from->diffInDays($until) / 2));
            $this->collect($method, $pass, $from, $middle, $base, $connection, $codes, $documents);
            $this->collect($method, $pass, $middle->addDay(), $until, $base, $connection, $codes, $documents);
        }
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: bool} */
    private function request(string $url, array $parameters, array $connection, bool $singleDocument = false): array
    {
        if ($this->deadline > 0 && hrtime(true) >= $this->deadline) {
            throw new RuntimeException('Provjera ukupne prodaje nije dovršena u dopuštenom vremenu.');
        }
        if (++$this->requestCount > self::MAX_REQUESTS) {
            throw new RuntimeException('Provjera ukupne prodaje premašila je sigurno ograničenje dohvata.');
        }
        $this->lastRequestContext['request_count'] = $this->requestCount;
        try {
            $response = Cache::lock('herrera-eracuni-api', 180)->block(10, function () use ($url, $parameters, $connection) {
                if (! app()->runningUnitTests()) {
                    $delay = max(0, (float) Cache::get('herrera-eracuni-api-next-request', 0) - microtime(true));
                    if ($delay > 0) {
                        usleep((int) min(1000000, $delay * 1000000));
                    }
                }
                try {
                    return Http::connectTimeout(15)->timeout(120)
                        ->withOptions([
                            'allow_redirects' => false,
                            'decode_content' => false,
                            'progress' => static function ($total, $downloaded): void {
                                if ($total > 50 * 1024 * 1024 || $downloaded > 50 * 1024 * 1024) {
                                    throw new RuntimeException;
                                }
                            },
                        ])
                        ->withHeaders(['Accept-Encoding' => 'identity'])->acceptJson()->asForm()
                        ->withBasicAuth((string) $connection['username'], $connection['token'].'_'.$connection['password'])
                        ->post($url, $parameters);
                } finally {
                    Cache::put('herrera-eracuni-api-next-request', microtime(true) + 1, 180);
                }
            });
            if (! $response->successful() && $this->explicitNoDocuments($response->status(), $response->body(), basename($url))) {
                return [[], false];
            }
            if (! $response->successful() || trim($response->body()) === '' || strlen($response->body()) > 50 * 1024 * 1024) {
                throw new RuntimeException;
            }
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Transport exceptions can contain authentication data or a private URL.
            throw new RuntimeException('Dohvat ukupne prodaje iz e-Računa nije uspio. Ništa nije potvrđeno.');
        }
        if (! is_array($data)) {
            throw new RuntimeException('e-Računi nije vratio valjanu evidenciju prodaje.');
        }
        $envelope = $data['response'] ?? $data;
        if (! is_array($envelope)) {
            throw new RuntimeException('e-Računi nije vratio valjanu evidenciju prodaje.');
        }
        $truncated = false;
        foreach ([$data, $envelope] as $state) {
            if (isset($state['status']) && ! is_string($state['status'])) {
                throw new RuntimeException('e-Računi je vratio nevaljan status dohvata prodaje.');
            }
            if (! empty($state['error']) || ! empty($state['errors']) || ! empty($state['fault'])
                || ! empty($state['errorCode']) || ! empty($state['error_code']) || ! empty($state['faultCode'])
                || (array_key_exists('success', $state) && ! in_array($state['success'], [true, 1, 'true'], true))
                || in_array(strtolower((string) ($state['status'] ?? '')), ['error', 'failed', 'failure'], true)) {
                throw new RuntimeException('e-Računi nije potvrdio uspješan dohvat prodaje.');
            }
            if ((array_key_exists('complete', $state) && ! in_array($state['complete'], [true, 1, 'true'], true))
                || ! empty($state['hasMore']) || ! empty($state['has_more'])
                || ! empty($state['truncated']) || ! empty($state['limitReached']) || ! empty($state['nextPage']) || ! empty($state['next_page'])) {
                $truncated = true;
            }
        }
        $rows = $envelope['result'] ?? $envelope;
        if ($singleDocument && is_array($rows) && ! array_is_list($rows)) {
            $rows = [$rows];
        }
        if ($singleDocument && (! is_array($rows) || count($rows) !== 1 || ! is_array($rows[0] ?? null))) {
            throw new RuntimeException('e-Računi nije vratio jednu potpunu otpremnicu.');
        }
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100000) {
            throw new RuntimeException('e-Računi nije vratio popis prodajnih dokumenata.');
        }
        foreach ([$data, $envelope] as $state) {
            foreach (['total', 'totalCount', 'total_count', 'totalRows'] as $key) {
                if (! array_key_exists($key, $state)) {
                    continue;
                }
                if ((! is_int($state[$key]) && (! is_string($state[$key]) || ! ctype_digit($state[$key]))) || (int) $state[$key] < count($rows)) {
                    throw new RuntimeException('e-Računi je vratio proturječan broj dokumenata.');
                }
                $truncated = $truncated || (int) $state[$key] > count($rows);
            }
        }

        return [$rows, $truncated];
    }

    private function hydrateDeliveryNote(array $header, string $base, array $connection): array
    {
        $key = 'document:'.(string) $header['documentID'];
        if (isset($this->hydratedDeliveryNotes[$key])) {
            $document = $this->hydratedDeliveryNotes[$key];
        } else {
            [$rows, $truncated] = $this->request($base.'/DeliveryNoteGet', ['documentID' => (string) $header['documentID']], $connection, true);
            if ($truncated || count($rows) !== 1) {
                throw new RuntimeException('e-Računi nije potvrdio potpuni dohvat otpremnice.');
            }
            $document = $rows[0];
        }
        if (! $this->validIdentifier($document['documentID'] ?? null, 160)
            || (string) $document['documentID'] !== (string) $header['documentID']
            || ($document['date'] ?? null) !== $header['date']) {
            throw new RuntimeException('Potpuna otpremnica ne odgovara identifikatoru i datumu zadanog dokumenta.');
        }
        if (! array_key_exists('Items', $document) && $this->confirmedEmptyDeliveryNote($document)) {
            // Items is optional in the documented full DeliveryNote object. The
            // actual ERP omits it on issued, zero-total documents with no lines.
            $document['Items'] = [];
        }
        if (! isset($document['Items']) || ! is_array($document['Items']) || ! array_is_list($document['Items'])) {
            throw new RuntimeException('Pojedinačni dohvat otpremnice nije vratio potpune stavke.');
        }
        // Keep only fields needed by the classifier, not buyer details or prices.
        $document = array_intersect_key($document, array_flip(['documentID', 'date', 'dateOfSupply', 'dateOfSupplyFrom', 'dateOfSupplyUntil', 'issuedTimestamp', 'Items']));
        $document['Items'] = array_map(static fn ($item) => is_array($item)
            ? array_intersect_key($item, array_flip(['position', 'productCode', 'productName', 'productBarCode', 'productCatalogueCode', 'quantity']))
            : $item, $document['Items']);
        $this->hydratedDeliveryNotes[$key] = $document;

        return $document;
    }

    private function confirmedEmptyDeliveryNote(array $document): bool
    {
        if (! in_array($document['status'] ?? null, ['Draft', 'IssuedInvoice'], true)) {
            return false;
        }
        foreach (['totalNetAmount', 'totalAmountInDomCurr'] as $field) {
            if (! array_key_exists($field, $document) || ! $this->numericZero($document[$field])) {
                return false;
            }
        }
        foreach ($document as $field => $value) {
            if (in_array($field, ['superDiscountAmount', 'returnFeeForPackagingAmount'], true)
                || preg_match('/^total(?:Discount|.*Amount)/D', $field)) {
                if (! $this->numericZero($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function numericZero(mixed $value): bool
    {
        return ! is_bool($value) && is_scalar($value) && is_numeric($value)
            && is_finite((float) $value) && (float) $value === 0.0;
    }

    private function explicitNoDocuments(int $status, string $body, string $method): bool
    {
        if ($status !== 500 || strlen($body) > 64 * 1024) {
            return false;
        }
        $data = json_decode($body, true, 32);
        $envelope = is_array($data) ? ($data['response'] ?? null) : null;
        if (is_array($data) && (! empty($data['error']) || ! empty($data['errors']) || ! empty($data['fault']))) {
            return false;
        }

        // Legacy REST returns an explicit business error for an empty selection.
        // Only this observed, exact error for the requested list is an empty list;
        // permission errors, unknown methods and generic HTTP 500 remain failures.
        return is_array($envelope) && ($envelope['apiMethod'] ?? null) === $method
            && is_string($envelope['status'] ?? null) && strtolower($envelope['status']) === 'error'
            && is_string($envelope['description'] ?? null)
            && preg_match('/^Error nr: 1 #noDocumentsFound - No documents were found matching search criteria!;\s*$/D', $envelope['description']) === 1
            && ! isset($envelope['result']) && empty($envelope['hasMore']) && empty($envelope['has_more'])
            && empty($envelope['truncated'])
            && (! array_key_exists('complete', $envelope) || in_array($envelope['complete'], [true, 1, 'true'], true));
    }

    private function baseUrl(array $connection): string
    {
        $url = trim((string) ($connection['url'] ?? ''));
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('API URL za e-Račune nije valjan.');
        }
        foreach (['username', 'token', 'password'] as $key) {
            if (! is_string($connection[$key] ?? null) || trim($connection[$key]) === '') {
                throw new RuntimeException('Pristup e-Računima nije potpun.');
            }
        }

        return preg_replace('~/(?:WarehouseGetArticleStockQuantity|ProductList|SalesInvoiceList|POSSalesInvoiceList|DeliveryNoteList)/?$~', '', rtrim($url, '/'));
    }

    private function validIdentifier(mixed $value, int $max): bool
    {
        return (is_string($value) || is_int($value)) && trim((string) $value) !== ''
            && mb_strlen((string) $value) <= $max && ! preg_match('/[\x00-\x1f\x7f]/', (string) $value);
    }

    private function unlinkedFreeTextLine(array $item): bool
    {
        // SalesInvoiceItem documents productName as present when an ERP product
        // is associated with the line. Free-text lines without any linked product
        // identity cannot represent a stock article; never infer this from prose.
        foreach (['productCode', 'productName', 'productBarCode', 'productCatalogueCode'] as $field) {
            if (isset($item[$field]) && (! is_string($item[$field]) || trim($item[$field]) !== '')) {
                return false;
            }
        }

        return true;
    }

    private function validateFilter(array $row, string $pass, CarbonImmutable $from, CarbonImmutable $until): void
    {
        if ($pass === 'supply' && empty($row['dateOfSupply']) && ! empty($row['dateOfSupplyFrom']) && ! empty($row['dateOfSupplyUntil'])) {
            // Invoice supplies may cover a period. The actual ERP returns a row
            // whose period starts before the requested range and ends within it.
            $periodFrom = $this->date($row['dateOfSupplyFrom']);
            $periodUntil = $this->date($row['dateOfSupplyUntil']);
            if ($periodFrom->gt($periodUntil)) {
                throw new RuntimeException('API je vratio proturječno razdoblje isporuke.');
            }
            if ($periodUntil->lt($from) || $periodFrom->gt($until)) {
                throw new RuntimeException('API nije poštovao zadano razdoblje prodaje. Potpuni obuhvat nije potvrđen.');
            }

            return;
        }
        $value = match ($pass) {
            'document' => $row['date'] ?? null,
            'supply' => $row['dateOfSupply'] ?? $row['dateOfSupplyFrom'] ?? $row['dateOfSupplyUntil'] ?? null,
            'issued' => $row['issuedTimestamp'] ?? null,
        };
        if (! is_string($value)) {
            throw new RuntimeException('API nije vratio datum potreban za provjeru zadanog razdoblja.');
        }
        if ($pass === 'issued') {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?$/D', $value, $parts)
                || (int) $parts[1] > 23 || (int) $parts[2] > 59 || (int) $parts[3] > 59) {
                throw new RuntimeException('API je vratio nevaljano vrijeme izdavanja.');
            }
            $this->date(substr($value, 0, 10));
            try {
                $documentDate = CarbonImmutable::parse($value, config('app.timezone'))->setTimezone(config('app.timezone'))->startOfDay();
            } catch (Throwable) {
                throw new RuntimeException('API je vratio nevaljano vrijeme izdavanja.');
            }
        } else {
            $documentDate = $this->date($value);
        }
        if ($documentDate->lt($from) || $documentDate->gt($until)) {
            throw new RuntimeException('API nije poštovao zadano razdoblje prodaje. Potpuni obuhvat nije potvrđen.');
        }
    }

    private function date(string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if ($date && $date->format('Y-m-d') === $value) {
                return $date;
            }
        } catch (Throwable) {
        }

        throw new RuntimeException('Datum evidencije prodaje mora biti stvaran datum GGGG-MM-DD.');
    }
}
