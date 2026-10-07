<?php

namespace App\Services\Integrations\Media;

use App\Services\Integrations\Stock\BraytronFeedClient;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class LegacyProductImageClient
{
    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly StockSyncSettingsService $settings, private readonly BraytronFeedClient $braytron) {}

    /** ProductImageGet returns the default FileAttachment, whose contents are base64. */
    public function eracuni(string $identifier): ?array
    {
        if (! $this->settings->configured('eracuni') || $identifier === '' || mb_strlen($identifier) > 120
            || preg_match('/[\x00-\x1f\x7f"\\\\]/', $identifier)) {
            throw new RuntimeException('Dohvat ERP slike nije konfiguriran ili šifra nije valjana.');
        }
        $connection = $this->settings->connection('eracuni');
        $url = trim((string) ($connection['url'] ?? ''));
        $parts = parse_url($url);
        $suffix = trim((string) ($connection['url_image_suffix'] ?? 'ProductImageGet'), '/');
        if (! is_array($parts) || ! isset($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || $suffix !== 'ProductImageGet') {
            throw new RuntimeException('ERP izvor slika nije valjan.');
        }
        $url = preg_replace('~/(?:WarehouseGetArticleStockQuantity|ProductList|ProductImageGet)/?$~', '', rtrim($url, '/')).'/'.$suffix;
        try {
            $response = Cache::lock('herrera-eracuni-api', 90)->block(10, function () use ($url, $connection, $identifier) {
                if (! app()->runningUnitTests()) {
                    $delay = max(0, (float) Cache::get('herrera-eracuni-api-next-request', 0) - microtime(true));
                    if ($delay > 0) {
                        usleep((int) min(1000000, $delay * 1000000));
                    }
                }
                try {
                    return Http::connectTimeout(10)->timeout(60)
                        ->withOptions(['allow_redirects' => false, 'decode_content' => false, 'progress' => $this->progress(12 * 1024 * 1024)])
                        ->withHeaders(['Accept-Encoding' => 'identity'])->acceptJson()->asForm()
                        ->withBasicAuth((string) $connection['username'], $connection['token'].'_'.$connection['password'])
                        ->post($url, ['productCode' => '"'.$identifier.'"']);
                } finally {
                    Cache::put('herrera-eracuni-api-next-request', microtime(true) + 1, 180);
                }
            });
            if (! $response->successful() || strlen($response->body()) > 12 * 1024 * 1024) {
                throw new RuntimeException;
            }
            $data = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
            $envelope = $data['response'] ?? $data;
            if (! is_array($data) || ! is_array($envelope)) {
                throw new RuntimeException;
            }
            foreach ([$data, $envelope] as $state) {
                if (! empty($state['error']) || ! empty($state['errors']) || ! empty($state['fault'])
                    || ($state['success'] ?? null) === false || ($state['complete'] ?? null) === false
                    || in_array(strtolower((string) ($state['status'] ?? '')), ['error', 'failed', 'failure'], true)) {
                    throw new RuntimeException;
                }
            }
            if (! array_key_exists('result', $envelope)) {
                throw new RuntimeException;
            }
            $attachment = $envelope['result'];
            if ($attachment === null || $attachment === []) {
                return null;
            }
            if (is_array($attachment) && array_is_list($attachment)) {
                if (count($attachment) !== 1) {
                    throw new RuntimeException;
                }
                $attachment = $attachment[0];
            }
            if (! is_array($attachment)) {
                throw new RuntimeException;
            }
            $attachment = $attachment['FileAttachment'] ?? $attachment['Attachment'] ?? $attachment;
            if (! is_array($attachment) || ! is_string($attachment['contents'] ?? null)) {
                throw new RuntimeException;
            }
            $bytes = base64_decode($attachment['contents'], true);
            if ($bytes === false) {
                throw new RuntimeException;
            }

            return $this->image($bytes);
        } catch (Throwable) {
            throw new RuntimeException('ERP sliku nije moguće preuzeti ili datoteka nije valjana.');
        }
    }

    /** @return array<string, array<int, string>> Private URLs remain in memory only. */
    public function braytronImages(): array
    {
        $body = $this->braytron->body();
        if (preg_match('/<!\s*ENTITY\b/i', $body) || preg_match('/<!\s*DOCTYPE\b[^>]*\[/i', $body)) {
            throw new RuntimeException('Braytron popis slika nije valjan.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA);
            if ($xml === false || $xml->getName() !== 'Stoklar') {
                throw new RuntimeException;
            }
            $result = [];
            foreach ($xml->Stok as $row) {
                $identifier = trim((string) $row->ProductCode);
                if ($identifier === '' || mb_strlen($identifier) > 120) {
                    continue;
                }
                $images = [];
                for ($index = 2; $index <= 10; $index++) {
                    $url = trim((string) $row->{'Image'.$index});
                    if ($url !== '') {
                        $images[] = $url;
                    }
                }
                $images = array_values(array_unique($images));
                $key = 'id:'.$identifier;
                if (isset($result[$key]) && $result[$key] !== $images) {
                    throw new RuntimeException;
                }
                $result[$key] = $images;
            }

            return $result;
        } catch (Throwable) {
            throw new RuntimeException('Braytron popis slika nije valjan.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function download(string $url): array
    {
        try {
            $options = $this->publicUrlOptions($url);
            $response = Http::connectTimeout(5)->timeout(15)
                ->withOptions($options + ['allow_redirects' => false, 'progress' => $this->progress(self::MAX_IMAGE_BYTES)])
                ->get($url);
            if (! $response->successful()) {
                throw new RuntimeException;
            }

            return $this->image($response->body());
        } catch (Throwable) {
            throw new RuntimeException('Slika dobavljača nije dostupna ili datoteka nije valjana.');
        }
    }

    private function image(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', default => null,
        };
        $dimensions = @getimagesizefromstring($bytes);
        if ($extension === null || $dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1
            || $dimensions[0] > 10000 || $dimensions[1] > 10000 || $dimensions[0] * $dimensions[1] > 40000000) {
            throw new RuntimeException;
        }

        return ['bytes' => $bytes, 'extension' => $extension, 'sha256' => hash('sha256', $bytes)];
    }

    private function publicUrlOptions(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true))) {
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
            if (str_starts_with(strtolower($address), '::ffff:')) {
                throw new RuntimeException;
            }
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException;
            }
        }
        $address = $addresses[0];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);

        return ['curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)]]];
    }

    private function progress(int $limit): \Closure
    {
        return static function ($total, $downloaded) use ($limit): void {
            if ($total > $limit || $downloaded > $limit) {
                throw new RuntimeException('Datoteka je prevelika.');
            }
        };
    }
}
