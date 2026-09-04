<?php

namespace Database\Seeders;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\MediaLibrary\HasMedia;

class MediaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedProducts();
        $this->seedBlogPosts();
        $this->seedCategories();
        $this->seedManufacturers();
        $this->seedUsers();
    }

    private function seedProducts(): void
    {
        Product::query()
            ->with(['translations' => fn ($q) => $q->where('locale', 'en')])
            ->chunkById(100, function ($products): void {
                foreach ($products as $product) {
                    $label = $product->translations->first()?->name ?: (string) $product->code;
                    $binary = $this->makePng($label, '#0f172a', '#ffffff', 1200, 800, 'PRODUCT');

                    $this->replaceSingleImage(
                        $product,
                        'product_main',
                        $binary,
                        'product-'.$product->id.'-main.png',
                        $label.' Main',
                        [
                            'alt' => ['en' => $label.' image', 'hr' => $label.' slika'],
                            'caption' => ['en' => 'Default product image', 'hr' => 'Zadana slika proizvoda'],
                        ]
                    );

                    $this->replaceSingleImage(
                        $product,
                        'product_gallery',
                        $binary,
                        'product-'.$product->id.'-gallery-1.png',
                        $label.' Gallery',
                        [
                            'alt' => ['en' => $label.' gallery image', 'hr' => $label.' galerijska slika'],
                            'caption' => ['en' => 'Default gallery image', 'hr' => 'Zadana galerijska slika'],
                        ]
                    );
                }
            });
    }

    private function seedBlogPosts(): void
    {
        BlogPost::query()
            ->with(['translations' => fn ($q) => $q->where('locale', 'en')])
            ->chunkById(100, function ($posts): void {
                foreach ($posts as $post) {
                    $title = $post->translations->first()?->title ?: (string) $post->code;
                    $binary = $this->makePng($title, '#0f766e', '#ffffff', 1400, 800, 'BLOG');

                    $this->replaceSingleImage(
                        $post,
                        'blog_cover',
                        $binary,
                        'blog-'.$post->id.'-cover.png',
                        $title.' Cover',
                        [
                            'alt' => ['en' => $title.' cover image', 'hr' => $title.' naslovna slika'],
                            'caption' => ['en' => 'Default blog cover image', 'hr' => 'Zadana naslovna slika bloga'],
                        ]
                    );

                    // Use same image in gallery to match requested behavior.
                    $this->replaceSingleImage(
                        $post,
                        'blog_gallery',
                        $binary,
                        'blog-'.$post->id.'-gallery-1.png',
                        $title.' Gallery',
                        [
                            'alt' => ['en' => $title.' gallery image', 'hr' => $title.' galerijska slika'],
                            'caption' => ['en' => 'Default blog gallery image', 'hr' => 'Zadana galerijska slika bloga'],
                        ]
                    );
                }
            });
    }

    private function seedCategories(): void
    {
        Category::query()
            ->with(['translations' => fn ($q) => $q->where('locale', 'en')])
            ->chunkById(100, function ($categories): void {
                foreach ($categories as $category) {
                    $name = $category->translations->first()?->name ?: (string) $category->code;

                    $iconBinary = $this->makePng($name, '#475569', '#ffffff', 512, 512, 'CATEGORY');
                    $bannerBinary = $this->makePng($name, '#334155', '#ffffff', 1440, 480, 'CATEGORY');

                    $this->replaceSingleImage(
                        $category,
                        'category_icon',
                        $iconBinary,
                        'category-'.$category->id.'-icon.png',
                        $name.' Icon',
                        [
                            'alt' => ['en' => $name.' icon', 'hr' => $name.' ikona'],
                            'caption' => ['en' => 'Default category icon', 'hr' => 'Zadana ikona kategorije'],
                        ]
                    );

                    $this->replaceSingleImage(
                        $category,
                        'category_banner',
                        $bannerBinary,
                        'category-'.$category->id.'-banner.png',
                        $name.' Banner',
                        [
                            'alt' => ['en' => $name.' banner', 'hr' => $name.' banner'],
                            'caption' => ['en' => 'Default category banner', 'hr' => 'Zadani banner kategorije'],
                        ]
                    );
                }
            });
    }

    private function seedManufacturers(): void
    {
        Manufacturer::query()
            ->with(['translations' => fn ($q) => $q->where('locale', 'en')])
            ->chunkById(100, function ($manufacturers): void {
                foreach ($manufacturers as $manufacturer) {
                    $name = $manufacturer->translations->first()?->name ?: (string) $manufacturer->code;

                    $logoBinary = $this->makePng($name, '#1e293b', '#ffffff', 512, 512, 'BRAND');
                    $bannerBinary = $this->makePng($name, '#0f172a', '#ffffff', 1440, 480, 'BRAND');

                    $this->replaceSingleImage(
                        $manufacturer,
                        'manufacturer_logo',
                        $logoBinary,
                        'manufacturer-'.$manufacturer->id.'-logo.png',
                        $name.' Logo',
                        [
                            'alt' => ['en' => $name.' logo', 'hr' => $name.' logo'],
                            'caption' => ['en' => 'Default manufacturer logo', 'hr' => 'Zadani logo proizvođača'],
                        ]
                    );

                    $this->replaceSingleImage(
                        $manufacturer,
                        'manufacturer_banner',
                        $bannerBinary,
                        'manufacturer-'.$manufacturer->id.'-banner.png',
                        $name.' Banner',
                        [
                            'alt' => ['en' => $name.' banner', 'hr' => $name.' banner'],
                            'caption' => ['en' => 'Default manufacturer banner', 'hr' => 'Zadani banner proizvođača'],
                        ]
                    );
                }
            });
    }

    private function seedUsers(): void
    {
        User::query()->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                $binary = $this->makePng($user->name, '#0369a1', '#ffffff', 512, 512, 'USER');

                $this->replaceSingleImage(
                    $user,
                    'avatar',
                    $binary,
                    'user-'.$user->id.'-avatar.png',
                    $user->name.' Avatar',
                    [
                        'alt' => ['en' => $user->name.' avatar', 'hr' => $user->name.' avatar'],
                        'caption' => ['en' => 'Default user avatar', 'hr' => 'Zadani avatar korisnika'],
                    ]
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $customProperties
     */
    private function replaceSingleImage(
        HasMedia $model,
        string $collection,
        string $binary,
        string $fileName,
        string $name,
        array $customProperties = []
    ): void {
        $model->clearMediaCollection($collection);

        $model->addMediaFromString($binary)
            ->usingName($name)
            ->usingFileName($fileName)
            ->withCustomProperties($customProperties)
            ->toMediaCollection($collection);
    }

    private function makePng(
        string $title,
        string $backgroundHex,
        string $foregroundHex,
        int $width,
        int $height,
        string $badge
    ): string {
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO0n8xkAAAAASUVORK5CYII='
            ) ?: '';
        }

        $image = imagecreatetruecolor($width, $height);
        if (! $image) {
            return '';
        }

        [$bgR, $bgG, $bgB] = $this->hexToRgb($backgroundHex);
        [$fgR, $fgG, $fgB] = $this->hexToRgb($foregroundHex);

        $background = imagecolorallocate($image, $bgR, $bgG, $bgB);
        $foreground = imagecolorallocate($image, $fgR, $fgG, $fgB);
        $accent = imagecolorallocate($image, max(0, $fgR - 40), max(0, $fgG - 40), max(0, $fgB - 40));

        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        $badgeText = strtoupper($badge);
        $badgeWidth = (int) (strlen($badgeText) * 9 + 30);
        imagefilledrectangle($image, 18, 18, 18 + $badgeWidth, 52, $accent);
        imagestring($image, 4, 30, 28, $badgeText, $foreground);

        $safeTitle = strtoupper(substr($title, 0, 36));
        imagestring($image, 5, 30, (int) ($height * 0.45), $safeTitle, $foreground);
        imagestring($image, 3, 30, (int) ($height * 0.45) + 30, 'AGSHOP DEMO IMAGE', $foreground);

        ob_start();
        imagepng($image, null, 7);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function hexToRgb(string $hex): array
    {
        $value = ltrim(trim($hex), '#');

        if (strlen($value) === 3) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        if (strlen($value) !== 6 || ! ctype_xdigit($value)) {
            return [17, 24, 39];
        }

        return [
            hexdec(substr($value, 0, 2)),
            hexdec(substr($value, 2, 2)),
            hexdec(substr($value, 4, 2)),
        ];
    }
}
