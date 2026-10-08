<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use App\Support\FontAwesomeIcon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraLayoutAlignmentFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_herrera_header_and_wide_content_use_consistent_shared_container_gutters(): void
    {
        $this->configure();
        $response = $this->get(route('home'))->assertOk()
            ->assertSee('href="mailto:info@example.test"', false)
            ->assertSee('href="tel:+38512345678"', false);
        $xpath = $this->xpath($response->getContent());

        foreach (['site-top-bar-inner', 'herrera-header-layout', 'store-announcement-shell', 'herrera-main', 'site-footer-shell'] as $section) {
            $this->assertSame(1, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.$section.' ") and contains(concat(" ", normalize-space(@class), " "), " herrera-layout-container ")]')->count(), $section);
        }
        $this->assertSame(1, $xpath->query('//main[@data-herrera-main]')->count());
        $this->assertSame(1, $xpath->query('//main[contains(@class, "herrera-home-main")]')->count());
        $this->assertInlineIcon($xpath, '//*[contains(@class, "site-top-bar-links")]/a[@href="mailto:info@example.test"]', 'envelope', 'regular');
        $this->assertInlineIcon($xpath, '//*[contains(@class, "site-top-bar-links")]/a[@href="tel:+38512345678"]', 'phone', 'regular');
        $this->get(route('front.storefront.styles'))->assertOk()
            ->assertSee('--storefront-container-width:1860px;--header-content-width:1860px;', false);
    }

    public function test_home_products_show_eight_representative_items_and_keep_guest_prices_private(): void
    {
        $this->configure();
        foreach (range(1, 12) as $index) {
            $product = Product::query()->create(['code' => 'ALIGNMENT-'.$index, 'is_active' => true, 'base_price' => 9876.54]);
            $product->translations()->create(['locale' => 'hr', 'name' => 'Alignment product '.$index, 'slug' => 'alignment-product-'.$index]);
        }
        $response = $this->get(route('home'))->assertOk()->assertDontSee('9876.54', false)
            ->assertDontSee('data-product-card-form', false);
        $xpath = $this->xpath($response->getContent());
        $carousel = $xpath->query('//*[@data-herrera-home-products-splide and @data-continuous-card-carousel]')->item(0);
        $this->assertNotNull($carousel);
        foreach (['herrera-hero-primary-action', 'herrera-home-products-link'] as $linkClass) {
            $link = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " '.$linkClass.' ")]')->item(0);
            $this->assertNotNull($link);
            $this->assertSame(route('categories.index'), $link->getAttribute('href'));
        }
        $cards = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " splide__slide ")]/*[@data-product-card]', $carousel);
        $this->assertSame(8, $cards->count());
        $options = json_decode($carousel->getAttribute('data-splide'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('loop', $options['type']);
        $this->assertSame('Prethodna', $options['i18n']['last']);
        $this->assertSame('Sljedeća', $options['i18n']['first']);
        $this->assertFalse($options['rewind']);
        $this->assertSame(6, $options['perPage']);
        $this->assertSame(1, $options['perMove']);
        $this->assertFalse($options['pagination']);
        $this->assertTrue($options['drag']);
        $this->assertSame(4, $options['breakpoints'][1279]['perPage']);
        $this->assertSame(2, $options['breakpoints'][767]['perPage']);
        $this->assertSame(2, $xpath->query('.//button[contains(@class, "splide__arrow") and @aria-label]', $carousel)->count());
        $response->assertSee('vendor/splide/splide.min.js', false);
        $response->assertDontSee('cdn.jsdelivr.net/npm/@splidejs', false);
    }

    public function test_single_home_product_does_not_offer_empty_carousel_navigation(): void
    {
        $this->configure();
        $product = Product::query()->create(['code' => 'SINGLE-HOME-PRODUCT', 'is_active' => true, 'base_price' => 9876.54]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Single home product', 'slug' => 'single-home-product']);

        $response = $this->get(route('home'))->assertOk()->assertDontSee('9876.54', false);
        $xpath = $this->xpath($response->getContent());
        $carousel = $xpath->query('//*[@data-herrera-home-products-splide]')->item(0);
        $this->assertNotNull($carousel);
        $options = json_decode($carousel->getAttribute('data-splide'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('slide', $options['type']);
        $this->assertSame(6, $options['perPage']);
        $this->assertSame(4, $options['breakpoints'][1279]['perPage']);
        $this->assertSame(2, $options['breakpoints'][767]['perPage']);
        $this->assertTrue($options['destroy']);
        $this->assertTrue($options['breakpoints'][1279]['destroy']);
        $this->assertTrue($options['breakpoints'][767]['destroy']);
        $this->assertFalse($options['arrows']);
        $this->assertFalse($options['drag']);
        $this->assertSame(1, $xpath->query('.//*[@data-product-card]', $carousel)->count());
        $this->assertSame(0, $xpath->query('.//button[contains(@class, "splide__arrow")]', $carousel)->count());
    }

    public function test_home_product_fallback_represents_multiple_manufacturers_instead_of_the_first_eight_ids(): void
    {
        $this->configure();
        $representatives = collect();
        foreach (range(1, 8) as $manufacturerIndex) {
            $manufacturer = Manufacturer::query()->create(['code' => 'HOME-BRAND-'.$manufacturerIndex, 'is_active' => true]);
            $manufacturer->translations()->create(['locale' => 'hr', 'name' => 'Home brand '.$manufacturerIndex, 'slug' => 'home-brand-'.$manufacturerIndex]);
            foreach (range(1, $manufacturerIndex === 1 ? 10 : 1) as $productIndex) {
                $product = Product::query()->create(['code' => 'HOME-'.$manufacturerIndex.'-'.$productIndex, 'manufacturer_id' => $manufacturer->id,
                    'is_active' => true, 'base_price' => 9876.54]);
                $product->translations()->create(['locale' => 'hr', 'name' => 'Representative '.$manufacturerIndex.' '.$productIndex,
                    'slug' => 'representative-'.$manufacturerIndex.'-'.$productIndex]);
                if ($productIndex === 1) {
                    $representatives->push((string) $product->id);
                }
            }
        }
        $inactive = Product::query()->create(['code' => 'herrera-oc-product-5165', 'is_active' => false, 'base_price' => 9876.54]);
        $inactive->translations()->create(['locale' => 'hr', 'name' => 'Inactive curated product', 'slug' => 'inactive-curated-product']);

        $response = $this->get(route('home'))->assertOk()->assertDontSee('9876.54', false)
            ->assertDontSee('Inactive curated product')->assertDontSee('data-product-card-form', false);
        $cards = $this->xpath($response->getContent())->query('//*[@data-herrera-home-products]//*[@data-product-card]');
        $this->assertSame($representatives->all(), array_map(fn ($node): string => $node->getAttribute('data-product-id'), iterator_to_array($cards)));
    }

    public function test_guest_cart_preview_has_compact_aligned_access_actions_without_totals(): void
    {
        $this->configure();
        $this->get(route('home'))->assertOk()
            ->assertSee('header-cart-empty--b2b', false)
            ->assertSee(route('front.auth.login'), false)
            ->assertSee(route('front.auth.b2b-register'), false)
            ->assertDontSee('header-cart-summary-total', false)
            ->assertDontSee('header-cart-item-meta', false);
        $css = file_get_contents(public_path('front-theme/styles/herrera.css'));
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.header-cart-popover\s*\{[^}]*right:\s*0;/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.header-cart-popover::before\s*\{[^}]*right:\s*17px;[^}]*width:\s*14px;/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.header-cart-empty--b2b\s*\{[^}]*min-height:\s*0;[^}]*padding:\s*24px;[^}]*text-align:\s*left;/s', $css);
    }

    public function test_alignment_and_full_width_contact_strip_styles_only_apply_to_herrera(): void
    {
        $this->configure('Termol');
        $this->get(route('home'))->assertOk()->assertDontSee('herrera-layout-container', false)
            ->assertDontSee('data-herrera-main', false)->assertDontSee('data-herrera-home-products-splide', false);
        $css = file_get_contents(public_path('front-theme/styles/herrera.css'));
        $this->assertStringContainsString('--herrera-layout-width: var(--header-content-width, 1400px)', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.herrera-layout-container\s*\{[^}]*max-width:\s*var\(--herrera-layout-width\);[^}]*padding-right:\s*var\(--herrera-layout-gutter\);[^}]*padding-left:\s*var\(--herrera-layout-gutter\);/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.site-top-bar\s*\{[^}]*display:\s*block !important;[^}]*background:\s*var\(--herrera-dark\);/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.site-top-bar-links\s*\{[^}]*flex-wrap:\s*wrap;[^}]*white-space:\s*normal;/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.store-benefits-shell\s*\{[^}]*width:\s*100%;[^}]*max-width:\s*none;/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.store-announcement-shell\.herrera-layout-container\s*\{[^}]*max-width:\s*var\(--herrera-wide-layout-width\);[^}]*background:\s*transparent;/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.store-announcement-bar\s*\{[^}]*background:\s*var\(--herrera-cyan\);/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.herrera-home-main\s*\{[^}]*padding-top:\s*24px;/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-hero\s*\{[^}]*padding:\s*clamp\(2rem, 5vw, 4\.5rem\);[^}]*margin:\s*0;/s', $css);
    }

    public function test_requested_social_profiles_use_distinct_accessible_brand_icons_and_desktop_labels(): void
    {
        $this->configure();
        $settings = app(SystemSettingsService::class);
        $topBar = $settings->get(NavigationMenuService::TOP_BAR_SETTINGS_KEY);
        $profiles = [
            ['network' => 'facebook', 'url' => 'https://www.facebook.com/Braytron-Hrvatska-111496658683612'],
            ['network' => 'linkedin', 'url' => 'https://www.linkedin.com/company/braytron-hrvatska'],
            ['network' => 'twitter', 'url' => 'https://twitter.com/BraytronHr?t=L6x9MrI3_apFe5ezB73StA&s=08'],
        ];
        $settings->put(NavigationMenuService::TOP_BAR_SETTINGS_KEY, [...$topBar, 'socials' => [
            ...$profiles,
            ['network' => 'youtube', 'url' => 'https://www.youtube.com/inactive-social-profile', 'is_active' => false],
        ]]);
        $response = $this->get(route('home'))->assertOk()->assertDontSee('inactive-social-profile', false);
        $xpath = $this->xpath($response->getContent());
        $links = $xpath->query('//*[contains(@class, "site-footer-links")]//*[contains(@class, "herrera-footer-socials")]/a');
        $this->assertSame(array_column($profiles, 'url'), array_map(fn ($node): string => $node->getAttribute('href'), iterator_to_array($links)));
        $this->assertSame(['Facebook', 'LinkedIn', 'X / Twitter'], array_map(fn ($node): string => $node->getAttribute('aria-label'), iterator_to_array($links)));
        $this->assertSame(['Facebook', 'LinkedIn', 'X / Twitter'], array_map(fn ($node): string => trim($node->textContent), iterator_to_array($links)));
        $topbarLinks = $xpath->query('//*[contains(@class, "site-top-bar-socials")]/a');
        $this->assertSame(array_column($profiles, 'url'), array_map(fn ($node): string => $node->getAttribute('href'), iterator_to_array($topbarLinks)));
        $this->assertSame(['Facebook', 'LinkedIn', 'X / Twitter'], array_map(fn ($node): string => $node->getAttribute('aria-label'), iterator_to_array($topbarLinks)));
        $this->assertSame(['Facebook', 'LinkedIn', 'X'], array_map(fn ($node): string => trim($node->textContent), iterator_to_array($topbarLinks)));
        foreach (['facebook-f', 'linkedin-in', 'x-twitter'] as $index => $icon) {
            $this->assertSame(1, $xpath->query('//*[contains(@class, "site-footer-links")]//*[contains(@class, "herrera-footer-socials")]//use[contains(@href, "#'.$icon.'")]')->count());
            $this->assertInlineIcon($xpath, '//*[contains(@class, "site-top-bar-socials")]/a[@href="'.$profiles[$index]['url'].'"]', $icon, 'brands');
        }
        $response->assertSee('vendor/fontawesome-pro-7.3.1/', false)->assertDontSee('Font Awesome Free 6.5.1', false);
    }

    public function test_announcement_moves_to_topbar_on_large_screens_with_only_one_visible_variant(): void
    {
        $this->configure();
        app(SystemSettingsService::class)->putMany([
            'store_announcement_enabled' => true, 'store_announcement_text' => 'Herrera · Nacionalni partner za veleprodaju i distribuciju',
            'store_announcement_url' => '/informacije/partneri', 'store_announcement_new_tab' => true,
        ]);
        $response = $this->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $topbar = $xpath->query('//*[@data-herrera-topbar-announcement]/a')->item(0);
        $this->assertSame('Herrera · Nacionalni partner za veleprodaju i distribuciju', trim($topbar->textContent));
        $this->assertSame('/informacije/partneri', $topbar->getAttribute('href'));
        $this->assertSame('_blank', $topbar->getAttribute('target'));
        $this->assertSame('noopener noreferrer', $topbar->getAttribute('rel'));
        $this->assertSame(1, $xpath->query('//*[contains(@class, "store-announcement-shell") and contains(@class, "herrera-announcement-in-topbar")]')->count());
        $css = file_get_contents(public_path('front-theme/styles/herrera.css'));
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.site-top-bar-announcement\s*\{\s*display:\s*none;/s', $css);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1440px\)\s*\{.*?\.site-top-bar-inner\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\) auto minmax\(0, 1fr\);.*?\.site-top-bar-announcement\s*\{\s*display:\s*block;.*?\.herrera-announcement-in-topbar\s*\{\s*display:\s*none;/s', $css);
    }

    public function test_disabling_topbar_keeps_the_separate_announcement_visible_as_fallback(): void
    {
        $this->configure();
        app(SystemSettingsService::class)->put(NavigationMenuService::TOP_BAR_SETTINGS_KEY, ['is_enabled' => false]);
        $this->get(route('home'))->assertOk()->assertDontSee('data-herrera-topbar-announcement', false)
            ->assertDontSee('herrera-announcement-in-topbar', false)->assertSee('store-announcement-content', false);
    }

    private function configure(string $brand = 'Herrera'): void
    {
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => $brand,
            'store_product_desktop_default_cols' => 5,
            NavigationMenuService::APPEARANCE_SETTINGS_KEY => ['container_width' => 1860, 'header_content_width' => 1860],
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => [
                'is_enabled' => true,
                'links' => [
                    ['label' => 'info@example.test', 'url' => 'mailto:info@example.test'],
                    ['label' => '+385 1 234 5678', 'url' => 'tel:+38512345678'],
                ],
            ],
        ]);
    }

    private function assertInlineIcon(DOMXPath $xpath, string $selector, string $name, string $style): void
    {
        $icons = $xpath->query($selector.'/svg');
        $this->assertSame(1, $icons->count());
        $icon = $icons->item(0);
        $expected = FontAwesomeIcon::inline($name, $style);
        $this->assertNotNull($expected);
        $this->assertSame($expected['viewBox'], $icon->getAttribute('viewbox'));
        $this->assertSame(0, $icon->getElementsByTagName('use')->length);

        $expectedDocument = new DOMDocument;
        $expectedDocument->loadXML('<svg>'.$expected['content'].'</svg>');
        $pathData = static fn ($paths): array => array_map(
            static fn ($path): string => $path->getAttribute('d'),
            iterator_to_array($paths),
        );
        $expectedPaths = $pathData($expectedDocument->getElementsByTagName('path'));
        $this->assertNotEmpty($expectedPaths);
        $this->assertSame($expectedPaths, $pathData($icon->getElementsByTagName('path')));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
