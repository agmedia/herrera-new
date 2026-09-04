<?php

namespace App\Services\Import;

use App\Models\Catalog\Product\Product;
use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;

class DesktopProductImageImportService
{
    /**
     * @return array<string, int|string>
     */
    public function import(string $sourceDir, string $locale = 'hr', bool $clearExisting = true): array
    {
        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        if ($sourceDir === '' || ! is_dir($sourceDir)) {
            throw new RuntimeException('Source directory not found: '.$sourceDir);
        }

        config([
            'media-library.max_file_size' => max((int) config('media-library.max_file_size', 0), 25 * 1024 * 1024),
        ]);

        $directories = collect(File::directories($sourceDir))
            ->sort()
            ->values();

        $products = Product::query()
            ->with(['translations' => fn ($query) => $query->where('locale', $locale)])
            ->get()
            ->keyBy(fn (Product $product): string => strtoupper((string) $product->code));

        $stats = [
            'source_dir' => $sourceDir,
            'folders_scanned' => $directories->count(),
            'matched_products' => 0,
            'unmatched_folders' => 0,
            'folders_without_images' => 0,
            'main_images_attached' => 0,
            'gallery_images_attached' => 0,
        ];

        foreach ($directories as $directory) {
            $code = strtoupper((string) basename($directory));
            $product = $products->get($code);

            if (! $product) {
                $stats['unmatched_folders']++;
                continue;
            }

            $files = $this->imageFiles($directory);
            if ($files->isEmpty()) {
                $stats['folders_without_images']++;
                continue;
            }

            $stats['matched_products']++;

            if ($clearExisting) {
                $product->clearMediaCollection('product_main');
                $product->clearMediaCollection('product_gallery');
            }

            $mainFile = $this->pickMainFile($files, $code);
            $galleryFiles = $files
                ->reject(fn (string $path): bool => $path === $mainFile)
                ->values();

            $label = trim((string) ($product->translations->first()?->name ?: $product->code));
            $this->attachFile($product, $mainFile, 'product_main', $label, $locale);
            $stats['main_images_attached']++;

            foreach ($galleryFiles as $galleryFile) {
                $this->attachFile($product, $galleryFile, 'product_gallery', $label, $locale);
                $stats['gallery_images_attached']++;
            }
        }

        return $stats;
    }

    /**
     * @return Collection<int, string>
     */
    private function imageFiles(string $directory): Collection
    {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/jpg'];

        return collect(File::files($directory))
            ->map(fn (\SplFileInfo $file): string => $file->getPathname())
            ->filter(function (string $path) use ($allowedExtensions, $allowedMimeTypes): bool {
                if (! is_file($path)) {
                    return false;
                }

                $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

                if (! in_array($extension, $allowedExtensions, true)) {
                    return false;
                }

                if ((int) filesize($path) <= 0) {
                    return false;
                }

                $mimeType = (string) (mime_content_type($path) ?: '');

                return in_array($mimeType, $allowedMimeTypes, true);
            })
            ->sort(function (string $left, string $right): int {
                return strnatcasecmp(basename($left), basename($right));
            })
            ->values();
    }

    private function pickMainFile(Collection $files, string $code): string
    {
        $preferred = $files
            ->sortBy(function (string $path) use ($code): array {
                $name = strtoupper((string) basename($path));

                return [
                    str_starts_with($name, 'ODJEL_'.$code) ? 0 : 1,
                    $name,
                ];
            })
            ->values()
            ->first();

        return (string) $preferred;
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
