<?php

namespace App\Console\Commands;

use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\User;
use App\Services\Import\HerreraBaselineConflictRevalidationService;
use App\Services\Import\HerreraCutoverSafety;
use App\Services\Import\HerreraHistoricalCrmImportService;
use App\Services\Import\HerreraLocalDatabaseCopy;
use App\Services\Import\HerreraNativePriceOverlayService;
use App\Services\Import\HerreraOpenCartImportService;
use App\Services\Import\HerreraStaffImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Rehearses in two new local databases; never switches an application or activates prices. */
class HerreraRehearseCutover extends Command
{
    protected $signature = 'herrera:rehearse-cutover {--source-connection=herrera_source} {--prefix=oc_} {--source-date= : Actual date of the incoming frozen snapshot, YYYY-MM-DD} {--baseline-connection= : Original frozen source used for first import; defaults to source-connection} {--baseline-date= : Actual date required when a separate baseline connection is supplied} {--admin-connection= : Optional privileged localhost connection for creating new databases} {--apply : Create new local copies and rehearse; default is a read-only preflight} {--copy-only : Create the copies and stop before importing} {--resume= : Resume using a private aggregate report from a prior run} {--continue-after-refresh : With resume, reuse its completed refresh and repeat every verification gate}';

    protected $description = 'Rehearse a Herrera OpenCart refresh using disposable local database copies only';

