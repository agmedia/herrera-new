<?php

namespace App\Services\Import;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Product\Product;
use App\Support\KozoCroatianTextNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

class KozoProductContentSyncService
{
    private const ATTRIBUTE_GROUPS = [
        'sastav' => [
            'label' => 'Sastav',
            'source_column' => 'Sastav',
        ],
        'kvaliteta' => [
            'label' => 'Kvaliteta',
            'source_column' => 'Balidoo kvaliteta bez skrivenih sastojaka',
        ],
        'garancija' => [
            'label' => 'Garancija',
            'source_column' => 'Garancija kvalitete',
        ],
    ];

    public function __construct(
        private readonly KozoCroatianTextNormalizer $normalizer
    ) {}

    /**
     * @param array{
     *     source_db?:string|null,
     *     source_host?:string|null,
     *     source_port?:int|string|null,
     *     source_user?:string|null,
     *     source_pass?:string|null,
     *     locale?:string|null,
     *     dry_run?:bool
     * } $options
     * @return array<string, mixed>
     */
    public function sync(array $options = []): array
    {
        $sourceDatabase = trim((string) ($options['source_db'] ?? 'kozo'));
        $locale = trim((string) ($options['locale'] ?? 'hr'));
        $dryRun = (bool) ($options['dry_run'] ?? false);

        $sourcePdo = $this->connectSourceDatabase([
            'host' => (string) ($options['source_host'] ?: config('database.connections.mysql.host', '127.0.0.1')),
            'port' => (int) ($options['source_port'] ?: config('database.connections.mysql.port', 3306)),
            'database' => $sourceDatabase,
            'username' => (string) ($options['source_user'] ?: config('database.connections.mysql.username', 'root')),
            'password' => (string) ($options['source_pass'] ?? config('database.connections.mysql.password', '')),
        ]);

        $sourceRows = $this->fetchSourceRows($sourcePdo);
        $products = Product::query()
            ->whereNotNull('sku')
            ->with([
                'translations' => fn ($query) => $query->where('locale', $locale),
                'attributes:id,group_code',
            ])
            ->get();

        $productsBySku = $products->keyBy(fn (Product $product): string => $this->normalizeSku((string) ($product->sku ?? '')));

        $matchedRows = [];
        $unmatchedSkus = [];
        $duplicateSourceSkus = [];
        $seenSourceSkus = [];

        foreach ($sourceRows as $row) {
            $rawSku = (string) ($row['Šifra'] ?? '');
            $sku = $this->normalizeSku($rawSku);
            if ($sku === '') {
                continue;
            }

            if (isset($seenSourceSkus[$sku])) {
                $duplicateSourceSkus[$sku] = ($duplicateSourceSkus[$sku] ?? 1) + 1;
                continue;
            }
            $seenSourceSkus[$sku] = true;

            $product = $productsBySku->get($sku);
            if (! $product instanceof Product) {
                $unmatchedSkus[] = $rawSku;
                continue;
            }

            $name = $this->normalizer->normalize((string) ($row['Naziv'] ?? ''));
            $descriptionText = $this->normalizer->normalize((string) ($row['Opis proizvoda'] ?? ''));

            $attributeTexts = [];
            foreach (self::ATTRIBUTE_GROUPS as $groupCode => $config) {
                $attributeTexts[$groupCode] = $this->normalizer->normalize((string) ($row[$config['source_column']] ?? ''));
            }

            $matchedRows[] = [
                'product' => $product,
                'source_sku' => $rawSku,
                'normalized_sku' => $sku,
                'name' => $name,
                'description_text' => $descriptionText,
                'description_html' => $this->plainTextToHtml($descriptionText),
                'excerpt' => $this->excerptFromText($descriptionText),
                'attributes' => $attributeTexts,
            ];
        }

        $remainingTokens = $this->collectRemainingTokens($matchedRows);

        $attributeValueMap = [];
        foreach (array_keys(self::ATTRIBUTE_GROUPS) as $groupCode) {
            $distinctValues = collect($matchedRows)
                ->map(fn (array $row): string => (string) ($row['attributes'][$groupCode] ?? ''))
                ->filter(fn (string $value): bool => $value !== '')
                ->unique()
                ->sort()
                ->values()
                ->all();

            foreach ($distinctValues as $index => $value) {
                $attributeValueMap[$groupCode][$value] = [
                    'code' => $this->attributeCode($groupCode, $value),
                    'sort_order' => $index + 1,
                ];
            }
        }

        $stats = [
            'source_database' => $sourceDatabase,
            'locale' => $locale,
            'dry_run' => $dryRun,
            'source_rows' => count($sourceRows),
            'matched_products' => count($matchedRows),
            'unmatched_source_rows' => count($unmatchedSkus),
            'unmatched_source_sample' => array_slice($unmatchedSkus, 0, 25),
            'duplicate_source_skus' => count($duplicateSourceSkus),
            'duplicate_source_sample' => array_slice(array_keys($duplicateSourceSkus), 0, 25),
            'attribute_values' => collect($attributeValueMap)
                ->map(fn (array $values): int => count($values))
                ->all(),
            'remaining_question_mark_tokens' => array_slice($remainingTokens, 0, 100),
            'remaining_question_mark_count' => count($remainingTokens),
            'products_updated' => 0,
            'translations_updated' => 0,
            'attribute_records_created' => 0,
            'attribute_records_updated' => 0,
            'product_attribute_links_synced' => 0,
        ];

        if ($dryRun) {
            return $stats;
        }

        if ($remainingTokens !== []) {
            throw new RuntimeException('Unresolved corrupted tokens remain after normalization: '.implode(', ', array_slice($remainingTokens, 0, 12)));
        }

        DB::transaction(function () use ($locale, $matchedRows, $attributeValueMap, &$stats): void {
            $attributeIdByGroupAndValue = $this->upsertAttributes($locale, $attributeValueMap, $stats);

            foreach ($matchedRows as $row) {
                /** @var Product $product */
                $product = $row['product'];
                $translation = $product->translations->first();
                $translationChanged = false;

                if ($row['name'] !== '' || $row['description_html'] !== '' || $row['excerpt'] !== '') {
                    $values = [
                        'name' => $row['name'] !== '' ? $row['name'] : (string) ($translation?->name ?? $product->code),
                        'excerpt' => $row['excerpt'] !== '' ? $row['excerpt'] : ($translation?->excerpt ?? null),
                        'description' => $row['description_html'] !== '' ? $row['description_html'] : ($translation?->description ?? null),
                    ];

                    $currentName = (string) ($translation?->name ?? '');
                    $currentExcerpt = (string) ($translation?->excerpt ?? '');
                    $currentDescription = (string) ($translation?->description ?? '');
                    $nextName = (string) ($values['name'] ?? '');
                    $nextExcerpt = (string) ($values['excerpt'] ?? '');
                    $nextDescription = (string) ($values['description'] ?? '');

                    $translationChanged = $currentName !== $nextName
                        || $currentExcerpt !== $nextExcerpt
                        || $currentDescription !== $nextDescription
                        || ! $translation;

                    $product->translations()->updateOrCreate(
                        ['locale' => $locale],
                        $values + [
                            'slug' => $translation?->slug ?: $this->fallbackProductSlug($product->id, $nextName),
                        ]
                    );
                }

                $managedAttributeIds = $product->attributes
                    ->filter(fn (Attribute $attribute): bool => in_array((string) $attribute->group_code, array_keys(self::ATTRIBUTE_GROUPS), true))
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                if ($managedAttributeIds !== []) {
                    $product->attributes()->detach($managedAttributeIds);
                }

                $attachPayload = [];
                $sortOrder = 0;
                foreach (array_keys(self::ATTRIBUTE_GROUPS) as $groupCode) {
                    $value = (string) ($row['attributes'][$groupCode] ?? '');
                    if ($value === '') {
                        continue;
                    }

                    $attributeId = $attributeIdByGroupAndValue[$groupCode][$value] ?? null;
                    if (! $attributeId) {
                        continue;
                    }

                    $attachPayload[$attributeId] = [
                        'sort_order' => $sortOrder,
                    ];
                    $sortOrder++;
                }

                if ($attachPayload !== []) {
                    $product->attributes()->syncWithoutDetaching($attachPayload);
                }

                $nextManagedAttributeIds = array_map('intval', array_keys($attachPayload));
                sort($managedAttributeIds);
                sort($nextManagedAttributeIds);
                $attributesChanged = $managedAttributeIds !== $nextManagedAttributeIds;

                if ($translationChanged) {
                    $stats['translations_updated']++;
                }
                if ($translationChanged || $attributesChanged) {
                    $stats['products_updated']++;
                }
                if ($attachPayload !== []) {
                    $stats['product_attribute_links_synced'] += count($attachPayload);
                }
            }
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

    /**
     * @return array<int, array<string, string|null>>
     */
    private function fetchSourceRows(PDO $pdo): array
    {
        $sql = <<<'SQL'
SELECT
    `Naziv`,
    `Šifra`,
    `Opis proizvoda`,
    `Sastav`,
    `Balidoo kvaliteta bez skrivenih sastojaka`,
    `Garancija kvalitete`
FROM proizvodi
WHERE `Šifra` IS NOT NULL
  AND `Šifra` <> ''
ORDER BY `Šifra`
SQL;

        return $pdo->query($sql)->fetchAll();
    }

    private function normalizeSku(string $value): string
    {
        return strtoupper(str_replace(' ', '', trim($value)));
    }

    private function plainTextToHtml(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $paragraphs = preg_split("/\n{2,}/u", $value) ?: [];
        $html = collect($paragraphs)
            ->map(fn (string $paragraph): string => trim($paragraph))
            ->filter()
            ->map(function (string $paragraph): string {
                $escaped = htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<p>'.nl2br($escaped, false).'</p>';
            })
            ->implode('');

        return trim($html);
    }

    private function excerptFromText(string $value, int $limit = 220): string
    {
        if ($value === '') {
            return '';
        }

        return (string) Str::of($value)->squish()->limit($limit, '');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function collectRemainingTokens(array $rows): array
    {
        $tokens = [];

        foreach ($rows as $row) {
            foreach ([
                (string) ($row['name'] ?? ''),
                (string) ($row['description_text'] ?? ''),
                ...array_values((array) ($row['attributes'] ?? [])),
            ] as $value) {
                foreach ($this->normalizer->unresolvedTokens($value) as $token) {
                    $tokens[$token] = true;
                }
            }
        }

        return array_values(array_keys($tokens));
    }

    private function attributeCode(string $groupCode, string $value): string
    {
        return sprintf('kozo-%s-%s', $groupCode, substr(sha1($value), 0, 16));
    }

    private function attributeSlug(string $groupCode, string $value): string
    {
        $base = Str::slug(Str::limit($value, 120, ''));
        $base = $base !== '' ? $base : $groupCode;

        return Str::limit(sprintf('kozo-%s-%s-%s', $groupCode, $base, substr(sha1($value), 0, 8)), 191, '');
    }

    private function fallbackProductSlug(int $productId, string $name): string
    {
        $slug = Str::slug($name);

        return $slug !== '' ? $slug : 'product-'.$productId;
    }

    /**
     * @param array<string, array<string, array{code:string,sort_order:int}>> $attributeValueMap
     * @param array<string, mixed> $stats
     * @return array<string, array<string, int>>
     */
    private function upsertAttributes(string $locale, array $attributeValueMap, array &$stats): array
    {
        $idMap = [];

        foreach (self::ATTRIBUTE_GROUPS as $groupCode => $config) {
            $groupLabel = (string) $config['label'];

            foreach (($attributeValueMap[$groupCode] ?? []) as $value => $meta) {
                $code = (string) $meta['code'];
                $sortOrder = (int) $meta['sort_order'];

                $attribute = Attribute::query()->firstOrNew(['code' => $code]);
                $isNew = ! $attribute->exists;

                $attribute->fill([
                    'group_code' => $groupCode,
                    'type' => Attribute::TYPE_SELECT,
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                    'payload' => [
                        'source' => [
                            'system' => 'kozo_proizvodi',
                            'group' => $groupCode,
                            'value_hash' => sha1($value),
                        ],
                    ],
                    'updated_by' => null,
                ]);

                if ($isNew) {
                    $attribute->created_by = null;
                }

                $attribute->save();

                $attribute->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'group_name' => $groupLabel,
                        'name' => $value,
                        'slug' => $this->attributeSlug($groupCode, $value),
                        'description' => $value,
                        'payload' => [
                            'source_column' => $config['source_column'],
                        ],
                    ]
                );

                $idMap[$groupCode][$value] = (int) $attribute->id;
                if ($isNew) {
                    $stats['attribute_records_created']++;
                } else {
                    $stats['attribute_records_updated']++;
                }
            }
        }

        return $idMap;
    }
}
