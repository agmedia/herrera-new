<?php

namespace App\Services\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use App\Support\ImportedDescriptionHtmlCleaner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class TermolProductSnapshotImportService
{
    public function __construct(
        private readonly ImportedDescriptionHtmlCleaner $descriptionCleaner,
        private readonly SystemSettingsService $settings,
    ) {}

    /**
     * @return array<string, int|string>
     *
     * @throws JsonException
     */
    public function import(string $snapshotFile, bool $importImages = true): array
    {
        $rows = $this->snapshotRows($snapshotFile);

        $userId = User::query()->value('id');
        $taxRate = TaxRate::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (! $taxRate) {
            throw new RuntimeException('An active tax rate is required before importing Termol products.');
        }

        $productsWithRows = [];
        $manufacturerIds = [];
        $stats = [
            'snapshot_file' => $snapshotFile,
            'source_products' => count($rows),
            'products_imported' => 0,
            'categories_linked' => 0,
            'main_images_attached' => 0,
            'images_skipped' => 0,
            'documents_attached' => 0,
            'documents_skipped' => 0,
            'prices_include_tax' => 1,
            'manufacturers_linked' => 0,
            'manufacturer_id' => 0,
            'tax_rate_id' => (int) $taxRate->id,
        ];

        DB::transaction(function () use (
            $rows,
            $taxRate,
            $userId,
            &$productsWithRows,
            &$manufacturerIds,
            &$stats
        ): void {
            foreach (array_values($rows) as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException('Invalid Termol product row at index '.$index.'.');
                }

                $normalized = $this->normalizeRow($row);
                $category = $this->resolveCategory($normalized['category_path']);
                $manufacturer = $this->resolveManufacturer($normalized, $userId);
                $code = 'termol-'.$normalized['sku'];
                $grossPrice = $this->parseGrossPrice($normalized['price']);

                $product = Product::query()
                    ->where('code', $code)
                    ->orWhere('sku', $normalized['sku'])
                    ->first() ?? new Product;

                if (! $product->exists) {
                    $product->created_by = $userId;
                }

                $product->fill([
                    'code' => $code,
                    'sku' => $normalized['sku'],
                    'is_active' => true,
                    'manufacturer_id' => $manufacturer?->id,
                    'tax_rate_id' => $taxRate->id,
                    'base_price' => $grossPrice,
                    'stock_qty' => $this->stockQuantity($normalized['stock']),
                    'payload' => [
                        'source' => 'termol.hr',
                        'source_url' => $normalized['source_url'],
                        'source_category_url' => $this->absoluteUrl($normalized['category_path']),
                        'source_image_url' => $normalized['source_image_url'],
                        'source_price_gross' => $grossPrice,
                        'source_price_includes_tax' => true,
                        'source_availability' => $normalized['stock'],
                        'source_main_category' => $normalized['main_category'],
                        'source_main_category_url' => $this->absoluteUrl($normalized['main_category_path']),
                        'source_breadcrumbs' => $normalized['breadcrumbs'],
                        'source_specifications' => $normalized['specifications'],
                        'source_installment_pricing' => $normalized['installment_pricing'],
                        'source_documents' => array_values(array_map(
                            static fn (array $document): array => [
                                'name' => $document['name'],
                                'url' => $document['url'],
                            ],
                            $normalized['documents']
                        )),
                        'source_image_urls' => array_values(array_filter(array_map(
                            static fn (array $image): string => trim((string) ($image['source_url'] ?? '')),
                            $normalized['images']
                        ))),
                    ],
                    'updated_by' => $userId,
                ]);
                $product->save();

                $description = $this->descriptionCleaner->clean(
                    $this->descriptionWithSpecifications(
                        $normalized['description_html'],
                        $normalized['specifications']
                    )
                );
                $excerpt = $this->excerpt($normalized['name'], $description);
                $slug = $this->sourceSlug($normalized['source_url'], $normalized['name']);

                $product->translations()->updateOrCreate(
                    ['locale' => 'hr'],
                    [
                        'name' => $normalized['name'],
                        'slug' => $slug,
                        'excerpt' => $excerpt,
                        'description' => $description,
                        'meta_title' => $normalized['name'],
                        'meta_description' => $excerpt,
                        'payload' => [
                            'source' => 'termol.hr',
                            'source_url' => $normalized['source_url'],
                        ],
                    ]
                );

                $product->categories()->sync([
                    $category->id => [
                        'sort_order' => ($index + 1) * 10,
                        'is_primary' => true,
                    ],
                ]);

                $productsWithRows[] = [$product, $normalized];
                if ($manufacturer) {
                    $manufacturerIds[(int) $manufacturer->id] = true;
                }
                $stats['products_imported']++;
                $stats['categories_linked']++;
            }
        });

