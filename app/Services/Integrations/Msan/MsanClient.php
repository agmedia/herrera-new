<?php

namespace App\Services\Integrations\Msan;

use App\Models\Integrations\Msan\MsanEndpointState;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use XMLWriter;

class MsanClient
{
    public const HOST = 'b2b.msan.hr';

    private const PRODUCT_SERVICE_URL = 'https://b2b.msan.hr/B2BService/B2BProductService.asmx';

    private const SOAP_NAMESPACE = 'http://www.msan.hr/B2B/';

    /**
     * Supplier limits from the M SAN specification. A failed request still
     * consumes the window because it may have reached the supplier service.
     * Endpoints without a documented cooldown are serialized and audited,
     * but may be called again immediately.
     *
     * @var array<string, int>
     */
    private const DATASET_COOLDOWNS = [
        'catalog' => 600,
        'prices' => 600,
        'availability' => 600,
        'specifications' => 3600,
    ];

    /** @var array<string, int> */
    private const DOWNLOAD_LIMITS = [
        'categories' => 64 * 1024 * 1024,
        'catalog' => 512 * 1024 * 1024,
        'prices' => 256 * 1024 * 1024,
        'availability' => 256 * 1024 * 1024,
        'specifications' => 1024 * 1024 * 1024,
        'specifications_icecat' => 1024 * 1024 * 1024,
        'product_categories' => 256 * 1024 * 1024,
        'barcodes' => 256 * 1024 * 1024,
        'product_image' => 16 * 1024 * 1024,
    ];

    /** @var array<string, array{method: string, url?: string, query?: array<string, scalar>, soap_action?: string, soap_parameters?: array<string, scalar>}> */
    private const DATASETS = [
        'categories' => [
            'method' => 'GET',
            'url' => 'https://b2b.msan.hr/B2BService/HTTP/Product/GetCategoriesList.aspx',
            'query' => ['CategoryTypeID' => 1],
        ],
        'catalog' => [
            'method' => 'GET',
            'url' => 'https://b2b.msan.hr/B2BService/HTTP/Product/GetProductsList.aspx',
        ],
        'prices' => [
            'method' => 'GET',
            'url' => 'https://b2b.msan.hr/B2BService/HTTP/Product/GetProductsPriceList.aspx',
        ],
        'availability' => [
            'method' => 'GET',
            'url' => 'https://b2b.msan.hr/B2BService/HTTP/Product/GetProductsAvailability.aspx',
        ],
        'specifications' => [
            'method' => 'GET',
            'url' => 'https://b2b.msan.hr/B2BService/HTTP/Product/GetProductsSpecification.aspx',
        ],
        'specifications_icecat' => [
            'method' => 'POST',
            'soap_action' => 'GetProductsSpecificationIceCat',
            'soap_parameters' => ['ProductCode' => '', 'ProductType' => ''],
        ],
        'product_categories' => [
            'method' => 'POST',
            'soap_action' => 'GetProductsCategory',
            'soap_parameters' => ['CategoryTypeID' => 1, 'ProductCode' => ''],
        ],
        'barcodes' => [
            'method' => 'POST',
            'soap_action' => 'GetProductsBarcodes',
            'soap_parameters' => ['ProductCode' => ''],
        ],
    ];

    private MsanTransportInterface $transport;

    public function __construct(
        private readonly MsanSettingsService $settings,
        private readonly MsanCertificateService $certificates,
        ?MsanTransportInterface $transport = null,
    ) {
        $this->transport = $transport ?? new GuzzleMsanTransport;
    }

