<?php

namespace App\Services\Sales;

/** Restricted image embedding for PDFs. Dompdf itself never accesses the network. */
class CustomerOfferImage
{
    /** @var array<string, ?string> */
    private array $resolved = [];

    private ?float $fetchStartedAt = null;

    public function beginRender(): void
    {
        $this->resolved = [];
        $this->fetchStartedAt = null;
    }

    public function dataUri(?string $url): ?string
    {
        if (! $url || mb_strlen($url) > 2000) {
            return null;
        }
        if (array_key_exists($url, $this->resolved)) {
            return $this->resolved[$url];
        }
        $this->resolved[$url] = null;
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || array_intersect(explode('/', $path), ['..', '.'])) {
            return null;
        }
        $bytes = null;
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (empty($parts['host']) || strcasecmp((string) $parts['host'], (string) $appHost) === 0) {
            $roots = [public_path(), storage_path('app/public')];
            if (str_starts_with($path, '/image/catalog/')) {
                $roots[] = public_path('image/catalog');
            }
            foreach ($roots as $root) {
                $relative = $root === storage_path('app/public') ? preg_replace('#^/storage/#', '/', $path) : $path;
                if ($root === public_path('image/catalog')) {
                    $relative = substr($path, strlen('/image/catalog/'));
                }
                $file = realpath($root.'/'.ltrim((string) $relative, '/'));
                $realRoot = realpath($root);
                if ($file && $realRoot && str_starts_with($file, $realRoot.'/') && is_file($file) && filesize($file) <= 4 * 1024 * 1024) {
                    $bytes = file_get_contents($file);
                    break;
                }
            }
        }
        $legacyOrigin = rtrim((string) config('legacy_media.origin'), '/');
        if ($bytes === null && config('legacy_media.enabled') && str_starts_with($url, $legacyOrigin.'/image/catalog/')
            && $this->validPublicOrigin($parts, $legacyOrigin)) {
            $bytes = $this->fetchKnownCatalogImage($url, (string) $parts['host']);
        }
        if (! is_string($bytes) || strlen($bytes) > 4 * 1024 * 1024 || ! ($size = @getimagesizefromstring($bytes))
            || $size[0] * $size[1] > 16000000 || ! in_array($size['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return null;
        }
        // Downsample in memory only, limiting document size. SVGs are excluded.
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return null;
        }
        $scale = min(1, 1000 / max($size[0], $size[1]));
        $resized = imagecreatetruecolor(max(1, (int) ($size[0] * $scale)), max(1, (int) ($size[1] * $scale)));
        imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $size[0], $size[1]);
        ob_start();
        imagejpeg($resized, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        imagedestroy($resized);

        return $this->resolved[$url] = 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    private function validPublicOrigin(array $parts, string $origin): bool
    {
        $configured = parse_url($origin);

        return ($parts['scheme'] ?? '') === 'https' && ! isset($parts['port']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ($parts['host'] ?? '') === ($configured['host'] ?? null)
            && ($configured['scheme'] ?? '') === 'https' && ! isset($configured['port']) && ! isset($configured['path']);
    }

    private function fetchKnownCatalogImage(string $url, string $host): ?string
    {
        $this->fetchStartedAt ??= microtime(true);
        $remaining = 15 - (microtime(true) - $this->fetchStartedAt);
        if ($remaining < 0.2) {
            return null;
        }
        // Pin a verified public IPv4 address to avoid DNS rebinding; no redirects,
        // cookies, credentials or arbitrary URLs. Each body is capped at 4 MiB.
        $addresses = gethostbynamel($host) ?: [];
        $ip = collect($addresses)->first(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
        if (! $ip) {
            return null;
        }
        $bytes = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RESOLVE => [$host.':443:'.$ip], CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT_MS => max(1, (int) (min(5, $remaining) * 1000)), CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'HerreraOfferPdf/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes): int {
                if (strlen($bytes) + strlen($chunk) > 4 * 1024 * 1024) {
                    return 0;
                }
                $bytes .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return $ok && $status === 200 ? $bytes : null;
    }
}
