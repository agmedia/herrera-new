<?php

namespace App\Services\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Records actual reservations so cancellation restores the original stock pools, not a new split. */
class OrderStockAllocationService
{
    /** Called inside the order transaction; option inventory remains independent of both product pools. */
    public function reserve(Product $product, int $quantity, ?ProductOptionValue $option = null): array
    {
        $product = Product::query()->lockForUpdate()->findOrFail($product->id);
        if (! $product->is_active) {
            $this->insufficientStock();
        }
        $allocation = [
            'version' => 1,
            'product_id' => $product->id,
            'product_option_value_id' => $option?->id,
            'local_quantity' => 0,
            'supplier_quantity' => 0,
            'option_quantity' => 0,
            'reserved_at' => now()->toIso8601String(),
            'restored_at' => null,
        ];

        if ($option) {
            $option = ProductOptionValue::query()->where('product_id', $product->id)->lockForUpdate()->findOrFail($option->id);
            if (! $option->is_active || $quantity < 1 || $quantity > max(0, (int) $option->stock_qty)) {
                $this->insufficientStock();
            }
            $option->forceFill(['stock_qty' => (int) $option->stock_qty - $quantity])->save();
            $allocation['option_quantity'] = $quantity;

            return $allocation;
        }

        if ($quantity < 1 || $quantity > $product->availableStockQuantity()) {
            $this->insufficientStock();
        }
        $localQuantity = min($quantity, max(0, (int) $product->stock_qty));
        $supplierQuantity = $quantity - $localQuantity;
        $supplierUntracked = data_get($product->payload, 'supplier_availability.mode') === 'fixed';
        $product->forceFill([
            'stock_qty' => (int) $product->stock_qty - $localQuantity,
            'supplier_stock_qty' => (int) $product->supplier_stock_qty - ($supplierUntracked ? 0 : $supplierQuantity),
        ])->save();
        app(\App\Services\Integrations\SupplierCatalog\SupplierCatalogRetirementService::class)->reconcile($product);
        app(\App\Services\Catalog\Clearance\ClearanceService::class)->reconcile($product);
        $allocation['local_quantity'] = $localQuantity;
        $allocation['supplier_quantity'] = $supplierQuantity;
        $allocation['supplier_untracked'] = $supplierUntracked;

        return $allocation;
    }

    /** Each item is restored once, even when distinct cancellation paths are invoked repeatedly. */
    public function restore(OrderItem $item): bool
    {
        return DB::transaction(function () use ($item): bool {
            // Receipt processing locks the order before its lines too.
            \App\Models\Sales\Order\Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            if (\App\Models\Sales\ContractWithdrawalItem::query()->where('order_item_id', $item->id)->where('received_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['order' => 'Narudžba ima fizički zaprimljen povrat. Zalihe uredite kroz evidenciju povrata.']);
            }
            $payload = is_array($item->payload) ? $item->payload : [];
            $allocation = is_array($payload['inventory'] ?? null) ? $payload['inventory'] : null;
            if (! empty($allocation['restored_at'])) {
                return false;
            }
            $quantity = max(0, (int) $item->quantity);
            if ($quantity === 0) {
                return false;
            }
            // Existing pre-allocation orders retain their established local-only restoration behavior.
            $allocation ??= [
                'version' => 1,
                'product_id' => $item->product_id,
                'product_option_value_id' => $item->product_option_value_id,
                'local_quantity' => $item->product_option_value_id ? 0 : $quantity,
                'supplier_quantity' => 0,
                'option_quantity' => $item->product_option_value_id ? $quantity : 0,
                'legacy_local_fallback' => true,
            ];

            if ($item->product_option_value_id) {
                $option = ProductOptionValue::query()->lockForUpdate()->find($item->product_option_value_id);
                if ($option) {
                    $stock = ! empty($allocation['legacy_local_fallback']) ? max(0, (int) $option->stock_qty) : (int) $option->stock_qty;
                    $option->forceFill(['stock_qty' => $stock + max(0, (int) ($allocation['option_quantity'] ?? 0))])->save();
                }
            } elseif ($item->product_id) {
                $product = Product::query()->lockForUpdate()->find($item->product_id);
                if ($product) {
                    $stock = ! empty($allocation['legacy_local_fallback']) ? max(0, (int) $product->stock_qty) : (int) $product->stock_qty;
                    $restoreSupplier = empty($allocation['supplier_untracked'])
                        && data_get($product->payload, 'supplier_availability.mode') !== 'fixed';
                    $product->forceFill([
                        'stock_qty' => $stock + max(0, (int) ($allocation['local_quantity'] ?? 0)),
                        'supplier_stock_qty' => (int) $product->supplier_stock_qty + ($restoreSupplier ? max(0, (int) ($allocation['supplier_quantity'] ?? 0)) : 0),
                    ])->save();
                }
            }
            $allocation['restored_at'] = now()->toIso8601String();
            $item->forceFill(['payload' => array_replace($payload, ['inventory' => $allocation])])->save();

            return true;
        });
    }

    private function insufficientStock(): never
    {
        throw ValidationException::withMessages(['cart' => 'Odabrana količina više nije dostupna. Provjerite košaricu i pokušajte ponovno.']);
    }
}
