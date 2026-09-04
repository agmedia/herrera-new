<?php

namespace App\Services\Import;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionTranslation;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Option\OptionValueTranslation;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

class OpenCartSizeOptionImportService
{
    /**
     * @param array{
     *     source_db:string,
     *     source_host?:string|null,
     *     source_port?:int|string|null,
     *     source_user?:string|null,
     *     source_pass?:string|null,
     *     language_id?:int|string|null,
     *     language_code?:string|null,
     *     source_option_id?:int|string|null,
     *     source_option_name?:string|null,
     *     target_option_code?:string|null,
     *     target_locale?:string|null,
     *     fallback_locale?:string|null
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
            'host' => (string) (($options['source_host'] ?? null) ?: config('database.connections.mysql.host', '127.0.0.1')),
            'port' => (int) (($options['source_port'] ?? null) ?: config('database.connections.mysql.port', 3306)),
            'database' => $sourceDatabase,
            'username' => (string) (($options['source_user'] ?? null) ?: config('database.connections.mysql.username', 'root')),
            'password' => (string) ($options['source_pass'] ?? config('database.connections.mysql.password', '')),
        ]);

        $sourceLanguageId = ($options['language_id'] ?? null) !== null && ($options['language_id'] ?? null) !== ''
            ? (int) $options['language_id']
            : $this->detectLanguageId($sourcePdo, (string) ($options['language_code'] ?? 'hr-hr'));

        $targetLocale = $this->normalizeLocale((string) ($options['target_locale'] ?? config('app.locale', 'hr')), 'hr');
        $fallbackLocale = $this->normalizeLocale((string) ($options['fallback_locale'] ?? 'en'), 'en');
        $targetOptionCode = trim((string) ($options['target_option_code'] ?? 'size'));
        $sourceOptionName = trim((string) ($options['source_option_name'] ?? 'Veličina'));
        $sourceOptionId = ($options['source_option_id'] ?? null) !== null && ($options['source_option_id'] ?? null) !== ''
            ? (int) $options['source_option_id']
            : null;

        $sourceOption = $this->resolveSourceOption(
            pdo: $sourcePdo,
            languageId: $sourceLanguageId,
            sourceOptionId: $sourceOptionId,
            sourceOptionName: $sourceOptionName
        );

        $sourceValues = $this->fetchSourceValues($sourcePdo, $sourceLanguageId, (int) $sourceOption['option_id']);
        $sourceProductRows = $this->fetchSourceProductRows($sourcePdo, (int) $sourceOption['option_id']);
        $products = Product::query()
            ->select(['id', 'code', 'base_price', 'stock_qty'])
            ->get()
            ->keyBy(fn (Product $product): string => strtoupper((string) $product->code));

        $stats = [
            'source_database' => $sourceDatabase,
            'source_language_id' => $sourceLanguageId,
            'source_option_id' => (int) $sourceOption['option_id'],
            'source_option_name' => (string) $sourceOption['name'],
            'target_option_code' => $targetOptionCode,
            'target_option_id' => null,
            'target_locale' => $targetLocale,
            'fallback_locale' => $fallbackLocale,
            'source_values' => count($sourceValues),
            'values_imported' => 0,
            'source_products_with_option' => count($sourceProductRows),
            'matched_products' => 0,
            'unmatched_products' => 0,
            'product_links_imported' => 0,
            'product_option_values_imported' => 0,
            'inactive_option_rows' => 0,
            'duplicate_source_rows_skipped' => 0,
        ];

        DB::transaction(function () use (
            $sourceDatabase,
            $sourceOption,
            $sourceValues,
            $sourceProductRows,
            $products,
            $targetOptionCode,
            $targetLocale,
            $fallbackLocale,
            &$stats
        ): void {
            $userId = User::query()->orderBy('id')->value('id');
            $now = now();

            $targetOption = $this->ensureTargetOption(
                targetOptionCode: $targetOptionCode,
                targetLocale: $targetLocale,
                fallbackLocale: $fallbackLocale,
                sourceDatabase: $sourceDatabase,
                sourceOption: $sourceOption,
                userId: $userId
            );

            $stats['target_option_id'] = (int) $targetOption->id;

            $targetValueMap = $this->ensureTargetOptionValues(
                option: $targetOption,
                sourceValues: $sourceValues,
                targetLocale: $targetLocale,
                fallbackLocale: $fallbackLocale,
                sourceDatabase: $sourceDatabase,
                userId: $userId
            );

            $stats['values_imported'] = count($targetValueMap);

            $targetOptionValueIds = OptionValue::query()
                ->where('option_id', $targetOption->id)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($targetOptionValueIds !== []) {
                DB::table('catalog_product_option_values')
                    ->whereIn('option_value_id', $targetOptionValueIds)
                    ->delete();
            }

            DB::table('catalog_option_product')
                ->where('option_id', $targetOption->id)
                ->delete();

            $pivotRows = [];
            $optionValueRows = [];

            foreach ($sourceProductRows as $productCode => $rows) {
                $product = $products->get($productCode);
                if (! $product) {
                    $stats['unmatched_products']++;
                    continue;
                }

                $productRows = [];
                $seenTargetValueIds = [];

                foreach ($rows as $sourceRow) {
                    $targetValueId = $targetValueMap[(int) $sourceRow['source_option_value_id']] ?? null;
                    if (! $targetValueId) {
                        continue;
                    }

                    if (isset($seenTargetValueIds[$targetValueId])) {
                        $stats['duplicate_source_rows_skipped']++;
                        continue;
                    }
                    $seenTargetValueIds[$targetValueId] = true;

                    $stockQty = max(0, (int) $sourceRow['quantity']);
                    $subtract = (bool) $sourceRow['subtract'];
                    $isActive = $subtract ? $stockQty > 0 : true;

                    if (! $isActive) {
                        $stats['inactive_option_rows']++;
                    }

                    $productRows[] = [
                        'product_id' => (int) $product->id,
                        'option_value_id' => (int) $targetValueId,
                        'parent_option_value_id' => null,
                        'mode' => 'single',
                        'sku' => $this->nullableString((string) $sourceRow['sku']),
                        'stock_qty' => $stockQty,
                        'price_override' => $this->resolvePriceOverride(
                            basePrice: (float) $product->base_price,
                            optionPrice: (float) $sourceRow['price'],
                            pricePrefix: (string) $sourceRow['price_prefix']
                        ),
                        'sort_order' => count($productRows),
                        'is_active' => $isActive,
                        'combination_hash' => hash('sha256', 's:'.$targetValueId),
                        'payload' => json_encode([
                            'source' => [
                                'database' => $sourceDatabase,
                                'option_id' => (int) $sourceOption['option_id'],
                                'option_value_id' => (int) $sourceRow['source_option_value_id'],
                                'product_option_value_id' => (int) $sourceRow['source_product_option_value_id'],
                                'price_prefix' => (string) $sourceRow['price_prefix'],
                                'subtract' => $subtract,
                            ],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_by' => $userId,
                        'updated_by' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($productRows === []) {
                    continue;
                }

                $pivotRows[] = [
                    'option_id' => (int) $targetOption->id,
                    'product_id' => (int) $product->id,
                    'is_required' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                array_push($optionValueRows, ...$productRows);
                $stats['matched_products']++;
            }

            foreach (array_chunk($pivotRows, 500) as $chunk) {
                DB::table('catalog_option_product')->insert($chunk);
            }

            foreach (array_chunk($optionValueRows, 500) as $chunk) {
                DB::table('catalog_product_option_values')->insert($chunk);
            }

            $stats['product_links_imported'] = count($pivotRows);
            $stats['product_option_values_imported'] = count($optionValueRows);
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

    /**
     * @return array{option_id:int,name:string,type:string}
     */
    private function resolveSourceOption(PDO $pdo, int $languageId, ?int $sourceOptionId, string $sourceOptionName): array
    {
        if ($sourceOptionId) {
            $stmt = $pdo->prepare(
                'SELECT o.option_id, od.name, o.type
                FROM oc_option o
                INNER JOIN oc_option_description od
                    ON od.option_id = o.option_id
                    AND od.language_id = :language_id
                WHERE o.option_id = :option_id
                LIMIT 1'
            );
            $stmt->execute([
                'language_id' => $languageId,
                'option_id' => $sourceOptionId,
            ]);

            $row = $stmt->fetch();
            if ($row !== false) {
                return $row;
            }
        }

        $stmt = $pdo->prepare(
            'SELECT o.option_id, od.name, o.type
            FROM oc_option o
            INNER JOIN oc_option_description od
                ON od.option_id = o.option_id
                AND od.language_id = :language_id
            WHERE LOWER(od.name) = :name
            ORDER BY o.option_id
            LIMIT 1'
        );
        $stmt->execute([
            'language_id' => $languageId,
            'name' => Str::lower(trim($sourceOptionName)),
        ]);

        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Source OpenCart option not found: '.$sourceOptionName);
        }

