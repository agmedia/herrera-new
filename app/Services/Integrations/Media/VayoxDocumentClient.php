<?php

namespace App\Services\Integrations\Media;

use App\Services\Integrations\Stock\StockSyncSettingsService;
use DOMDocument;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/** Reads external document links from the supplier feed. PDF contents are never requested or stored. */
class VayoxDocumentClient
{
    public function __construct(private readonly StockSyncSettingsService $settings) {}

    public function links(): array
    {
        try {
            $connection = $this->settings->connection('vayox');
            $body = $this->feed((string) ($connection['url'] ?? ''));
            if (preg_match('/<!\s*(?:ENTITY|DOCTYPE)\b/i', $body)) {
                throw new RuntimeException;
            }
            $previous = libxml_use_internal_errors(true);
            try {
                $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA);
                if ($xml === false || $xml->getName() !== 'products') {
                    throw new RuntimeException;
                }
                $links = [];
                foreach ($xml->product as $product) {
                    $ean = trim((string) $product->ean);
                    if (! preg_match('/^(?:\d{8}|\d{12,14})$/', $ean)) {
                        continue;
                    }
                    foreach (['description', 'description_extra_1', 'description_extra_2'] as $field) {
                        $html = (string) $product->{$field};
                        if (trim($html) === '') {
                            continue;
                        }
                        $document = new DOMDocument;
                        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                        foreach ($document->getElementsByTagName('a') as $anchor) {
                            $url = self::safeDocumentUrl(trim($anchor->getAttribute('href')));
                            if ($url !== null) {
                                $label = mb_substr(trim(preg_replace('/\s+/u', ' ', $anchor->textContent)), 0, 191);
                                if (preg_match('/^(?:tutaj|here|click here|kliknij|pobierz)$/iu', $label)) {
                                    $label = '';
                                }
                                $links[$ean.':'.hash('sha256', $url)] = ['ean' => $ean, 'url' => $url,
                                    'label' => $label];
                            }
                        }
                    }
                }

                return array_values($links);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        } catch (Throwable) {
            // Never expose a transport exception containing the private feed URL.
            throw new RuntimeException('Dohvat Vayox poveznica nije uspio. Provjerite izvor dobavljača.');
        }
    }

    /** Pure validation for import and storefront rendering; never performs a network request. */
    public static function safeDocumentUrl(string $url): ?string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! in_array(strtolower($parts['host'] ?? ''), (array) config('supplier-documents.vayox.document_hosts'), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! str_ends_with(strtolower($parts['path'] ?? ''), '.pdf')) {
            return null;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)
            && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        // The live supplier path contains Polish letters; encode each segment
        // once while preserving separators and existing percent-encoded paths.
        $path = implode('/', array_map(fn ($segment) => rawurlencode(rawurldecode($segment)), explode('/', $parts['path'])));

        return 'https://'.strtolower($parts['host']).$path;
    }

    private function feed(string $url): string
    {
        $limit = (int) config('supplier-documents.vayox.feed_max_bytes');
        if ($limit < 1 || $limit > 20 * 1024 * 1024) {
            throw new RuntimeException;
        }
        $response = Http::connectTimeout(5)->timeout(30)->withOptions($this->publicOptions($url) + [
            'allow_redirects' => false, 'stream' => true, 'decode_content' => false,
        ])->withHeaders(['Accept-Encoding' => 'identity'])->get($url);
        $stream = $response->toPsrResponse()->getBody();
        try {
            if ($response->status() !== 200 || (int) $response->header('Content-Length') > $limit
                || ! in_array(strtolower((string) $response->header('Content-Encoding')), ['', 'identity'], true)) {
                throw new RuntimeException;
            }
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $bytes = '';
            while (! $stream->eof()) {
                $chunk = $stream->read(min(65536, $limit + 1 - strlen($bytes)));
                if ($chunk === '' && ! $stream->eof()) {
                    throw new RuntimeException;
                }
                $bytes .= $chunk;
                if (strlen($bytes) > $limit) {
                    throw new RuntimeException;
                }
            }
            if ($bytes === '') {
                throw new RuntimeException;
            }

            return $bytes;
        } finally {
            $stream->close();
        }
    }

    private function publicOptions(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host']) || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (app()->runningUnitTests() && str_ends_with($host, '.example.test')) {
            return [];
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            gethostbynamel($host) ?: [], array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );
        if ($addresses === []) {
            throw new RuntimeException;
        }
        foreach ($addresses as $address) {
            if (str_starts_with(strtolower($address), '::ffff:')
                || ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException;
            }
        }
        $address = $addresses[0];

        return ['curl' => [CURLOPT_RESOLVE => [$host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)]]];
    }
}
