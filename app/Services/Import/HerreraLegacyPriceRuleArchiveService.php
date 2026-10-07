<?php

namespace App\Services\Import;

use App\Models\Catalog\Pricing\LegacyGroupDiscountReference;
use App\Models\Catalog\Pricing\PriceCatalog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Arhivira definicije uz SELECT-only izvor, bez dodirivanja bilo koje izvršive cijene. */
class HerreraLegacyPriceRuleArchiveService
{
    public function archive(string $sourceConnection = 'herrera_source', string $prefix = 'oc_', ?int $catalogId = null): array
    {
        if (! app()->environment(['local', 'testing']) || ! preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
            throw new RuntimeException('Arhiviranje pravila dopušteno je samo lokalno ili u testu, s valjanim prefiksom.');
        }
        $source = DB::connection($sourceConnection);
        $this->guardConnections($source);
        $catalogQuery = PriceCatalog::query()->where('source_system', 'herrera-opencart')->where('source_snapshot', $source->getDatabaseName());
        $catalog = $catalogId ? $catalogQuery->findOrFail($catalogId) : $catalogQuery->oldest('id')->firstOrFail();
        if (($catalog->metadata['import_complete'] ?? false) !== true || ! preg_match('/^[a-f0-9]{64}$/', (string) $catalog->source_checksum)) {
            throw new RuntimeException('Izvorni cjenik mora imati dovršen uvoz i verificirani kontrolni zbroj.');
        }
        $associationTables = ['customer_group_ids' => ['mega_customer_group_to_sale', 'customer_group_id', 'customer_group'], 'category_ids' => ['mega_category_to_sale', 'category_id', 'category'], 'manufacturer_ids' => ['mega_manufacturer_to_sale', 'manufacturer_id', 'manufacturer'], 'excluded_product_ids' => ['mega_exclude_products', 'product_id', 'product'], 'filter_ids' => ['mega_filter_to_sale', 'filter_id', null]];
        foreach (['mega_sales', ...array_column($associationTables, 0)] as $table) {
            if (! $source->getSchemaBuilder()->hasTable($prefix.$table)) {
                throw new RuntimeException('Nedostaju izvorne tablice definicija Mega Sales Pro.');
            }
        }
        $maps = [];
        foreach (DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->whereIn('entity', ['customer_group', 'category', 'manufacturer', 'product'])->get(['entity', 'source_id', 'target_id']) as $map) {
            $maps[$map->entity][(string) $map->source_id] = (int) $map->target_id;
        }
        $associations = [];
        foreach ($associationTables as $key => [$table, $column]) {
            $associations[$key] = $source->table($prefix.$table)->orderBy('id')->get()->groupBy('sale_id');
        }
        $rows = [];
        foreach ($source->table($prefix.'mega_sales')->orderBy('id')->get() as $sale) {
            $definition = ['sale' => (array) $sale];
            $mapped = [];
            $reasons = [];
            foreach ($associationTables as $key => [$table, $column, $entity]) {
                $records = $associations[$key]->get((string) $sale->id, collect());
                $definition[$key] = $records->map(fn ($row) => (array) $row)->all();
                $sourceIds = $records->pluck($column)->map(fn ($id) => (int) $id)->unique()->values()->all();
                if ($entity) {
                    $mapped[$key] = $this->mapIds($sourceIds, $maps[$entity] ?? [], $key, $reasons);
                } elseif ($sourceIds !== []) {
                    $reasons[] = 'Izvorno pravilo koristi filtre proizvoda koji nisu podržani u novom obrascu.';
                }
            }
            if ($sale->discount_type !== 'percent' || (float) $sale->discount_value <= 0 || (float) $sale->discount_value > 100) {
                $reasons[] = 'Izvorni izračun nije podržani postotni popust od 0 do 100%.';
            }
            if ((int) $sale->round_prices !== 0) {
                $reasons[] = 'Izvorno pravilo zaokružuje cijene na korak 0,05. Novi obrazac to ne smije tiho promijeniti.';
            }
            if ((int) $sale->remove_individual_specials !== 0) {
                $reasons[] = 'Izvorno pravilo briše prethodne akcijske cijene (remove_individual_specials=1). Novi modul čuva prethodne cijene; ova opcija nije automatski prenesena.';
            }
            if (($mapped['customer_group_ids'] ?? []) === []) {
                $reasons[] = 'Pravilo nema nijednu prenesenu grupu kupaca.';
            }
            $start = $this->date($sale->date_start, $reasons);
            $end = $this->date($sale->date_end, $reasons);
            if ($start && $end && $end <= $start) {
                $reasons[] = 'Izvorno vremensko razdoblje nije valjano.';
            }
            $rows[] = $this->row($catalog, [
                'source_key' => 'mega-sale:'.$sale->id, 'source_type' => 'mega_sale', 'source_sale_id' => $sale->id, 'source_template_id' => null,
                'name' => 'Izvorna akcija #'.$sale->id,
                'percent' => $sale->discount_type === 'percent' && (float) $sale->discount_value >= 0 && (float) $sale->discount_value <= 100 ? (string) $sale->discount_value : null,
                ...$mapped, 'include_descendants' => ! (bool) $sale->exclude_child, 'starts_at' => $start, 'ends_at' => $end, 'priority' => (int) $sale->priority,
                'source_flags' => ['exclude_child' => (int) $sale->exclude_child, 'round_prices' => (int) $sale->round_prices, 'remove_individual_specials' => (int) $sale->remove_individual_specials], 'definition' => $definition,
            ], $reasons);
        }
        if ($source->getSchemaBuilder()->hasTable($prefix.'cigroupprice_template')) {
            foreach ($source->table($prefix.'cigroupprice_template')->orderBy('template_id')->get() as $template) {
                $setting = json_decode($template->setting, true, flags: JSON_THROW_ON_ERROR);
                foreach ((array) ($setting['customer_group_price'] ?? []) as $groupId => $groupSetting) {
                    if (! isset($groupSetting['status'])) {
                        continue;
                    }
                    // Predložak GROUP cijene nije akcija: njegova niža hijerarhija ne smije se zamijeniti novom akcijom.
                    $reasons = ['Ovo je spremljeni predložak grupnih cijena, a ne dokaz da je izvršen za sve artikle.', 'Predložak mijenja osnovne GROUP cijene ispod akcija. Novo pravilo popusta ima drugi prioritet; za izmjenu koristite grupne cijene artikla ili zasebno pregledano novo pravilo.'];
                    $mappedGroups = $this->mapIds([(int) $groupId], $maps['customer_group'] ?? [], 'customer_group_ids', $reasons);
                    $brands = [];
                    $categories = [];
                    $excluded = $this->mapIds(array_map('intval', (array) ($setting['filter_exclude_products'] ?? [])), $maps['product'] ?? [], 'excluded_product_ids', $reasons);
                    if (($setting['filter_type'] ?? 'all') === 'custom_manufacturer') {
                        $brands = $this->mapIds(array_map('intval', (array) ($setting['manufacturers'] ?? [])), $maps['manufacturer'] ?? [], 'manufacturer_ids', $reasons);
                        if ($brands === []) {
                            $reasons[] = 'Izvorno ograničenje proizvođača nije preneseno.';
                        }
                    } elseif (($setting['filter_type'] ?? 'all') === 'custom_category') {
                        $categories = $this->mapIds(array_map('intval', (array) ($setting['categories'] ?? [])), $maps['category'] ?? [], 'category_ids', $reasons);
                    } elseif (($setting['filter_type'] ?? 'all') !== 'all') {
                        $reasons[] = 'Predložak sadrži dodatno ograničenje proizvoda; pogledajte spremljenu izvornu definiciju.';
                    }
                    $value = (string) ($groupSetting['value'] ?? '');
                    $percent = ($groupSetting['type'] ?? '') === 'P' && ($groupSetting['action'] ?? '') === '-' && is_numeric($value) && (float) $value >= 0 && (float) $value <= 100 ? $value : null;
                    $rows[] = $this->row($catalog, ['source_key' => 'cigroup-template:'.$template->template_id.':'.$groupId, 'source_type' => 'cigroup_template', 'source_sale_id' => null, 'source_template_id' => $template->template_id, 'name' => mb_substr('Predložak: '.$template->name, 0, 191), 'percent' => $percent, 'customer_group_ids' => $mappedGroups, 'manufacturer_ids' => $brands, 'category_ids' => $categories, 'excluded_product_ids' => $excluded, 'include_descendants' => true, 'starts_at' => null, 'ends_at' => null, 'priority' => 0, 'source_flags' => $groupSetting + ['filter_type' => $setting['filter_type'] ?? 'all'], 'definition' => ['template' => (array) $template, 'setting' => $setting, 'source_customer_group_id' => (int) $groupId]], $reasons);
                }
            }
        }

        return DB::transaction(function () use ($catalog, $rows): array {
            $created = 0;
            foreach ($rows as $row) {
                $existing = LegacyGroupDiscountReference::query()->where('source_snapshot', $row['source_snapshot'])->where('source_catalog_checksum', $row['source_catalog_checksum'])->where('source_key', $row['source_key'])->first();
                if ($existing) {
                    if (! hash_equals($existing->definition_checksum, $row['definition_checksum'])) {
                        throw new RuntimeException('Izvorna definicija snapshota promijenjena je. Arhiviranje je zaustavljeno bez promjene postojećih referenci.');
                    }

                    continue;
                }
                LegacyGroupDiscountReference::query()->create($row);
                $created++;
            }

            return ['source_rules' => count($rows), 'new_references' => $created, 'supported_references' => count(array_filter($rows, fn ($row) => $row['is_supported'])), 'source_catalog_id' => $catalog->id];
        });
    }

