<?php

namespace App\Services\Integrations\SupplierCatalog;

use App\Models\Catalog\Product\Product;

class SupplierCatalogRetirementService
{
    /** Call with the product row locked in the surrounding stock transaction. */
    public function reconcile(Product $product): void
    {
        if (! data_get($product->payload, 'supplier_catalog_retirement.pending') || (int) $product->stock_qty > 0) {
            return;
        }
        $payload = $product->payload ?? [];
        $payload['supplier_catalog_retirement']['pending'] = false;
        $payload['supplier_catalog_retirement']['deactivated_at'] = now()->toIso8601String();
        $product->update(['is_active' => false, 'payload' => $payload]);
    }
}
