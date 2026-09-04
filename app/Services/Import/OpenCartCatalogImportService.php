<?php

namespace App\Services\Import;

use App\Models\Catalog\Category\Category;
use App\Support\ImportedDescriptionHtmlCleaner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

class OpenCartCatalogImportService
{
    public function __construct(
        private readonly ImportedDescriptionHtmlCleaner $descriptionHtmlCleaner
    ) {}

    /**
     * @param array{
     *     source_db:string,
     *     source_host?:string|null,
     *     source_port?:int|string|null,
     *     source_user?:string|null,
     *     source_pass?:string|null,
     *     language_id?:int|string|null,
     *     language_code?:string|null,
     *     locale?:string|null,
     *     default_tax_rate_id?:int|string|null,
     *     wipe_products?:bool
     * } $options
     * @return array<string, int|string|null>
     */
    public function import(array $options): array
    {
        $sourceDatabase = trim((string) ($options['source_db'] ?? ''));
        if ($sourceDatabase === '') {
            throw new RuntimeException('Source OpenCart database name is required.');
        }

        $sourcePdo = $this->connectSourceDatabase([
            'host' => (string) ($options['source_host'] ?: config('database.connections.mysql.host', '127.0.0.1')),
            'port' => (int) ($options['source_port'] ?: config('database.connections.mysql.port', 3306)),
            'database' => $sourceDatabase,
            'username' => (string) ($options['source_user'] ?: config('database.connections.mysql.username', 'root')),
            'password' => (string) ($options['source_pass'] ?? config('database.connections.mysql.password', '')),
        ]);

        $locale = trim((string) ($options['locale'] ?? config('app.locale', 'hr')));
        $languageId = $options['language_id'] !== null && $options['language_id'] !== ''
            ? (int) $options['language_id']
            : $this->detectLanguageId($sourcePdo, (string) ($options['language_code'] ?? 'hr-hr'));

        $defaultTaxRateId = $options['default_tax_rate_id'] !== null && $options['default_tax_rate_id'] !== ''
            ? (int) $options['default_tax_rate_id']
            : $this->defaultTaxRateId();

        $manufacturers = $this->fetchManufacturers($sourcePdo);
        $categories = $this->orderCategoriesForInsert($this->fetchCategories($sourcePdo, $languageId));
        $products = $this->fetchProducts($sourcePdo, $languageId);
        $categorySeo = $this->fetchSeoMap($sourcePdo, 'category', $languageId);
        $productSeo = $this->fetchSeoMap($sourcePdo, 'product', $languageId);
        $productCategoryMap = $this->fetchProductCategoryMap($sourcePdo);

        $stats = [
            'source_database' => $sourceDatabase,
            'source_language_id' => $languageId,
            'target_locale' => $locale,
            'manufacturers_imported' => 0,
            'categories_imported' => 0,
            'products_imported' => 0,
            'category_links_imported' => 0,
            'catalog_categories_deleted' => 0,
            'products_deleted' => 0,
            'manufacturers_deleted' => 0,
        ];

        DB::transaction(function () use (
            $options,
            $locale,
            $defaultTaxRateId,
            $sourceDatabase,
            $manufacturers,
            $categories,
            $products,
            $categorySeo,
            $productSeo,
            $productCategoryMap,
            &$stats
        ): void {
            if (!empty($options['wipe_products'])) {
                $stats['products_deleted'] = $this->deleteProducts();
                $stats['manufacturers_deleted'] = $this->deleteManufacturers();
            }

            $stats['catalog_categories_deleted'] = $this->deleteCatalogCategories();

            $manufacturerIdMap = $this->importManufacturers(
                manufacturers: $manufacturers,
                locale: $locale,
                sourceDatabase: $sourceDatabase
            );
            $stats['manufacturers_imported'] = count($manufacturerIdMap);

            $categoryIdMap = $this->importCategories(
                categories: $categories,
                locale: $locale,
                seoMap: $categorySeo,
                sourceDatabase: $sourceDatabase
            );
            $stats['categories_imported'] = count($categoryIdMap);

            $productIdMap = $this->importProducts(
                products: $products,
                locale: $locale,
                seoMap: $productSeo,
                sourceDatabase: $sourceDatabase,
                manufacturerIdMap: $manufacturerIdMap,
                defaultTaxRateId: $defaultTaxRateId
            );
            $stats['products_imported'] = count($productIdMap);

            $stats['category_links_imported'] = $this->syncProductCategories(
                productIdMap: $productIdMap,
                categoryIdMap: $categoryIdMap,
                productCategoryMap: $productCategoryMap
            );
        }, 3);

        return $stats;
    }