    public function handle(HerreraCutoverSafety $safety, HerreraLocalDatabaseCopy $copy): int
    {
        $originalDefault = DB::getDefaultConnection();
        $started = microtime(true);
        $report = ['started_at' => now()->toIso8601String(), 'production_modified' => false, 'rehearsal_completed' => false, 'rehearsal_passed' => false, 'ready_for_live' => false];
        try {
            if ($this->option('continue-after-refresh') && (! $this->option('apply') || ! $this->option('resume') || $this->option('copy-only'))) {
                throw new RuntimeException('Continuing a completed refresh requires apply and a verified resume report.');
            }
            $target = DB::connection();
            $source = DB::connection((string) $this->option('source-connection'));
            $prefix = (string) $this->option('prefix');
            $sourceDate = (string) $this->option('source-date');
            $baselineConnection = (string) ($this->option('baseline-connection') ?: $this->option('source-connection'));
            $baseline = DB::connection($baselineConnection);
            $baselineDate = (string) ($this->option('baseline-connection') ? $this->option('baseline-date') : $sourceDate);
            $safety->validate(app()->environment(), $target->getConfig(), $source->getConfig(), $prefix, $sourceDate);
            $safety->validate(app()->environment(), $target->getConfig(), $baseline->getConfig(), $prefix, $baselineDate);
            $targetServer = (array) $target->selectOne('SELECT @@hostname AS host, @@port AS port');
            $sourceServer = (array) $source->selectOne('SELECT @@hostname AS host, @@port AS port');
            if ($targetServer !== $sourceServer || (array) $baseline->selectOne('SELECT @@hostname AS host, @@port AS port') !== $targetServer) {
                throw new RuntimeException('Restore both frozen databases on the same local database instance first.');
            }
            $administrator = $this->option('admin-connection') ? DB::connection((string) $this->option('admin-connection')) : $target;
            HerreraCutoverSafety::assertNoSplitConnection($administrator->getConfig());
            if (! in_array($administrator->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
                || ! in_array($administrator->getDriverName(), ['mysql', 'mariadb'], true)
                || (array) $administrator->selectOne('SELECT @@hostname AS host, @@port AS port') !== $targetServer) {
                throw new RuntimeException('The copy administrator must use the same isolated localhost database instance.');
            }
            $report += ['source_snapshot' => $source->getDatabaseName(), 'source_snapshot_date' => $sourceDate,
                'baseline_snapshot' => $baseline->getDatabaseName(), 'baseline_snapshot_date' => $baselineDate,
                'original_target' => $target->getDatabaseName(), 'original_counts' => $this->counts($target), 'timings_seconds' => []];
            if (! $this->option('apply')) {
                $this->info('Preflight passed. No databases or records were changed.');
                $this->line('The apply mode creates two new local copies, imports there, and leaves both hosted shops untouched.');

                return self::SUCCESS;
            }
            config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
            if ($resume = $this->option('resume')) {
                if (! preg_match('~^herrera/cutover-rehearsal-[0-9]{8}-[0-9]{6}\.json$~D', $resume)) {
                    throw new RuntimeException('Resume accepts only a named private rehearsal report.');
                }
                $previous = json_decode(Storage::disk('local')->get($resume), true, 512, JSON_THROW_ON_ERROR);
                $targetName = $previous['rehearsal_target'] ?? '';
                $sourceName = $previous['rehearsal_source'] ?? '';
                if (! HerreraCutoverSafety::rehearsalDatabase($targetName)
                    || ! preg_match('/^herrera_rehearsal_source_[0-9]{8}_[0-9]{6}_[a-f0-9]{4}$/D', $sourceName)
                    || ($previous['source_snapshot'] ?? '') !== $source->getDatabaseName()
                    || ($previous['source_snapshot_date'] ?? '') !== $sourceDate
                    || ($previous['baseline_snapshot'] ?? $previous['source_snapshot'] ?? '') !== $baseline->getDatabaseName()
                    || ($previous['baseline_snapshot_date'] ?? $previous['source_snapshot_date'] ?? '') !== $baselineDate
                    || ($previous['copy_complete'] ?? false) !== true) {
                    throw new RuntimeException('The prior report does not identify verified isolated rehearsal copies.');
                }
                $report += ['resumed_from' => $resume, 'protected_before' => $previous['protected_before'] ?? []];
                $report['timings_seconds']['copy'] = $previous['timings_seconds']['copy'] ?? null;
            } else {
                $suffix = now()->format('Ymd_His').'_'.bin2hex(random_bytes(2));
                $targetName = 'herrera_rehearsal_target_'.$suffix;
                $sourceName = 'herrera_rehearsal_source_'.$suffix;
                $report += ['rehearsal_target' => $targetName, 'rehearsal_source' => $sourceName];
                $this->line('Creating isolated local copies. Existing databases are retained.');
                $phase = microtime(true);
                $copy->copy($administrator, $target->getDatabaseName(), $targetName);
                $copy->copy($administrator, $source->getDatabaseName(), $sourceName);
                $report['timings_seconds']['copy'] = round(microtime(true) - $phase, 2);
            }
            $report += ['rehearsal_target' => $targetName, 'rehearsal_source' => $sourceName, 'copy_complete' => true];
            $targetConfig = HerreraCutoverSafety::isolatedConnectionConfig($administrator->getConfig());
            $sourceConfig = $targetConfig;
            config(['database.connections.herrera_rehearsal_target' => array_replace($targetConfig, ['database' => $targetName]),
                'database.connections.herrera_rehearsal_source' => array_replace($sourceConfig, ['database' => $sourceName])]);
            DB::purge('herrera_rehearsal_target');
            DB::purge('herrera_rehearsal_source');
            DB::setDefaultConnection('herrera_rehearsal_target');
            config(['database.default' => 'herrera_rehearsal_target']);
            Schema::clearResolvedInstance('db.schema');
            if (DB::connection()->getDatabaseName() !== $targetName
                || DB::connection()->getName() !== 'herrera_rehearsal_target'
                || DB::connection('herrera_rehearsal_source')->getDatabaseName() !== $sourceName
                || DB::connection('herrera_rehearsal_source')->getName() !== 'herrera_rehearsal_source') {
                throw new RuntimeException('The isolated rehearsal destination could not be verified.');
            }
            $report['protected_before'] = array_replace($this->protectedState(), $report['protected_before'] ?? []);
            $report['protected_snapshot_version'] = 2;
            if ($this->option('copy-only')) {
                $this->info('Isolated copies are ready. No import or activation was performed.');

                return self::SUCCESS;
            }
            if ($this->call('migrate', ['--database' => 'herrera_rehearsal_target', '--force' => true]) !== self::SUCCESS) {
                throw new RuntimeException('The isolated database migrations did not complete; importing is blocked.');
            }
            if (! DB::connection()->getSchemaBuilder()->hasColumn('customer_sales_sessions', 'payload')) {
                throw new RuntimeException('The rehearsal schema does not contain the required historical CRM column; review its migration ledger before importing.');
            }
            $primeStages = ['catalog', 'customers', 'billing_addresses', 'orders', 'seo', 'relations'];
            if ($this->option('continue-after-refresh')) {
                $catalog = DB::table('catalog_price_catalogs')->where('id', $previous['refresh_import']['draft_price_catalog_id'] ?? 0)->first();
                $checkpointCatalog = $catalog ? (array) $catalog : null;
                if ($checkpointCatalog) {
                    $checkpointCatalog['metadata'] = json_decode($catalog->metadata ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                }
                HerreraCutoverSafety::assertRefreshCheckpoint($previous, $baseline->getDatabaseName(), $sourceName, $checkpointCatalog);
                $report['baseline_import'] = $previous['baseline_import'];
                $report['refresh_import'] = $previous['refresh_import'];
                foreach (['baseline', 'refresh'] as $timing) {
                    $report['timings_seconds'][$timing] = $previous['timings_seconds'][$timing] ?? null;
                }
                $baselineIssues = DB::table('herrera_import_issues')->where('code', 'like', 'final_sync_%')->get()
                    ->filter(fn ($issue) => in_array(data_get(json_decode($issue->context ?? '{}', true, 512, JSON_THROW_ON_ERROR), 'snapshot'), [$baseline->getDatabaseName(), $sourceName], true))
                    ->map(fn ($issue) => (array) $issue)->all();
                $this->line('Continuing the verified completed refresh; reconciliation, pricing, preservation and repeat gates will all run.');
            } else {
                $phase = microtime(true);
                $baselineStartedAt = now()->startOfSecond();
                $this->line('Priming ownership from the original frozen source, without pricing activation.');
                $report['baseline_import'] = app(HerreraOpenCartImportService::class)->import($baselineConnection, $prefix, false, $primeStages, fn ($stage) => $this->line('Baseline: '.$stage), finalSync: true);
                $baselineIssues = DB::table('herrera_import_issues')->where('code', 'like', 'final_sync_%')
                    ->where('updated_at', '>=', $baselineStartedAt)->get()
                    ->filter(fn ($issue) => data_get(json_decode($issue->context ?? '{}', true, 512, JSON_THROW_ON_ERROR), 'snapshot') === $baseline->getDatabaseName())
                    ->map(fn ($issue) => (array) $issue)->all();
                $report['timings_seconds']['baseline'] = round(microtime(true) - $phase, 2);
                $phase = microtime(true);
                $this->line('Rehearsing the refresh against a separately named source snapshot.');
                $report['refresh_import'] = app(HerreraOpenCartImportService::class)->import('herrera_rehearsal_source', $prefix, false, [], fn ($stage) => $this->line('Refresh: '.$stage), finalSync: true);
                $report['timings_seconds']['refresh'] = round(microtime(true) - $phase, 2);
            }
            $baselineIssueDeficit = max(0, (int) ($report['baseline_import']['final_sync_conflicts'] ?? 0) - count($baselineIssues));
            $report['staff'] = app(HerreraStaffImportService::class)->import(DB::connection('herrera_rehearsal_source'), $prefix, true, finalSync: true);
            $phase = microtime(true);
            $this->line('Retaining historical customer login sessions and their original records.');
            $report['historical_crm'] = app(HerreraHistoricalCrmImportService::class)->import(DB::connection('herrera_rehearsal_source'), $prefix, true);
            $report['timings_seconds']['historical_crm'] = round(microtime(true) - $phase, 2);
            $report['protected_after'] = $this->protectedState();
            $report['protected_data_preserved'] = $report['protected_before'] === $report['protected_after'];
            $report['original_counts_after'] = $this->counts($target);
            $report['original_counts_unchanged'] = $report['original_counts'] === $report['original_counts_after'];
            $report['reconciliation'] = $this->reconcile($sourceName, $prefix);
            $phase = microtime(true);
            $report['source_pricing_audit_passed'] = $this->call('herrera:audit-pricing', ['--source-connection' => 'herrera_rehearsal_source', '--prefix' => $prefix, '--samples' => 100, '--cover-groups' => true]) === self::SUCCESS;
            $report['timings_seconds']['pricing_audit'] = round(microtime(true) - $phase, 2);
            if ($report['source_pricing_audit_passed']) {
                $draft = PriceCatalog::query()->where('source_system', 'herrera-opencart')->where('source_snapshot', $sourceName)->where('status', PriceCatalog::DRAFT)->oldest('id')->firstOrFail();
                $actor = User::query()->where('account_type', 'staff')->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'superadmin']))->first();
                if ($actor) {
                    $proof = ['passed' => true, 'catalog_id' => $draft->id, 'source_checksum' => $draft->source_checksum, 'entry_count' => $draft->entries()->count()];
                    $overlay = app(HerreraNativePriceOverlayService::class);
                    $report['native_price_plan'] = $overlay->plan($draft, $actor, $proof);
                    if ($report['native_price_plan']['ready']) {
                        $nativeCount = $report['native_price_plan']['native_rule_count'] + $report['native_price_plan']['native_entry_count'];
                        $report['working_price_draft'] = $nativeCount > 0
                            ? $overlay->prepare($draft, $actor, $proof)
                            : ['draft_catalog_id' => $draft->id, 'status' => 'draft', 'native_rule_count' => 0, 'native_entry_count' => 0];
                    }
                } else {
                    $report['native_price_plan'] = ['ready' => false, 'conflicts' => [['code' => 'reviewed_native_pricing_administrator_missing']]];
                }
            }
            $phase = microtime(true);
            $report['repeat_import'] = app(HerreraOpenCartImportService::class)->import('herrera_rehearsal_source', $prefix, false, $primeStages, null, finalSync: true);
            $report['timings_seconds']['repeat'] = round(microtime(true) - $phase, 2);
            $baselineReview = app(HerreraBaselineConflictRevalidationService::class)->plan($baselineIssues, DB::connection('herrera_rehearsal_source'), $prefix);
            $report['baseline_conflict_revalidation'] = array_diff_key($baselineReview, ['details' => true]) + ['unrecorded_conflicts' => $baselineIssueDeficit];
            $report['protected_after'] = $this->protectedState();
            $report['protected_data_preserved'] = $report['protected_before'] === $report['protected_after'];
            $report['reconciliation'] = $this->reconcile($sourceName, $prefix);
            $report['original_counts_after'] = $this->counts($target);
            $report['original_counts_unchanged'] = $report['original_counts'] === $report['original_counts_after'];
            $report['rehearsal_passed'] = $report['protected_data_preserved'] && $report['original_counts_unchanged']
                && $report['source_pricing_audit_passed'] && ! in_array(false, $report['reconciliation'], true)
                && ($report['native_price_plan']['ready'] ?? false)
                && (int) $baselineReview['unresolved'] === 0 && $baselineIssueDeficit === 0
                && (int) ($report['refresh_import']['final_sync_conflicts'] ?? 0) === 0
                && (int) ($report['repeat_import']['final_sync_conflicts'] ?? 0) === 0
                && (int) ($report['staff']['conflicts'] ?? $report['staff']['final_sync_conflicts'] ?? 0) === 0
                && ($report['historical_crm']['all_rows_archived'] ?? false)
                && (int) ($report['historical_crm']['conflicts'] ?? 0) === 0;
            $report['remaining_gates'] = ['Use a freshly captured production snapshot and rehearse it before switching',
                'Resolve all recorded identity, ownership and staff assignment conflicts',
                'Carry forward and verify native manual prices and discount rules before activating the new catalog',
                'Identify test-only accounts/orders without deleting real history',
                'Reconcile original media and downloads without replacing curated media',
                'Verify login, B2B checkout, ERP/mail/payment configuration and SEO redirects',
                'Freeze old-shop writes and cron jobs for final delta; prepare a write-safe rollback'];
            $report['rehearsal_completed'] = true;
            $this->info($report['rehearsal_passed'] ? 'Rehearsal gates passed on local copies. This does not authorize a live switch.' : 'Rehearsal completed with review gates. Both original databases remain unchanged.');
        } catch (Throwable $exception) {
            $report['error_class'] = get_class($exception);
            if ($exception instanceof \Illuminate\Database\QueryException) {
                $report['sqlstate'] = $exception->errorInfo[0] ?? $exception->getCode();
                $report['driver_error_code'] = $exception->errorInfo[1] ?? null;
            }
            $this->error($exception instanceof RuntimeException && ! $exception instanceof \Illuminate\Database\QueryException
                ? $exception->getMessage() : 'The rehearsal stopped safely; inspect the private aggregate report.');
        } finally {
            DB::setDefaultConnection($originalDefault);
            config(['database.default' => $originalDefault]);
            Schema::clearResolvedInstance('db.schema');
            if ($this->option('apply')) {
                $report['elapsed_seconds'] = round(microtime(true) - $started, 2);
                $path = 'herrera/cutover-rehearsal-'.now()->format('Ymd-His').'.json';
                Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                @chmod(Storage::disk('local')->path($path), 0600);
                $this->line('Private aggregate report: '.$path);
                if (isset($report['rehearsal_target'])) {
                    $this->line('Rehearsal database retained: '.$report['rehearsal_target']);
                }
            }
        }

