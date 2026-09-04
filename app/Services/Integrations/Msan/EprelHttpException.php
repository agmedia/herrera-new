<?php

namespace App\Services\Integrations\Msan;

class EprelHttpException extends EprelException
{
    public function __construct(
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public function isServerError(): bool
    {
        return $this->status >= 500 && $this->status <= 599;
    }
}
