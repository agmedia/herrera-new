<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Pricing\LegacyGroupDiscountReference;
use App\Models\Catalog\Pricing\PriceCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LegacyGroupDiscountReferenceService
{
    public function forGroup(PriceCatalog $catalog, int $groupId): Collection
    {
        return $this->references($catalog)->whereJsonContains('customer_group_ids', $groupId)
            ->orderBy('source_type')->orderByDesc('source_sale_id')->orderBy('id')->get();
    }

    /** Samo popunjava obrazac. Ne stvara pravilo, ne računa stavke i ne objavljuje cjenik. */
    public function draftData(PriceCatalog $catalog, int $referenceId): array
    {
        $reference = $this->references($catalog)->findOrFail($referenceId);
        if (! $reference->is_supported) {
            throw ValidationException::withMessages(['legacyReference' => 'Ova definicija ima nepodržane uvjete. Pregledajte izvorne napomene i unesite novo pravilo ručno.']);
        }

        return ['form' => [
            'name' => $reference->name,
            'percent' => $reference->percent,
            'customer_group_ids' => $reference->customer_group_ids,
            'manufacturer_ids' => $reference->manufacturer_ids,
            'category_ids' => $reference->category_ids,
            'excluded_product_ids' => $reference->excluded_product_ids,
            'include_descendants' => $reference->include_descendants,
            'starts_at' => $reference->starts_at?->format('Y-m-d\TH:i') ?? '',
            'ends_at' => $reference->ends_at?->format('Y-m-d\TH:i') ?? '',
            'priority' => $reference->priority,
            'is_active' => true,
        ], 'warnings' => array_values(array_unique([...$reference->warnings,
            'Pripremljeno je novo pravilo za sve izvorne grupe. Promjene vrijede tek nakon spremanja i objave radnog cjenika.',
            'Novo pravilo zamjenjuje prethodne akcijske i ugovorene cijene u odabranom opsegu, bez zbrajanja popusta. Izvorne cijene ostaju sačuvane kao podloga.',
            'Novo pravilo uključuje točan trenutak početka; izvorni OpenCart isključuje početnu granicu.',
        ]))];
    }

    private function references(PriceCatalog $catalog): Builder
    {
        // Radne kopije smiju vidjeti reference samo istog verificiranog izvornog snapshota.
        return LegacyGroupDiscountReference::query()->where('source_system', $catalog->source_system ?? '')
            ->where('source_snapshot', $catalog->source_snapshot ?? '')
            ->where('source_catalog_checksum', $catalog->source_checksum ?? '');
    }
}
