<?php

namespace App\Services\Integrations\Msan;

interface MsanFtpTransportInterface
{
    public function testConnection(
        string $username,
        #[\SensitiveParameter] string $password,
        int $connectTimeout,
        int $timeout,
        ?string $caPath,
        int $maxBytes,
    ): void;

    public function downloadToFile(
        string $remotePath,
        string $destinationPath,
        string $username,
        #[\SensitiveParameter] string $password,
        int $connectTimeout,
        int $timeout,
        ?string $caPath,
        int $maxBytes,
    ): void;
}
