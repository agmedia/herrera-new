<?php

namespace App\Jobs\Integrations\SupplierCatalog;

use App\Models\Catalog\Category\Category;
use App\Models\Integrations\SupplierCatalog\SupplierCatalogItem;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportSupplierCatalogueDrafts implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(public string $supplier, public array $options, public ?int $actorId, public int $lastItemId) {}

    public function handle(SupplierCatalogService $service): void
    {
        $options = $service->validateOptions($this->options);
        $categories = Category::query()->where('scope', 'catalog')->pluck('id')->all();
        SupplierCatalogItem::query()->where('supplier', $this->supplier)->where('is_current', true)->where('status', 'new')
            ->where('id', '<=', $this->lastItemId)->chunkById(100, function ($items) use ($service, $options, $categories): void {
                $ids = $items->filter(fn ($item) => $service->price($item, $options) !== null && in_array($service->categoryId($item, $options), $categories, true))->pluck('id')->all();
                if ($ids !== []) {
                    $service->import($this->supplier, $ids, $options, $this->actorId);
                }
            });
    }
}