    /**
     * @param array{host:string,port:int,database:string,username:string,password:string} $config
     */
    private function connectSourceDatabase(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function detectLanguageId(PDO $pdo, string $preferredCode): int
    {
        $preferredCode = strtolower(trim($preferredCode));

        $stmt = $pdo->prepare('SELECT language_id FROM oc_language WHERE status = 1 ORDER BY sort_order, language_id');
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            throw new RuntimeException('No active languages found in source OpenCart database.');
        }

        $preferredStmt = $pdo->prepare('SELECT language_id FROM oc_language WHERE status = 1 AND LOWER(code) = :code LIMIT 1');
        $preferredStmt->execute(['code' => $preferredCode]);
        $preferred = $preferredStmt->fetchColumn();
        if ($preferred !== false) {
            return (int) $preferred;
        }

        return (int) $rows[0]['language_id'];
    }

    private function defaultTaxRateId(): ?int
    {
        $id = DB::table('tax_rates')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchManufacturers(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT manufacturer_id, name, image, sort_order FROM oc_manufacturer ORDER BY sort_order, manufacturer_id');

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCategories(PDO $pdo, int $languageId): array
    {
        $stmt = $pdo->prepare(
            'SELECT
                c.category_id,
                c.image,
                c.parent_id,
                c.top,
                c.column,
                c.sort_order,
                c.status,
                c.date_added,
                c.date_modified,
                cd.name,
                cd.description,
                cd.meta_title,
                cd.meta_description,
                cd.meta_keyword
            FROM oc_category c
            INNER JOIN oc_category_description cd
                ON cd.category_id = c.category_id
                AND cd.language_id = :language_id
            ORDER BY c.parent_id, c.sort_order, c.category_id'
        );
        $stmt->execute(['language_id' => $languageId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProducts(PDO $pdo, int $languageId): array
    {
        $stmt = $pdo->prepare(
            'SELECT
                p.product_id,
                p.model,
                p.sku,
                p.upc,
                p.ean,
                p.jan,
                p.isbn,
                p.mpn,
                p.location,
                p.quantity,
                p.stock_status_id,
                p.image,
                p.manufacturer_id,
                p.shipping,
                p.price,
                p.price_eur,
                p.price_last_30,
                p.points,
                p.tax_class_id,
                p.date_available,
                p.weight,
                p.weight_class_id,
                p.length,
                p.width,
                p.height,
                p.length_class_id,
                p.subtract,
                p.minimum,
                p.sort_order,
                p.status,
                p.viewed,
                p.date_added,
                p.date_modified,
                pd.name,
                pd.description,
                pd.tag,
                pd.meta_title,
                pd.meta_description,
                pd.meta_keyword
            FROM oc_product p
            INNER JOIN oc_product_description pd
                ON pd.product_id = p.product_id
                AND pd.language_id = :language_id
            ORDER BY p.product_id'
        );
        $stmt->execute(['language_id' => $languageId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, string>
     */
    private function fetchSeoMap(PDO $pdo, string $entity, int $languageId): array
    {
        $prefix = match ($entity) {
            'category' => 'category_id=',
            'product' => 'product_id=',
            default => throw new RuntimeException('Unsupported OpenCart SEO entity: '.$entity),
        };

        $stmt = $pdo->prepare(
            'SELECT query, keyword
            FROM oc_seo_url
            WHERE language_id = :language_id
              AND query LIKE :query_prefix
            ORDER BY seo_url_id'
        );
        $stmt->execute([
            'language_id' => $languageId,
            'query_prefix' => $prefix.'%',
        ]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) Str::after((string) $row['query'], '=');
            $keyword = trim((string) ($row['keyword'] ?? ''));
            if ($id > 0 && $keyword !== '') {
                $result[$id] = $keyword;
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function fetchProductCategoryMap(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT product_id, category_id FROM oc_product_to_category ORDER BY product_id, category_id');

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $productId = (int) $row['product_id'];
            $categoryId = (int) $row['category_id'];
            $map[$productId] ??= [];
            $map[$productId][] = $categoryId;
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<string, mixed>>
     */
    private function orderCategoriesForInsert(array $categories): array
    {
        $byId = [];
        foreach ($categories as $category) {
            $byId[(int) $category['category_id']] = $category;
        }

        $ordered = [];
        $visited = [];

        $visit = function (int $categoryId) use (&$visit, &$ordered, &$visited, $byId): void {
            if (isset($visited[$categoryId])) {
                return;
            }

            $visited[$categoryId] = true;
            $category = $byId[$categoryId] ?? null;
            if (!$category) {
                return;
            }

            $parentId = (int) ($category['parent_id'] ?? 0);
            if ($parentId > 0 && isset($byId[$parentId])) {
                $visit($parentId);
            }

            $ordered[] = $category;
        };

        foreach (array_keys($byId) as $categoryId) {
            $visit((int) $categoryId);
        }

        return $ordered;
    }

    private function deleteCatalogCategories(): int
    {
        return DB::table('categories')
            ->where('scope', Category::SCOPE_CATALOG)
            ->delete();
    }

    private function deleteProducts(): int
    {
        return DB::table('products')->delete();
    }

    private function deleteManufacturers(): int
    {
        return DB::table('catalog_manufacturers')->delete();
    }

    /**
     * @param array<int, array<string, mixed>> $manufacturers
     * @return array<int, int>
     */
    private function importManufacturers(array $manufacturers, string $locale, string $sourceDatabase): array
    {
        $slugOwners = $this->loadSlugOwners('catalog_manufacturer_translations', 'manufacturer_id', $locale);
        $idMap = [];

        foreach ($manufacturers as $manufacturer) {
            $sourceId = (int) $manufacturer['manufacturer_id'];
            $code = 'oc-manufacturer-'.$sourceId;
            $now = now()->toDateTimeString();

            $payload = [
                'source' => [
                    'system' => 'opencart',
                    'database' => $sourceDatabase,
                    'manufacturer_id' => $sourceId,
                    'image' => $this->nullIfBlank($manufacturer['image'] ?? null),
                ],
            ];

            $manufacturerId = DB::table('catalog_manufacturers')
                ->where('code', $code)
                ->value('id');

            $baseData = [
                'code' => $code,
                'is_active' => true,
                'is_featured' => false,
                'sort_order' => (int) ($manufacturer['sort_order'] ?? 0),
                'payload' => $this->jsonEncode($payload),
                'updated_by' => null,
                'updated_at' => $now,
            ];

            if ($manufacturerId) {
                DB::table('catalog_manufacturers')
                    ->where('id', $manufacturerId)
                    ->update($baseData);
                $manufacturerId = (int) $manufacturerId;
            } else {
                $manufacturerId = (int) DB::table('catalog_manufacturers')->insertGetId($baseData + [
                    'created_by' => null,
                    'created_at' => $now,
                ]);
            }

            $name = trim((string) ($manufacturer['name'] ?? ''));
            $slug = $this->reserveUniqueSlug(
                base: $name !== '' ? $name : 'manufacturer-'.$sourceId,
                owners: $slugOwners,
                ownerId: $manufacturerId
            );

            DB::table('catalog_manufacturer_translations')->updateOrInsert(
                [
                    'manufacturer_id' => $manufacturerId,
                    'locale' => $locale,
                ],
                [
                    'name' => $name !== '' ? $name : 'Manufacturer '.$sourceId,
                    'slug' => $slug,
                    'description' => null,
                    'meta_title' => $name !== '' ? $name : 'Manufacturer '.$sourceId,
                    'meta_description' => null,
                    'payload' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $idMap[$sourceId] = $manufacturerId;
        }

        return $idMap;
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @param array<int, string> $seoMap
     * @return array<int, int>
     */
    private function importCategories(array $categories, string $locale, array $seoMap, string $sourceDatabase): array
    {
        $slugOwners = $this->loadSlugOwners('category_translations', 'category_id', $locale);
        $idMap = [];

        foreach ($categories as $category) {
            $sourceId = (int) $category['category_id'];
            $sourceParentId = (int) ($category['parent_id'] ?? 0);
            $parentId = $sourceParentId > 0 ? ($idMap[$sourceParentId] ?? null) : null;
            $code = 'oc-category-'.$sourceId;
            $createdAt = $this->normalizeTimestamp($category['date_added'] ?? null);
            $updatedAt = $this->normalizeTimestamp($category['date_modified'] ?? null);

            $payload = [
                'source' => [
                    'system' => 'opencart',
                    'database' => $sourceDatabase,
                    'category_id' => $sourceId,
                    'parent_id' => $sourceParentId,
                    'image' => $this->nullIfBlank($category['image'] ?? null),
                    'top' => (int) ($category['top'] ?? 0),
                    'column' => (int) ($category['column'] ?? 0),
                    'meta_keyword' => $this->nullIfBlank($category['meta_keyword'] ?? null),
                ],
            ];

            $categoryId = DB::table('categories')
                ->where('scope', Category::SCOPE_CATALOG)
                ->where('code', $code)
                ->value('id');

            $baseData = [
                'scope' => Category::SCOPE_CATALOG,
                'code' => $code,
                'is_active' => (bool) ($category['status'] ?? false),
                'show_in_menu' => (bool) ($category['status'] ?? false),
                'sort_order' => (int) ($category['sort_order'] ?? 0),
                'payload' => $this->jsonEncode($payload),
                'parent_id' => $parentId,
                'updated_by' => null,
                'updated_at' => $updatedAt,
            ];

            if ($categoryId) {
                DB::table('categories')
                    ->where('id', $categoryId)
                    ->update($baseData);
                $categoryId = (int) $categoryId;
            } else {
                $categoryId = (int) DB::table('categories')->insertGetId($baseData + [
                    'created_by' => null,
                    'created_at' => $createdAt,
                    '_lft' => 0,
                    '_rgt' => 0,
                ]);
            }

            $name = trim((string) ($category['name'] ?? ''));
            $slug = $this->reserveUniqueSlug(
                base: $seoMap[$sourceId] ?? $name ?: $code,
                owners: $slugOwners,
                ownerId: $categoryId
            );

            $description = $this->decodeHtml((string) ($category['description'] ?? ''));
            $metaTitle = trim((string) ($category['meta_title'] ?? '')) ?: $name;
            $metaDescription = trim((string) ($category['meta_description'] ?? ''));

            DB::table('category_translations')->updateOrInsert(
                [
                    'category_id' => $categoryId,
                    'locale' => $locale,
                ],
                [
                    'name' => $name !== '' ? $name : 'Category '.$sourceId,
                    'slug' => $slug,
                    'description' => $description !== '' ? $description : null,
                    'meta_title' => $metaTitle !== '' ? $metaTitle : null,
                    'meta_description' => $metaDescription !== '' ? $metaDescription : null,
                    'payload' => null,
                    'updated_at' => $updatedAt,
                    'created_at' => $createdAt,
                ]
            );

            $idMap[$sourceId] = $categoryId;
        }

        Category::query()->fixTree();

        return $idMap;
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<int, string> $seoMap
     * @param array<int, int> $manufacturerIdMap
     * @return array<int, int>
     */
    private function importProducts(
        array $products,
        string $locale,
        array $seoMap,
        string $sourceDatabase,
        array $manufacturerIdMap,
        ?int $defaultTaxRateId
    ): array {
        $slugOwners = $this->loadSlugOwners('product_translations', 'product_id', $locale);
        $skuOwners = $this->loadSkuOwners();
        $idMap = [];

        foreach ($products as $product) {
            $sourceId = (int) $product['product_id'];
            $code = trim((string) ($product['model'] ?? ''));
            if ($code === '') {
                $code = 'oc-product-'.$sourceId;
            }

            $productId = DB::table('products')
                ->where('code', $code)
                ->value('id');

            $createdAt = $this->normalizeTimestamp($product['date_added'] ?? null);
            $updatedAt = $this->normalizeTimestamp($product['date_modified'] ?? null);
            $manufacturerId = (int) ($product['manufacturer_id'] ?? 0);
            $resolvedProductId = $productId ? (int) $productId : null;
            $resolvedSku = $this->resolveSku(
                sku: $this->nullIfBlank($product['sku'] ?? null) ?: $code,
                owners: $skuOwners,
                ownerId: $resolvedProductId
            );

            $payload = [
                'source' => [
                    'system' => 'opencart',
                    'database' => $sourceDatabase,
                    'product_id' => $sourceId,
                    'image' => $this->nullIfBlank($product['image'] ?? null),
                    'upc' => $this->nullIfBlank($product['upc'] ?? null),
                    'ean' => $this->nullIfBlank($product['ean'] ?? null),
                    'jan' => $this->nullIfBlank($product['jan'] ?? null),
                    'isbn' => $this->nullIfBlank($product['isbn'] ?? null),
                    'mpn' => $this->nullIfBlank($product['mpn'] ?? null),
                    'location' => $this->nullIfBlank($product['location'] ?? null),
                    'stock_status_id' => (int) ($product['stock_status_id'] ?? 0),
                    'shipping' => (bool) ($product['shipping'] ?? false),
                    'price_eur' => $this->nullIfBlank($product['price_eur'] ?? null),
                    'price_last_30' => $this->nullIfBlank($product['price_last_30'] ?? null),
                    'points' => (int) ($product['points'] ?? 0),
                    'tax_class_id' => (int) ($product['tax_class_id'] ?? 0),
                    'date_available' => $this->nullIfBlank($product['date_available'] ?? null),
                    'weight' => $this->nullIfBlank($product['weight'] ?? null),
                    'weight_class_id' => (int) ($product['weight_class_id'] ?? 0),
                    'length' => $this->nullIfBlank($product['length'] ?? null),
                    'width' => $this->nullIfBlank($product['width'] ?? null),
                    'height' => $this->nullIfBlank($product['height'] ?? null),
                    'length_class_id' => (int) ($product['length_class_id'] ?? 0),
                    'subtract' => (bool) ($product['subtract'] ?? false),
                    'minimum' => (int) ($product['minimum'] ?? 1),
                    'sort_order' => (int) ($product['sort_order'] ?? 0),
                    'viewed' => (int) ($product['viewed'] ?? 0),
                    'tag' => $this->nullIfBlank($product['tag'] ?? null),
                    'meta_keyword' => $this->nullIfBlank($product['meta_keyword'] ?? null),
                ],
            ];

            $baseData = [
                'code' => $code,
                'sku' => $resolvedSku,
                'is_active' => (bool) ($product['status'] ?? false),
                'manufacturer_id' => $manufacturerIdMap[$manufacturerId] ?? null,
                'tax_rate_id' => $defaultTaxRateId,
                'base_price' => round((float) ($product['price'] ?? 0), 2),
                'stock_qty' => (int) ($product['quantity'] ?? 0),
                'payload' => $this->jsonEncode($payload),
                'updated_by' => null,
                'updated_at' => $updatedAt,
            ];

            if ($resolvedProductId) {
                DB::table('products')
                    ->where('id', $resolvedProductId)
                    ->update($baseData);
                $productId = $resolvedProductId;
            } else {
                $productId = (int) DB::table('products')->insertGetId($baseData + [
                    'created_by' => null,
                    'created_at' => $createdAt,
                ]);
            }

            $name = trim((string) ($product['name'] ?? ''));
            $slug = $this->reserveUniqueSlug(
                base: $seoMap[$sourceId] ?? $name ?: $code,
                owners: $slugOwners,
                ownerId: $productId
            );
            $description = $this->decodeHtml((string) ($product['description'] ?? ''));
            $excerpt = $this->excerptFromHtml($description);
            $metaTitle = trim((string) ($product['meta_title'] ?? '')) ?: $name;
            $metaDescription = trim((string) ($product['meta_description'] ?? ''));

            DB::table('product_translations')->updateOrInsert(
                [
                    'product_id' => $productId,
                    'locale' => $locale,
                ],
                [
                    'name' => $name !== '' ? $name : 'Product '.$sourceId,
                    'slug' => $slug,
                    'excerpt' => $excerpt !== '' ? $excerpt : null,
                    'description' => $description !== '' ? $description : null,
                    'meta_title' => $metaTitle !== '' ? $metaTitle : null,
                    'meta_description' => $metaDescription !== '' ? $metaDescription : ($excerpt !== '' ? $excerpt : null),
                    'payload' => null,
                    'updated_at' => $updatedAt,
                    'created_at' => $createdAt,
                ]
            );

            $idMap[$sourceId] = $productId;
        }

        return $idMap;
    }

    /**
     * @param array<int, int> $productIdMap
     * @param array<int, int> $categoryIdMap
     * @param array<int, array<int, int>> $productCategoryMap
     */
    private function syncProductCategories(array $productIdMap, array $categoryIdMap, array $productCategoryMap): int
    {
        if ($productIdMap === []) {
            return 0;
        }

        DB::table('category_product')
            ->whereIn('product_id', array_values($productIdMap))
            ->delete();

        $rows = [];
        $now = now()->toDateTimeString();

        foreach ($productCategoryMap as $sourceProductId => $sourceCategoryIds) {
            $productId = $productIdMap[(int) $sourceProductId] ?? null;
            if (!$productId) {
                continue;
            }

            $targetCategoryIds = [];
            foreach ($sourceCategoryIds as $sourceCategoryId) {
                $targetCategoryId = $categoryIdMap[(int) $sourceCategoryId] ?? null;
                if ($targetCategoryId) {
                    $targetCategoryIds[] = $targetCategoryId;
                }
            }

            $targetCategoryIds = array_values(array_unique($targetCategoryIds));
            foreach ($targetCategoryIds as $index => $targetCategoryId) {
                $rows[] = [
                    'category_id' => $targetCategoryId,
                    'product_id' => $productId,
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('category_product')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * @return array<string, int>
     */
    private function loadSlugOwners(string $table, string $ownerColumn, string $locale): array
    {
        return DB::table($table)
            ->where('locale', $locale)
            ->pluck($ownerColumn, 'slug')
            ->mapWithKeys(fn ($ownerId, $slug): array => [(string) $slug => (int) $ownerId])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function loadSkuOwners(): array
    {
        return DB::table('products')
            ->whereNotNull('sku')
            ->where('sku', '<>', '')
            ->pluck('id', 'sku')
            ->mapWithKeys(fn ($ownerId, $sku): array => [(string) $sku => (int) $ownerId])
            ->all();
    }

    /**
     * @param array<string, int> $owners
     */
    private function reserveUniqueSlug(string $base, array &$owners, int $ownerId): string
    {
        $normalized = Str::slug(trim($base));
        if ($normalized === '') {
            $normalized = 'item';
        }

        $candidate = $normalized;
        $suffix = 2;

        while (isset($owners[$candidate]) && $owners[$candidate] !== $ownerId) {
            $candidate = $normalized.'-'.$suffix;
            $suffix++;
        }

        $owners[$candidate] = $ownerId;

        return $candidate;
    }

    /**
     * @param array<string, int> $owners
     */
    private function resolveSku(?string $sku, array &$owners, ?int $ownerId): ?string
    {
        $sku = $this->nullIfBlank($sku);
        if ($sku === null) {
            return null;
        }

        $existingOwner = $owners[$sku] ?? null;
        if ($existingOwner !== null && ($ownerId === null || $existingOwner !== $ownerId)) {
            return null;
        }

        $owners[$sku] = $ownerId ?? 0;

        return $sku;
    }

    private function decodeHtml(string $value): string
    {
        return $this->descriptionHtmlCleaner->clean($value);
    }

    private function excerptFromHtml(string $value, int $limit = 220): string
    {
        $plain = trim(strip_tags($value));
        if ($plain === '') {
            return '';
        }

        return (string) Str::of($plain)->squish()->limit($limit, '');
    }

    private function normalizeTimestamp(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : now()->toDateTimeString();
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function jsonEncode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