        $stats['manufacturers_linked'] = count($manufacturerIds);
        if (count($manufacturerIds) === 1) {
            $stats['manufacturer_id'] = (int) array_key_first($manufacturerIds);
        }

        $this->settings->put('store_pricing_prices_include_tax', true);

        foreach ($productsWithRows as [$product, $row]) {
            $imagePath = trim((string) $row['local_image_path']);
            if (! $importImages || $imagePath === '' || ! is_file($imagePath)) {
                $stats['images_skipped']++;
            } else {
                $extension = strtolower((string) pathinfo($imagePath, PATHINFO_EXTENSION));
                if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
                    $extension = 'jpg';
                }

                $product->clearMediaCollection('product_main');
                $product->addMedia($imagePath)
                    ->usingName($row['name'])
                    ->usingFileName($row['sku'].'.'.$extension)
                    ->preservingOriginal()
                    ->withCustomProperties([
                        'alt' => ['hr' => $row['name']],
                        'source' => 'termol.hr',
                        'source_url' => $row['source_image_url'],
                    ])
                    ->toMediaCollection('product_main');

                $stats['main_images_attached']++;
            }

            $documents = is_array($row['documents'] ?? null) ? $row['documents'] : [];
            $product->clearMediaCollection('product_documents');

            foreach ($documents as $documentIndex => $document) {
                if (! is_array($document)) {
                    $stats['documents_skipped']++;

                    continue;
                }

                $documentPath = trim((string) ($document['local_path'] ?? ''));
                if ($documentPath === '' || ! is_file($documentPath)) {
                    $stats['documents_skipped']++;

                    continue;
                }

                $sourceUrl = trim((string) ($document['url'] ?? ''));
                $documentName = trim((string) ($document['name'] ?? ''));
                $extension = strtolower((string) pathinfo($documentPath, PATHINFO_EXTENSION));
                $fileName = $row['sku'].'-document-'.($documentIndex + 1)
                    .($extension !== '' ? '.'.$extension : '');

                $product->addMedia($documentPath)
                    ->usingName($documentName !== '' ? $documentName : $row['name'])
                    ->usingFileName($fileName)
                    ->preservingOriginal()
                    ->withCustomProperties([
                        'source' => 'termol.hr',
                        'source_url' => $sourceUrl,
                    ])
                    ->toMediaCollection('product_documents');

                $stats['documents_attached']++;
            }
        }

        return $stats;
    }

    /**
     * @return array<string, int|string>
     *
     * @throws JsonException
     */
    public function importGalleries(string $snapshotFile, bool $clearExisting = true): array
    {
        $rows = $this->snapshotRows($snapshotFile);
        $stats = [
            'snapshot_file' => $snapshotFile,
            'source_products' => count($rows),
            'source_images' => 0,
            'matched_products' => 0,
            'products_with_galleries' => 0,
            'galleries_cleared' => 0,
            'gallery_images_attached' => 0,
            'images_skipped' => 0,
        ];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Invalid Termol gallery row at index '.$index.'.');
            }

            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['name'] ?? $sku));
            $images = is_array($row['images'] ?? null) ? array_values($row['images']) : [];
            $mainImagePath = trim((string) ($row['local_image_path'] ?? ''));
            $mainImageUrl = trim((string) ($row['source_image_url'] ?? ''));
            $images = array_values(array_filter(
                $images,
                static function (mixed $image) use ($mainImagePath, $mainImageUrl): bool {
                    if (! is_array($image)) {
                        return true;
                    }

                    $imagePath = trim((string) ($image['local_image_path'] ?? ''));
                    $imageUrl = trim((string) ($image['source_url'] ?? ''));

                    return ! (
                        ($mainImagePath !== '' && $imagePath === $mainImagePath)
                        || ($mainImageUrl !== '' && $imageUrl === $mainImageUrl)
                    );
                }
            ));
            $stats['source_images'] += count($images);

            if ($sku === '') {
                throw new RuntimeException('Termol gallery row is missing a product SKU.');
            }

            $product = Product::query()
                ->where('sku', $sku)
                ->where('code', 'like', 'termol-%')
                ->first();

            if (! $product) {
                continue;
            }

            $stats['matched_products']++;

            if ($clearExisting) {
                $product->clearMediaCollection('product_gallery');
                $stats['galleries_cleared']++;
            }

            if ($images !== []) {
                $stats['products_with_galleries']++;
            }

            foreach ($images as $imageIndex => $image) {
                if (! is_array($image)) {
                    $stats['images_skipped']++;

                    continue;
                }

                $imagePath = trim((string) ($image['local_image_path'] ?? ''));
                if ($imagePath === '' || ! is_file($imagePath)) {
                    $stats['images_skipped']++;

                    continue;
                }

                $dimensions = getimagesize($imagePath);
                if (! is_array($dimensions) || (int) $dimensions[0] !== (int) $dimensions[1]) {
                    throw new RuntimeException('Termol gallery image must be square: '.$imagePath);
                }

                $extension = strtolower((string) pathinfo($imagePath, PATHINFO_EXTENSION));
                if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
                    $extension = 'jpg';
                }

                $position = $imageIndex + 2;
                $alt = $name.' — fotografija '.$position;
                $sourceUrl = trim((string) ($image['source_url'] ?? ''));

                $product->addMedia($imagePath)
                    ->usingName($alt)
                    ->usingFileName($sku.'-gallery-'.$position.'.'.$extension)
                    ->preservingOriginal()
                    ->withCustomProperties([
                        'alt' => ['hr' => $alt],
                        'source' => 'termol.hr',
                        'source_url' => $sourceUrl,
                        'source_dimensions' => [
                            'width' => (int) $dimensions[0],
                            'height' => (int) $dimensions[1],
                        ],
                    ])
                    ->toMediaCollection('product_gallery');

                $stats['gallery_images_attached']++;
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveManufacturer(array $row, ?int $userId): ?Manufacturer
    {
        $name = trim((string) ($row['manufacturer'] ?? ''));
        if ($name === '') {
            $name = $this->inferManufacturerName((string) ($row['name'] ?? ''));
        }
        $name = match (mb_strtoupper($name, 'UTF-8')) {
            'INNNOLAB' => 'INNOLAB',
            default => $name,
        };

        if ($name === '') {
            return null;
        }

        $code = Str::slug($name);
        if ($code === '') {
            $code = 'termol-'.substr(hash('sha256', Str::lower($name)), 0, 24);
        }

        $manufacturer = Manufacturer::query()->firstOrNew(['code' => $code]);

        if (! $manufacturer->exists) {
            $manufacturer->created_by = $userId;
            $manufacturer->is_featured = false;
            $manufacturer->sort_order = 0;
        }

        $payload = is_array($manufacturer->payload) ? $manufacturer->payload : [];
        $manufacturer->fill([
            'is_active' => true,
            'payload' => array_merge($payload, [
                'source' => 'termol.hr',
            ]),
            'updated_by' => $userId,
        ]);
        $manufacturer->save();

        $manufacturer->translations()->updateOrCreate(
            ['locale' => 'hr'],
            [
                'name' => $name,
                'slug' => $code,
                'description' => null,
                'meta_title' => $name,
                'meta_description' => null,
                'payload' => [
                    'source' => 'termol.hr',
                ],
            ]
        );

        return $manufacturer;
    }

    private function inferManufacturerName(string $productName): string
    {
        $firstWord = trim((string) Str::of($productName)->before(' '));
        if (
            $firstWord === ''
            || mb_strtoupper($firstWord, 'UTF-8') !== $firstWord
            || mb_strlen($firstWord, 'UTF-8') < 3
        ) {
            return '';
        }

        $genericWords = [
            'ALUPLAST',
            'ANTIFRIZ',
            'APARAT',
            'BRTVA',
            'CIJEV',
            'DETEKTOR',
            'ELEKTRIČNI',
            'ELEKTRICNI',
            'HIDRANTNI',
            'HIDRANTSKI',
            'LUSTER',
            'MLAZNICA',
            'PLAFONJERA',
            'POKLOPAC',
            'RAD',
            'STROPNI',
            'VISILICA',
        ];

        return in_array($firstWord, $genericWords, true) ? '' : $firstWord;
    }

    /**
     * @return array<int, mixed>
     *
     * @throws JsonException
     */
    private function snapshotRows(string $snapshotFile): array
    {
        if (! is_file($snapshotFile) || ! is_readable($snapshotFile)) {
            throw new RuntimeException('Termol snapshot not found or unreadable: '.$snapshotFile);
        }

        $contents = file_get_contents($snapshotFile);
        if ($contents === false) {
            throw new RuntimeException('Unable to read Termol snapshot: '.$snapshotFile);
        }

        $rows = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('Termol snapshot contains no products.');
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $required = [
            'name',
            'sku',
            'price',
            'stock',
            'source_url',
            'category_path',
            'description_html',
            'source_image_url',
            'local_image_path',
        ];

        $normalized = [];
        foreach ($required as $field) {
            $normalized[$field] = trim((string) ($row[$field] ?? ''));
        }

        foreach (['name', 'sku', 'price', 'source_url', 'category_path'] as $field) {
            if ($normalized[$field] === '') {
                throw new RuntimeException('Termol product row is missing required field: '.$field);
            }
        }

        $normalized['manufacturer'] = trim((string) ($row['manufacturer'] ?? ''));
        $normalized['main_category'] = trim((string) ($row['main_category'] ?? ''));
        $normalized['main_category_path'] = trim((string) ($row['main_category_path'] ?? ''));
        $normalized['installment_pricing'] = trim((string) ($row['installment_pricing'] ?? ''));
        $normalized['breadcrumbs'] = $this->normalizeNamedLinks($row['breadcrumbs'] ?? []);
        $normalized['documents'] = $this->normalizeDocuments($row['documents'] ?? []);
        $normalized['images'] = $this->normalizeImages($row['images'] ?? []);
        $normalized['specifications'] = $this->normalizeSpecifications($row['specifications'] ?? []);

        return $normalized;
    }

    /**
     * @return array<int, array{name:string,path:string}>
     */
    private function normalizeNamedLinks(mixed $links): array
    {
        if (! is_array($links)) {
            return [];
        }

        return collect($links)
            ->filter(static fn (mixed $link): bool => is_array($link))
            ->map(static fn (array $link): array => [
                'name' => trim((string) ($link['name'] ?? '')),
                'path' => trim((string) ($link['path'] ?? '')),
            ])
            ->filter(static fn (array $link): bool => $link['name'] !== '' || $link['path'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name:string,url:string,local_path:string}>
     */
    private function normalizeDocuments(mixed $documents): array
    {
        if (! is_array($documents)) {
            return [];
        }

        return collect($documents)
            ->filter(static fn (mixed $document): bool => is_array($document))
            ->map(static fn (array $document): array => [
                'name' => trim((string) ($document['name'] ?? '')),
                'url' => trim((string) ($document['url'] ?? '')),
                'local_path' => trim((string) ($document['local_path'] ?? '')),
            ])
            ->filter(static fn (array $document): bool => $document['url'] !== '' || $document['local_path'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{source_url:string,local_image_path:string,alt:string}>
     */
    private function normalizeImages(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }

        return collect($images)
            ->filter(static fn (mixed $image): bool => is_array($image))
            ->map(static fn (array $image): array => [
                'source_url' => trim((string) ($image['source_url'] ?? '')),
                'local_image_path' => trim((string) ($image['local_image_path'] ?? '')),
                'alt' => trim((string) ($image['alt'] ?? '')),
            ])
            ->filter(static fn (array $image): bool => $image['source_url'] !== '' || $image['local_image_path'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{group:string,label:string,value:string}>
     */
    private function normalizeSpecifications(mixed $specifications): array
    {
        if (! is_array($specifications)) {
            return [];
        }

        return collect($specifications)
            ->filter(static fn (mixed $specification): bool => is_array($specification))
            ->map(static fn (array $specification): array => [
                'group' => trim((string) ($specification['group'] ?? '')),
                'label' => trim((string) ($specification['label'] ?? '')),
                'value' => trim((string) ($specification['value'] ?? '')),
            ])
            ->filter(static fn (array $specification): bool => (
                $specification['label'] !== '' && $specification['value'] !== ''
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{group:string,label:string,value:string}>  $specifications
     */
    private function descriptionWithSpecifications(string $description, array $specifications): string
    {
        if ($specifications === []) {
            return $description;
        }

        $groups = collect($specifications)->groupBy(
            static fn (array $specification): string => $specification['group'] !== ''
                ? $specification['group']
                : 'Specifikacije'
        );
        $html = trim($description);

        foreach ($groups as $group => $rows) {
            $html .= '<h2>'.htmlspecialchars((string) $group, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</h2><dl>';

            foreach ($rows as $row) {
                $html .= '<dt>'.htmlspecialchars($row['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8').'</dt>';
                $html .= '<dd>'.htmlspecialchars($row['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8').'</dd>';
            }

            $html .= '</dl>';
        }

        return $html;
    }

    private function resolveCategory(string $sourcePath): Category
    {
        $path = '/'.ltrim((string) (parse_url($sourcePath, PHP_URL_PATH) ?: $sourcePath), '/');
        $code = 'termol-'.substr(hash('sha256', $path), 0, 24);

        $category = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->where('code', $code)
            ->first();

        if (! $category) {
            throw new RuntimeException('Termol category is missing from the local catalog: '.$path);
        }

        return $category;
    }

    private function parseGrossPrice(string $price): float
    {
        $normalized = str_replace(["\u{00A0}", ' ', '€', '.'], '', $price);
        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized)) {
            throw new RuntimeException('Invalid Termol price: '.$price);
        }

        return round(max(0, (float) $normalized), 2);
    }

    private function stockQuantity(string $availability): int
    {
        return in_array(Str::lower(trim($availability)), ['na stanju', 'raspoloživo'], true) ? 1 : 0;
    }

    private function excerpt(string $name, string $description): ?string
    {
        $withLineBreaks = preg_replace('/<br\s*\/?>/i', "\n", $description) ?? $description;
        $text = html_entity_decode(strip_tags($withLineBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\R+/u', $text) ?: [];
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? $line), $lines),
            static fn (string $line): bool => $line !== ''
        ));

        if ($lines !== [] && Str::lower($lines[0]) === Str::lower(trim($name))) {
            array_shift($lines);
        }

        $excerpt = $lines[0] ?? '';

        return $excerpt !== '' ? Str::limit($excerpt, 300, '') : null;
    }

    private function sourceSlug(string $sourceUrl, string $fallbackName): string
    {
        $path = (string) (parse_url($sourceUrl, PHP_URL_PATH) ?: '');
        $basename = preg_replace('/\.aspx$/i', '', basename($path)) ?? '';
        $slug = Str::slug($basename);

        return $slug !== '' ? $slug : Str::slug($fallbackName);
    }

    private function absoluteUrl(string $path): string
    {
        return 'https://www.termol.hr/'.ltrim($path, '/');
    }
}