    /**
     * Streams a supported M SAN XML dataset to the requested local destination.
     */
    public function downloadDataset(string $dataset, string $destinationPath): void
    {
        $dataset = strtolower(trim($dataset));
        $definition = self::DATASETS[$dataset] ?? null;
        if (! is_array($definition)) {
            throw new InvalidArgumentException('Nepoznat M SAN dataset.');
        }

        $method = $definition['method'];
        $url = $definition['url'] ?? self::PRODUCT_SERVICE_URL;
        $headers = [
            'Accept' => 'application/xml, text/xml;q=0.9',
            'User-Agent' => 'Termol-MSAN-Connector/1.0',
        ];
        $body = null;

        if (isset($definition['soap_action'])) {
            $action = $definition['soap_action'];
            $headers['Content-Type'] = 'text/xml; charset=utf-8';
            $headers['SOAPAction'] = '"'.self::SOAP_NAMESPACE.$action.'"';
            $body = $this->soapEnvelope($action, $definition['soap_parameters'] ?? []);
        }

        $endpoint = $dataset === 'specifications_icecat' ? 'specifications' : $dataset;

        $this->withEndpointGuard($endpoint, function () use (
            $dataset,
            $method,
            $url,
            $destinationPath,
            $definition,
            $headers,
            $body,
        ): void {
            $this->downloadToFile(
                operation: $dataset,
                method: $method,
                url: $url,
                destinationPath: $destinationPath,
                query: $definition['query'] ?? [],
                headers: $headers,
                body: $body,
                expectXml: true,
            );
        });
    }

