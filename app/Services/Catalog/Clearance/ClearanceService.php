<?php

namespace App\Services\Catalog\Clearance;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductClearance;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Catalog\Product\ProductPriceHistory;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class ClearanceService
{
    public const INACTIVITY_DAYS = 100;

    public const DISCOUNT_PERCENT = 60;

    public function settings(): array
    {
        return array_replace(['enabled' => false, 'category_id' => '', 'inactivity_days' => self::INACTIVITY_DAYS,
            'discount_percent' => self::DISCOUNT_PERCENT], (array) app(SystemSettingsService::class)->get('catalog_automatic_clearance', []));
    }

    public function saveSettings(array $settings): void
    {
        $settings = Validator::make(array_replace($this->settings(), $settings), [
            'enabled' => 'required|boolean',
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('scope', 'catalog')],
            'inactivity_days' => 'required|integer|min:1|max:365',
            'discount_percent' => 'required|integer|min:1|max:99',
        ], [], ['inactivity_days' => 'broj dana bez prodaje', 'discount_percent' => 'postotak popusta'])->validate();
        $settings['inactivity_days'] = (int) $settings['inactivity_days'];
        $settings['discount_percent'] = (float) $settings['discount_percent'];
        $salesState = app(CompanySalesClearanceSync::class)->state();
        $freshApi = ($salesState['status'] ?? null) === 'completed' && ($salesState['as_of'] ?? null) === today()->toDateString();
        if ($settings['enabled'] && ! $this->settings()['enabled'] && ! $freshApi && ! ProductClearance::query()->where('confirmed_company_scope', true)->whereDate('snapshot_date', today())->exists()) {
            throw new RuntimeException('Prije uključivanja potreban je svjež potvrđen izvještaj ukupnih izlaza iz e-Računa. Prodaja samo s webshopa nije dovoljna.');
        }
        Cache::lock('catalog:automatic-clearance', 600)->block(1, function () use ($settings): void {
            app(SystemSettingsService::class)->clearRuntimeCache();
            $previous = $this->settings();
            DB::transaction(function () use ($settings, $previous): void {
                if ((int) $previous['inactivity_days'] !== $settings['inactivity_days']) {
                    // A report fetched for a different rule must not silently
                    // open new discounts before a fresh ERP scan/import.
                    ProductClearance::query()->where('status', 'observed')->update(['confirmed_company_scope' => false]);
                }
                if ((float) $previous['discount_percent'] !== $settings['discount_percent']) {
                    ProductClearance::query()->where('status', 'active')->select(['id', 'product_id'])
                        ->chunkById(50, function ($rows) use ($settings, $previous): void {
                            foreach ($rows as $row) {
                                $product = Product::query()->whereKey($row->product_id)->lockForUpdate()->first();
                                if (! $product) {
                                    continue;
                                }
                                $payload = $product->payload ?? [];
                                $payload['clearance']['discount_percent'] = $settings['discount_percent'];
                                $product->updateQuietly(['payload' => $payload]);
                                $base = $this->fullBase($product);
                                $newPrice = $base * (1 - $settings['discount_percent'] / 100);
                                $this->recordPrice($product, $base * (1 - $previous['discount_percent'] / 100), $newPrice, 'settings_changed', $settings);
                                ProductGroupPrice::query()->where('product_id', $product->id)->where('is_active', true)
                                    ->where('payload->source', 'automatic_clearance')
                                    ->update(['price' => $newPrice]);
                            }
                        });
                }
                app(SystemSettingsService::class)->put('catalog_automatic_clearance', $settings);
            });
            app(SystemSettingsService::class)->flush();
        });
        CartService::forgetRequestSnapshot();
        app(\App\Services\Front\GuestStorefrontCache::class)->invalidate();
    }

    public function inactivityDays(): int
    {
        return (int) $this->settings()['inactivity_days'];
    }

    public function discountPercent(): float
    {
        return (float) $this->settings()['discount_percent'];
    }

    public function actionCode(): string
    {
        return 'B2B'.rtrim(rtrim(number_format($this->discountPercent(), 2, '.', ''), '0'), '.');
    }

    public function price(Product $product, ?float $base = null): ?float
    {
        if (data_get($product->payload, 'clearance.status') !== 'active' || (int) $product->stock_qty <= 0) {
            return null;
        }

        return max(0.0, $this->fullBase($product, $base) * (1 - $this->discountPercent() / 100));
    }

    public function fullBase(Product $product, ?float $fallback = null): float
    {
        return app(\App\Services\Pricing\FullWholesalePriceResolver::class)->base($product, $fallback);
    }

    /** A dry run never creates categories, groups, prices or product flags. */
    public function run(bool $apply = false, ?string $requiredSourceFingerprint = null): array
    {
        return Cache::lock('catalog:automatic-clearance', 600)->block(1, function () use ($apply, $requiredSourceFingerprint): array {
            app(SystemSettingsService::class)->clearRuntimeCache();
            $settings = $this->settings();
            if (! $settings['enabled']) {
                return ['eligible' => 0, 'activated' => 0, 'retired' => 0, 'stale_or_unproven' => 0, 'disabled' => true, 'applied' => $apply];
            }
            $category = Category::query()->where('scope', 'catalog')->find($settings['category_id']);
            if (! $category) {
                throw new RuntimeException('Odaberite postojeću kategoriju Rasprodaja prije obrade.');
            }

            $counts = ['eligible' => 0, 'activated' => 0, 'retired' => 0, 'stale_or_unproven' => 0];
            $salesState = app(CompanySalesClearanceSync::class)->state();
            $verifiedZero = ($salesState['status'] ?? null) === 'completed' && ($salesState['as_of'] ?? null) === today()->toDateString()
                && ($requiredSourceFingerprint === null || ($salesState['fingerprint'] ?? null) === $requiredSourceFingerprint)
                ? ($salesState['zero_stock_product_proofs'] ?? []) : [];
            ProductClearance::query()->whereIn('status', ['observed', 'active'])->select('id')->chunkById(200, function ($rows) use ($apply, $category, $requiredSourceFingerprint, $verifiedZero, &$counts): void {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $apply, $category, $requiredSourceFingerprint, $verifiedZero, &$counts): void {
                        $observed = ProductClearance::query()->find($row->id);
                        $product = $observed ? Product::query()->lockForUpdate()->find($observed->product_id) : null;
                        if (! $product) {
                            return;
                        }
                        $clearance = ProductClearance::query()->lockForUpdate()->findOrFail($row->id);
                        if ($clearance->status === 'active') {
                            $erpZero = isset($verifiedZero[$product->id]) && hash_equals($verifiedZero[$product->id], app(CompanySalesClearanceSync::class)->productFingerprint($product));
                            if ((int) $product->stock_qty <= 0 || $erpZero) {
                                $counts['retired']++;
                                if ($apply) {
                                    $this->retire($product, $clearance);
                                }
                            }

                            return;
                        }
                        if (! $this->proven($clearance) || ($requiredSourceFingerprint !== null && $clearance->source_fingerprint !== $requiredSourceFingerprint)) {
                            $counts['stale_or_unproven']++;

                            return;
                        }
                        if (! $product->is_active || (int) $product->stock_qty <= 0 || (float) $product->base_price <= 0 || $product->optionValues()->exists()) {
                            return;
                        }
                        $counts['eligible']++;
                        if ($apply) {
                            $this->activate($product, $clearance, $category);
                            $counts['activated']++;
                        }
                    });
                }
            });

            return $counts + ['disabled' => false, 'applied' => $apply];
        });
    }

    /** Call inside the transaction holding the product stock row. */
    public function reconcile(Product $product): void
    {
        if (data_get($product->payload, 'clearance.status') !== 'active' || (int) $product->stock_qty > 0) {
            return;
        }
        $clearance = ProductClearance::query()->where('product_id', $product->id)->where('status', 'active')->lockForUpdate()->first();
        if ($clearance) {
            $this->retire($product, $clearance);
        }
    }

    public function recordManualReactivation(Product $product): void
    {
        if (! $product->is_active || data_get($product->payload, 'clearance.status') !== 'sold_out') {
            return;
        }
        $clearance = ProductClearance::query()->where('product_id', $product->id)->where('status', 'sold_out')->first();
        if (! $clearance) {
            return;
        }
        $clearance->update(['status' => 'manual_override', 'manually_reactivated_at' => now()]);
        $payload = $product->payload ?? [];
        $payload['clearance']['status'] = 'manual_override';
        $product->updateQuietly(['payload' => $payload]);
        if ($clearance->category_added && $clearance->category_id) {
            $product->categories()->detach($clearance->category_id);
        }
    }

    private function proven(ProductClearance $clearance): bool
    {
        $cutoff = $clearance->snapshot_date->copy()->subDays($this->inactivityDays());
        if ($clearance->source === CompanySalesClearanceSync::SOURCE) {
            $state = app(CompanySalesClearanceSync::class)->state();
            if (($state['status'] ?? null) !== 'completed' || ($state['fingerprint'] ?? null) !== $clearance->source_fingerprint
                || ($state['as_of'] ?? null) !== today()->toDateString()) {
                return false;
            }
        }

        return $clearance->confirmed_company_scope && in_array($clearance->source, ['eracuni_company_export', CompanySalesClearanceSync::SOURCE], true)
            && $clearance->snapshot_date->isSameDay(today()) && $clearance->snapshot_date->lte(today())
            && $clearance->coverage_from->lte($cutoff)
            && ($clearance->last_outgoing_at === null || $clearance->last_outgoing_at->lte($cutoff));
    }

    private function activate(Product $product, ProductClearance $clearance, Category $category): void
    {
        $fullBase = $this->fullBase($product);
        $group = CustomerGroup::query()->where(fn ($query) => $query->where('code', 'B2B60')->orWhere('name', 'B2B60'))->first()
            ?? CustomerGroup::query()->create(['code' => 'B2B60', 'name' => 'B2B60', 'is_active' => true, 'is_default' => false, 'default_discount_percent' => 0, 'payload' => ['source' => 'automatic_clearance']]);
        $categoryAdded = ! $product->categories()->where('categories.id', $category->id)->exists();
        if ($categoryAdded) {
            $product->categories()->attach($category->id, ['is_primary' => false, 'sort_order' => 0]);
        }
        ProductGroupPrice::query()->create(['product_id' => $product->id, 'customer_group_id' => $group->id, 'minimum_quantity' => 1, 'price' => $fullBase * (1 - $this->discountPercent() / 100),
            'currency_code' => app(SupplierCatalogService::class)->storeCurrency(), 'is_active' => true, 'payload' => ['source' => 'automatic_clearance', 'clearance_id' => $clearance->id]]);
        $clearance->update(['status' => 'active', 'base_price_at_activation' => $fullBase, 'category_id' => $category->id, 'category_added' => $categoryAdded, 'customer_group_id' => $group->id, 'activated_at' => now()]);
        $payload = $product->payload ?? [];
        $payload['clearance'] = ['status' => 'active', 'discount_percent' => $this->discountPercent(), 'inactivity_days' => $this->inactivityDays(), 'group' => 'B2B60', 'source' => $clearance->source, 'snapshot_date' => $clearance->snapshot_date->toDateString(), 'activated_at' => now()->toIso8601String()];
        $product->updateQuietly(['payload' => $payload]);
        $this->recordPrice($product, $fullBase, $fullBase * (1 - $this->discountPercent() / 100), 'activated', $this->settings());
    }

    private function recordPrice(Product $product, float $oldPrice, float $newPrice, string $event, array $settings): void
    {
        if (round($oldPrice, 4) === round($newPrice, 4)) {
            return;
        }
        ProductPriceHistory::query()->create([
            'product_id' => $product->id, 'price_type' => 'clearance',
            'old_price' => $oldPrice, 'new_price' => $newPrice,
            'currency_code' => app(SupplierCatalogService::class)->storeCurrency(),
            'effective_at' => now(), 'source' => 'automatic_clearance', 'changed_by' => auth()->id(),
            'payload' => ['event' => $event, 'discount_percent' => $settings['discount_percent'],
                'inactivity_days' => $settings['inactivity_days']],
        ]);
    }

    private function retire(Product $product, ProductClearance $clearance): void
    {
        $clearance->update(['status' => 'sold_out', 'retired_at' => now()]);
        $payload = $product->payload ?? [];
        $payload['clearance']['status'] = 'sold_out';
        $payload['clearance']['retired_at'] = now()->toIso8601String();
        $product->updateQuietly(['is_active' => false, 'payload' => $payload]);
        ProductGroupPrice::query()->where('product_id', $product->id)->where('payload->source', 'automatic_clearance')->update(['is_active' => false]);
    }
}
