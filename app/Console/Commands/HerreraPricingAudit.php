<?php

namespace App\Console\Commands;

use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\PriceCatalogResolver;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Select-only migration gate. No customer identifiers or contract amounts are printed. */
class HerreraPricingAudit extends Command
{
    protected $signature = 'herrera:audit-pricing {--source-connection=herrera_source} {--prefix=oc_} {--catalog= : Original imported catalog ID; defaults to the first catalog for this snapshot} {--samples=200 : Effective-price sample limit, between 1 and 2000} {--cover-groups : Add bounded checks for every usable mapped group and scheduled/quantity boundaries}';

    protected $description = 'Read-only local Herrera audit of all source prices and a bounded legacy effective-price sample';

    private ConnectionInterface $source;

    private string $prefix;

    private array $maps = [];

    private array $counts = [];

    private array $errors = [];

    protected const MAX_EFFECTIVE_SCENARIOS = 4000;

    private const DEFINITIONS = [
        'product' => ['base', null, null],
        'product_price_by_cigroup' => ['group', 'group_id', null],
        'product_price_by_customer_id' => ['customer', null, 'customer_id'],
        'product_discount' => ['quantity', 'customer_group_id', null],
        'product_special' => ['special', 'customer_group_id', null],
    ];

    public function handle(PriceCatalogResolver $resolver): int
    {
        try {
            $this->guard();
            $catalogQuery = PriceCatalog::query()->where('source_system', 'herrera-opencart')->where('source_snapshot', $this->source->getDatabaseName());
            $catalog = $this->option('catalog') !== null
                ? $catalogQuery->findOrFail((int) $this->option('catalog'))
                : $catalogQuery->oldest('id')->firstOrFail();
            if (($catalog->metadata['import_complete'] ?? false) !== true) {
                throw new RuntimeException('Pricing import is incomplete. Wait for it to finish before auditing.');
            }
            $this->loadMaps();
            $this->auditUnsupportedPriceScopes();
            $before = $this->catalogFingerprint($catalog);
            $digest = hash_init('sha256');
            foreach (self::DEFINITIONS as $table => [$kind, $groupKey, $customerKey]) {
                $this->line('Read-only audit: '.$kind.' prices');
                $this->auditSourceTable($catalog, $table, $kind, $groupKey, $customerKey, $digest);
            }
            $sourceChecksum = hash_final($digest);
            $this->countError('source_checksum_mismatch', ! is_string($catalog->source_checksum) || ! hash_equals($sourceChecksum, $catalog->source_checksum));
            $nativeCount = $catalog->entries()->count();
            $this->counts['native_entries'] = $nativeCount;
            $this->countError('native_count_mismatch', $nativeCount !== ($this->counts['source_valid_entries'] ?? 0));
            $this->countError('metadata_count_mismatch', (int) ($catalog->metadata['import_expected_entries'] ?? -1) !== $nativeCount);
            $this->countError('currency_mismatch', $catalog->currency_code !== 'EUR');
            $sampleContext = $this->auditEffectiveSamples($catalog, $resolver);
            if ($this->option('cover-groups')) {
                $this->auditGroupCoverage($catalog, $resolver, $sampleContext);
            }
            $this->countError('catalog_changed_during_audit', $before !== $this->catalogFingerprint($catalog->fresh()));

            $this->table(['Aggregate check', 'Count'], collect($this->counts + $this->errors)->map(fn ($value, $key) => [$key, $value])->all());
            if (array_sum($this->errors) > 0) {
                $this->error('FAIL: pricing is not ready for activation. No data was changed.');

                return self::FAILURE;
            }
            $this->info('PASS: supported charged-price tables match; the bounded effective-price sample matches the legacy algorithm. No data was changed.');
            $this->line($this->counts['reference_rows'] > 0
                ? 'Reference-price display is outside this parity proof. Archived anchor rows are present; both source display features are verified disabled.'
                : 'Reference-price display is outside this parity proof. No source anchor rows are present.');
            $this->line($this->option('cover-groups')
                ? 'Every usable mapped group is covered; scheduled and quantity boundaries use bounded representative rows, not every effective combination. Groups without usable accounts are reported separately.'
                : 'Effective combinations are sampled, not exhaustively enumerated. Taxes, order history, SEO and media require their separate audits.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // SQL exceptions can include customer data. Never emit their message or bindings.
            $this->error(get_class($exception) === RuntimeException::class
                ? $exception->getMessage()
                : 'Audit stopped safely ('.get_class($exception).'). No data was changed.');

            return self::FAILURE;
        }
    }

    private function guard(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pricing audit is restricted to local or testing environments.');
        }
        $this->prefix = (string) $this->option('prefix');
        if (! preg_match('/^[a-zA-Z0-9_]*$/', $this->prefix)
            || ! ctype_digit((string) $this->option('samples'))
            || (int) $this->option('samples') < 1 || (int) $this->option('samples') > 2000
            || ($this->option('catalog') !== null && (! ctype_digit((string) $this->option('catalog')) || (int) $this->option('catalog') < 1))) {
            throw new RuntimeException('Invalid prefix, catalog or sample limit.');
        }
        $target = DB::connection();
        $this->source = DB::connection((string) $this->option('source-connection'));
        foreach ([$target, $this->source] as $connection) {
            \App\Services\Import\HerreraCutoverSafety::assertNoSplitConnection($connection->getConfig());
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
                && ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
                throw new RuntimeException('Only isolated local database connections are permitted.');
            }
            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
                throw new RuntimeException('This audit supports local MySQL/MariaDB and isolated SQLite tests only.');
            }
        }
        if ($target === $this->source || ($target->getDatabaseName() === $this->source->getDatabaseName()
            && ! ($target->getDriverName() === 'sqlite' && $target->getDatabaseName() === ':memory:'))) {
            throw new RuntimeException('Source and target must be separate databases.');
        }
        if (app()->environment('local') && $target->getDatabaseName() !== 'herrera_new_migration'
            && ! \App\Services\Import\HerreraCutoverSafety::rehearsalDatabase($target->getDatabaseName())) {
            throw new RuntimeException('Local audit target must be herrera_new_migration.');
        }
        foreach ([...array_keys(self::DEFINITIONS), 'customer'] as $table) {
            if (! $this->source->getSchemaBuilder()->hasTable($this->prefix.$table)) {
                throw new RuntimeException('Required source pricing tables are missing.');
            }
        }
        foreach (['herrera_import_maps', 'catalog_price_catalogs', 'catalog_price_entries', 'herrera_source_records'] as $table) {
            if (! $target->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException('Required target migration tables are missing.');
            }
        }
        if (! (bool) config('commerce.b2b_only', true)) {
            throw new RuntimeException('Enable strict B2B mode before auditing Herrera pricing.');
        }
    }

    private function loadMaps(): void
    {
        $this->maps = $this->counts = $this->errors = [];
        foreach (DB::table('herrera_import_maps')->where('source', 'herrera-opencart')
            ->whereIn('entity', ['product', 'customer_group', 'customer'])->get(['entity', 'source_id', 'target_id']) as $map) {
            $this->maps[$map->entity][(string) $map->source_id] = (int) $map->target_id;
        }
    }

    private function auditUnsupportedPriceScopes(): void
    {
        $schema = $this->source->getSchemaBuilder();
        $unsupported = 0;
        foreach (['product_option_price_by_cigroup', 'product_customergroup_price'] as $table) {
            $rows = $schema->hasTable($this->prefix.$table) ? $this->source->table($this->prefix.$table)->count() : 0;
            $this->counts['unsupported_'.$table.'_rows'] = $rows;
            $unsupported += $rows;
        }
        $this->countError('unsupported_charged_price_scope', $unsupported > 0);
        $this->counts['reference_rows'] = $schema->hasTable($this->prefix.'product_anchor_price')
            ? $this->source->table($this->prefix.'product_anchor_price')->count() : 0;
        $settingsAvailable = $schema->hasTable($this->prefix.'setting') && $schema->hasColumns($this->prefix.'setting', ['store_id', 'key', 'value']);
        $unavailable = 0;
        $enabled = false;
        foreach (['module_anchor_price_status', 'module_digital_pricelist_status'] as $key) {
            $values = $settingsAvailable
                ? $this->source->table($this->prefix.'setting')->where('store_id', 0)->where('key', $key)->pluck('value')
                : collect();
            $value = $values->count() === 1 ? trim((string) $values->first()) : '';
            $verified = in_array($value, ['0', '1'], true);
            $this->counts[$key] = $verified ? (int) $value : -1;
            $unavailable += (int) ! $verified;
            $enabled = $enabled || ($verified && $value === '1');
        }
        $this->counts['reference_display_flags_unavailable'] = $unavailable;
        $this->counts['reference_display_unverified_rows'] = $this->counts['reference_rows'];
        $this->countError('unsupported_active_reference_display', $this->counts['reference_rows'] > 0 && ($enabled || $unavailable > 0));
    }

    private function auditSourceTable(PriceCatalog $catalog, string $table, string $kind, ?string $groupKey, ?string $customerKey, $digest): void
    {
        $this->counts['source_'.$kind] = 0;
        $this->counts['valid_'.$kind] = 0;
        $this->counts['orphan_'.$kind] = 0;
        $buffer = [];
        $columns = $this->source->getSchemaBuilder()->getColumnListing($this->prefix.$table);
        $query = $this->source->table($this->prefix.$table);
        // Match the importer's deterministic row order and JSON representation exactly.
        foreach (array_slice($columns, 0, 3) as $column) {
            $query->orderBy($column);
        }
        foreach ($query->cursor() as $record) {
            $row = (array) $record;
            hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
            $this->counts['source_'.$kind]++;
            $this->countError('invalid_source_price', BigDecimal::of($this->decimal($row['price']))->compareTo(0) < 0);
            $start = $this->importedDate($row['date_start'] ?? null);
            $end = $this->importedDate($row['date_end'] ?? null);
            $this->countError('invalid_source_price_window', $start !== null && $end !== null && $end <= $start);
            $product = $this->maps['product'][(string) $row['product_id']] ?? null;
            $group = $groupKey ? ($this->maps['customer_group'][(string) $row[$groupKey]] ?? null) : null;
            $user = $customerKey ? ($this->maps['customer'][(string) $row[$customerKey]] ?? null) : null;
            $valid = $product !== null && (! $groupKey || $group !== null) && (! $customerKey || $user !== null);
            $key = $table.':'.($row['product_special_id'] ?? $row['product_discount_id'] ?? ($kind === 'base' ? $row['product_id'] : ($row['product_id'].':'.($row[$groupKey ?? $customerKey] ?? 0).':'.($row['category_id'] ?? 0))));
            $this->counts[($valid ? 'valid_' : 'orphan_').$kind]++;
            if ($valid) {
                $this->counts['source_valid_entries'] = ($this->counts['source_valid_entries'] ?? 0) + 1;
            }
            $buffer[] = compact('row', 'product', 'group', 'user', 'valid', 'key', 'kind');
            if (count($buffer) >= 500) {
                $this->compareBuffer($catalog, $table, $buffer);
                $buffer = [];
            }
        }
        $this->compareBuffer($catalog, $table, $buffer);
    }

    private function compareBuffer(PriceCatalog $catalog, string $table, array $buffer): void
    {
        if ($buffer === []) {
            return;
        }
        $entries = DB::table('catalog_price_entries')->where('price_catalog_id', $catalog->id)
            ->whereIn('source_key', array_column($buffer, 'key'))->get()->keyBy('source_key');
        $orphanKeys = array_column(array_filter($buffer, fn ($expected) => ! $expected['valid']), 'key');
        $archives = $orphanKeys === [] ? collect() : DB::table('herrera_source_records')->where('source_table', $table)->whereIn('source_key', $orphanKeys)->get(['source_key', 'payload'])->keyBy('source_key');
        $baseIds = array_column(array_filter($buffer, fn ($expected) => $expected['kind'] === 'base' && $expected['valid']), 'product');
        $products = $baseIds === [] ? collect() : DB::table('products')->whereIn('id', $baseIds)->pluck('base_price', 'id');
        foreach ($buffer as $expected) {
            $row = $expected['row'];
            $entry = $entries->get($expected['key']);
            if (! $expected['valid']) {
                $this->countError('unmapped_price_published', $entry !== null);
                $archive = $archives->get($expected['key']);
                $this->countError('orphan_archive_missing_or_changed', ! $archive || ! $this->samePayload(json_decode($archive->payload, true, flags: JSON_THROW_ON_ERROR), $row));
                $this->countError('source_product_map_missing', $expected['kind'] === 'base');

                continue;
            }
            if ($expected['kind'] === 'base') {
                $this->counts['native_base_checked'] = ($this->counts['native_base_checked'] ?? 0) + 1;
                $this->countError('native_base_missing_or_changed', ! $products->has($expected['product']) || $this->decimal($products[$expected['product']]) !== $this->decimal($row['price']));
            }
            $this->countError('native_entry_missing', ! $entry);
            if (! $entry) {
                continue;
            }
            $payload = json_decode($entry->payload ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $this->countError('entry_price_changed', $this->decimal($entry->price) !== $this->decimal($row['price']));
            $this->countError('entry_source_payload_changed', ! $this->samePayload($payload['opencart'] ?? [], $row));
            $dimensionsMatch = (int) $entry->product_id === $expected['product'] && $entry->kind === $expected['kind']
                && ($entry->customer_group_id === null ? null : (int) $entry->customer_group_id) === $expected['group']
                && ($entry->user_id === null ? null : (int) $entry->user_id) === $expected['user']
                && (int) $entry->minimum_quantity === ($expected['kind'] === 'base' ? 1 : max(1, (int) ($row['quantity'] ?? 1)))
                && (int) $entry->priority === (int) ($row['priority'] ?? 0)
                && $entry->starts_at === $this->importedDate($row['date_start'] ?? null)
                && $entry->ends_at === $this->importedDate($row['date_end'] ?? null)
                && (bool) $entry->is_active;
            $this->countError('entry_dimensions_changed', ! $dimensionsMatch);
        }
    }

    private function auditEffectiveSamples(PriceCatalog $catalog, PriceCatalogResolver $resolver): array
    {
        $maximum = (int) $this->option('samples');
        $sourceCustomers = $this->source->table($this->prefix.'customer')->get(['customer_id', 'customer_group_id', 'status'])->keyBy('customer_id');
        $reverseCustomers = array_flip($this->maps['customer'] ?? []);
        $reverseProducts = array_flip($this->maps['product'] ?? []);
        $accounts = DB::table('b2b_accounts')->where('status', 'approved')->get(['user_id', 'customer_group_id']);
        $eligible = $byGroup = [];
        foreach ($accounts as $account) {
            $sourceId = $reverseCustomers[(int) $account->user_id] ?? null;
            $sourceCustomer = $sourceId !== null ? $sourceCustomers->get($sourceId) : null;
            if (! $sourceCustomer || ! (bool) $sourceCustomer->status) {
                continue;
            }
            $expectedGroup = $this->maps['customer_group'][(string) $sourceCustomer->customer_group_id] ?? null;
            $this->countError('approved_account_primary_group_changed', (int) $account->customer_group_id !== $expectedGroup);
            if ((int) $account->customer_group_id === $expectedGroup) {
                $eligible[(int) $account->user_id] = $sourceCustomer;
                $byGroup[(int) $account->customer_group_id] ??= (int) $account->user_id;
            }
        }
        $perKind = max(1, (int) ceil($maximum / 5));
        $checked = $seen = [];
        $at = now()->format('Y-m-d H:i:s');
        foreach (['special', 'quantity', 'customer', 'group', 'base'] as $kind) {
            $kindChecks = 0;
            foreach ($catalog->entries()->where('kind', $kind)->orderBy('id')->limit($maximum * 10)->get(['id', 'product_id', 'customer_group_id', 'user_id', 'minimum_quantity']) as $entry) {
                if (count($checked) >= $maximum || $kindChecks >= $perKind) {
                    break;
                }
                $userId = $kind === 'customer' ? (int) $entry->user_id
                    : ($entry->customer_group_id ? ($byGroup[(int) $entry->customer_group_id] ?? null) : array_key_first($eligible));
                $sourceCustomer = $userId ? ($eligible[$userId] ?? null) : null;
                $sourceProductId = $reverseProducts[(int) $entry->product_id] ?? null;
                if (! $sourceCustomer || $sourceProductId === null) {
                    continue;
                }
                $quantity = max(1, (int) $entry->minimum_quantity);
                $sampleKey = $userId.':'.$entry->product_id.':'.$quantity;
                if (isset($seen[$sampleKey])) {
                    continue;
                }
                $seen[$sampleKey] = true;
                $product = Product::query()->find($entry->product_id);
                $user = User::query()->with('b2bAccount.customerGroup')->find($userId);
                $expected = $this->legacyPrice((int) $sourceProductId, (int) $sourceCustomer->customer_id, (int) $sourceCustomer->customer_group_id, $quantity, $at);
                $actual = $product && $user ? $resolver->resolve($product, $user, $quantity, catalog: $catalog, at: $at) : null;
                $this->countError('effective_price_mismatch', ! $actual || $this->decimal(number_format($actual->price, 4, '.', '')) !== $this->decimal($expected['price']) || $actual->source_type !== 'price_catalog_'.$expected['kind']);
                $this->counts['effective_source_'.$expected['kind']] = ($this->counts['effective_source_'.$expected['kind']] ?? 0) + 1;
                $checked[] = true;
                $kindChecks++;
            }
        }
        $this->counts['effective_samples_checked'] = count($checked);
        $this->counts['approved_source_accounts_available'] = count($eligible);
        $this->countError('effective_sample_unavailable', $checked === []);

        return compact('eligible', 'reverseProducts', 'at');
    }

    /** Select-only supplement. No synthetic customer identities or account changes are needed. */
    private function auditGroupCoverage(PriceCatalog $catalog, PriceCatalogResolver $resolver, array $context): void
    {
        $metrics = [
            'coverage_enabled', 'coverage_mapped_groups', 'coverage_groups_with_usable_accounts', 'coverage_groups_without_usable_accounts',
            'coverage_groups_checked', 'coverage_groups_without_mapped_products', 'coverage_scenarios_planned', 'coverage_scenarios_checked',
            'coverage_scenarios_unchecked', 'coverage_date_boundaries_checked', 'coverage_quantity_boundaries_checked',
            'coverage_groups_with_group_prices', 'coverage_groups_with_special_prices', 'coverage_groups_with_quantity_prices',
            'coverage_group_price_groups_checked', 'coverage_special_groups_checked', 'coverage_quantity_groups_checked',
            'coverage_groups_without_group_prices', 'coverage_groups_without_special_prices', 'coverage_groups_without_quantity_prices',
            'coverage_special_rows_sampled', 'coverage_quantity_rows_sampled',
        ];
        foreach ($metrics as $metric) {
            $this->counts[$metric] = 0;
        }
        $this->counts['coverage_enabled'] = 1;
        $this->counts['effective_scenario_limit'] = static::MAX_EFFECTIVE_SCENARIOS;
        $at = CarbonImmutable::parse($context['at']);
        $users = User::query()->with('b2bAccount.customerGroup')->whereIn('id', array_keys($context['eligible']))->orderBy('id')->get()->keyBy('id');
        $bySourceGroup = [];
        foreach ($users as $userId => $user) {
            $sourceCustomer = $context['eligible'][$userId];
            if ($user && app(B2BAccessService::class)->approvedAccount($user)) {
                $bySourceGroup[(int) $sourceCustomer->customer_group_id] ??= $user;
            }
        }
        $products = Product::query()->whereIn('id', array_values($this->maps['product'] ?? []))->orderBy('id')->get()->keyBy('id');
        $sourceProductIds = array_flip($this->maps['product'] ?? []);
        $firstProduct = $products->first();
        $groups = [];
        $mappedGroups = $this->maps['customer_group'] ?? [];
        ksort($mappedGroups, SORT_NUMERIC);
        foreach ($mappedGroups as $sourceGroupId => $targetGroupId) {
            $this->counts['coverage_mapped_groups']++;
            $user = $bySourceGroup[(int) $sourceGroupId] ?? null;
            if (! $user) {
                $this->counts['coverage_groups_without_usable_accounts']++;

                continue;
            }
            $this->counts['coverage_groups_with_usable_accounts']++;
            if (! $firstProduct) {
                $this->counts['coverage_groups_without_mapped_products']++;

                continue;
            }
            $rows = [];
            foreach (['group' => ['product_price_by_cigroup', 'group_id', 'product_id'], 'special' => ['product_special', 'customer_group_id', 'product_special_id'], 'quantity' => ['product_discount', 'customer_group_id', 'product_discount_id']] as $kind => [$table, $column, $order]) {
                $representatives = [];
                foreach ($this->source->table($this->prefix.$table)->where($column, $sourceGroupId)->orderBy($order)->cursor() as $record) {
                    $targetProductId = $this->maps['product'][(string) $record->product_id] ?? null;
                    if (! $targetProductId || ! $products->has($targetProductId)) {
                        continue;
                    }
                    $stratum = $kind === 'group' ? 'group' : $this->scheduleStratum((array) $record, $at);
                    $representatives[$stratum] ??= (array) $record;
                    if ($kind === 'group' || count($representatives) === 4) {
                        break;
                    }
                }
                $rows[$kind] = array_values($representatives);
                $this->counts['coverage_groups_'.($representatives ? 'with_' : 'without_').$kind.'_prices']++;
                if ($kind !== 'group') {
                    $this->counts['coverage_'.$kind.'_rows_sampled'] += count($representatives);
                }
            }
            $groups[] = compact('sourceGroupId', 'user', 'rows') + ['sourceCustomer' => $context['eligible'][$user->id]];
        }
        $seen = $planned = $checkedGroups = $checkedKinds = [];
        $check = function (array $group, array $row, int $quantity, string $time, string $origin, string $boundary = '') use ($catalog, $resolver, $products, $sourceProductIds, &$seen, &$planned, &$checkedGroups, &$checkedKinds): void {
            $productId = $this->maps['product'][(string) $row['product_id']] ?? null;
            $product = $productId ? $products->get($productId) : null;
            if (! $product) {
                return;
            }
            $key = $group['user']->id.':'.$productId.':'.$quantity.':'.$time;
            if (! isset($planned[$key])) {
                $planned[$key] = true;
                $this->counts['coverage_scenarios_planned']++;
            }
            if (! isset($seen[$key])) {
                if (($this->counts['effective_samples_checked'] ?? 0) + $this->counts['coverage_scenarios_checked'] >= static::MAX_EFFECTIVE_SCENARIOS) {
                    return;
                }
                $sourceCustomer = $group['sourceCustomer'];
                if (! $sourceCustomer) {
                    return;
                }
                $expected = $this->legacyPrice((int) $sourceProductIds[$productId], (int) $sourceCustomer->customer_id, (int) $group['sourceGroupId'], $quantity, $time);
                $actual = $resolver->resolve($product, $group['user'], $quantity, catalog: $catalog, at: $time);
                $this->countError('coverage_effective_price_mismatch', ! $actual || $this->decimal(number_format($actual->price, 4, '.', '')) !== $this->decimal($expected['price']) || $actual->source_type !== 'price_catalog_'.$expected['kind']);
                $this->counts['coverage_effective_source_'.$expected['kind']] = ($this->counts['coverage_effective_source_'.$expected['kind']] ?? 0) + 1;
                $seen[$key] = true;
                $this->counts['coverage_scenarios_checked']++;
            }
            $checkedGroups[(int) $group['sourceGroupId']] = true;
            if ($origin !== 'base') {
                $checkedKinds[$origin][(int) $group['sourceGroupId']] = true;
            }
            if ($boundary !== '' && ! isset($seen[$key.':'.$boundary])) {
                $seen[$key.':'.$boundary] = true;
                $this->counts['coverage_'.$boundary.'_boundaries_checked']++;
            }
        };
        // Reserve one applicable scenario for each group and kind before spending the budget on boundaries.
        foreach ($groups as $group) {
            $check($group, ['product_id' => $sourceProductIds[$firstProduct->id]], 1, $context['at'], 'base');
            foreach ($group['rows'] as $kind => $rows) {
                if ($row = $rows[0] ?? null) {
                    $quantity = max(1, (int) ($row['quantity'] ?? 1));
                    $check($group, $row, $quantity, $this->interiorTime($row, $at), $kind);
                }
            }
        }
        foreach ($groups as $group) {
            foreach (['special', 'quantity'] as $kind) {
                foreach ($group['rows'][$kind] as $row) {
                    $threshold = max(1, (int) ($row['quantity'] ?? 1));
                    $interior = $this->interiorTime($row, $at);
                    $check($group, $row, $threshold, $context['at'], $kind);
                    foreach (array_unique([max(1, $threshold - 1), $threshold, $threshold + 1]) as $quantity) {
                        $check($group, $row, $quantity, $interior, $kind, 'quantity');
                    }
                    foreach (['date_start', 'date_end'] as $column) {
                        if ($date = $this->sourcePriceDate($row[$column] ?? null)) {
                            foreach ([-1, 0, 1] as $offset) {
                                $check($group, $row, $threshold, CarbonImmutable::parse($date)->addSeconds($offset)->format('Y-m-d H:i:s'), $kind, 'date');
                            }
                        }
                    }
                }
            }
        }
        $this->counts['coverage_groups_checked'] = count($checkedGroups);
        foreach (['group', 'special', 'quantity'] as $kind) {
            $this->counts[$kind === 'group' ? 'coverage_group_price_groups_checked' : 'coverage_'.$kind.'_groups_checked'] = count($checkedKinds[$kind] ?? []);
        }
        $this->counts['coverage_scenarios_unchecked'] = $this->counts['coverage_scenarios_planned'] - $this->counts['coverage_scenarios_checked'];
        $this->counts['effective_scenarios_total'] = ($this->counts['effective_samples_checked'] ?? 0) + $this->counts['coverage_scenarios_checked'];
        $this->countError('coverage_limit_exceeded', $this->counts['coverage_scenarios_unchecked'] > 0);
        $this->countError('coverage_usable_group_unchecked', count($checkedGroups) !== $this->counts['coverage_groups_with_usable_accounts']);
    }

    private function sourcePriceDate(?string $date): ?string
    {
        // Legacy predicates exempt only the zero date; native import normalization must not define the oracle.
        if (! $date || str_starts_with($date, '0000-')) {
            return null;
        }

        return strlen($date) === 10 ? $date.' 00:00:00' : $date;
    }

    private function scheduleStratum(array $row, CarbonImmutable $at): string
    {
        $start = $this->sourcePriceDate($row['date_start'] ?? null);
        $end = $this->sourcePriceDate($row['date_end'] ?? null);
        if (! $start && ! $end) {
            return 'unbounded';
        }
        if ($start && CarbonImmutable::parse($start)->gte($at)) {
            return 'future';
        }

        return $end && CarbonImmutable::parse($end)->lte($at) ? 'expired' : 'active';
    }

    private function interiorTime(array $row, CarbonImmutable $at): string
    {
        $start = $this->sourcePriceDate($row['date_start'] ?? null);
        $end = $this->sourcePriceDate($row['date_end'] ?? null);

        return ($start ? CarbonImmutable::parse($start)->addSecond() : ($end ? CarbonImmutable::parse($end)->subSecond() : $at))->format('Y-m-d H:i:s');
    }

    /** Read the old algorithm independently from source tables, never from the copied payload. */
    private function legacyPrice(int $productId, int $customerId, int $groupId, int $quantity, string $at): array
    {
        $price = $this->source->table($this->prefix.'product')->where('product_id', $productId)->value('price');
        $result = ['price' => $price, 'kind' => 'base'];
        foreach ([
            ['product_price_by_cigroup', 'group', fn (Builder $q) => $q->where('group_id', $groupId)],
            ['product_price_by_customer_id', 'customer', fn (Builder $q) => $q->where('customer_id', $customerId)],
            ['product_discount', 'quantity', fn (Builder $q) => $this->legacyQuantity($q, $groupId, $quantity, $at)],
            ['product_special', 'special', fn (Builder $q) => $this->legacyPriority($this->activeLegacy($q, $at)->where('customer_group_id', $groupId))],
        ] as [$table, $kind, $scope]) {
            $row = $scope($this->source->table($this->prefix.$table)->where('product_id', $productId))->first(['price']);
            if ($row) {
                $result = ['price' => $row->price, 'kind' => $kind];
            }
        }

        return $result;
    }

    private function legacyQuantity(Builder $query, int $groupId, int $quantity, string $at): Builder
    {
        $query = $this->activeLegacy($query, $at)->where('customer_group_id', $groupId);
        $query = $this->source->getDriverName() === 'sqlite'
            ? $query->whereRaw('CAST(quantity AS INTEGER) <= ?', [$quantity])->orderByRaw('CAST(quantity AS INTEGER) DESC')
            : $query->where('quantity', '<=', $quantity)->orderByDesc('quantity');

        return $this->legacyPriority($query);
    }

    private function legacyPriority(Builder $query): Builder
    {
        return $this->source->getDriverName() === 'sqlite'
            ? $query->orderByRaw('CAST(priority AS INTEGER)')->orderByRaw('CAST(price AS NUMERIC)')
            : $query->orderBy('priority')->orderBy('price');
    }

    private function activeLegacy(Builder $query, string $at): Builder
    {
        // SQLite test snapshots store DATE values as text; normalize midnight to match real MySQL DATE comparisons.
        if ($this->source->getDriverName() === 'sqlite') {
            return $query->where(fn (Builder $q) => $q->where('date_start', '0000-00-00')->orWhereRaw("(CASE WHEN LENGTH(date_start) = 10 THEN date_start || ' 00:00:00' ELSE date_start END) < ?", [$at]))
                ->where(fn (Builder $q) => $q->where('date_end', '0000-00-00')->orWhereRaw("(CASE WHEN LENGTH(date_end) = 10 THEN date_end || ' 00:00:00' ELSE date_end END) > ?", [$at]));
        }

        return $query->where(fn (Builder $q) => $q->where('date_start', '0000-00-00')->orWhere('date_start', '<', $at))
            ->where(fn (Builder $q) => $q->where('date_end', '0000-00-00')->orWhere('date_end', '>', $at));
    }

    private function importedDate(?string $date): ?string
    {
        if (! $date || str_starts_with($date, '0000-') || str_starts_with($date, '1970-01-01')) {
            return null;
        }

        return strlen($date) === 10 ? $date.' 00:00:00' : $date;
    }

    private function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(4);
    }

    private function samePayload(array $actual, array $expected): bool
    {
        ksort($actual);
        ksort($expected);

        return $actual === $expected;
    }

    private function countError(string $metric, bool $failed): void
    {
        $this->errors[$metric] = ($this->errors[$metric] ?? 0) + (int) $failed;
    }

    private function catalogFingerprint(PriceCatalog $catalog): array
    {
        return [$catalog->status, $catalog->source_checksum, $catalog->metadata, $catalog->updated_at?->format('Y-m-d H:i:s'), $catalog->entries()->count(), $catalog->entries()->max('updated_at')];
    }
}
