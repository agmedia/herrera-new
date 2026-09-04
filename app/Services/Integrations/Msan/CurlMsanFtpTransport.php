<?php

namespace App\Services\Integrations\Msan;

use RuntimeException;

class CurlMsanFtpTransport implements MsanFtpTransportInterface
{
    /**
     * Authenticates over explicit FTPS and lists the root while discarding its contents.
     */
    public function testConnection(
        string $username,
        #[\SensitiveParameter] string $password,
        int $connectTimeout,
        int $timeout,
        ?string $caPath,
        int $maxBytes,
    ): void {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('PHP cURL ekstenzija potrebna je za M SAN FTP vezu.');
        }

        $handle = curl_init($this->url('/'));
        if ($handle === false) {
            throw new RuntimeException('M SAN FTP vezu nije moguće pripremiti.');
        }

        try {
            curl_setopt_array($handle, $this->connectionOptions(
                username: $username,
                password: $password,
                connectTimeout: $connectTimeout,
                timeout: $timeout,
                caPath: $caPath,
            ) + [
                CURLOPT_DIRLISTONLY => true,
                CURLOPT_WRITEFUNCTION => static fn ($handle, string $data): int => strlen($data),
            ]);

            if (curl_exec($handle) !== true) {
                throw new RuntimeException('M SAN FTPS provjera veze nije uspjela.');
            }
        } finally {
            curl_setopt($handle, CURLOPT_USERPWD, ':');
            curl_close($handle);
            $password = '';
        }
    }

    /**
     * Uses explicit FTPS and never falls back to unencrypted FTP credentials.
     */
    public function downloadToFile(
        string $remotePath,
        string $destinationPath,
        string $username,
        #[\SensitiveParameter] string $password,
        int $connectTimeout,
        int $timeout,
        ?string $caPath,
        int $maxBytes,
    ): void {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('PHP cURL ekstenzija potrebna je za M SAN FTP dohvat.');
        }

        $destination = @fopen($destinationPath, 'wb');
        if (! is_resource($destination)) {
            throw new RuntimeException('Privremenu M SAN FTP datoteku nije moguće otvoriti.');
        }

        $handle = curl_init($this->url($remotePath));
        if ($handle === false) {
            fclose($destination);
            throw new RuntimeException('M SAN FTP vezu nije moguće pripremiti.');
        }

        try {
            curl_setopt_array($handle, $this->connectionOptions(
                username: $username,
                password: $password,
                connectTimeout: $connectTimeout,
                timeout: $timeout,
                caPath: $caPath,
            ) + [
                CURLOPT_FILE => $destination,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static function (
                    $handle,
                    float $downloadTotal,
                    float $downloadedBytes,
                    float $uploadTotal,
                    float $uploadedBytes,
                ) use ($maxBytes): int {
                    return (($downloadTotal > 0 && $downloadTotal > $maxBytes) || $downloadedBytes > $maxBytes) ? 1 : 0;
                },
            ]);

            if (curl_exec($handle) !== true) {
                throw new RuntimeException('M SAN FTPS prijenos nije uspio.');
            }
        } finally {
            curl_setopt($handle, CURLOPT_USERPWD, ':');
            curl_close($handle);
            fclose($destination);
            $password = '';
        }
    }

    /**
     * @return array<int, bool|int|string>
     */
    private function connectionOptions(
        string $username,
        #[\SensitiveParameter] string $password,
        int $connectTimeout,
        int $timeout,
        ?string $caPath,
    ): array {
        $options = [
            CURLOPT_USERPWD => $username.':'.$password,
            CURLOPT_CONNECTTIMEOUT => max(2, $connectTimeout),
            CURLOPT_TIMEOUT => max(15, $timeout),
            CURLOPT_PROTOCOLS => CURLPROTO_FTP,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FTP_USE_EPSV => true,
            CURLOPT_USERAGENT => 'Termol-MSAN-Connector/1.0',
            CURLOPT_FAILONERROR => true,
        ];

        if ($caPath !== null && $caPath !== '') {
            $options[CURLOPT_CAINFO] = $caPath;
        }

        return $options;
    }

    private function url(string $remotePath): string
    {
        $segments = array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', ltrim($remotePath, '/')),
        );

        return 'ftp://'.MsanClient::HOST.'/'.implode('/', $segments);
    }
}
