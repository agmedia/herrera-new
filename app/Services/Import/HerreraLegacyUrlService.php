<?php

namespace App\Services\Import;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HerreraLegacyUrlService
{
    public static function normalizePath(string $path): ?string
    {
        $path = rawurldecode(trim($path));
        if (str_contains($path, '://') || str_starts_with($path, '//') || preg_match('/[\x00-\x1f\x7f\\\\?#]/u', $path)) {
            return null;
        }
        $parts = explode('/', trim($path, '/'));
        if (in_array('..', $parts, true) || in_array('.', $parts, true)) {
            return null;
        }

        return '/'.implode('/', array_filter($parts, fn ($part) => $part !== ''));
    }

    public function resolve(Request $request): ?array
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true) || ! DB::getSchemaBuilder()->hasTable('herrera_legacy_urls')) {
            return null;
        }
        $path = self::normalizePath($request->path());
        if (! $path) {
            return null;
        }
        if (in_array($path, ['/', '/index.php'], true) && $request->query('route')) {
            $query = $this->queryFromOpenCartRequest($request);
            $destination = $query ? $this->destinationForQuery($query, app()->getLocale()) : null;

            return $destination ? ['status' => 'redirect', 'destination' => $destination] : ['status' => 'unresolved', 'destination' => null];
        }
        if ($path === '/index.php') {
            $legacyPath = $request->query('_route_');
            if (! $legacyPath) {
                return ['status' => 'redirect', 'destination' => '/'];
            }
            $path = is_string($legacyPath) ? self::normalizePath($legacyPath) : null;
            if (! $path) {
                return ['status' => 'unresolved', 'destination' => null];
            }
        }
        if (preg_match('#^/(admin|api|livewire|storage|build|product|category|manufacturer|page|blog)/#', $path)) {
            return null;
        }
        $row = $this->findPath($path);
        if (! $row && str_contains(trim($path, '/'), '/')) {
            // OpenCart composes category prefixes dynamically. Only accept
            // known category aliases before the leaf; never arbitrary paths.
            $parts = explode('/', trim($path, '/'));
            $leaf = array_pop($parts);
            $valid = true;
            foreach ($parts as $part) {
                $prefix = $this->findPath('/'.$part);
                if (! $prefix || (! str_starts_with($prefix->source_query, 'category_id=') && ! str_starts_with($prefix->source_query, 'manufacturer_id='))) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                $row = $this->findPath('/'.$leaf);
            }
        }
        if (! $row) {
            return null;
        }
        // Recheck the actual target at request time: deleted/disabled records
        // return a deliberate 410, never a redirect into a new 404.
        $destination = $row->status === 'redirect' ? $this->destinationForQuery($row->source_query, $row->locale) : null;
        if ($destination === $path) {
            return null;
        }

        return ['status' => $destination ? 'redirect' : $row->status, 'destination' => $destination];
    }

    private function findPath(string $path): ?object
    {
        return DB::table('herrera_legacy_urls')->where('path_hash', hash('sha256', $path))->orderByRaw('CASE WHEN locale = ? THEN 0 WHEN locale = ? THEN 1 ELSE 2 END', [app()->getLocale(), 'hr'])->first();
    }

    public function destinationForQuery(string $query, string $locale = 'hr'): ?string
    {
        if ($query === 'product/special') {
            return '/akcije';
        }
        $static = ['common/home' => '/', 'product/search' => '/shop', 'product/manufacturer' => '/brendovi', 'information/contact' => '/contact', 'information/sitemap' => '/categories', 'extension/blog/home' => '/blog', 'account/login' => '/auth/login', 'account/register' => '/auth/b2b-register', 'account/forgotten' => '/auth/forgot-password', 'account/account' => '/account', 'account/edit' => '/account/profile', 'account/order' => '/account/orders', 'account/address' => '/account/profile', 'account/wishlist' => '/wishlist', 'checkout/cart' => '/cart', 'checkout/checkout' => '/checkout'];
        if (isset($static[$query])) {
            return $static[$query];
        }
        if (! preg_match('/^(product_id|category_id|manufacturer_id|information_id|blog_id|blog_category_id)=(\d+)$/', $query, $match)) {
            return null;
        }
        [$entity, $table, $translations, $foreignKey, $prefix] = match ($match[1]) {
            'product_id' => ['product', 'products', 'product_translations', 'product_id', '/product/'],
            'category_id' => ['category', 'categories', 'category_translations', 'category_id', '/category/'],
            'manufacturer_id' => ['manufacturer', 'catalog_manufacturers', 'catalog_manufacturer_translations', 'manufacturer_id', '/manufacturer/'],
            'information_id' => ['information', 'content_info_pages', 'content_info_page_translations', 'page_id', '/page/'],
            'blog_id' => ['blog', 'content_blog_posts', 'content_blog_post_translations', 'post_id', '/blog/'],
            'blog_category_id' => ['blog_category', 'categories', 'category_translations', 'category_id', '/blog?category='],
        };
        $id = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', $entity)->where('source_id', $match[2])->value('target_id');
        if (! $id || ! DB::table($table)->where('id', $id)->where('is_active', true)->exists()) {
            return null;
        }
        $slug = DB::table($translations)->where($foreignKey, $id)->orderByRaw('CASE WHEN locale = ? THEN 0 WHEN locale = ? THEN 1 ELSE 2 END', [$locale, 'hr'])->value('slug');

        return $slug ? $prefix.rawurlencode($slug) : null;
    }

    private function queryFromOpenCartRequest(Request $request): ?string
    {
        return match ($request->query('route')) {
            'product/product' => $this->idQuery('product_id', $request->query('product_id')),
            'product/category' => $this->idQuery('category_id', last(explode('_', (string) $request->query('path')))),
            'product/manufacturer/info' => $this->idQuery('manufacturer_id', $request->query('manufacturer_id')),
            'information/information' => $this->idQuery('information_id', $request->query('information_id')),
            'extension/blog/blog' => $this->idQuery('blog_id', $request->query('blog_id')),
            default => (string) $request->query('route'),
        };
    }

    private function idQuery(string $entity, mixed $id): ?string
    {
        return is_scalar($id) && ctype_digit((string) $id) ? $entity.'='.$id : null;
    }
}
