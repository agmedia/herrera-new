<?php

namespace App\Services\Import;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use LogicException;

class HerreraBlogRelatedProductsService
{
    public const PAYLOAD_VERSION_KEY = 'herrera_blog_related_products_v1';

    public function __construct(private readonly SystemSettingsService $settings) {}

    /** Populate the existing editable relationship once, without changing article content. */
    public function apply(): int
    {
        if (! config('commerce.b2b_only')
            || ! str_contains(strtolower((string) $this->settings->get('store_brand_name', '')), 'herrera')) {
            throw new LogicException('Blog product setup requires the Herrera B2B store.');
        }

        return DB::transaction(function (): int {
            $updated = 0;
            foreach ((array) config('herrera-blog-related-products', []) as $postCode => $configuredCodes) {
                $post = BlogPost::query()->where('code', $postCode)->lockForUpdate()->first();
                if (! $post || data_get($post->payload, self::PAYLOAD_VERSION_KEY.'.applied_at')) {
                    continue;
                }

                $payload = is_array($post->payload) ? $post->payload : [];
                $existingIds = collect($payload['related_product_ids'] ?? [])
                    ->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values();
                $version = ['applied_at' => now()->toIso8601String(), 'previous_related_product_ids' => $payload['related_product_ids'] ?? null];
                if ($existingIds->isNotEmpty()) {
                    // Record the decision too, so a later manual clear stays cleared.
                    $payload[self::PAYLOAD_VERSION_KEY] = $version + ['mode' => 'preserved_manual'];
                    $post->update(['payload' => $payload]);

                    continue;
                }

                $codes = collect((array) $configuredCodes)->filter(fn ($code): bool => is_string($code) && $code !== '')
                    ->unique()->values();
                if ($codes->isEmpty()) {
                    continue;
                }
                $products = Product::query()->whereIn('code', $codes)->pluck('id', 'code');
                if ($products->count() !== $codes->count()) {
                    // Imported IDs differ by environment; retry after missing products arrive.
                    continue;
                }

                $payload['related_product_ids'] = $codes->map(fn (string $code): int => (int) $products[$code])->all();
                $payload[self::PAYLOAD_VERSION_KEY] = $version + ['mode' => 'curated', 'product_codes' => $codes->all()];
                $post->update(['payload' => $payload]);
                $updated++;
            }

            return $updated;
        });
    }
}
