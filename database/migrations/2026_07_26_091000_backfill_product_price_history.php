<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->select(['id', 'base_price', 'created_at'])
            ->orderBy('id')
            ->chunkById(500, function ($products): void {
                $productIds = $products->pluck('id')->map(static fn ($id): int => (int) $id);
                $existingIds = DB::table('catalog_product_price_history')
                    ->whereIn('product_id', $productIds)
                    ->where('price_type', 'base')
                    ->where('source', 'migration_baseline')
                    ->pluck('product_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                $now = now();
                $rows = $products
                    ->reject(static fn ($product): bool => in_array((int) $product->id, $existingIds, true))
                    ->map(static fn ($product): array => [
                        'product_id' => (int) $product->id,
                        'product_option_value_id' => null,
                        'customer_group_id' => null,
                        'product_package_id' => null,
                        'price_type' => 'base',
                        'old_price' => null,
                        'new_price' => $product->base_price,
                        'currency_code' => 'EUR',
                        'effective_at' => $product->created_at ?? $now,
                        'source' => 'migration_baseline',
                        'changed_by' => null,
                        'payload' => json_encode(['event' => 'baseline'], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->values()
                    ->all();

                if ($rows !== []) {
                    DB::table('catalog_product_price_history')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('catalog_product_price_history')
            ->where('price_type', 'base')
            ->where('source', 'migration_baseline')
            ->delete();
    }
};
