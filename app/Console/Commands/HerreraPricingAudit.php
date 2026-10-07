<?php

namespace App\Console\Commands;

use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Pricing\PriceCatalogResolver;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Select-only migration gate. No customer identifiers or contract amounts are printed. */
class HerreraPricingAudit extends Command
{
    protected $signature = 'herrera:audit-pricing {--source-connection=herrera_source} {--prefix=oc_} {--catalog= : Original imported catalog ID; defaults to the first catalog for this snapshot} {--samples=200 : Effective-price sample limit, between 1 and 2000}';

    protected $description = 'Read-only local Herrera audit of all source prices and a bounded legacy effective-price sample';

    private ConnectionInterface $source;

    private string $prefix;

    private array $maps = [];

    private array $counts = [];

    private array $errors = [];

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
            $this->auditEffectiveSamples($catalog, $resolver);
            $this->countError('catalog_changed_during_audit', $before !== $this->catalogFingerprint($catalog->fresh()));

            $this->table(['Aggregate check', 'Count'], collect($this->counts + $this->errors)->map(fn ($value, $key) => [$key, $value])->all());
            if (array_sum($this->errors) > 0) {
                $this->error('FAIL: pricing is not ready for activation. No data was changed.');

                return self::FAILURE;
            }
            $this->info('PASS: all stored source prices match; the bounded effective-price sample matches the legacy algorithm. No data was changed.');
            $this->line('Effective combinations are sampled, not exhaustively enumerated. Taxes, order history, SEO and media require their separate audits.');

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
        if (app()->environment('local') && $target->getDatabaseName() !== 'herrera_new_migration') {
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

    private function auditEffectiveSamples(PriceCatalog $catalog, PriceCatalogResolver $resolver): void
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
    }

    /** Read the old algorithm independently from source tables, never from the copied payload. */
    private function legacyPrice(int $productId, int $customerId, int $groupId, int $quantity, string $at): array
    {
        $price = $this->source->table($this->prefix.'product')->where('product_id', $productId)->value('price');
        $result = ['price' => $price, 'kind' => 'base'];
        foreach ([
            ['product_price_by_cigroup', 'group', fn (Builder $q) => $q->where('group_id', $groupId)],
            ['product_price_by_customer_id', 'customer', fn (Builder $q) => $q->where('customer_id', $customerId)],
            ['product_discount', 'quantity', fn (Builder $q) => $this->activeLegacy($q, $at)->where('customer_group_id', $groupId)->where('quantity', '<=', $quantity)->orderByDesc('quantity')->orderBy('priority')->orderBy('price')],
            ['product_special', 'special', fn (Builder $q) => $this->activeLegacy($q, $at)->where('customer_group_id', $groupId)->orderBy('priority')->orderBy('price')],
        ] as [$table, $kind, $scope]) {
            $row = $scope($this->source->table($this->prefix.$table)->where('product_id', $productId))->first(['price']);
            if ($row) {
                $result = ['price' => $row->price, 'kind' => $kind];
            }
        }

        return $result;
    }

    private function activeLegacy(Builder $query, string $at): Builder
    {
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
