<?php

namespace App\Services\Import;

use App\Models\Catalog\Product\Product;
use Illuminate\Support\Facades\DB;

class LegacyOrderQuantityBackfill
{
    public function run(bool $apply = false, array $productIds = []): array
    {
        $counts = ['candidates' => 0, 'changed' => 0, 'already_correct' => 0, 'manual_preserved' => 0, 'no_source' => 0];
        Product::query()->when($productIds !== [], fn ($query) => $query->whereIn('id', $productIds))->select('id')->chunkById(300, function ($products) use ($apply, &$counts): void {
            DB::transaction(function () use ($products, $apply, &$counts): void {
                foreach (Product::query()->whereKey($products->pluck('id'))->lockForUpdate()->get() as $product) {
                    $source = data_get($product->payload, 'opencart.minimum');
                    if (! is_scalar($source) || ! preg_match('/^[1-9]\d{0,5}$/D', (string) $source)) {
                        $counts['no_source']++;

                        continue;
                    }
                    $minimum = (int) $source;
                    $counts['candidates']++;
                    if ((int) $product->minimum_order_quantity === $minimum && (int) $product->order_quantity_step === $minimum) {
                        $counts['already_correct']++;

                        continue;
                    }
                    // Existing imports already copied minimum. Any difference
                    // can be a deliberate native edit and must be reviewed.
                    if ((int) $product->minimum_order_quantity !== $minimum || (int) $product->order_quantity_step !== 1
                        || data_get($product->payload, 'ordering_quantities.manual_override')) {
                        $counts['manual_preserved']++;

                        continue;
                    }
                    $counts['changed']++;
                    if ($apply) {
                        $payload = $product->payload ?? [];
                        $payload['ordering_quantities'] = ['source' => 'opencart.minimum', 'source_value' => $minimum, 'repaired_at' => now()->toIso8601String()];
                        $product->update(['minimum_order_quantity' => $minimum, 'order_quantity_step' => $minimum, 'payload' => $payload]);
                    }
                }
            });
        });

        return $counts + ['applied' => $apply];
    }
}