        return ($report['rehearsal_passed'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function counts($connection): array
    {
        $counts = [];
        foreach (['products', 'users', 'orders', 'order_items', 'catalog_price_entries', 'media'] as $table) {
            $counts[$table] = $connection->table($table)->count();
        }

        return $counts;
    }

    private function protectedState(): array
    {
        $result = [];
        foreach (['media', 'customer_visits', 'customer_sales_sessions'] as $table) {
            $hash = hash_init('sha256');
            $query = DB::table($table);
            if ($table === 'customer_sales_sessions') {
                $query->whereNotIn('id', DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', 'order_manager_sales')->select('target_id'));
            }
            $query->orderBy('id')->chunkById(1000, function ($rows) use ($hash, $table): void {
                foreach ($rows as $row) {
                    if ($table === 'customer_sales_sessions') {
                        $row->payload ??= null;
                    }
                    hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR)."\n");
                }
            });
            $result[$table] = hash_final($hash);
        }
        $result['active_catalog_id'] = DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id');
        $queries = [
            'supplier_documents' => DB::table('products')->select('id')->selectRaw("JSON_EXTRACT(payload, '$.supplier_documents') AS documents")->whereRaw("JSON_EXTRACT(payload, '$.supplier_documents') IS NOT NULL"),
            'native_customer_details' => DB::table('user_profiles')->select('id', 'county', 'customer_type')->where(fn ($query) => $query->whereNotNull('county')->orWhereNotNull('customer_type')),
            'native_b2b_workflow' => DB::table('b2b_accounts')->select('id', 'assigned_sales_rep_id', 'contacted_at', 'sales_comment', 'proposed_terms', 'proposed_by', 'proposed_at')
                ->selectRaw("JSON_EXTRACT(payload, '$.approval_workflow_required') AS workflow_required")
                ->where(fn ($query) => $query->whereNotNull('assigned_sales_rep_id')->orWhereNotNull('contacted_at')->orWhereNotNull('sales_comment')->orWhereNotNull('proposed_terms')->orWhereRaw("JSON_EXTRACT(payload, '$.approval_workflow_required') IS NOT NULL")),
            'native_business_groups' => DB::table('customer_groups')->where('code', 'not like', 'herrera-oc-group-%'),
        ];
        foreach (['catalog_product_option_values' => 'product_option_value', 'catalog_options' => 'option', 'catalog_option_values' => 'option_value'] as $table => $entity) {
            $queries[$table] = DB::table($table)->whereNotIn('id', DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', $entity)->select('target_id'));
        }
        foreach ($queries as $name => $query) {
            $hash = hash_init('sha256');
            foreach ($query->orderBy('id')->cursor() as $row) {
                hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR)."\n");
            }
            $result[$name] = hash_final($hash);
        }

        return $result;
    }