    /**
     * Makes one authenticated, streamed request without returning response contents.
     *
     * @return array{ok: true, host: string, certificate: array{fingerprint: string, subject: string, issuer: string, valid_until: string}, checked_at: string}
     */
    public function testConnection(): array
    {
        $path = Storage::disk('local')->path(
            'integrations/msan/connectivity/categories-'.Str::uuid().'.xml'
        );

        try {
            $this->downloadDataset('categories', $path);
            $metadata = $this->certificates->currentMetadata();
            if ($metadata === null) {
                throw new RuntimeException('M SAN certifikat nije postavljen.');
            }

            return [
                'ok' => true,
                'host' => self::HOST,
                'certificate' => $metadata,
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        } finally {
            @unlink($path);
        }
    }

    /**
     * Downloads a catalog image locally through the authenticated M SAN connection.
     * The original URL is never returned for storefront hotlinking.
     */
    public function downloadProductImage(string $imageUrl, string $destinationPath): void
    {
        $this->assertAllowedImageUrl($imageUrl);

        $this->downloadToFile(
            operation: 'product_image',
            method: 'GET',
            url: $imageUrl,
            destinationPath: $destinationPath,
            headers: [
                'Accept' => 'image/jpeg, image/png, image/webp, image/gif, application/octet-stream;q=0.5',
                'User-Agent' => 'Termol-MSAN-Connector/1.0',
            ],
            expectXml: false,
        );
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     */
    private function downloadToFile(
        string $operation,
        string $method,
        string $url,
        string $destinationPath,
        array $query = [],
        array $headers = [],
        ?string $body = null,
        bool $expectXml = true,
    ): void {
        $this->settings->assertEnabled();

        $destinationPath = $this->absoluteDestinationPath($destinationPath);
        $directory = dirname($destinationPath);
        if ((! is_dir($directory) && ! @mkdir($directory, 0750, true)) || ! is_writable($directory)) {
            throw new RuntimeException('Odredišni direktorij za M SAN datoteku nije zapisiv.');
        }
        if (is_link($destinationPath)) {
            throw new RuntimeException('Odredišna M SAN datoteka ne smije biti simbolička poveznica.');
        }

        $temporaryPath = $destinationPath.'.part-'.Str::uuid();
        $options = [
            'certificate_path' => $this->certificates->absolutePath(),
            'certificate_pin' => $this->settings->p12Pin(),
            'ca_path' => $this->certificates->caAbsolutePath(),
            'connect_timeout' => $this->settings->connectTimeout(),
            'timeout' => str_starts_with($operation, 'specifications')
                ? $this->settings->specificationsTimeout()
                : $this->settings->requestTimeout(),
            'headers' => $headers,
            'query' => $query,
            'max_bytes' => self::DOWNLOAD_LIMITS[$operation] ?? 256 * 1024 * 1024,
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->transport->sendToFile($method, $url, $temporaryPath, $options);
        } catch (Throwable) {
            @unlink($temporaryPath);
            throw new RuntimeException('M SAN '.$operation.' zahtjev nije uspio zbog transportne greške.');
        } finally {
            $options['certificate_pin'] = '';
        }

        if ($response->status < 200 || $response->status >= 300) {
            @unlink($temporaryPath);
            throw new RuntimeException(sprintf(
                'M SAN %s zahtjev nije uspio (HTTP %d).',
                $operation,
                $response->status,
            ));
        }

        if (! is_file($temporaryPath) || filesize($temporaryPath) === 0) {
            @unlink($temporaryPath);
            throw new RuntimeException('M SAN '.$operation.' odgovor je prazan.');
        }

        if ($expectXml && ! $this->looksLikeXml($temporaryPath, $response->contentType)) {
            @unlink($temporaryPath);
            throw new RuntimeException('M SAN '.$operation.' odgovor nije XML dokument.');
        }

        if (is_link($destinationPath) || ! @rename($temporaryPath, $destinationPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('M SAN '.$operation.' datoteku nije moguće atomarno spremiti.');
        }

        @chmod($destinationPath, 0600);
    }

    /** @param callable(): void $callback */
    private function withEndpointGuard(string $dataset, callable $callback): void
    {
        /** @var Lock $lock */
        $lock = Cache::lock(
            'integrations:msan:endpoint:'.$dataset,
            max(60, ($dataset === 'specifications'
                ? $this->settings->specificationsTimeout()
                : $this->settings->requestTimeout()) + 60),
        );

        if (! $lock->get()) {
            throw new RuntimeException('M SAN '.$dataset.' dohvat je već u tijeku.');
        }

        try {
            $state = MsanEndpointState::query()->firstOrCreate(['endpoint' => $dataset]);
            $now = now();

            if ($state->next_allowed_at?->isFuture()) {
                $remainingSeconds = max(1, (int) ceil($now->diffInSeconds($state->next_allowed_at)));

                throw new RuntimeException(sprintf(
                    'M SAN %s endpoint bit će ponovno dostupan za %d sekundi.',
                    $dataset,
                    $remainingSeconds,
                ));
            }

            $cooldown = self::DATASET_COOLDOWNS[$dataset] ?? 0;
            $state->forceFill([
                'last_attempt_at' => $now,
                'next_allowed_at' => $cooldown > 0 ? $now->copy()->addSeconds($cooldown) : null,
                'last_error' => null,
                'metadata' => ['cooldown_seconds' => $cooldown],
            ])->save();

            try {
                $callback();
            } catch (Throwable $exception) {
                $state->forceFill([
                    'last_error' => $this->sanitizeError($exception->getMessage()),
                ])->save();

                throw $exception;
            }

            $state->forceFill([
                'last_success_at' => now(),
                'last_error' => null,
            ])->save();
        } finally {
            $lock->release();
        }
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/(password|passphrase|pin)\s*[=:]\s*\S+/iu', '$1=[skriveno]', $message) ?? $message;

        return mb_substr(trim($message), 0, 1500);
    }

    /**
     * @param  array<string, scalar>  $parameters
     */
    private function soapEnvelope(string $action, array $parameters): string
    {
        $writer = new XMLWriter;
        if (! $writer->openMemory()) {
            throw new RuntimeException('M SAN SOAP zahtjev nije moguće pripremiti.');
        }

        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElementNS('soap', 'Envelope', 'http://schemas.xmlsoap.org/soap/envelope/');
        $writer->startElementNS('soap', 'Body', null);
        $writer->startElementNS(null, $action, self::SOAP_NAMESPACE);
        foreach ($parameters as $name => $value) {
            $writer->writeElement($name, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }
        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    private function assertAllowedImageUrl(string $imageUrl): void
    {
        $parts = parse_url($imageUrl);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== self::HOST
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new InvalidArgumentException('Slika se može preuzeti samo s HTTPS M SAN B2B hosta.');
        }
    }

    private function absoluteDestinationPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Odredišna putanja M SAN datoteke nije valjana.');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
        ) {
            return $path;
        }

        return Storage::disk('local')->path(ltrim($path, '/\\'));
    }

    private function looksLikeXml(string $path, ?string $contentType): bool
    {
        if ($contentType !== null && str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return false;
        }

        try {
            $prefix = fread($handle, 1024 * 1024);
        } finally {
            fclose($handle);
        }

        if (! is_string($prefix)) {
            return false;
        }

        $prefix = preg_replace('/^\xEF\xBB\xBF/', '', $prefix) ?? $prefix;

        $prefix = ltrim($prefix);
        if (! str_starts_with($prefix, '<')) {
            return false;
        }

        return preg_match('/<(?:[A-Za-z0-9_-]+:)?(?:html|Fault)\b/i', $prefix) !== 1;
    }
}