    private function row(PriceCatalog $catalog, array $data, array $reasons): array
    {
        return $data + ['source_system' => $catalog->source_system, 'source_snapshot' => $catalog->source_snapshot, 'source_catalog_checksum' => $catalog->source_checksum, 'definition_checksum' => hash('sha256', json_encode($data['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'is_supported' => $reasons === [], 'warnings' => array_values(array_unique(['Gotove uvezene cijene su mjerodavne. Izvorna definicija nema pouzdanu vezu sa svakom pojedinom akcijskom cijenom.', ...$reasons]))];
    }

    private function mapIds(array $ids, array $map, string $field, array &$reasons): array
    {
        $mapped = [];
        foreach (array_unique($ids) as $id) {
            if (! isset($map[(string) $id])) {
                $reasons[] = 'Nedostaje prenesena poveznica za '.$field.' (izvorni ID '.$id.'). Opseg se ne smije automatski proširiti.';
            } else {
                $mapped[] = $map[(string) $id];
            }
        }
        sort($mapped);

        return array_values(array_unique($mapped));
    }

    private function date(mixed $value, array &$reasons): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $reasons[] = 'Izvorni datum nije valjan.';

            return null;
        }
    }

    private function guardConnections(ConnectionInterface $source): void
    {
        $target = DB::connection();
        if ($source === $target || ($source->getDatabaseName() === $target->getDatabaseName() && ! (app()->environment('testing') && $source->getDriverName() === 'sqlite' && $source->getDatabaseName() === ':memory:'))) {
            throw new RuntimeException('Izvorna i ciljna baza moraju biti odvojene.');
        }
        foreach ([$source, $target] as $connection) {
            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)
                || (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) && ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true))) {
                throw new RuntimeException('Dopuštene su samo izolirane lokalne baze.');
            }
        }
        if (app()->environment('local') && ($target->getDatabaseName() !== 'herrera_new_migration' || $source->getDatabaseName() !== 'herrera_live_snapshot_20261006')) {
            throw new RuntimeException('Lokalno arhiviranje mora koristiti izolirani Herrera cilj i originalni snapshot.');
        }
    }
}
