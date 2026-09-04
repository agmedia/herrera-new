<?php

namespace App\Services\Integrations\Msan;

final readonly class MsanTransportResponse
{
    public function __construct(
        public int $status,
        public ?string $contentType = null,
    ) {}
}
