<?php

namespace App\Services\Import;

use App\Models\Catalog\Product\Product;
use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

class OpenCartPathProductImageImportService
{
    /**
     * @return array<string, int|string>
     */
    public function import(
        string $sourceDatabase,
        string $baseDir,
        string $locale = 'hr',
        bool $clearExisting = true
    ): array {
        $sourceDatabase = trim($sourceDatabase);
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);

        if ($sourceDatabase === '') {
            throw new RuntimeException('Source OpenCart database name is required.');
        }

        if ($baseDir === '' || ! is_dir($baseDir)) {
            throw new RuntimeException('Base image directory not found: '.$baseDir);
        }

        config([
            'media-library.max_file_size' => max((int) config('media-library.max_file_size', 0), 25 * 1024 * 1024),
        ]);

        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                (string) config('database.connections.mysql.host', '127.0.0.1'),
                (string) config('database.connections.mysql.port', 3306),
                $sourceDatabase
            ),
            (string) config('database.connections.mysql.username', 'root'),
            (string) config('database.connections.mysql.password', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $pathIndex = $this->buildPathIndex($baseDir);
        $sourceRows = $this->fetchSourceRows($pdo);
        $products = Product::query()
            ->with(['translations' => fn ($query) => $query->where('locale', $locale)])
            ->get()
            ->keyBy(fn (Product $product): string => strtoupper((string) $product->code));

        $stats = [
            'source_database' => $sourceDatabase,
            'base_dir' => $baseDir,
            'indexed_files' => count($pathIndex['relative']),
            'source_products' => count($sourceRows),
            'matched_products' => 0,
            'updated_products' => 0,
            'main_images_attached' => 0,
            'gallery_images_attached' => 0,
            'unmatched_products' => 0,
            'products_without_any_source_path' => 0,
            'products_without_resolved_images' => 0,
            'missing_main_paths' => 0,
            'missing_gallery_paths' => 0,
        ];

        foreach ($sourceRows as $code => $row) {
            $product = $products->get($code);
            if (! $product) {
                $stats['unmatched_products']++;
                continue;
            }

            $stats['matched_products']++;

            $sourceMain = trim((string) ($row['main_image'] ?? ''));
            $sourceGallery = array_values(array_filter((array) ($row['gallery_images'] ?? []), static fn ($value): bool => trim((string) $value) !== ''));

            if ($sourceMain === '' && $sourceGallery === []) {
                $stats['products_without_any_source_path']++;
                continue;
            }

            $resolvedMain = $this->resolveSourcePath($sourceMain, $pathIndex);
            if ($sourceMain !== '' && $resolvedMain === null) {
                $stats['missing_main_paths']++;
            }

            $resolvedGallery = [];
            foreach ($sourceGallery as $galleryPath) {
                $resolved = $this->resolveSourcePath((string) $galleryPath, $pathIndex);
                if ($resolved === null) {
                    $stats['missing_gallery_paths']++;
                    continue;
                }
                $resolvedGallery[] = $resolved;
            }

            $resolvedGallery = array_values(array_unique(array_filter($resolvedGallery)));

            if ($resolvedMain === null && $resolvedGallery !== []) {
                $resolvedMain = array_shift($resolvedGallery);
            }

            if ($resolvedMain === null && $resolvedGallery === []) {
                $stats['products_without_resolved_images']++;
                continue;
            }

            if ($clearExisting) {
                $product->clearMediaCollection('product_main');
                $product->clearMediaCollection('product_gallery');
            }

            $label = trim((string) ($product->translations->first()?->name ?: $product->code));

            if ($resolvedMain !== null) {
                $this->attachFile($product, $resolvedMain, 'product_main', $label, $locale);
                $stats['main_images_attached']++;
            }

            foreach ($resolvedGallery as $galleryFile) {
                if ($resolvedMain !== null && $galleryFile === $resolvedMain) {
                    continue;
                }

                $this->attachFile($product, $galleryFile, 'product_gallery', $label, $locale);
                $stats['gallery_images_attached']++;
            }

            $stats['updated_products']++;
        }

        return $stats;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchSourceRows(PDO $pdo): array
    {
        $rows = [];

        foreach ($pdo->query('SELECT model, image FROM oc_product ORDER BY product_id') as $row) {
            $code = strtoupper(trim((string) ($row['model'] ?? '')));
            if ($code === '') {
                continue;
            }

            $rows[$code] = [
                'main_image' => trim((string) ($row['image'] ?? '')),
                'gallery_images' => [],
            ];
        }

        $sql = 'SELECT p.model, pi.image
            FROM oc_product_image pi
            INNER JOIN oc_product p ON p.product_id = pi.product_id
            ORDER BY pi.product_id, pi.product_image_id';

        foreach ($pdo->query($sql) as $row) {
            $code = strtoupper(trim((string) ($row['model'] ?? '')));
            $image = trim((string) ($row['image'] ?? ''));
            if ($code === '' || $image === '') {
                continue;
            }

            $rows[$code] ??= [
                'main_image' => '',
                'gallery_images' => [],
            ];

            $rows[$code]['gallery_images'][] = $image;
        }

        return $rows;
    }

    /**
     * @return array{relative: array<string, string>, basename: array<string, array<int, string>>}
     */
    private function buildPathIndex(string $baseDir): array
    {
        $relative = [];
        $basename = [];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

        foreach (File::allFiles($baseDir) as $file) {
            $path = $file->getPathname();
            if ((int) $file->getSize() <= 0) {
                continue;
            }

            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($extension, $allowedExtensions, true)) {
                continue;
            }

            $relativePath = ltrim(str_replace('\\', '/', str_replace($baseDir, '', $path)), '/');
            $relativeKey = $this->normalizePath($relativePath);
            $basenameKey = $this->normalizePath(basename($path));

            $relative[$relativeKey] = $path;
            $basename[$basenameKey] ??= [];
            $basename[$basenameKey][] = $path;
        }

        return [
            'relative' => $relative,
            'basename' => $basename,
        ];
    }

    /**
     * @param array{relative: array<string, string>, basename: array<string, array<int, string>>} $pathIndex
     */
    private function resolveSourcePath(string $sourcePath, array $pathIndex): ?string
    {
        $sourcePath = trim(str_replace('\\', '/', $sourcePath));
        if ($sourcePath === '') {
            return null;
        }

        $relativePath = preg_replace('#^catalog/#i', '', ltrim($sourcePath, '/')) ?? $sourcePath;
        $relativeKey = $this->normalizePath($relativePath);

        if (isset($pathIndex['relative'][$relativeKey])) {
            return $pathIndex['relative'][$relativeKey];
        }

        $basenameKey = $this->normalizePath(basename($relativePath));
        $candidates = $pathIndex['basename'][$basenameKey] ?? [];
        if ($candidates === []) {
            return null;
        }

        $expectedDir = $this->normalizePath(dirname($relativePath));
        foreach ($candidates as $candidate) {
            if (str_contains($this->normalizePath($candidate), $expectedDir)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    private function normalizePath(string $value): string
    {
        $value = str_replace('\\', '/', trim($value));
        $value = preg_replace('#/+#', '/', $value) ?? $value;

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KD);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        return mb_strtolower($value);
    }

    private function attachFile(Product $product, string $path, string $collection, string $label, string $locale): void
    {
        $fileName = basename($path);
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        $product->addMedia(new HttpFile($path))
            ->usingName($name !== '' ? $name : $label)
            ->usingFileName($fileName)
            ->preservingOriginal()
            ->withCustomProperties([
                'alt' => [$locale => $label],
            ])
            ->toMediaCollection($collection);
    }
}
