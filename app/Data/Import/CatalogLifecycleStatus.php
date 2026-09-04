<?php

namespace App\Data\Import;

use InvalidArgumentException;

enum CatalogLifecycleStatus: string
{
    case Web = 'w';
    case Inactive = 'n';
    case Deleted = 'b';

    public static function normalize(self|string $status): self
    {
        if ($status instanceof self) {
            return $status;
        }

        $normalized = strtolower(trim($status));

        return self::tryFrom($normalized)
            ?? throw new InvalidArgumentException("Unsupported catalog lifecycle status [{$status}].");
    }

    public function isActive(): bool
    {
        return $this === self::Web;
    }

    public function isTombstone(): bool
    {
        return $this === self::Deleted;
    }
}
