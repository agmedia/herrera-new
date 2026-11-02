<?php

namespace App\Services\Integrations\Eprel;

use App\Jobs\Integrations\Eprel\SyncProductEprelJob;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Services\Integrations\Msan\EprelDeclarationWriter;
use App\Services\Integrations\Msan\EprelMatchConflictException;
use App\Services\Integrations\Msan\EprelProductIdentity;
use Illuminate\Support\Facades\Log;

class EprelAutomaticLookupService
{
    private const ENERGY_FIELDS = [
        'energy_label_required', 'energy_efficiency_class', 'energy_efficiency_scale',
        'eprel_registration_number', 'eprel_product_group', 'eprel_lookup_product_group',
        'eprel_energy_label_image', 'energy_label_url', 'product_information_sheet_url',
        'energy_data_synced_at',
    ];

    public function __construct(
        private readonly EprelSettingsService $settings,
        private readonly EprelCatalogProductMatcher $matcher,
    ) {}

    public function dispatchFor(int $productId): void
    {
        if (! $this->configured()) {
            return;
        }

        $product = Product::query()->find($productId);
        if (! $product || ! $this->needsLookup($product)) {
            return;
        }
        $criteria = $this->matcher->criteria($product);
        if (! $this->eligible($product, $criteria)) {
            return;
        }

        SyncProductEprelJob::dispatch(
            $productId,
            EprelProductIdentity::fingerprint($product),
            $criteria,
            $this->energyState($product),
        )->afterCommit();
    }

    /** An exact match may enrich a new article, but never a newer admin edit. */
    public function lookup(int $productId, string $identity, array $criteria, array $energyState): void
    {
        $product = Product::query()->find($productId);
        if (! $product || ! $this->unchanged($product, $identity, $criteria, $energyState)) {
            Log::info('Automatski EPREL dohvat preskočen.', ['product_id' => $productId, 'status' => 'changed_or_unavailable']);

            return;
        }

        try {
            $outcome = $this->matcher->match($product, $criteria, $identity, function () use ($productId, $identity, $criteria, $energyState): bool {
                $current = Product::query()->find($productId);

                return $current && $this->unchanged($current, $identity, $criteria, $energyState);
            }, EprelDeclarationWriter::ORIGIN_AUTOMATIC_LOOKUP);
            Log::info('Automatski EPREL dohvat završen.', ['product_id' => $productId, 'status' => $outcome['status']]);
        } catch (EprelMatchConflictException) {
            // Ambiguous records and edits made while the request was in flight
            // require the existing manual lookup, not an automatic replacement.
            Log::notice('Automatski EPREL dohvat zahtijeva ručni pregled.', ['product_id' => $productId, 'status' => 'conflict']);
        }
    }

    private function configured(): bool
    {
        return $this->settings->enabled() && $this->settings->hasApiKey();
    }

    private function needsLookup(Product $product): bool
    {
        return $product->is_active
            && (! $product->energy_label_url || ! $product->product_information_sheet_url)
            && ! $product->energyDeclarations()->where(function ($query): void {
                $query->where('source', ProductEnergyDeclaration::SOURCE_EPREL)
                    ->orWhere('source', ProductEnergyDeclaration::SOURCE_MANUAL);
            })->exists();
    }

    private function eligible(Product $product, array $criteria): bool
    {
        if ($criteria['groups'] === [] && $criteria['registrations'] === [] && ! $product->energy_label_required) {
            return false;
        }

        return $criteria['gtins'] !== [] || $criteria['registrations'] !== []
            || ($criteria['models'] !== [] && $criteria['groups'] !== [] && $criteria['brands'] !== []);
    }

    private function unchanged(Product $product, string $identity, array $criteria, array $energyState): bool
    {
        return $this->configured() && $this->needsLookup($product)
            && EprelProductIdentity::matches($product, $identity)
            && $this->energyState($product) === $energyState
            && $this->matcher->sameCriteria($this->matcher->criteria($product), $criteria);
    }

    private function energyState(Product $product): array
    {
        // Raw database values survive serialization without date/object changes.
        return array_intersect_key($product->getAttributes(), array_flip(self::ENERGY_FIELDS));
    }
}