    private function reconcile(string $sourceName, string $prefix): array
    {
        $result = [];
        $mapped = [];
        foreach (['product' => ['product_id', 'products'], 'category' => ['category_id', 'categories'],
            'manufacturer' => ['manufacturer_id', 'catalog_manufacturers'], 'order' => ['order_id', 'orders'],
            'order_product' => ['order_product_id', 'order_items'], 'order_total' => ['order_total_id', 'order_totals'],
            'order_history' => ['order_history_id', 'order_history'], 'customer' => ['customer_id', 'users']] as $entity => [$key, $table]) {
            $sourceTable = $sourceName.'.'.$prefix.$entity;
            $expected = DB::table($sourceTable)->count();
            $mapped[$entity] = DB::table('herrera_import_maps as mapping')->where('mapping.source', 'herrera-opencart')->where('mapping.entity', $entity)
                ->join($sourceTable.' as legacy', 'legacy.'.$key, '=', 'mapping.source_id')->join($table.' as native', 'native.id', '=', 'mapping.target_id');
            $result[$entity.'_linked'] = $expected === (clone $mapped[$entity])->count();
            $result[$entity.'_one_to_one'] = $expected === (clone $mapped[$entity])->distinct()->count('native.id');
            if (in_array($entity, ['order', 'order_product', 'order_total', 'order_history'], true)) {
                $result[$entity.'_source_identity_verified'] = true;
                $columns = ['legacy.'.$key.' as source_id', 'native.payload'];
                if ($entity === 'order') {
                    $columns = [...$columns, 'native.order_number', 'native.source'];
                }
                foreach ((clone $mapped[$entity])->select($columns)->orderBy('native.id')->cursor() as $row) {
                    $payload = json_decode($row->payload ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                    if ((string) data_get($payload, 'opencart.'.$key, '') !== (string) $row->source_id
                        || ($entity === 'order' && ($row->source !== 'opencart_import' || $row->order_number !== 'OC-HERRERA-'.$row->source_id))) {
                        $result[$entity.'_source_identity_verified'] = false;
                    }
                }
            }
        }
        $orders = $mapped['order'];
        $result['order_totals_exact'] = (clone $orders)->whereColumn('native.grand_total', '!=', 'legacy.total')->count() === 0;
        $orderCustomers = (clone $orders)->leftJoin('herrera_import_maps as parent_mapping', function ($join): void {
            $join->on('parent_mapping.source_id', '=', DB::raw('CAST(legacy.customer_id AS CHAR)'))->where('parent_mapping.source', 'herrera-opencart')->where('parent_mapping.entity', 'customer');
        });
        $result['order_customer_links_exact'] = $orderCustomers->whereRaw('((native.user_id IS NULL) <> (parent_mapping.target_id IS NULL) OR native.user_id <> parent_mapping.target_id)')->count() === 0;
        foreach (['order_product', 'order_total', 'order_history'] as $entity) {
            $children = (clone $mapped[$entity])->leftJoin('herrera_import_maps as parent_mapping', function ($join): void {
                $join->on('parent_mapping.source_id', '=', DB::raw('CAST(legacy.order_id AS CHAR)'))->where('parent_mapping.source', 'herrera-opencart')->where('parent_mapping.entity', 'order');
            });
            $result[$entity.'_parent_links_exact'] = $children->where(fn ($query) => $query->whereNull('parent_mapping.target_id')->orWhereNull('native.order_id')->orWhereColumn('native.order_id', '!=', 'parent_mapping.target_id'))->count() === 0;
        }
        $items = $mapped['order_product'];
        $result['order_item_unit_prices_exact'] = (clone $items)->whereColumn('native.unit_price', '!=', 'legacy.price')->count() === 0;
        $result['order_item_line_totals_exact'] = (clone $items)->whereColumn('native.line_total', '!=', 'legacy.total')->count() === 0;
        $quantity = 'CASE WHEN legacy.quantity < 1 THEN 1 ELSE legacy.quantity END';
        $result['order_item_quantities_exact'] = (clone $items)->whereRaw('native.quantity <> '.$quantity)->count() === 0;
        $result['order_item_taxes_exact'] = (clone $items)->whereRaw('native.tax_amount <> ROUND(legacy.tax * ('.$quantity.'), 4)')->count() === 0;
        $totals = $mapped['order_total'];
        $result['order_total_components_exact'] = (clone $totals)->whereColumn('native.value', '!=', 'legacy.value')->count() === 0;
        $customers = $mapped['customer'];
        $result['customer_login_emails_equal'] = (clone $customers)->whereRaw('LOWER(TRIM(native.email)) <> LOWER(TRIM(legacy.email))')->count() === 0;

        return $result;
    }
}
