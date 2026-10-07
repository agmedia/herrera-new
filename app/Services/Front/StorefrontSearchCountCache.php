<?php

namespace App\Services\Front;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class StorefrontSearchCountCache
{
    public function total(Builder $query, ?int $viewerId = null): int
    {
        // Cache only the aggregate. Cards, availability and customer prices are
        // still fetched for the current request and are never shared here.
        $baseQuery = $query->toBase()
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->cloneWithoutBindings(['select', 'order']);
        $seconds = max(0, min(120, (int) config('storefront-search.count_cache_seconds', 30)));

        if ($seconds === 0) {
            return (int) $baseQuery->getCountForPagination();
        }

        $connection = $baseQuery->getConnection();
        $key = 'front:search:count:v1:'.hash('sha256', serialize([
            $connection->getName(),
            $connection->getDatabaseName(),
            app()->getLocale(),
            $viewerId,
            $baseQuery->toSql(),
            $baseQuery->getBindings(),
        ]));

        $cached = Cache::get($key);
        if (is_int($cached)) {
            return $cached;
        }

        $remember = static fn (): int => (int) Cache::remember(
            $key, $seconds, static fn (): int => (int) $baseQuery->getCountForPagination()
        );

        try {
            // Coalesce simultaneous identical cold searches across workers.
            return (int) Cache::lock($key.':lock', 10)->block(1, $remember);
        } catch (LockTimeoutException) {
            return $remember();
        }
    }
}
