<?php

namespace App\Services\Integrations\Msan;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use Illuminate\Support\Facades\DB;

class EprelDeclarationWriter
{
    public const ORIGIN_ADMIN_LOOKUP = 'admin_lookup';

    public const ORIGIN_MSAN_SYNC = 'msan_sync';

    /**
     * @param array{
     *   eprel_registration_number:string,
     *   eprel_product_group:string,
     *   model_identifier:?string,
     *   energy_class:?string,
     *   scale_min:?string,
     *   scale_max:?string,
     *   energy_label_image:?string,
     *   energy_label_url:string,
     *   product_information_sheet_url:?string
     * } $data
     */
    public function store(
        int $productId,
        array $data,
        string $origin = self::ORIGIN_ADMIN_LOOKUP,
        array $expectedProductIdentity = [],
        ?callable $identityGuard = null,
    ): ProductEnergyDeclaration {
        if (! in_array($origin, [self::ORIGIN_ADMIN_LOOKUP, self::ORIGIN_MSAN_SYNC], true)) {
            throw new \InvalidArgumentException('EPREL izvor zapisa nije podržan.');
        }

        return DB::transaction(function () use ($productId, $data, $origin, $expectedProductIdentity, $identityGuard): ProductEnergyDeclaration {
            /** @var Product|null $product */
            $product = Product::query()
                ->with('energyDeclarations')
                ->lockForUpdate()
                ->find($productId);
            if (! $product) {
                throw new EprelMatchConflictException('Artikl više ne postoji pa EPREL zapis nije spremljen.');
            }
            foreach (['code', 'sku', 'barcode', 'manufacturer_id'] as $field) {
                if (! array_key_exists($field, $expectedProductIdentity)) {
                    continue;
                }
                $expected = $field === 'manufacturer_id'
                    ? (int) ($expectedProductIdentity[$field] ?? 0)
                    : trim((string) ($expectedProductIdentity[$field] ?? ''));
                $actual = $field === 'manufacturer_id'
                    ? (int) ($product->{$field} ?? 0)
                    : trim((string) ($product->{$field} ?? ''));
                if ($expected !== $actual) {
                    throw new EprelMatchConflictException('Identifikacijski podaci artikla promijenjeni su tijekom EPREL dohvata. Pokrenite pretragu ponovno.');
                }
            }
            if ($identityGuard && $identityGuard($product) !== true) {
                throw new EprelMatchConflictException('Identifikacijski podaci artikla promijenjeni su tijekom EPREL dohvata. Pokrenite pretragu ponovno.');
            }

            $manualPrimary = $product->energyDeclarations
                ->first(fn (ProductEnergyDeclaration $item): bool => $item->source === ProductEnergyDeclaration::SOURCE_MANUAL
                    && $item->is_primary);
            // Ownership priority is manual administrator data, then an exact
            // official EPREL result, then supplier-detected M SAN data.
            $promote = ! $manualPrimary;

            $baseContext = 'eprel-'.substr(hash(
                'sha256',
                $data['eprel_product_group'].'|'.$data['eprel_registration_number'],
            ), 0, 32);
            $context = null;
            $existingDeclaration = null;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidate = $attempt === 0 ? $baseContext : $baseContext.'-'.$attempt;
                $candidateDeclaration = ProductEnergyDeclaration::query()
                    ->where('product_id', $product->id)
                    ->where('context_code', $candidate)
                    ->first();
                if (! $candidateDeclaration
                    || $candidateDeclaration->source === ProductEnergyDeclaration::SOURCE_EPREL) {
                    $context = $candidate;
                    $existingDeclaration = $candidateDeclaration;
                    break;
                }
            }
            if ($context === null) {
                throw new EprelMatchConflictException('Nije moguće sigurno spremiti službeni EPREL zapis zbog kolizije konteksta. Ručni podaci nisu promijenjeni.');
            }
            $origins = collect(data_get($existingDeclaration?->payload, 'origins', []))
                ->push(data_get($existingDeclaration?->payload, 'origin'))
                ->filter(static fn ($value): bool => is_string($value) && $value !== '')
                ->unique()
                ->values();
            if ($existingDeclaration && $origins->isEmpty()) {
                // Before interactive admin lookup existed, every EPREL row was
                // created by the M SAN synchronization flow.
                $origins->push(self::ORIGIN_MSAN_SYNC);
            }
            $origins = $origins->push($origin)->unique()->values()->all();

            $staleEprelDeclarations = ProductEnergyDeclaration::query()
                ->where('product_id', $product->id)
                ->where('source', ProductEnergyDeclaration::SOURCE_EPREL)
                ->where('context_code', '!=', $context)
                ->get();
            if ($origin === self::ORIGIN_MSAN_SYNC
                && $staleEprelDeclarations->contains(function (ProductEnergyDeclaration $declaration): bool {
                    $origins = collect(data_get($declaration->payload, 'origins', []))
                        ->push(data_get($declaration->payload, 'origin'))
                        ->filter(static fn ($value): bool => is_string($value) && $value !== '')
                        ->unique();

                    if ($origins->isEmpty()) {
                        return false;
                    }

                    return ! ($origins->contains(self::ORIGIN_MSAN_SYNC)
                        && ! $origins->contains(self::ORIGIN_ADMIN_LOOKUP));
                })) {
                throw new EprelMatchConflictException('M SAN sinkronizacija pronašla je EPREL zapis različit od administratorski potvrđenog zapisa. Ništa nije promijenjeno.');
            }
            if ($staleEprelDeclarations->isNotEmpty()) {
                ProductEnergyDeclaration::query()
                    ->whereKey($staleEprelDeclarations->modelKeys())
                    ->delete();
            }
            if ($promote) {
                ProductEnergyDeclaration::query()
                    ->where('product_id', $product->id)
                    ->where('source', '!=', ProductEnergyDeclaration::SOURCE_MANUAL)
                    ->update(['is_primary' => false, 'updated_at' => now()]);
            }

            /** @var ProductEnergyDeclaration $declaration */
            $declaration = ProductEnergyDeclaration::query()->updateOrCreate(
                ['product_id' => $product->id, 'context_code' => $context],
                [
                    'label' => 'Službena EPREL energetska oznaka',
                    'energy_class' => $data['energy_class'],
                    'scale_min' => $data['scale_min'],
                    'scale_max' => $data['scale_max'],
                    'eprel_registration_number' => $data['eprel_registration_number'],
                    'eprel_product_group' => $data['eprel_product_group'],
                    'energy_label_image' => $data['energy_label_image'],
                    'energy_label_url' => $data['energy_label_url'],
                    'product_information_sheet_url' => $data['product_information_sheet_url'],
                    'is_primary' => $promote,
                    'source' => ProductEnergyDeclaration::SOURCE_EPREL,
                    'payload' => [
                        'model_identifier' => $data['model_identifier'],
                        'match' => 'exact',
                        'origins' => $origins,
                    ],
                    'synced_at' => now(),
                ],
            );

            $productUpdates = ['energy_label_required' => true];
            if ($promote) {
                $scale = $this->scaleLabel($data['scale_min'], $data['scale_max']);
                $sameOfficialIdentity = trim((string) $product->eprel_registration_number)
                    === $data['eprel_registration_number']
                    && strtolower(trim((string) $product->eprel_product_group))
                    === $data['eprel_product_group'];
                $productUpdates += [
                    'energy_efficiency_class' => $data['energy_class'] ?: ($sameOfficialIdentity ? $product->energy_efficiency_class : null),
                    'energy_efficiency_scale' => $scale ?: ($sameOfficialIdentity ? $product->energy_efficiency_scale : null),
                    'eprel_registration_number' => $data['eprel_registration_number'],
                    'eprel_product_group' => $data['eprel_product_group'],
                    'eprel_energy_label_image' => $data['energy_label_image'] ?: ($sameOfficialIdentity ? $product->eprel_energy_label_image : null),
                    'energy_label_url' => $data['energy_label_url'] ?: ($sameOfficialIdentity ? $product->energy_label_url : null),
                    'product_information_sheet_url' => $data['product_information_sheet_url']
                        ?: ($sameOfficialIdentity ? $product->product_information_sheet_url : null),
                    'energy_data_synced_at' => now(),
                ];
            }
            $product->forceFill($productUpdates)->save();

            return $declaration->refresh();
        }, 3);
    }

    private function scaleLabel(?string $minimum, ?string $maximum): ?string
    {
        $parts = array_values(array_filter([
            trim((string) $minimum),
            trim((string) $maximum),
        ], static fn (string $value): bool => $value !== ''));

        return $parts === [] ? null : implode('-', array_unique($parts));
    }
}
