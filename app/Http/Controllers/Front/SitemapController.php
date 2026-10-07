<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Page\InfoPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    private const PAGE_SIZE = 10000;

    public function robots(): Response
    {
        if (! app()->environment('production')) {
            return response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response("User-agent: *\nDisallow: /admin\nDisallow: /account\nDisallow: /cart\nDisallow: /checkout\nDisallow: /search\nDisallow: /api/\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function index(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (['products', 'categories', 'manufacturers', 'pages', 'blog'] as $kind) {
            $pages = max(1, (int) ceil($this->query($kind)->count() / self::PAGE_SIZE));
            for ($page = 1; $page <= $pages; $page++) {
                $xml .= '<sitemap><loc>'.$this->escape($this->url('seo.sitemap.part', ['kind' => $kind, 'page' => $page])).'</loc></sitemap>';
            }
        }

        return $this->xml($xml.'</sitemapindex>');
    }

    public function part(string $kind, int $page): Response
    {
        abort_unless(in_array($kind, ['products', 'categories', 'manufacturers', 'pages', 'blog'], true) && $page > 0, 404);
        $query = $this->query($kind);
        abort_if($page > max(1, (int) ceil((clone $query)->count() / self::PAGE_SIZE)), 404);
        $route = match ($kind) {
            'products' => 'products.show', 'categories' => 'categories.show',
            'manufacturers' => 'manufacturers.show', 'pages' => 'pages.show',
            'blog' => 'blog.show',
        };
        $locale = config('app.locale');
        $rows = $query->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->orderBy('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE)->get();
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        if ($kind === 'pages' && $page === 1) {
            $xml .= '<url><loc>'.$this->escape($this->url('home')).'</loc></url>';
        }
        foreach ($rows as $row) {
            $slug = $row->translations->first()?->slug;
            if (! $slug) {
                continue;
            }
            $xml .= '<url><loc>'.$this->escape($this->url($route, ['slug' => $slug])).'</loc>';
            if ($row->updated_at) {
                $xml .= '<lastmod>'.$row->updated_at->toAtomString().'</lastmod>';
            }
            $xml .= '</url>';
        }

        return $this->xml($xml.'</urlset>');
    }

    private function query(string $kind): Builder
    {
        $query = match ($kind) {
            'products' => Product::query()->where('is_active', true),
            'categories' => Category::query()->where('scope', Category::SCOPE_CATALOG)->currentlyVisible(),
            'manufacturers' => Manufacturer::query()->where('is_active', true),
            'pages' => InfoPage::query()->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now())),
            'blog' => BlogPost::query()->where('is_active', true)
                ->whereRaw(app(\App\Services\Catalog\CatalogFeatureService::class)->useBlog() ? '1 = 1' : '1 = 0')
                ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now())),
        };

        return $query->whereHas('translations', fn ($q) => $q->where('locale', config('app.locale'))->whereNotNull('slug')->where('slug', '<>', ''));
    }

    private function url(string $name, array $parameters = []): string
    {
        return rtrim((string) config('app.url'), '/').route($name, $parameters, absolute: false);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xml(string $xml): Response
    {
        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Robots-Tag' => app()->environment('production') ? 'index, follow' : 'noindex, nofollow',
        ]);
    }
}
