<?php

namespace App\Jobs\Integrations\SupplierCatalog;

use App\Models\Catalog\Product\Product;
use App\Models\Integrations\SupplierCatalog\SupplierCatalogItem;
use App\Services\Integrations\Media\LegacyProductImageClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportSupplierImages implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 60;

    public function __construct(public readonly int $itemId) {}

    public function handle(LegacyProductImageClient $client): void
    {
        $item = SupplierCatalogItem::query()->find($this->itemId);
        $product = $item ? Product::query()->find($item->product_id) : null;
        if (! $item || ! $product
            || data_get($product->payload, 'supplier_catalog.supplier') !== $item->supplier
            || data_get($product->payload, 'supplier_catalog.external_id') !== $item->external_id) {
            return;
        }
        foreach ($item->images ?? [] as $position => $url) {
            $key = hash('sha256', $url);
            if ($product->media()->where('custom_properties->supplier_image_key', $key)->exists()) {
                continue;
            }
            $path = null;
            try {
                $image = $client->download($url);
                $relative = 'integrations/supplier-images/'.bin2hex(random_bytes(16)).'.'.$image['extension'];
                Storage::disk('local')->put($relative, $image['bytes']);
                $path = Storage::disk('local')->path($relative);
                $collection = $position === 0 && ! $product->getFirstMedia('product_main') ? 'product_main' : 'product_gallery';
                $product->addMedia($path)->usingFileName($item->supplier.'-'.$key.'.'.$image['extension'])
                    ->withCustomProperties(['supplier_image_key' => $key, 'supplier_source' => $item->supplier])->toMediaCollection($collection);
            } catch (Throwable) {
                $item->update(['message' => 'Nacrt je uvezen; preuzimanje slika nije završeno. Ponovite dohvat slika.']);
                throw new \RuntimeException('Slike dobavljača trenutačno nije moguće preuzeti.');
            } finally {
                if ($path && is_file($path)) {
                    @unlink($path);
                }
            }
        }
        $item->update(['message' => 'Nacrt i slike su uvezeni.']);
    }
}
