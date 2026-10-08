<?php

namespace App\Services\Import;

use App\Models\Catalog\Category\Category;
use App\Models\User\LegacyCredential;
use App\Support\ImportedDescriptionHtmlCleaner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/** A resumable, source-owned import. The source connection is SELECT-only. */
class HerreraOpenCartImportService
{
    private ConnectionInterface $source;

    private string $prefix;

    private string $snapshot;

    private array $maps = [];

    private array $checksums = [];

    private array $locales = [];

    private array $keywords = [];

    private array $stats = [];

    private array $countries = [];

    private array $columnCache = [];

    /** Non-null only during the explicit assigned-customer prerequisite. */
    private ?array $missingAssignedCustomerIds = null;

    /** Original cache values for writes in the current atomic order chunk. */
    private ?array $orderChunkMapUndo = null;

    public function __construct(private readonly ImportedDescriptionHtmlCleaner $cleaner) {}

    public function importMissingAssignedCustomers(string $connection, string $prefix = 'oc_'): array
    {
        if (! preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
            throw new RuntimeException('Invalid source table prefix.');
        }
        $this->source = DB::connection($connection);
        $this->prefix = $prefix;
        $this->snapshot = $this->source->getDatabaseName();
        if (app()->environment(['local', 'testing'])) {
            $this->guardSource();
        } elseif (! app()->environment('staging')
            || DB::connection()->getDatabaseName() !== 'herrera_redesign'
            || rtrim((string) config('app.url'), '/') !== 'https://herrera.herrera.hr'
            || $this->source === DB::connection()
            || $this->snapshot === DB::connection()->getDatabaseName()) {
            throw new RuntimeException('Assigned-customer prerequisites are restricted to the verified Herrera test shop.');
        }

        if (! $this->has('customer_to_user') || ! $this->has('customer')) {
            throw new RuntimeException('The assigned-customer source is incomplete.');
        }
        $this->maps = $this->checksums = $this->stats = $this->countries = [];
        DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->orderBy('id')->chunkById(5000, function ($maps): void {
            foreach ($maps as $map) {
                $this->maps[$map->entity][(string) $map->source_id] = (int) $map->target_id;
                $this->checksums[$map->entity][(string) $map->source_id] = $map->checksum;
            }
        });
        $missing = $this->source->table($this->table('customer_to_user'))->distinct()->pluck('customer_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && ! $this->id('customer', $id))
            ->unique()->values()->all();
        $report = ['missing_assigned_customers' => count($missing), 'imported_assigned_customers' => 0, 'imported_assigned_addresses' => 0];
        if ($missing === []) {
            return $report;
        }

        $rows = $this->source->table($this->table('customer'))->whereIn('customer_id', $missing)->get();
        if ($rows->count() !== count($missing)) {
            throw new RuntimeException('An assigned customer is missing from the source; no customers were imported.');
        }
        foreach ($rows as $row) {
            $group = $this->id('customer_group', $row->customer_group_id);
            if (! $row->status || ! $group || ! DB::table('customer_groups')->where('id', $group)->where('is_active', true)->exists()) {
                throw new RuntimeException('An assigned customer is disabled or lacks an existing active mapped group.');
            }
            $email = strtolower(trim((string) $row->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)
                || DB::table('users')->where('account_type', 'customer')->where('email', $email)->exists()
                || $this->id('profile', $row->customer_id) || $this->id('b2b_account', $row->customer_id)) {
                throw new RuntimeException('An assigned customer has a conflicting identity or existing dependent mapping.');
            }
        }
        if ($this->has('address')) {
            foreach ($this->source->table($this->table('address'))->whereIn('customer_id', $missing)->pluck('address_id') as $addressId) {
                if ($this->id('address', $addressId)) {
                    throw new RuntimeException('An assigned customer address already has a target mapping.');
                }
            }
        }
        $this->each('country', function (array $row): void {
            $this->countries[$row['country_id']] = strtoupper($row['iso_code_2'] ?: 'HR');
        });

        $this->missingAssignedCustomerIds = $missing;
        try {
            return DB::transaction(function () use ($missing, $report): array {
                $this->customers();
                foreach ($missing as $id) {
                    if (! $this->id('customer', $id)) {
                        throw new RuntimeException('Assigned-customer prerequisites could not be completed; no customers were imported.');
                    }
                }

                return array_replace($report, [
                    'imported_assigned_customers' => (int) ($this->stats['customer_written'] ?? 0),
                    'imported_assigned_addresses' => (int) ($this->stats['address_written'] ?? 0),
                ]);
            });
        } finally {
            $this->missingAssignedCustomerIds = null;
        }
    }

    public function import(string $connection = 'herrera_source', string $prefix = 'oc_', bool $dryRun = false, array $only = [], ?callable $progress = null): array
    {
        if (! preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
            throw new RuntimeException('Invalid source table prefix.');
        }
        $this->source = DB::connection($connection);
        $this->prefix = $prefix;
        $this->snapshot = $this->source->getDatabaseName();
        $this->guardSource();
        $stages = $only ?: ['catalog', 'customers', 'billing_addresses', 'pricing', 'orders', 'seo', 'relations', 'archive'];
        if (array_diff($stages, ['catalog', 'content', 'base_prices', 'supplier_stock', 'customers', 'billing_addresses', 'pricing', 'orders', 'order_identity', 'seo', 'relations', 'archive'])) {
            throw new RuntimeException('Unknown import stage.');
        }
        if (in_array('supplier_stock', $stages, true)) {
            if ($stages !== ['supplier_stock']) {
                throw new RuntimeException('Supplier stock repair must run as a separate explicit stage.');
            }
            $this->guardSupplierStockRepair();
        }
        $this->stats = ['source_snapshot' => $this->snapshot];
        foreach (['category', 'manufacturer', 'product', 'customer_group', 'customer', 'address', 'product_price_by_cigroup', 'product_price_by_customer_id', 'product_special', 'product_discount', 'order', 'seo_url', 'hb_url_preserve'] as $table) {
            $this->stats['source_'.$table] = $this->has($table) ? $this->source->table($this->table($table))->count() : 0;
        }
        if ($dryRun) {
            return $this->stats;
        }
        $run = DB::table('herrera_import_runs')->insertGetId(['source_snapshot' => $this->snapshot, 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        $this->maps = $this->checksums = $this->keywords = $this->locales = [];
        DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->orderBy('id')->chunkById(5000, function ($maps): void {
            foreach ($maps as $map) {
                $this->maps[$map->entity][(string) $map->source_id] = (int) $map->target_id;
                $this->checksums[$map->entity][(string) $map->source_id] = $map->checksum;
            }
        });
        $this->each('language', function (array $row): void {
            $this->locales[$row['language_id']] = strtolower(explode('-', $row['code'])[0]);
        });
        $this->each('country', function (array $row): void {
            $this->countries[$row['country_id']] = strtoupper($row['iso_code_2'] ?: 'HR');
        });
        $this->each('seo_url', function (array $row): void {
            if ((int) ($row['store_id'] ?? 0) === 0 && trim($row['keyword']) !== '') {
                $this->keywords[$row['query']][$this->locale($row['language_id'])] ??= trim($row['keyword']);
            }
        });
        try {
            foreach ($stages as $stage) {
                if ($progress) {
                    $progress($stage);
                }
                $this->{$stage}();
            }
            $this->stats['unresolved_issues'] = DB::table('herrera_import_issues')->count();
            DB::table('herrera_import_runs')->where('id', $run)->update(['status' => 'completed', 'summary' => $this->json($this->stats), 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            DB::table('herrera_import_runs')->where('id', $run)->update(['status' => 'failed', 'summary' => $this->json($this->stats), 'updated_at' => now()]);
            throw $exception;
        }

        return $this->stats;
    }

    private function guardSource(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Import is restricted to local and testing environments.');
        }
        $target = DB::connection();
        if ($target === $this->source || ($target->getDatabaseName() === $this->snapshot && $target->getDriverName() === $this->source->getDriverName())) {
            throw new RuntimeException('Source and destination must be separate databases.');
        }
        if (in_array($this->source->getDriverName(), ['mysql', 'mariadb'], true) && ! in_array($this->source->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Only an isolated local source snapshot is permitted.');
        }
        if (in_array($target->getDriverName(), ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true) && ! in_array($target->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Only a local destination database is permitted.');
        }
    }

    private function catalog(): void
    {
        $weightClasses = $lengthClasses = [];
        $this->each('weight_class', function (array $row) use (&$weightClasses): void {
            $weightClasses[$row['weight_class_id']] = (float) $row['value'] ?: 1;
        });
        $this->each('length_class', function (array $row) use (&$lengthClasses): void {
            $lengthClasses[$row['length_class_id']] = (float) $row['value'] ?: 1;
        });
        $zeroTax = $this->mapped('tax_rate', 'zero', 'tax_rates', ['code' => 'herrera-oc-tax-exempt', 'name' => 'Bez PDV-a (OpenCart klasa 0)', 'rate' => 0, 'is_active' => true, 'is_default' => false], ['rate' => 0]);
        $taxClasses = [];
        $this->each('tax_rate', function (array $row) use (&$taxClasses): void {
            if ($row['type'] !== 'P') {
                $this->issue('tax_rate', $row['tax_rate_id'], 'fixed_tax_requires_review');

                return;
            }
            $id = $this->mapped('tax_rate', $row['tax_rate_id'], 'tax_rates', ['code' => 'herrera-oc-tax-'.$row['tax_rate_id'], 'name' => $row['name'], 'rate' => $row['rate'], 'is_active' => true, 'is_default' => false], $row);
            $taxClasses[$row['tax_rate_id']] = $id;
        });
        $classRates = [0 => $zeroTax];
        $this->each('tax_rule', function (array $row) use (&$classRates, $taxClasses): void {
            if (isset($taxClasses[$row['tax_rate_id']])) {
                $classRates[$row['tax_class_id']] ??= $taxClasses[$row['tax_rate_id']];
            }
        });
        $this->each('manufacturer', function (array $row): void {
            $id = $this->mapped('manufacturer', $row['manufacturer_id'], 'catalog_manufacturers', ['code' => 'herrera-oc-brand-'.$row['manufacturer_id'], 'is_active' => true, 'sort_order' => max(0, $row['sort_order']), 'payload' => $this->json(['opencart' => $row])], $row);
            $this->translation('catalog_manufacturer_translations', 'manufacturer_id', $id, 'hr', $row['name'], 'manufacturer_id='.$row['manufacturer_id']);
        });
        $this->each('category', function (array $row): void {
            $this->mapped('category', $row['category_id'], 'categories', ['scope' => 'catalog', 'code' => 'herrera-oc-category-'.$row['category_id'], 'is_active' => (bool) $row['status'], 'show_in_menu' => (bool) ($row['top'] ?? true), 'sort_order' => max(0, $row['sort_order']), 'payload' => $this->json(['opencart' => $row])], $row);
        });
        $this->each('category', function (array $row): void {
            $id = $this->id('category', $row['category_id']);
            $parent = $this->id('category', $row['parent_id']);
            if ($parent === $id) {
                $parent = null;
                $this->issue('category', $row['category_id'], 'self_parent');
            }
            DB::table('categories')->where('id', $id)->update(['parent_id' => $parent]);
        });
        Category::fixTree();
        $this->each('category_description', function (array $row): void {
            if ($id = $this->id('category', $row['category_id'])) {
                $this->translation('category_translations', 'category_id', $id, $this->locale($row['language_id']), $row['name'], 'category_id='.$row['category_id'], $row, ['scope' => 'catalog']);
            }
        });
        $this->each('product', function (array $row) use ($classRates, $weightClasses, $lengthClasses): void {
            $sku = trim((string) ($row['sku'] ?: $row['model']));
            $existing = $this->id('product', $row['product_id']);
            if ($sku !== '' && DB::table('products')->where('sku', $sku)->when($existing, fn ($q) => $q->where('id', '!=', $existing))->exists()) {
                $sku = mb_substr($sku, 0, 90).'-oc-'.$row['product_id'];
                $this->issue('product', $row['product_id'], 'duplicate_sku');
            }
            $barcode = trim((string) ($row['ean'] ?: ($row['upc'] ?: ''))) ?: null;
            if ($barcode && DB::table('products')->where('barcode', $barcode)->when($existing, fn ($q) => $q->where('id', '!=', $existing))->exists()) {
                // A suffixed EAN would be invented data. Preserve the original
                // identifier in private source metadata and flag reconciliation.
                $barcode = null;
                $this->issue('product', $row['product_id'], 'duplicate_barcode');
            }
            $this->mapped('product', $row['product_id'], 'products', [
                'code' => 'herrera-oc-product-'.$row['product_id'], 'sku' => $sku ?: null,
                'barcode' => $barcode, 'is_active' => (bool) $row['status'],
                'manufacturer_id' => $this->id('manufacturer', $row['manufacturer_id']), 'tax_rate_id' => $classRates[$row['tax_class_id']] ?? null,
                'base_price' => $row['price'], 'stock_qty' => $row['quantity'], 'supplier_stock_qty' => (int) ($row['suplierqty'] ?? 0), 'minimum_order_quantity' => max(1, $row['minimum']), 'order_quantity_step' => 1,
                'weight_kg' => (float) $row['weight'] / ($weightClasses[$row['weight_class_id'] ?? 0] ?? 1), 'length_cm' => (float) $row['length'] / ($lengthClasses[$row['length_class_id'] ?? 0] ?? 1), 'width_cm' => (float) $row['width'] / ($lengthClasses[$row['length_class_id'] ?? 0] ?? 1), 'height_cm' => (float) $row['height'] / ($lengthClasses[$row['length_class_id'] ?? 0] ?? 1),
                'payload' => $this->json(['opencart' => $row + ['gallery_images' => []]]),
            ], $row);
        });
        $this->each('product_description', function (array $row): void {
            if ($id = $this->id('product', $row['product_id'])) {
                $this->translation('product_translations', 'product_id', $id, $this->locale($row['language_id']), $row['name'], 'product_id='.$row['product_id'], $row);
            }
        });
        $this->each('product_to_category', function (array $row): void {
            $product = $this->id('product', $row['product_id']);
            $category = $this->id('category', $row['category_id']);
            if ($product && $category) {
                DB::table('category_product')->updateOrInsert(['product_id' => $product, 'category_id' => $category], ['sort_order' => 0, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $gallery = [];
        $this->each('product_image', function (array $row) use (&$gallery): void {
            $gallery[$row['product_id']][] = ['image' => $row['image'], 'sort_order' => $row['sort_order']];
        });
        foreach ($gallery as $sourceId => $images) {
            if ($id = $this->id('product', $sourceId)) {
                $payload = json_decode(DB::table('products')->where('id', $id)->value('payload') ?: '{}', true);
                $payload['opencart']['gallery_images'] = $images;
                DB::table('products')->where('id', $id)->update(['payload' => $this->json($payload)]);
            }
        }
        $this->attributes();
        $this->options();
        $this->content();
    }

    private function attributes(): void
    {
        $names = [];
        $groups = [];
        $this->each('attribute_group_description', function (array $row) use (&$groups): void {
            $groups[$row['attribute_group_id']][$row['language_id']] = $row['name'];
        });
        $this->each('attribute_description', function (array $row) use (&$names): void {
            $names[$row['attribute_id']][$row['language_id']] = $row['name'];
        });
        $attributes = [];
        $this->each('attribute', function (array $row) use (&$attributes): void {
            $attributes[$row['attribute_id']] = $row;
        });
        $this->each('product_attribute', function (array $row) use ($attributes, $names, $groups): void {
            $id = $this->id('product', $row['product_id']);
            if (! $id) {
                return;
            }
            $attribute = $attributes[$row['attribute_id']] ?? [];
            $key = $row['attribute_id'].':'.$row['language_id'];
            $groupCode = 'herrera-oc-attribute-'.$row['attribute_id'];
            $groupName = $names[$row['attribute_id']][$row['language_id']] ?? 'Atribut '.$row['attribute_id'];
            $group = $this->mapped('attribute_group', $row['attribute_id'], 'catalog_attribute_groups', ['code' => $groupCode, 'type' => 'select', 'sort_order' => max(0, $attribute['sort_order'] ?? 0), 'payload' => $this->json(['source' => 'herrera-opencart'])], $attribute);
            DB::table('catalog_attribute_group_translations')->updateOrInsert(['attribute_group_id' => $group, 'locale' => $this->locale($row['language_id'])], ['name' => $groupName, 'created_at' => now(), 'updated_at' => now()]);
            $valueKey = $row['attribute_id'].':'.hash('sha256', trim($row['text']));
            $value = $this->mapped('attribute_value', $valueKey, 'catalog_attributes', ['attribute_group_id' => $group, 'code' => 'herrera-oc-attr-'.hash('sha256', $valueKey), 'group_code' => $groupCode, 'type' => 'select', 'is_active' => true, 'sort_order' => max(0, $attribute['sort_order'] ?? 0), 'payload' => $this->json(['source' => 'herrera-opencart'])], ['key' => $valueKey]);
            $this->translation('catalog_attribute_translations', 'attribute_id', $value, $this->locale($row['language_id']), trim($row['text']) ?: '—', 'attribute_value='.$value, [], ['group_name' => $groupName]);
            DB::table('catalog_attribute_product')->updateOrInsert(['product_id' => $id, 'attribute_id' => $value], ['sort_order' => max(0, $attribute['sort_order'] ?? 0), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('catalog_product_specifications')->updateOrInsert(['product_id' => $id, 'source' => 'herrera-opencart', 'source_key' => $key], [
                'group_name' => $groups[$attribute['attribute_group_id'] ?? 0][$row['language_id']] ?? 'Specifikacije',
                'item_name' => $names[$row['attribute_id']][$row['language_id']] ?? 'Atribut '.$row['attribute_id'],
                'values' => $this->json([$row['text']]), 'sort_order' => max(0, $attribute['sort_order'] ?? 0), 'payload' => $this->json(['opencart' => $row, 'locale' => $this->locale($row['language_id'])]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function options(): void
    {
        $this->each('option', function (array $row): void {
            $supported = in_array($row['type'], ['select', 'radio', 'checkbox'], true);
            $this->mapped('option', $row['option_id'], 'catalog_options', ['code' => 'herrera-oc-option-'.$row['option_id'], 'type' => $supported ? $row['type'] : 'select', 'is_active' => $supported, 'sort_order' => max(0, $row['sort_order']), 'payload' => $this->json(['opencart' => $row])], $row);
            if (! $supported) {
                $this->issue('option', $row['option_id'], 'unsupported_option_type');
            }
        });
        $this->each('option_description', function (array $row): void {
            if ($id = $this->id('option', $row['option_id'])) {
                $this->translation('catalog_option_translations', 'option_id', $id, $this->locale($row['language_id']), $row['name'], 'option_id='.$row['option_id']);
            }
        });
        $this->each('option_value', function (array $row): void {
            if ($option = $this->id('option', $row['option_id'])) {
                $this->mapped('option_value', $row['option_value_id'], 'catalog_option_values', ['option_id' => $option, 'code' => 'herrera-oc-value-'.$row['option_value_id'], 'is_active' => true, 'sort_order' => max(0, $row['sort_order']), 'payload' => $this->json(['opencart' => $row])], $row);
            }
        });
        $this->each('option_value_description', function (array $row): void {
            if ($id = $this->id('option_value', $row['option_value_id'])) {
                $this->translation('catalog_option_value_translations', 'option_value_id', $id, $this->locale($row['language_id']), $row['name'], 'option_value_id='.$row['option_value_id']);
            }
        });
        $this->each('product_option', function (array $row): void {
            if (($product = $this->id('product', $row['product_id'])) && ($option = $this->id('option', $row['option_id']))) {
                DB::table('catalog_option_product')->updateOrInsert(['product_id' => $product, 'option_id' => $option], ['is_required' => (bool) $row['required'], 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->each('product_option_value', function (array $row): void {
            if (($product = $this->id('product', $row['product_id'])) && ($value = $this->id('option_value', $row['option_value_id']))) {
                $base = (float) DB::table('products')->where('id', $product)->value('base_price');
                $this->mapped('product_option_value', $row['product_option_value_id'], 'catalog_product_option_values', ['product_id' => $product, 'option_value_id' => $value, 'mode' => 'single', 'stock_qty' => $row['quantity'], 'price_override' => $base + ($row['price_prefix'] === '-' ? -1 : 1) * (float) $row['price'], 'combination_hash' => hash('sha256', 'herrera:'.$row['product_option_value_id']), 'is_active' => true, 'payload' => $this->json(['opencart' => $row])], $row);
            }
        });
    }

    private function content(): void
    {
        $availableBlogTranslations = [];
        $this->each('blog_description', function (array $row) use (&$availableBlogTranslations): void {
            if (isset($this->locales[$row['language_id']])) {
                $availableBlogTranslations[$row['blog_id']] = true;
            }
        });
        $this->each('blog_category', function (array $row): void {
            $this->mapped('blog_category', $row['blog_category_id'], 'categories', ['scope' => 'blog', 'code' => 'herrera-oc-blog-category-'.$row['blog_category_id'], 'is_active' => (bool) $row['status'], 'show_in_menu' => false, 'sort_order' => max(0, $row['sort_order']), 'payload' => $this->json(['opencart' => $row])], $row);
        });
        $this->each('blog_category_description', function (array $row): void {
            if ($id = $this->id('blog_category', $row['blog_category_id'])) {
                $this->translation('category_translations', 'category_id', $id, $this->locale($row['language_id']), $row['name'], 'blog_category_id='.$row['blog_category_id'], $row, ['scope' => 'blog']);
            }
        });
        $this->each('information', function (array $row): void {
            $this->mapped('information', $row['information_id'], 'content_info_pages', ['code' => 'herrera-oc-page-'.$row['information_id'], 'layout' => 'default', 'is_active' => (bool) $row['status'], 'show_in_footer' => (bool) $row['bottom'], 'sort_order' => max(0, $row['sort_order']), 'payload' => $this->json(['opencart' => $row])], $row);
        });
        $this->each('information_description', function (array $row): void {
            if (! isset($this->locales[$row['language_id']])) {
                $this->archiveRow('information_description', $row['information_id'].':'.$row['language_id'], $row);

                return;
            }
            if ($id = $this->id('information', $row['information_id'])) {
                $this->translation('content_info_page_translations', 'page_id', $id, $this->locale($row['language_id']), $row['title'], 'information_id='.$row['information_id'], $row);
            }
        });
        $this->each('blog', function (array $row) use ($availableBlogTranslations): void {
            $public = (bool) $row['status'] && isset($availableBlogTranslations[$row['blog_id']]);
            $this->mapped('blog', $row['blog_id'], 'content_blog_posts', ['code' => 'herrera-oc-blog-'.$row['blog_id'], 'is_active' => $public, 'published_at' => $this->date($row['date_added']), 'sort_order' => max(0, $row['sort_order']), 'payload' => $this->json(['opencart' => $row])], $row + ['has_available_translation' => isset($availableBlogTranslations[$row['blog_id']])]);
        });
        $this->each('blog_description', function (array $row): void {
            if (! isset($this->locales[$row['language_id']])) {
                $this->archiveRow('blog_description', $row['blog_id'].':'.$row['language_id'], $row);

                return;
            }
            if ($id = $this->id('blog', $row['blog_id'])) {
                $this->translation('content_blog_post_translations', 'post_id', $id, $this->locale($row['language_id']), $row['title'], 'blog_id='.$row['blog_id'], $row + ['excerpt' => $row['short_description'], 'meta_title' => $row['page_title']]);
            }
        });
        $this->each('blog_to_category', function (array $row): void {
            if (($post = $this->id('blog', $row['blog_id'])) && ($category = $this->id('blog_category', $row['blog_category_id']))) {
                DB::table('content_blog_post_category')->updateOrInsert(['post_id' => $post, 'category_id' => $category], ['sort_order' => 0, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        Category::fixTree();
    }

    private function customers(): void
    {
        $taxRates = [];
        $rateClasses = [];
        $groupTaxRates = [];
        $taxScopeDefined = $this->has('tax_rate_to_customer_group');
        $this->each('tax_rate', function (array $row) use (&$taxRates): void {
            $taxRates[$row['tax_rate_id']] = ['tax_rate_id' => (int) $row['tax_rate_id'], 'rate' => $row['rate'], 'type' => $row['type']];
        });
        $this->each('tax_rate_to_customer_group', function (array $row) use (&$groupTaxRates, $taxRates): void {
            $groupTaxRates[$row['customer_group_id']][] = $taxRates[$row['tax_rate_id']] ?? ['tax_rate_id' => (int) $row['tax_rate_id']];
        });
        $this->each('tax_rule', function (array $row) use (&$rateClasses): void {
            $rateClasses[$row['tax_rate_id']][] = (int) $row['tax_class_id'];
        });
        $groupDescriptions = [];
        $this->each('customer_group_description', function (array $row) use (&$groupDescriptions): void {
            $groupDescriptions[$row['customer_group_id']][] = $row;
        });
        $this->each('customer_group', function (array $row) use ($groupDescriptions, $groupTaxRates, $taxScopeDefined, $rateClasses): void {
            $description = $groupDescriptions[$row['customer_group_id']][0] ?? [];
            $scope = ['tax_scope_defined' => $taxScopeDefined, 'tax_rates' => $groupTaxRates[$row['customer_group_id']] ?? [], 'tax_rate_ids' => array_column($groupTaxRates[$row['customer_group_id']] ?? [], 'tax_rate_id')];
            $scope['taxable_class_ids'] = array_values(array_unique(array_merge([], ...array_map(fn ($rate) => $rateClasses[$rate] ?? [], $scope['tax_rate_ids']))));
            $this->mapped('customer_group', $row['customer_group_id'], 'customer_groups', ['code' => 'herrera-oc-group-'.$row['customer_group_id'], 'name' => $description['name'] ?? 'Grupa '.$row['customer_group_id'], 'description' => $description['description'] ?? null, 'is_active' => true, 'is_default' => false, 'sort_order' => max(0, $row['sort_order']), 'payload' => $this->json(['opencart' => $row + $scope, 'translations' => $groupDescriptions[$row['customer_group_id']] ?? []])], $row + ['descriptions' => $groupDescriptions[$row['customer_group_id']] ?? []] + $scope);
        });
        $addresses = [];
        $this->each('address', function (array $row) use (&$addresses): void {
            $addresses[$row['customer_id']][$row['address_id']] = $row;
        });
        $this->each('customer', function (array $row) use ($addresses): void {
            $safe = array_diff_key($row, array_flip(['password', 'salt', 'token', 'code', 'cart', 'wishlist', 'ip']));
            $email = strtolower(trim($row['email']));
            $id = $this->id('customer', $row['customer_id']);
            if (! $id && DB::table('users')->where('account_type', 'customer')->where('email', $email)->exists()) {
                $this->issue('customer', $row['customer_id'], 'existing_email_not_merged');
                $this->archiveRow('customer', (string) $row['customer_id'], $safe);

                return;
            }
            $fields = ['name' => trim($row['firstname'].' '.$row['lastname']), 'email' => $email, 'api_access_enabled' => false];
            if (! $id) {
                $fields['password'] = Hash::make(Str::random(64));
            }
            $id = $this->mapped('customer', $row['customer_id'], 'users', $fields, $safe);
            LegacyCredential::firstOrCreate(['user_id' => $id], ['legacy_hash' => $row['password'], 'legacy_salt' => $row['salt'], 'enabled' => (bool) $row['status']]);
            $default = $addresses[$row['customer_id']][$row['address_id']] ?? (array_values($addresses[$row['customer_id']] ?? [])[0] ?? []);
            $custom = json_decode($row['custom_field'] ?: '{}', true) ?: [];
            $company = trim((string) ($custom['1'] ?? '')) ?: (trim((string) ($default['company'] ?? '')) ?: trim($row['firstname'].' '.$row['lastname']));
            $oib = $this->oib($custom, json_decode($default['custom_field'] ?? '{}', true) ?: []);
            if (! $oib) {
                $this->issue('customer', $row['customer_id'], 'missing_or_invalid_oib');
            }
            $this->mapped('profile', $row['customer_id'], 'user_profiles', ['user_id' => $id, 'first_name' => $row['firstname'], 'last_name' => $row['lastname'], 'phone' => $row['telephone'], 'company' => $company, 'oib' => $oib, 'newsletter_opt_in' => (bool) $row['newsletter'], 'payload' => $this->json(['opencart' => $safe])], $safe + ['default_address' => $default]);
            $group = $this->id('customer_group', $row['customer_group_id']);
            if ($group) {
                DB::table('customer_group_user')->updateOrInsert(['user_id' => $id, 'customer_group_id' => $group], ['created_at' => now(), 'updated_at' => now()]);
            }
            // The live OpenCart login checks status, not its obsolete approved
            // column. Preserve the actual buying entitlement of existing users.
            $status = ! $row['status'] ? 'suspended' : 'approved';
            if (! $group) {
                $status = 'pending';
                $this->issue('customer', $row['customer_id'], 'missing_customer_group');
            }
            $this->mapped('b2b_account', $row['customer_id'], 'b2b_accounts', ['user_id' => $id,
                'status' => $status, 'company_name' => $company ?: 'OpenCart kupac '.$row['customer_id'], 'oib' => $oib ?? '',
                'phone' => $row['telephone'], 'address_line_1' => $default['address_1'] ?? null, 'address_line_2' => $default['address_2'] ?? null, 'postal_code' => $default['postcode'] ?? null, 'city' => $default['city'] ?? null, 'country_code' => $this->countries[$default['country_id'] ?? 0] ?? 'HR',
                'customer_group_id' => $group, 'requested_customer_group_id' => $group, 'erp_customer_id' => (string) $row['customer_id'], 'requested_at' => $this->date($row['date_added']), 'reviewed_at' => $status === 'approved' ? $this->date($row['date_added']) : null,
                'payload' => $this->json(['opencart' => ['customer_id' => $row['customer_id'], 'approved' => $row['approved'] ?? 0, 'status' => $row['status'], 'custom_field' => $custom]]),
            ], $safe + ['default_address' => $default]);
        });
        foreach ($addresses as $customer => $rows) {
            if (! $user = $this->id('customer', $customer)) {
                continue;
            }
            $sourceDefault = (int) $this->source->table($this->table('customer'))->where('customer_id', $customer)->value('address_id');
            foreach ($rows as $row) {
                $this->mapped('address', $row['address_id'], 'user_addresses', ['user_id' => $user, 'type' => 'shipping', 'first_name' => $row['firstname'], 'last_name' => $row['lastname'], 'company' => $row['company'], 'address_line_1' => $row['address_1'], 'address_line_2' => $row['address_2'], 'postal_code' => $row['postcode'], 'city' => $row['city'], 'country_code' => $this->countries[$row['country_id']] ?? 'HR', 'is_default' => (int) $row['address_id'] === $sourceDefault, 'payload' => $this->json(['opencart' => $row])], $row);
            }
        }
    }

    private function pricing(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('catalog_price_catalogs')) {
            throw new RuntimeException('Price catalog migration must be installed before pricing import.');
        }
        $catalog = DB::table('catalog_price_catalogs')->where('source_system', 'herrera-opencart')->where('source_snapshot', $this->snapshot)->first();
        if ($catalog && $catalog->status !== 'draft') {
            $this->stats['price_catalog_unchanged'] = $catalog->id;

            return;
        }
        $catalogId = $catalog?->id ?? DB::table('catalog_price_catalogs')->insertGetId(['name' => 'Herrera OpenCart snapshot', 'status' => 'draft', 'currency_code' => 'EUR', 'source_system' => 'herrera-opencart', 'source_snapshot' => $this->snapshot, 'metadata' => $this->json(['absolute_source_prices' => true, 'source_database' => $this->snapshot, 'import_complete' => false]), 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(function () use ($catalogId): void {
            $catalog = DB::table('catalog_price_catalogs')->where('id', $catalogId)->lockForUpdate()->first();
            if ($catalog->status !== 'draft') {
                throw new RuntimeException('Published price catalogs cannot be modified by an import.');
            }
            $metadata = json_decode($catalog->metadata ?: '{}', true);
            $metadata['import_complete'] = false;
            DB::table('catalog_price_catalogs')->where('id', $catalogId)->update(['metadata' => $this->json($metadata)]);
        });
        $definitions = [
            'product' => ['base', null, null],
            'product_price_by_cigroup' => ['group', 'group_id', null],
            'product_price_by_customer_id' => ['customer', null, 'customer_id'],
            'product_discount' => ['quantity', 'customer_group_id', null],
            'product_special' => ['special', 'customer_group_id', null],
        ];
        $digest = hash_init('sha256');
        foreach ($definitions as $table => [$kind, $groupKey, $customerKey]) {
            $buffer = [];
            $this->each($table, function (array $row) use ($table, $kind, $groupKey, $customerKey, $catalogId, $digest, &$buffer): void {
                hash_update($digest, $this->json($row));
                $product = $this->id('product', $row['product_id']);
                $group = $groupKey ? $this->id('customer_group', $row[$groupKey]) : null;
                $user = $customerKey ? $this->id('customer', $row[$customerKey]) : null;
                $key = $table.':'.($row['product_special_id'] ?? $row['product_discount_id'] ?? ($kind === 'base' ? $row['product_id'] : ($row['product_id'].':'.($row[$groupKey ?? $customerKey] ?? 0).':'.($row['category_id'] ?? 0))));
                if (! $product || ($groupKey && ! $group) || ($customerKey && ! $user)) {
                    $this->issue('price_entry', $key, 'unmapped_scope_or_product');
                    $this->archiveRow($table, $key, $row);

                    return;
                }
                $buffer[] = ['price_catalog_id' => $catalogId, 'source_key' => $key, 'product_id' => $product, 'kind' => $kind, 'customer_group_id' => $group, 'user_id' => $user, 'minimum_quantity' => $kind === 'base' ? 1 : max(1, $row['quantity'] ?? 1), 'price' => $row['price'], 'priority' => $row['priority'] ?? 0, 'starts_at' => $this->date($row['date_start'] ?? null), 'ends_at' => $this->date($row['date_end'] ?? null), 'is_active' => true, 'payload' => $this->json(['opencart' => $row]), 'created_at' => now(), 'updated_at' => now()];
                if (count($buffer) >= 500) {
                    $this->flushPrices($buffer);
                }
            });
            $this->flushPrices($buffer);
        }
        $entryCount = DB::table('catalog_price_entries')->where('price_catalog_id', $catalogId)->count();
        DB::table('catalog_price_catalogs')->where('id', $catalogId)->update(['source_checksum' => hash_final($digest), 'metadata' => $this->json(['absolute_source_prices' => true, 'source_database' => $this->snapshot, 'import_complete' => true, 'import_expected_entries' => $entryCount]), 'updated_at' => now()]);
        $this->stats['draft_price_catalog_id'] = $catalogId;
        $this->stats['price_entries'] = DB::table('catalog_price_entries')->where('price_catalog_id', $catalogId)->count();
    }

    /** Explicit repair after widening an existing two-decimal staging schema. */
    private function guardSupplierStockRepair(): void
    {
        if (app()->environment('local') && (DB::connection()->getDatabaseName() !== 'herrera_new_migration'
            || $this->snapshot !== 'herrera_live_snapshot_20261006')) {
            throw new RuntimeException('Supplier stock repair requires the original Herrera snapshot and local migration database.');
        }
        if (! DB::getSchemaBuilder()->hasColumn('products', 'supplier_stock_qty')
            || ! $this->source->getSchemaBuilder()->hasColumn($this->table('product'), 'suplierqty')) {
            throw new RuntimeException('Supplier stock schema or original source quantity is missing.');
        }
    }

    /** Izričit dopunski uvoz samo nove zalihe dobavljača, bez ponovnog uvoza kataloga. */
    private function supplier_stock(): void
    {
        $previousRepairCompleted = DB::table('herrera_import_runs')->where('source_snapshot', $this->snapshot)
            ->where('status', 'completed')->whereNotNull('summary->supplier_stock_checked')->exists();
        $this->stats += [
            'supplier_stock_checked' => 0, 'supplier_stock_repaired' => 0,
            'supplier_stock_unchanged' => 0, 'supplier_stock_local_preserved' => 0,
            'supplier_stock_unmapped' => 0, 'supplier_stock_source_mismatch' => 0,
        ];
        $this->source->table($this->table('product'))->select(['product_id', 'suplierqty'])
            ->chunkById(500, function ($sourceRows) use ($previousRepairCompleted): void {
                $chunkStats = DB::transaction(function () use ($sourceRows, $previousRepairCompleted): array {
                    $counts = array_fill_keys([
                        'supplier_stock_checked', 'supplier_stock_repaired', 'supplier_stock_unchanged',
                        'supplier_stock_local_preserved', 'supplier_stock_unmapped', 'supplier_stock_source_mismatch',
                    ], 0);
                    $targetIds = $sourceRows->map(fn ($row) => $this->id('product', $row->product_id))->filter()->all();
                    $products = DB::table('products')->whereIn('id', $targetIds)->orderBy('id')->lockForUpdate()
                        ->get(['id', 'supplier_stock_qty', 'payload'])->keyBy('id');
                    $repairs = [];
                    foreach ($sourceRows as $sourceRow) {
                        $counts['supplier_stock_checked']++;
                        $targetId = $this->id('product', $sourceRow->product_id);
                        $product = $targetId ? $products->get($targetId) : null;
                        if (! $product) {
                            $counts['supplier_stock_unmapped']++;

                            continue;
                        }
                        $original = json_decode($product->payload ?: '{}', true)['opencart'] ?? [];
                        if ((string) ($original['product_id'] ?? '') !== (string) $sourceRow->product_id
                            || ! array_key_exists('suplierqty', $original)
                            || (int) $original['suplierqty'] !== (int) $sourceRow->suplierqty) {
                            $counts['supplier_stock_source_mismatch']++;

                            continue;
                        }
                        $supplierQuantity = (int) $sourceRow->suplierqty;
                        if ((int) $product->supplier_stock_qty === $supplierQuantity) {
                            $counts['supplier_stock_unchanged']++;
                        } elseif ((int) $product->supplier_stock_qty !== 0 || $previousRepairCompleted) {
                            // Nakon dovršenog dopunskog uvoza čuva se i ručno ili narudžbom potrošena nula.
                            $counts['supplier_stock_local_preserved']++;
                        } else {
                            $repairs[(int) $targetId] = $supplierQuantity;
                        }
                    }
                    if ($repairs !== []) {
                        $cases = [];
                        $bindings = [];
                        foreach ($repairs as $id => $quantity) {
                            $cases[] = 'WHEN ? THEN ?';
                            array_push($bindings, $id, $quantity);
                        }
                        $ids = array_keys($repairs);
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        DB::update('UPDATE products SET supplier_stock_qty = CASE id '.implode(' ', $cases)
                            .' ELSE supplier_stock_qty END WHERE id IN ('.$placeholders.')', [...$bindings, ...$ids]);
                        $counts['supplier_stock_repaired'] = count($repairs);
                    }

                    return $counts;
                }, attempts: 3);
                foreach ($chunkStats as $key => $count) {
                    $this->stats[$key] += $count;
                }
            }, 'product_id');
    }

    private function base_prices(): void
    {
        if (DB::getSchemaBuilder()->hasTable('catalog_price_catalogs') && DB::table('catalog_price_catalogs')->where('status', 'active')->exists()) {
            throw new RuntimeException('Explicit base price repair is restricted to staging before any price catalog activation.');
        }
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $definition = DB::getSchemaBuilder()->getColumnType('products', 'base_price', true);
            if (! preg_match('/decimal\(\d+,\s*(\d+)\)/i', $definition, $matches) || (int) $matches[1] < 4) {
                throw new RuntimeException('Four-decimal product schema must be installed before the explicit base price repair.');
            }
        }
        $this->each('product', function (array $row): void {
            if ($id = $this->id('product', $row['product_id'])) {
                DB::table('products')->where('id', $id)->update(['base_price' => $row['price']]);
                $this->stats['base_prices_repaired'] = ($this->stats['base_prices_repaired'] ?? 0) + 1;
            }
        });
    }

    private function billing_addresses(): void
    {
        $addresses = [];
        $this->each('address', function (array $row) use (&$addresses): void {
            $addresses[$row['customer_id']][$row['address_id']] = $row;
        });
        $normalize = fn ($value): string => ' '.trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))))).' ';
        $this->each('customer', function (array $row) use ($addresses, $normalize): void {
            if (! $user = $this->id('customer', $row['customer_id'])) {
                $safe = array_diff_key($row, array_flip(['password', 'salt', 'token', 'code', 'cart', 'wishlist', 'ip']));
                $this->archiveRow('customer', (string) $row['customer_id'], $safe);
                foreach ($addresses[$row['customer_id']] ?? [] as $address) {
                    $this->archiveRow('address', (string) $address['address_id'], $address);
                }

                return;
            }
            if (! $this->id('billing_address', $row['customer_id']) && DB::table('user_addresses')->where('user_id', $user)->where('type', 'billing')->exists()) {
                return;
            }
            $address = $addresses[$row['customer_id']][$row['address_id']] ?? [];
            $custom = json_decode($row['custom_field'] ?: '{}', true) ?: [];
            $billingText = is_scalar($custom['3'] ?? null) ? trim((string) $custom['3']) : '';
            $structured = trim($address['address_1'] ?? '') !== '' && trim($address['city'] ?? '') !== '' && trim($address['postcode'] ?? '') !== '' && isset($this->countries[$address['country_id'] ?? 0]);
            $matches = $billingText === '';
            if ($structured && ! $matches) {
                $matches = true;
                foreach (['address_1', 'city', 'postcode'] as $key) {
                    if (! str_contains($normalize($billingText), $normalize($address[$key]))) {
                        $matches = false;
                        break;
                    }
                }
            }
            if (! $structured || ! $matches) {
                $this->issue('customer', $row['customer_id'], 'billing_address_requires_review', ['has_billing_text' => $billingText !== '', 'has_structured_default_address' => $structured]);

                return;
            }
            $company = is_scalar($custom['1'] ?? null) ? trim((string) $custom['1']) : '';
            $company = $company ?: trim((string) ($address['company'] ?? ''));
            $oib = $this->oib($custom, json_decode($address['custom_field'] ?? '{}', true) ?: []);
            $this->mapped('billing_address', $row['customer_id'], 'user_addresses', [
                'user_id' => $user, 'type' => 'billing', 'first_name' => $address['firstname'] ?: $row['firstname'], 'last_name' => $address['lastname'] ?: $row['lastname'], 'company' => $company ?: null, 'oib' => $oib, 'phone' => $row['telephone'], 'address_line_1' => $address['address_1'], 'address_line_2' => $address['address_2'], 'postal_code' => $address['postcode'], 'city' => $address['city'], 'country_code' => $this->countries[$address['country_id']], 'is_default' => true,
                'payload' => $this->json(['opencart' => ['customer_id' => $row['customer_id'], 'default_address_id' => $row['address_id'], 'billing_text' => $billingText]]),
            ], ['address' => $address, 'company' => $company, 'oib' => $oib, 'billing_text' => $billingText]);
        });
    }

    private function flushPrices(array &$buffer): void
    {
        if ($buffer) {
            DB::transaction(function () use ($buffer): void {
                $catalog = DB::table('catalog_price_catalogs')->where('id', $buffer[0]['price_catalog_id'])->lockForUpdate()->first();
                if ($catalog->status !== 'draft') {
                    throw new RuntimeException('Published price catalogs cannot be modified by an import.');
                }
                DB::table('catalog_price_entries')->upsert($buffer, ['price_catalog_id', 'source_key'], ['product_id', 'kind', 'customer_group_id', 'user_id', 'minimum_quantity', 'price', 'priority', 'starts_at', 'ends_at', 'is_active', 'payload', 'updated_at']);
            });
            $buffer = [];
        }
    }

    private function orders(): void
    {
        $this->each('order_status', function (array $row): void {
            if (isset($row['language_id']) && ! isset($this->locales[$row['language_id']])) {
                return;
            }
            $this->mapped('order_status', $row['order_status_id'], 'order_statuses', ['code' => 'herrera-oc-status-'.$row['order_status_id'], 'name' => $row['name'], 'is_active' => true, 'is_default' => false, 'is_paid' => false, 'is_cancelled' => false, 'settings' => $this->json(['opencart' => $row])], $row);
        });
        $this->each('order', function (array $row): void {
            $fields = ['order_number' => 'OC-HERRERA-'.$row['order_id'], 'status_id' => $this->id('order_status', $row['order_status_id']), 'user_id' => $this->id('customer', $row['customer_id']), 'source' => 'opencart_import', 'locale' => $this->locale($row['language_id']), 'currency_code' => $row['currency_code'] ?: 'EUR', 'currency_rate' => $row['currency_value'] ?: 1, 'customer_name' => trim($row['firstname'].' '.$row['lastname']), 'customer_email' => $row['email'], 'customer_phone' => $row['telephone'], 'grand_total' => $row['total'], 'customer_note' => $row['comment'], 'placed_at' => $this->date($row['date_added']), 'payload' => $this->json(['opencart' => array_diff_key($row, array_flip(['ip', 'forwarded_ip', 'user_agent']))])];
            foreach (['payment' => 'billing', 'shipping' => 'shipping'] as $old => $new) {
                foreach (['firstname' => 'first_name', 'lastname' => 'last_name', 'company' => 'company', 'address_1' => 'address_line_1', 'address_2' => 'address_line_2', 'postcode' => 'postal_code', 'city' => 'city', 'zone' => 'state'] as $source => $target) {
                    $fields[$new.'_'.$target] = $row[$old.'_'.$source];
                }
                $fields[$new.'_country_code'] = $this->countries[$row[$old.'_country_id']] ?? 'HR';
                $fields[$old === 'payment' ? 'payment_method_code' : 'shipping_method_code'] = mb_substr($row[$old.'_code'], 0, 60);
                $fields[$old === 'payment' ? 'payment_method_name' : 'shipping_method_name'] = $row[$old.'_method'];
            }
            $fields = array_replace($fields, $this->historicalOrderIdentity($row));
            $this->mapped('order', $row['order_id'], 'orders', $fields, $row);
        });
        $this->each('order_product', function (array $row): void {
            if ($order = $this->id('order', $row['order_id'])) {
                $this->mapped('order_product', $row['order_product_id'], 'order_items', ['order_id' => $order, 'product_id' => $this->id('product', $row['product_id']), 'sku' => $row['sku'] ?: $row['model'], 'code' => $row['model'], 'name' => $row['name'], 'unit_price' => $row['price'], 'quantity' => max(1, $row['quantity']), 'line_total' => $row['total'], 'tax_amount' => (float) $row['tax'] * max(1, $row['quantity']), 'payload' => $this->json(['opencart' => $row])], $row);
            }
        });
        $this->each('order_total', function (array $row): void {
            if ($order = $this->id('order', $row['order_id'])) {
                $this->mapped('order_total', $row['order_total_id'], 'order_totals', ['order_id' => $order, 'code' => $row['code'], 'title' => $row['title'], 'value' => $row['value'], 'sort_order' => max(0, $row['sort_order']), 'payload' => $this->json(['opencart' => $row])], $row);
            }
        });
        $this->each('order_history', function (array $row): void {
            if ($order = $this->id('order', $row['order_id'])) {
                $this->mapped('order_history', $row['order_history_id'], 'order_history', ['order_id' => $order, 'to_status_id' => $this->id('order_status', $row['order_status_id']), 'comment' => $row['comment'], 'payload' => $this->json(['opencart' => ['notify' => $row['notify'], 'order_history_id' => $row['order_history_id']]]), 'created_at' => $this->date($row['date_added']) ?? now()], $row);
            }
        });
        DB::table('orders')->where('source', 'opencart_import')->orderBy('id')->chunkById(500, function ($orders): void {
            DB::transaction(function () use ($orders): void {
                foreach ($orders as $order) {
                    $totals = DB::table('order_totals')->where('order_id', $order->id)->get()->groupBy('code');
                    $value = fn ($code) => $totals->get($code)?->sum('value') ?? 0;
                    DB::table('orders')->where('id', $order->id)->update(['item_qty' => DB::table('order_items')->where('order_id', $order->id)->sum('quantity'), 'subtotal' => $value('sub_total'), 'shipping_total' => $value('shipping'), 'tax_total' => $value('tax'), 'discount_total' => abs($value('coupon')) + abs($value('voucher'))]);
                }
            });
        });
    }

    /** Explicit backfill: never rewrite amounts, addresses, statuses or local identity edits. */
    private function order_identity(): void
    {
        $this->each('order', function (array $row): void {
            $id = $this->id('order', $row['order_id']);
            if (! $id) {
                $this->issue('order', $row['order_id'], 'historical_identity_order_not_imported');

                return;
            }
            $identity = $this->historicalOrderIdentity($row);
            $checksum = hash('sha256', $this->json(['mapping_version' => 1, 'identity' => $identity]));
            $sourceId = (string) $row['order_id'];
            $entity = 'order_business_identity';
            if (($this->checksums[$entity][$sourceId] ?? null) === $checksum) {
                return;
            }
            $order = DB::table('orders')->where('id', $id)->where('source', 'opencart_import')->where('order_number', 'OC-HERRERA-'.$sourceId)->lockForUpdate()->first(['id', 'billing_company', 'billing_oib']);
            if (! $order) {
                $this->issue('order', $sourceId, 'historical_identity_order_map_mismatch');

                return;
            }
            $updates = [];
            foreach ($identity as $field => $original) {
                if ($original === null || $original === '') {
                    continue;
                }
                $current = trim((string) $order->{$field});
                if ($current === '') {
                    $updates[$field] = $original;
                } elseif ($current !== $original) {
                    $this->issue('order', $sourceId, 'historical_identity_local_value_preserved_'.$field);
                }
            }
            if ($updates) {
                DB::table('orders')->where('id', $id)->update($updates + ['updated_at' => now()]);
                $this->stats['order_identity_written'] = ($this->stats['order_identity_written'] ?? 0) + 1;
            }
            DB::table('herrera_import_maps')->updateOrInsert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => $sourceId], ['target_id' => $id, 'checksum' => $checksum, 'created_at' => now(), 'updated_at' => now()]);
            $this->orderChunkMapUndo[$entity][$sourceId] ??= ['id' => $this->maps[$entity][$sourceId] ?? null, 'checksum' => $this->checksums[$entity][$sourceId] ?? null];
            $this->maps[$entity][$sourceId] = $id;
            $this->checksums[$entity][$sourceId] = $checksum;
            $this->stats['order_identity_checked'] = ($this->stats['order_identity_checked'] ?? 0) + 1;
        });
    }

    private function historicalOrderIdentity(array $row): array
    {
        // Herrera's original invoice reads ORDER.custom_field[1]/[2]. These
        // are the historical checkout snapshot, not today's customer profile.
        $custom = json_decode($row['custom_field'] ?? '{}', true);
        $custom = is_array($custom) ? $custom : [];
        $company = is_scalar($custom[1] ?? null) ? trim((string) $custom[1]) : '';
        $company = $company !== '' ? $company : trim((string) ($row['payment_company'] ?? ''));
        $identifier = is_scalar($custom[2] ?? null) ? trim((string) $custom[2]) : '';
        $identity = ['billing_company' => $company !== '' ? $company : null, 'billing_oib' => $identifier !== '' ? $identifier : null];
        foreach (['billing_company' => 191, 'billing_oib' => 60] as $field => $limit) {
            if (mb_strlen((string) $identity[$field]) > $limit) {
                $this->issue('order', $row['order_id'], 'historical_identity_exceeds_length_'.$field);
                $identity[$field] = null;
            }
        }

        return $identity;
    }

    private function seo(): void
    {
        $this->each('seo_url', function (array $row): void {
            if ((int) ($row['store_id'] ?? 0) === 0) {
                $this->legacyUrl($row['keyword'], $row['query'], $this->locale($row['language_id']), ['seo_url_id' => $row['seo_url_id']]);
            }
        });
        $this->each('hb_url_preserve', function (array $row): void {
            if ((int) ($row['store_id'] ?? 0) === 0) {
                $this->legacyUrl($row['old_keyword'], $row['query'], $this->locale($row['language_id']), ['preserve_id' => $row['id'], 'new_keyword' => $row['new_keyword']]);
            }
        });
        $this->stats['seo_redirects'] = DB::table('herrera_legacy_urls')->where('status', 'redirect')->count();
        $this->stats['seo_review_required'] = DB::table('herrera_legacy_urls')->where('status', '!=', 'redirect')->count();
    }

    private function legacyUrl(string $keyword, string $query, string $locale, array $payload): void
    {
        $path = HerreraLegacyUrlService::normalizePath($keyword);
        if ($path === null || $path === '/') {
            $this->issue('seo', hash('sha256', $keyword), 'invalid_path');

            return;
        }
        $destination = app(HerreraLegacyUrlService::class)->destinationForQuery($query, $locale);
        $key = ['locale' => $locale, 'path_hash' => hash('sha256', $path)];
        $existing = DB::table('herrera_legacy_urls')->where($key)->first();
        $status = $destination ? 'redirect' : 'unresolved';
        if ($existing && ($existing->status === 'conflict' || ($existing->source_query !== $query && $existing->destination !== $destination))) {
            $status = 'conflict';
            $destination = null;
            $this->issue('seo', hash('sha256', $path), 'alias_collision');
        }
        DB::table('herrera_legacy_urls')->updateOrInsert($key, ['path' => $path, 'source_query' => $query, 'status' => $status, 'destination' => $destination, 'payload' => $this->json($payload), 'created_at' => now(), 'updated_at' => now()]);
        if (! $destination) {
            $this->issue('seo', hash('sha256', $path), 'unresolved_destination', ['query' => $query]);
        }
    }

    private function archive(): void
    {
        // Keep unsupported rules and associations intact for reconciliation;
        // documents remain metadata-only until the final media copy.
        foreach (['customer_transaction', 'product_anchor_price', 'product_customergroup_price', 'product_option_price_by_cigroup', 'cigroupprice_template', 'download', 'download_description', 'product_to_download', 'order_option', 'product_related', 'review', 'blog_category', 'blog_category_description', 'blog_to_category', 'blog_related', 'blog_related_products', 'blog_comment', 'coupon', 'coupon_product', 'coupon_category', 'coupon_history'] as $table) {
            $this->each($table, function (array $row) use ($table): void {
                $this->archiveRow($table, hash('sha256', $this->json($row)), $row);
            });
        }
        $this->stats['archived_source_records'] = DB::table('herrera_source_records')->count();
    }

    private function relations(): void
    {
        $related = [];
        $this->each('product_related', function (array $row) use (&$related): void {
            $related[$row['product_id']][] = (int) $row['related_id'];
        });
        foreach ($related as $sourceId => $sourceIds) {
            if (! $product = $this->id('product', $sourceId)) {
                continue;
            }
            $nativeIds = array_values(array_unique(array_filter(array_map(fn ($id) => $this->id('product', $id), $sourceIds))));
            $payload = json_decode(DB::table('products')->where('id', $product)->value('payload') ?: '{}', true);
            $checksum = hash('sha256', $this->json($sourceIds));
            if (($payload['opencart']['related_source_checksum'] ?? null) === $checksum) {
                continue;
            }
            $payload['related_product_ids'] = $nativeIds;
            $payload['opencart']['related_product_ids'] = $sourceIds;
            $payload['opencart']['related_source_checksum'] = $checksum;
            DB::table('products')->where('id', $product)->update(['payload' => $this->json($payload), 'updated_at' => now()]);
            $this->stats['related_product_associations'] = ($this->stats['related_product_associations'] ?? 0) + count($nativeIds);
        }
        $this->each('customer_wishlist', function (array $row): void {
            $user = $this->id('customer', $row['customer_id']);
            $product = $this->id('product', $row['product_id']);
            if ($user && $product) {
                $sourceKey = $row['customer_id'].':'.$row['product_id'];
                if (! $this->id('wishlist', $sourceKey)) {
                    $existing = DB::table('user_wishlist_items')->where('user_id', $user)->where('product_id', $product)->first();
                    if ($existing) {
                        $this->maps['wishlist'][$sourceKey] = (int) $existing->id;
                    }
                }
                $this->mapped('wishlist', $sourceKey, 'user_wishlist_items', ['user_id' => $user, 'product_id' => $product, 'created_at' => $this->date($row['date_added']) ?? now()], $row);
            } else {
                $this->archiveRow('customer_wishlist', $row['customer_id'].':'.$row['product_id'], $row);
            }
        });
    }

    private function archiveRow(string $table, string $key, array $row): void
    {
        $safe = array_diff_key($row, array_flip(['password', 'salt', 'token', 'ip', 'forwarded_ip']));
        DB::table('herrera_source_records')->updateOrInsert(['source_table' => $table, 'source_key' => $key], ['checksum' => hash('sha256', $this->json($safe)), 'payload' => $this->json($safe), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mapped(string $entity, int|string $sourceId, string $table, array $fields, array $sourceRow): int
    {
        $sourceId = (string) $sourceId;
        $checksum = hash('sha256', $this->json($sourceRow));
        $id = $this->id($entity, $sourceId);
        if ($id && ($this->checksums[$entity][$sourceId] ?? null) === $checksum) {
            return $id;
        }
        $write = function () use ($entity, $sourceId, $table, $fields, $checksum, &$id): void {
            if ($id) {
                DB::table($table)->where('id', $id)->update($fields + ['updated_at' => now()]);
            } else {
                $id = DB::table($table)->insertGetId($fields + ['created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('herrera_import_maps')->updateOrInsert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => $sourceId], ['target_id' => $id, 'checksum' => $checksum, 'created_at' => now(), 'updated_at' => now()]);
        };
        if ($this->orderChunkMapUndo === null) {
            DB::transaction($write);
        } else {
            // The enclosing 500-row order transaction owns both the target row
            // and its map. Avoid a savepoint for every historical child row.
            $this->orderChunkMapUndo[$entity][$sourceId] ??= [
                'id' => $this->maps[$entity][$sourceId] ?? null,
                'checksum' => $this->checksums[$entity][$sourceId] ?? null,
            ];
            $write();
        }
        $this->maps[$entity][$sourceId] = $id;
        $this->checksums[$entity][$sourceId] = $checksum;
        $this->stats[$entity.'_written'] = ($this->stats[$entity.'_written'] ?? 0) + 1;

        return $id;
    }

    private function translation(string $table, string $foreignKey, int $id, string $locale, string $name, string $query, array $row = [], array $extra = []): void
    {
        $keyword = $this->keywords[$query][$locale] ?? $name;
        $slug = Str::slug(basename($keyword)) ?: 'herrera-'.$id;
        $slug = mb_substr($slug, 0, 220);
        if (DB::table($table)->where('locale', $locale)->where('slug', $slug)->where($foreignKey, '!=', $id)->exists()) {
            $slug .= '-oc-'.$id;
        }
        $columns = $this->columnCache[$table] ??= DB::getSchemaBuilder()->getColumnListing($table);
        $fields = $extra + [in_array('name', $columns, true) ? 'name' : 'title' => $name ?: 'OpenCart '.$id, 'slug' => $slug, 'payload' => $this->json(['opencart' => $row])];
        if (in_array('body_html', $columns, true)) {
            $fields['body_html'] = $this->cleaner->clean(html_entity_decode($row['description'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        foreach (['description', 'meta_title', 'meta_description', 'excerpt'] as $field) {
            if (in_array($field, $columns, true)) {
                $fields[$field] = $field === 'description' ? $this->cleaner->clean(html_entity_decode($row[$field] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')) : ($row[$field] ?? null);
            }
        }
        $this->mapped('translation_'.$table, $id.':'.$locale, $table, [$foreignKey => $id, 'locale' => $locale] + $fields, ['row' => $row, 'name' => $name, 'keyword' => $keyword, 'extra' => $extra]);
    }

    private function issue(string $entity, int|string $sourceId, string $code, array $context = []): void
    {
        DB::table('herrera_import_issues')->updateOrInsert(['entity' => $entity, 'source_id' => (string) $sourceId, 'code' => $code], ['context' => $this->json($context), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function id(string $entity, int|string $sourceId): ?int
    {
        return $this->maps[$entity][(string) $sourceId] ?? null;
    }

    private function locale(int $id): string
    {
        return $this->locales[$id] ?? 'hr';
    }

    private function table(string $table): string
    {
        return $this->prefix.$table;
    }

    private function has(string $table): bool
    {
        return $this->source->getSchemaBuilder()->hasTable($this->table($table));
    }

    private function each(string $table, callable $callback): void
    {
        if ($this->missingAssignedCustomerIds !== null && $table === 'customer_group') {
            return;
        }
        if (! $this->has($table)) {
            return;
        }
        $query = $this->source->table($this->table($table));
        if ($this->missingAssignedCustomerIds !== null && in_array($table, ['customer', 'address'], true)) {
            $query->whereIn('customer_id', $this->missingAssignedCustomerIds);
        }
        $orderKey = [
            'order' => 'order_id',
            'order_product' => 'order_product_id',
            'order_total' => 'order_total_id',
            'order_history' => 'order_history_id',
        ][$table] ?? null;
        $consumeChunk = function ($rows) use ($callback, $orderKey): void {
            $consume = function () use ($rows, $callback): void {
                foreach ($rows as $row) {
                    $callback((array) $row);
                }
            };
            if ($orderKey === null) {
                $consume();

                return;
            }
            $stats = $this->stats;
            $this->orderChunkMapUndo = [];
            try {
                DB::transaction($consume);
            } catch (\Throwable $exception) {
                // Roll back the in-memory maps too, so the same service can be
                // safely reused after failure without phantom child ownership.
                foreach ($this->orderChunkMapUndo as $entity => $entries) {
                    foreach ($entries as $sourceId => $original) {
                        if ($original['id'] === null) {
                            unset($this->maps[$entity][$sourceId]);
                        } else {
                            $this->maps[$entity][$sourceId] = $original['id'];
                        }
                        if ($original['checksum'] === null) {
                            unset($this->checksums[$entity][$sourceId]);
                        } else {
                            $this->checksums[$entity][$sourceId] = $original['checksum'];
                        }
                    }
                }
                $this->stats = $stats;
                throw $exception;
            } finally {
                $this->orderChunkMapUndo = null;
            }
        };
        if ($orderKey !== null) {
            // These four source IDs are unique primary keys. Additional sort
            // columns force a full filesort in MySQL, while OFFSET repeatedly
            // revisits old rows. Keyset reads use the existing PRIMARY index.
            $query->chunkById(500, $consumeChunk, $orderKey);
        } else {
            $columns = $this->source->getSchemaBuilder()->getColumnListing($this->table($table));
            foreach (array_slice($columns, 0, 3) as $column) {
                $query->orderBy($column);
            }
            $query->chunk(500, $consumeChunk);
        }
    }

    private function date(?string $value, bool $endOfDay = false): ?string
    {
        if (! $value || str_starts_with($value, '0000-') || str_starts_with($value, '1970-01-01')) {
            return null;
        }

        return strlen($value) === 10 ? $value.($endOfDay ? ' 23:59:59' : ' 00:00:00') : $value;
    }

    private function oib(array ...$values): ?string
    {
        foreach ($values as $value) {
            foreach (array_intersect_key($value, array_flip(['2', 'oib', 'tax_id'])) as $candidate) {
                if (is_scalar($candidate) && preg_match('/^\d{11}$/', trim((string) $candidate))) {
                    return trim((string) $candidate);
                }
            }
        }

        return null;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }
}
