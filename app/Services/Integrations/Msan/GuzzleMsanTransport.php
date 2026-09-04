<?php

namespace App\Services\Integrations\Msan;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

class GuzzleMsanTransport implements MsanTransportInterface
{
    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client;
    }

    public function sendToFile(
        string $method,
        string $url,
        string $destinationPath,
        #[\SensitiveParameter] array $options,
    ): MsanTransportResponse {
        if (! extension_loaded('curl')) {
            throw new \RuntimeException('PHP cURL ekstenzija potrebna je za M SAN mTLS vezu.');
        }

        $certificatePath = (string) ($options['certificate_path'] ?? '');
        $certificatePin = (string) ($options['certificate_pin'] ?? '');
        $caPath = (string) ($options['ca_path'] ?? '');
        $maxBytes = max(1, (int) ($options['max_bytes'] ?? 256 * 1024 * 1024));
        if ($certificatePath === '' || $certificatePin === '') {
            throw new \RuntimeException('M SAN mTLS vjerodajnice nisu potpune.');
        }

        $requestOptions = [
            'allow_redirects' => false,
            'connect_timeout' => max(2, (int) ($options['connect_timeout'] ?? 15)),
            'timeout' => max(15, (int) ($options['timeout'] ?? 120)),
            'http_errors' => false,
            'verify' => $caPath !== '' ? $caPath : true,
            'sink' => $destinationPath,
            'headers' => (array) ($options['headers'] ?? []),
            'query' => (array) ($options['query'] ?? []),
            'progress' => static function (int $downloadTotal, int $downloadedBytes) use ($maxBytes): void {
                if (($downloadTotal > 0 && $downloadTotal > $maxBytes) || $downloadedBytes > $maxBytes) {
                    throw new \RuntimeException('M SAN odgovor prelazi dopuštenu veličinu.');
                }
            },
            'curl' => [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSLCERT => $certificatePath,
                CURLOPT_SSLCERTTYPE => 'P12',
                CURLOPT_KEYPASSWD => $certificatePin,
            ],
        ];

        if ($caPath !== '') {
            $requestOptions['curl'][CURLOPT_CAINFO] = $caPath;
        }

        if (array_key_exists('body', $options)) {
            $requestOptions['body'] = (string) $options['body'];
        }

        try {
            $response = $this->client->request(strtoupper($method), $url, $requestOptions);
        } catch (Throwable) {
            throw new \RuntimeException('M SAN transportni zahtjev nije uspio.');
        } finally {
            $requestOptions['curl'][CURLOPT_KEYPASSWD] = '';
            $certificatePin = '';
        }

        return new MsanTransportResponse(
            status: $response->getStatusCode(),
            contentType: $response->getHeaderLine('Content-Type') ?: null,
        );
    }
}