        return $row;
    }

    /**
     * @return array<int, array{source_option_value_id:int,name:string,sort_order:int}>
     */
    private function fetchSourceValues(PDO $pdo, int $languageId, int $sourceOptionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT
                ov.option_value_id AS source_option_value_id,
                ov.sort_order,
                ovd.name
            FROM oc_option_value ov
            INNER JOIN oc_option_value_description ovd
                ON ovd.option_value_id = ov.option_value_id
                AND ovd.language_id = :language_id
            WHERE ov.option_id = :option_id
            ORDER BY ov.sort_order, ov.option_value_id'
        );
        $stmt->execute([
            'language_id' => $languageId,
            'option_id' => $sourceOptionId,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fetchSourceProductRows(PDO $pdo, int $sourceOptionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT
                p.model,
                pov.product_option_value_id AS source_product_option_value_id,
                pov.option_value_id AS source_option_value_id,
                pov.quantity,
                pov.subtract,
                pov.price,
                pov.price_prefix,
                pov.sku
            FROM oc_product_option_value pov
            INNER JOIN oc_product_option po
                ON po.product_option_id = pov.product_option_id
            INNER JOIN oc_product p
                ON p.product_id = po.product_id
            INNER JOIN oc_option_value ov
                ON ov.option_value_id = pov.option_value_id
            WHERE po.option_id = :option_id
            ORDER BY p.product_id, ov.sort_order, pov.product_option_value_id'
        );
        $stmt->execute([
            'option_id' => $sourceOptionId,
        ]);

        $grouped = [];

        foreach ($stmt->fetchAll() as $row) {
            $productCode = strtoupper(trim((string) ($row['model'] ?? '')));
            if ($productCode === '') {
                continue;
            }

            $grouped[$productCode] ??= [];
            $grouped[$productCode][] = $row;
        }

        return $grouped;
    }

    private function ensureTargetOption(
        string $targetOptionCode,
        string $targetLocale,
        string $fallbackLocale,
        string $sourceDatabase,
        array $sourceOption,
        ?int $userId
    ): Option {
        $option = Option::query()->firstOrNew([
            'code' => $targetOptionCode,
        ]);

        $isNewOption = ! $option->exists;

        $option->fill([
            'type' => $isNewOption
                ? $this->mapSourceOptionType((string) ($sourceOption['type'] ?? ''))
                : (string) ($option->type ?: $this->mapSourceOptionType((string) ($sourceOption['type'] ?? ''))),
            'is_active' => true,
            'sort_order' => (int) ($option->sort_order ?? 0),
            'payload' => array_filter(array_merge((array) ($option->payload ?? []), [
                'source' => [
                    'database' => $sourceDatabase,
                    'option_id' => (int) $sourceOption['option_id'],
                    'option_name' => (string) $sourceOption['name'],
                    'option_type' => (string) $sourceOption['type'],
                ],
            ])),
            'updated_by' => $userId,
        ]);

        if (! $option->exists) {
            $option->created_by = $userId;
        }

        $option->save();

        $sourceLabel = trim((string) ($sourceOption['name'] ?? ''));
        $fallbackLabel = $this->humanizeOptionCode($targetOptionCode);
        $labels = [
            $targetLocale => $sourceLabel !== '' ? $sourceLabel : $fallbackLabel,
            $fallbackLocale => $fallbackLabel,
        ];

        foreach ($labels as $locale => $label) {
            $translation = OptionTranslation::query()->firstOrNew([
                'option_id' => $option->id,
                'locale' => $locale,
            ]);

            if (trim((string) $translation->name) === '') {
                $translation->name = $label;
            }
            $translation->description = $translation->description;
            $translation->payload = array_filter(array_merge((array) ($translation->payload ?? []), [
                'source' => [
                    'database' => $sourceDatabase,
                    'option_id' => (int) $sourceOption['option_id'],
                ],
            ]));

            if (! $translation->exists || trim((string) $translation->slug) === '') {
                $translation->slug = $this->uniqueSlug(
                    table: 'catalog_option_translations',
                    locale: $locale,
                    baseSlug: $locale === $targetLocale
                        ? ($sourceLabel !== '' ? $sourceLabel : $targetOptionCode)
                        : $fallbackLabel,
                    keyColumn: 'option_id',
                    ignoreId: (int) $option->id
                );
            }

            $translation->save();
        }

        return $option;
    }

    /**
     * @param array<int, array{source_option_value_id:int,name:string,sort_order:int}> $sourceValues
     * @return array<int, int>
     */
    private function ensureTargetOptionValues(
        Option $option,
        array $sourceValues,
        string $targetLocale,
        string $fallbackLocale,
        string $sourceDatabase,
        ?int $userId
    ): array {
        $existingValues = $option->values()->get()->keyBy('code');
        $valueMap = [];
        $existingCodes = $existingValues->keys()
            ->mapWithKeys(fn ($code): array => [(string) $code => true])
            ->all();
        $reservedCodes = [];

        foreach ($sourceValues as $sourceValue) {
            $sourceValueId = (int) $sourceValue['source_option_value_id'];
            $label = trim((string) $sourceValue['name']);
            $code = $this->buildValueCode($label, $sourceValueId, $existingCodes, $reservedCodes);

            /** @var OptionValue|null $value */
            $value = $existingValues->get($code);
            if (! $value) {
                $value = new OptionValue([
                    'option_id' => $option->id,
                    'code' => $code,
                    'created_by' => $userId,
                ]);
            }

            $value->fill([
                'option_id' => $option->id,
                'code' => $code,
                'is_active' => true,
                'sort_order' => (int) $sourceValue['sort_order'],
                'payload' => array_filter(array_merge((array) ($value->payload ?? []), [
                    'source' => [
                        'database' => $sourceDatabase,
                        'option_value_id' => $sourceValueId,
                    ],
                ])),
                'updated_by' => $userId,
            ]);

            $value->save();
            $existingValues->put($code, $value);
            $valueMap[$sourceValueId] = (int) $value->id;

            $valueLabels = [
                $targetLocale => $label,
                $fallbackLocale => $label,
            ];

            foreach ($valueLabels as $locale => $translationLabel) {
                $translation = OptionValueTranslation::query()->firstOrNew([
                    'option_value_id' => $value->id,
                    'locale' => $locale,
                ]);

                $translation->name = $translationLabel;
                $translation->payload = array_filter(array_merge((array) ($translation->payload ?? []), [
                    'source' => [
                        'database' => $sourceDatabase,
                        'option_value_id' => $sourceValueId,
                    ],
                ]));

                if (! $translation->exists || trim((string) $translation->slug) === '') {
                    $translation->slug = $this->uniqueSlug(
                        table: 'catalog_option_value_translations',
                        locale: $locale,
                        baseSlug: $option->code.'-'.$code,
                        keyColumn: 'option_value_id',
                        ignoreId: (int) $value->id
                    );
                }

                $translation->save();
            }
        }

        return $valueMap;
    }

    private function buildValueCode(string $label, int $sourceValueId, array $existingCodes, array &$reservedCodes): string
    {
        $base = Str::slug(Str::lower($label), '-');
        if ($base === '') {
            $base = 'value-'.$sourceValueId;
        }

        if (isset($existingCodes[$base])) {
            return $base;
        }

        $code = $base;
        $suffix = 2;

        while (isset($existingCodes[$code]) || isset($reservedCodes[$code])) {
            $code = $base.'-'.$suffix;
            $suffix++;
        }

        $reservedCodes[$code] = true;

        return $code;
    }

    private function uniqueSlug(
        string $table,
        string $locale,
        string $baseSlug,
        string $keyColumn,
        int $ignoreId
    ): string {
        $baseSlug = trim(Str::slug($baseSlug));
        if ($baseSlug === '') {
            $baseSlug = 'value';
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (
            DB::table($table)
                ->where('locale', $locale)
                ->where('slug', $slug)
                ->where($keyColumn, '!=', $ignoreId)
                ->exists()
        ) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function resolvePriceOverride(float $basePrice, float $optionPrice, string $pricePrefix): ?float
    {
        $pricePrefix = trim($pricePrefix);
        $optionPrice = round($optionPrice, 2);

        if ($pricePrefix === '+' && $optionPrice > 0) {
            return round($basePrice + $optionPrice, 2);
        }

        if ($pricePrefix === '-' && $optionPrice > 0) {
            return round(max(0, $basePrice - $optionPrice), 2);
        }

        if ($pricePrefix === '' && $optionPrice > 0) {
            return $optionPrice;
        }

        return null;
    }

    private function normalizeLocale(string $value, string $fallback): string
    {
        $value = trim(Str::lower($value));

        return $value !== '' ? $value : $fallback;
    }

    private function mapSourceOptionType(string $sourceType): string
    {
        return match (Str::lower(trim($sourceType))) {
            'radio' => Option::TYPE_RADIO,
            'checkbox' => Option::TYPE_CHECKBOX,
            default => Option::TYPE_SELECT,
        };
    }

    private function humanizeOptionCode(string $code): string
    {
        return trim(Str::of($code)->replace(['-', '_'], ' ')->title()->value());
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
