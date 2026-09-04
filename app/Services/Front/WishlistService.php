<?php

namespace App\Services\Front;

use App\Models\Catalog\Product\Product;
use App\Models\User\WishlistItem;
use App\Support\ProductMaterialLabel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class WishlistService
{
    private const SESSION_KEY = 'front.wishlist.product_ids';

    /** @var array<string, array<int, int>> */
    private static array $idsCache = [];

    /**
     * @return array<int, int>
     */
    public function ids(): array
    {
        $userId = (int) (Auth::id() ?? 0);
        $sessionCacheKey = 'guest:'.sha1(json_encode($this->sessionIds()));

        if ($userId > 0) {
            $cacheKey = 'user:'.$userId;
            if (isset(self::$idsCache[$cacheKey])) {
                return self::$idsCache[$cacheKey];
            }

            $this->syncSessionToUser($userId);

            self::$idsCache[$cacheKey] = WishlistItem::query()
                ->where('user_id', $userId)
                ->orderByDesc('id')
                ->pluck('product_id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();

            return self::$idsCache[$cacheKey];
        }

        if (isset(self::$idsCache[$sessionCacheKey])) {
            return self::$idsCache[$sessionCacheKey];
        }

        self::$idsCache[$sessionCacheKey] = $this->sessionIds();

        return self::$idsCache[$sessionCacheKey];
    }

    /**
     * @return array<int, int>
     */
    private function sessionIds(): array
    {
        $ids = Session::get(self::SESSION_KEY, []);
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * @return array<int, bool>
     */
    public function map(): array
    {
        return array_fill_keys($this->ids(), true);
    }

    /**
     * @return array{item_count:int}
     */
    public function summary(): array
    {
        return [
            'item_count' => count($this->ids()),
        ];
    }

    public function has(int $productId): bool
    {
        return in_array($productId, $this->ids(), true);
    }

    public function add(Product $product): bool
    {
        if (! $product->is_active) {
            return false;
        }

        $productId = (int) $product->id;
        $userId = (int) (Auth::id() ?? 0);

        if ($userId > 0) {
            WishlistItem::query()->firstOrCreate([
                'user_id' => $userId,
                'product_id' => $productId,
            ]);
            $this->flushCache();

            return true;
        }

        $ids = $this->sessionIds();
        if (! in_array($productId, $ids, true)) {
            $ids[] = $productId;
        }
        Session::put(self::SESSION_KEY, $ids);
        $this->flushCache();

        return true;
    }

    public function remove(int $productId): void
    {
        $productId = (int) $productId;
        $userId = (int) (Auth::id() ?? 0);

        if ($userId > 0) {
            WishlistItem::query()
                ->where('user_id', $userId)
                ->where('product_id', $productId)
                ->delete();
            $this->flushCache();

            return;
        }

        $ids = array_values(array_filter($this->sessionIds(), static fn (int $id): bool => $id !== $productId));
        Session::put(self::SESSION_KEY, $ids);
        $this->flushCache();
    }

    public function clear(): void
    {
        $userId = (int) (Auth::id() ?? 0);
        if ($userId > 0) {
            WishlistItem::query()->where('user_id', $userId)->delete();
        }

        Session::forget(self::SESSION_KEY);
        $this->flushCache();
    }

    /**
     * @return Collection<int, Product>
     */
    public function products(?string $locale = null): Collection
    {
        $ids = $this->ids();
        if ($ids === []) {
            return collect();
        }

        $locale = $locale ?: app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $orderMap = array_flip($ids);

        return Product::query()
            ->withStorefrontEnergyData()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'manufacturer.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'attributes' => ProductMaterialLabel::eagerLoadAttributes($locale, $fallbackLocale),
                'media' => fn ($q) => $q
                    ->whereIn('collection_name', ['product_main', 'product_gallery'])
                    ->orderBy('order_column')
                    ->orderBy('id'),
                'optionValues' => fn ($q) => $q
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with([
                        'optionValue.translations' => fn ($tq) => $tq->whereIn('locale', [$locale, $fallbackLocale]),
                        'parentOptionValue.translations' => fn ($tq) => $tq->whereIn('locale', [$locale, $fallbackLocale]),
                    ]),
            ])
            ->get()
            ->sortBy(static fn (Product $product): int => (int) ($orderMap[(int) $product->id] ?? PHP_INT_MAX))
            ->values();
    }

    private function syncSessionToUser(int $userId): void
    {
        $sessionIds = $this->sessionIds();
        if ($sessionIds === []) {
            return;
        }

        $existing = WishlistItem::query()
            ->where('user_id', $userId)
            ->whereIn('product_id', $sessionIds)
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $existingMap = array_fill_keys($existing, true);

        $insertRows = [];
        $now = now();
        foreach ($sessionIds as $productId) {
            if (isset($existingMap[$productId])) {
                continue;
            }

            $insertRows[] = [
                'user_id' => $userId,
                'product_id' => $productId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($insertRows !== []) {
            WishlistItem::query()->insert($insertRows);
        }

        Session::forget(self::SESSION_KEY);
        $this->flushCache();
    }

    private function flushCache(): void
    {
        self::$idsCache = [];
    }
}
