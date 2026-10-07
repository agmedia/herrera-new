<?php

namespace App\Console\Commands;

use App\Support\PriceCatalogImportAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Aggregate-only reconciliation: never print buyer identities or order contents. */
class HerreraMigrationAudit extends Command
{
    protected $signature = 'herrera:audit {--report : Save the aggregate report on the private local disk}';

    protected $description = 'Read-only reconciliation of the isolated Herrera migration and four-decimal order history';

    public function handle(): int
    {
        $target = DB::connection();
        $source = DB::connection('herrera_source');
        $snapshot = $source->getDatabaseName();
        if (! app()->environment('local') || $target->getDatabaseName() !== 'herrera_new_migration'
            || ! in_array($target->getDriverName(), ['mysql', 'mariadb'], true)
            || ! in_array($source->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true)
            || ! in_array($target->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true)
            || ! preg_match('/^[a-zA-Z0-9_]+$/', $snapshot) || $snapshot === $target->getDatabaseName()) {
            $this->error('Audit is restricted to the isolated local Herrera migration and source snapshot.');

            return self::FAILURE;
        }

        $records = [];
        $failed = false;
        foreach ([
            'product' => ['product_id', 'products'], 'category' => ['category_id', 'categories'],
            'manufacturer' => ['manufacturer_id', 'catalog_manufacturers'],
            'customer_group' => ['customer_group_id', 'customer_groups'],
            'order' => ['order_id', 'orders'], 'order_product' => ['order_product_id', 'order_items'],
            'order_total' => ['order_total_id', 'order_totals'], 'order_history' => ['order_history_id', 'order_history'],
        ] as $entity => [$sourceKey, $nativeTable]) {
            $expected = $source->table('oc_'.$entity)->count();
            $actual = $this->mappedQuery($entity, $nativeTable, $snapshot, $sourceKey)->count();
            $records[$entity] = ['source' => $expected, 'linked_native' => $actual, 'matches' => $actual === $expected];
            $failed = $failed || $actual !== $expected;
        }

        $money = [
            'base_price' => $this->mappedQuery('product', 'products', $snapshot, 'product_id')
                ->whereColumn('native.base_price', 'legacy.price')->count(),
            'order_grand_total' => $this->mappedQuery('order', 'orders', $snapshot, 'order_id')
                ->whereColumn('native.grand_total', 'legacy.total')->count(),
            'order_item_unit_price' => $this->mappedQuery('order_product', 'order_items', $snapshot, 'order_product_id')
                ->whereColumn('native.unit_price', 'legacy.price')->count(),
            'order_item_line_total' => $this->mappedQuery('order_product', 'order_items', $snapshot, 'order_product_id')
                ->whereColumn('native.line_total', 'legacy.total')->count(),
            'order_item_tax' => $this->mappedQuery('order_product', 'order_items', $snapshot, 'order_product_id')
                ->whereRaw('native.tax_amount = ROUND(legacy.tax * GREATEST(1, legacy.quantity), 4)')->count(),
            'order_total_value' => $this->mappedQuery('order_total', 'order_totals', $snapshot, 'order_total_id')
                ->whereColumn('native.value', 'legacy.value')->count(),
        ];
        $expectedMoney = [
            'base_price' => $records['product']['source'], 'order_grand_total' => $records['order']['source'],
            'order_item_unit_price' => $records['order_product']['source'],
            'order_item_line_total' => $records['order_product']['source'],
            'order_item_tax' => $records['order_product']['source'], 'order_total_value' => $records['order_total']['source'],
        ];
        foreach ($money as $key => $matched) {
            $money[$key] = ['expected' => $expectedMoney[$key], 'exact_four_decimal_matches' => $matched];
            $failed = $failed || $matched !== $expectedMoney[$key];
        }

        $identities = ['checked' => 0, 'original_company_matches' => 0, 'original_tax_identifier_matches' => 0];
        foreach ($this->mappedQuery('order', 'orders', $snapshot, 'order_id')->get([
            'native.billing_company', 'native.billing_oib', 'legacy.custom_field', 'legacy.payment_company',
        ]) as $order) {
            $custom = json_decode($order->custom_field ?: '{}', true) ?: [];
            $company = is_scalar($custom['1'] ?? null) ? trim((string) $custom['1']) : '';
            $company = $company ?: trim((string) $order->payment_company);
            $identifier = is_scalar($custom['2'] ?? null) ? trim((string) $custom['2']) : '';
            $identities['checked']++;
            $identities['original_company_matches'] += trim((string) $order->billing_company) === $company ? 1 : 0;
            $identities['original_tax_identifier_matches'] += trim((string) $order->billing_oib) === $identifier ? 1 : 0;
        }
        $failed = $failed || $identities['checked'] !== $records['order']['source']
            || $identities['original_company_matches'] !== $records['order']['source']
            || $identities['original_tax_identifier_matches'] !== $records['order']['source'];

        $customers = $source->table('oc_customer')->count();
        $linked = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', 'customer')->count();
        $quarantined = DB::table('herrera_import_issues')->where('entity', 'customer')->where('code', 'existing_email_not_merged')->count();
        $quarantineArchives = DB::table('herrera_source_records')->where('source_table', 'customer')
            ->whereIn('source_key', DB::table('herrera_import_issues')->where('entity', 'customer')->where('code', 'existing_email_not_merged')->select('source_id'))->count();
        $failed = $failed || $linked + $quarantined !== $customers || $quarantineArchives !== $quarantined;
        $issues = DB::table('herrera_import_issues')->select('code')->selectRaw('COUNT(*) AS count')->groupBy('code')->orderBy('code')->pluck('count', 'code')->all();
        $catalogSources = DB::table('catalog_price_catalogs')->get(['id', 'status', 'source_system', 'source_snapshot', 'source_checksum', 'metadata'])
            ->map(fn ($catalog) => array_replace((array) $catalog, ['metadata' => json_decode($catalog->metadata ?: '{}', true) ?: []]))->keyBy('id');
        $catalogs = $catalogSources->map(function (array $catalog) use ($catalogSources): array {
            $metadata = $catalog['metadata'];
            $actual = DB::table('catalog_price_entries')->where('price_catalog_id', $catalog['id'])->count();

            return PriceCatalogImportAudit::summarize($catalog, $actual, $catalogSources->get($metadata['cloned_from'] ?? null));
        })->values()->all();
        $failed = $failed || $catalogs === [] || collect($catalogs)->contains(fn ($catalog) => ! $catalog['integrity_passed']);
        $report = [
            'generated_at' => now()->toIso8601String(), 'source_snapshot' => $snapshot,
            'target_database' => $target->getDatabaseName(), 'integrity_passed' => ! $failed,
            'production_ready' => false, 'records' => $records, 'monetary_exactness' => $money,
            'historical_business_identity' => $identities,
            'customers' => ['source' => $customers, 'linked' => $linked, 'quarantined' => $quarantined, 'quarantine_archives' => $quarantineArchives],
            'b2b_statuses' => DB::table('b2b_accounts')->select('status')->selectRaw('COUNT(*) AS count')->groupBy('status')->pluck('count', 'status')->all(),
            'legacy_urls' => DB::table('herrera_legacy_urls')->select('status')->selectRaw('COUNT(*) AS count')->groupBy('status')->pluck('count', 'status')->all(),
            'price_catalogs' => $catalogs, 'issue_counts' => $issues,
            'remaining_release_checks' => ['Reconcile quarantined identities and ambiguous billing addresses', 'Copy original image/catalog and storage/download from production', 'Approve delivery/payment terms and outbound integrations', 'Rehearse final delta import and production cutover'],
        ];
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($this->option('report')) {
            Storage::disk('local')->put('herrera/migration-audit.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info('Aggregate report saved on the private local disk: herrera/migration-audit.json');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function mappedQuery(string $entity, string $nativeTable, string $snapshot, string $sourceKey): \Illuminate\Database\Query\Builder
    {
        return DB::table('herrera_import_maps as mapping')
            ->where('mapping.source', 'herrera-opencart')->where('mapping.entity', $entity)
            ->join($nativeTable.' as native', 'native.id', '=', 'mapping.target_id')
            ->join($snapshot.'.oc_'.$entity.' as legacy', 'legacy.'.$sourceKey, '=', 'mapping.source_id');
    }
}
