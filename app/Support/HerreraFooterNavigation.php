<?php

namespace App\Support;

use Illuminate\Support\Collection;

class HerreraFooterNavigation
{
    /** Retain custom footer columns; reorganize the imported storefront defaults. */
    public static function columns(Collection $columns): Collection
    {
        $legacyTitles = ['proizvodi', 'informacije', 'b2b kupci', 'shop', 'help', 'information', 'trgovina', 'pomoć', 'info'];
        if ($columns->contains(fn (array $column): bool => ! in_array(mb_strtolower(trim($column['title'])), $legacyTitles, true))) {
            return $columns;
        }

        // Only the original imported arrangement is regrouped. CMS selections
        // must survive even when their editor keeps one of the default titles.
        $importedPaths = [
            ['/category/rasvjeta', '/category/elektromaterijal', '/category/uticnice-i-prekidaci', '/category/produzni-kabeli-i-motalice', '/category/oprema-za-mjerenje-i-ispitivanje', '/category/senzori'],
            ['/page/o-nama', '/page/opci-uvjeti-koristenja', '/page/nacin-placanja-i-dostava', '/page/pravila-privatnosti', '/forma-za-povrat-i-reklamacije'],
            ['/auth/login', '/auth/b2b-register', '/contact'],
        ];
        $configuredPaths = $columns->map(fn (array $column): array => array_map(
            fn (array $link): string => rtrim((string) parse_url($link['url'], PHP_URL_PATH), '/'),
            $column['links']
        ))->all();
        if ($configuredPaths !== $importedPaths) {
            return $columns;
        }

        $links = $columns->flatMap(fn (array $column): array => $column['links'])->unique('url')->values();
        $support = $links->filter(fn (array $link): bool => (bool) preg_match('/placan|plaćan|dostav|povrat|reklam|raskid|shipping|payment|return/i', $link['url'].' '.$link['label']))->values();
        $support->push(['label' => __('ui.mobile.menu.contact'), 'url' => route('contact.create')]);
        $company = $links->filter(fn (array $link): bool => ! preg_match('/\/category\/|\/auth\/|\/account\/|\/contact(?:[\s?\/]|$)|placan|plaćan|dostav|povrat|reklam|raskid|shipping|payment|return/i', $link['url'].' '.$link['label']))->values();
        $company->push(['label' => __('herrera.brands_all'), 'url' => route('manufacturers.index')]);

        return collect([
            ['title' => __('herrera.footer.business_customers'), 'links' => [
                ['label' => __('herrera.header.login'), 'url' => route('front.auth.login')],
                ['label' => __('herrera.footer.partner'), 'url' => route('front.auth.b2b-register')],
                ['label' => __('herrera.footer.quick_order'), 'url' => route('account.b2b.quick-order')],
                ['label' => __('herrera.footer.orders'), 'url' => route('account.orders')],
            ]],
            ['title' => __('herrera.footer.buying_support'), 'links' => $support->unique('url')->all()],
            ['title' => __('herrera.footer.company'), 'links' => $company->unique('url')->all()],
        ]);
    }
}
