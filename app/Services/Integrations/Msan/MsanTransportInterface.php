<?php

namespace App\Services\Integrations\Msan;

interface MsanTransportInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function sendToFile(
        string $method,
        string $url,
        string $destinationPath,
        #[\SensitiveParameter] array $options,
    ): MsanTransportResponse;
}
