<?php

namespace Tests\Feature\Import;

use App\Models\Content\Blog\BlogPost;
use App\Services\Front\NavigationMenuService;
use App\Services\Import\HerreraQuickFixConfigurationService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraQuickFixConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_exposes_blog_and_all_four_correct_profiles_while_preserving_other_settings(): void
    {
        config(['commerce.b2b_only' => true]);
        $settings = app(SystemSettingsService::class);
        $settings->putMany([
            'store_brand_name' => 'Herrera',
            'store_footer_social_facebook_enabled' => false,
            'store_product_filter_attribute_group_codes' => ['custom-attribute'],
            NavigationMenuService::SETTINGS_KEY => [
                ['type' => 'catalog', 'label' => 'Artikli', 'url' => '/shop', 'sort_order' => 0],
                ['type' => 'custom', 'label' => 'Kontakt', 'url' => '/contact', 'sort_order' => 1],
            ],
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => [
                'is_enabled' => true, 'height' => 40,
                'links' => [['label' => 'Kontakt', 'url' => '/contact']],
                'socials' => [['network' => 'facebook', 'url' => 'https://www.facebook.com/old-company']],
            ],
        ]);

        $this->assertTrue(app(HerreraQuickFixConfigurationService::class)->apply());
        $this->assertTrue($settings->get('catalog_hide_out_of_stock_products'));
        $this->assertContains('custom-attribute', $settings->get('store_product_filter_attribute_group_codes'));
        $this->assertContains('herrera-oc-attribute-3', $settings->get('store_product_filter_attribute_group_codes'));
        $this->assertSame(40, $settings->get(NavigationMenuService::TOP_BAR_SETTINGS_KEY)['height']);
        $this->assertSame('/contact', $settings->get(NavigationMenuService::TOP_BAR_SETTINGS_KEY)['links'][0]['url']);

        $response = $this->get(route('home'))->assertOk()->assertDontSee('https://www.facebook.com/old-company', false);
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $expected = [
            'Facebook Hrvatska' => 'https://www.facebook.com/people/Herrera-Hrvatska/61592455240805/',
            'Facebook Slovenija' => 'https://www.facebook.com/people/Herrera-Slovenija/61593625222066/',
            'Instagram' => 'https://www.instagram.com/herrerahrvatska/',
            'TikTok' => 'https://www.tiktok.com/@herrera.hr1',
        ];
        foreach (['site-top-bar-socials', 'site-footer-links', 'site-footer-mobile-links'] as $container) {
            foreach ($expected as $label => $url) {
                $this->assertSame(1, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.$container.' ")]//a[@href="'.$url.'"][@aria-label="'.$label.'"]')->count());
            }
        }
        $this->assertGreaterThan(0, $xpath->query('//a[@href="'.route('blog.index').'"]')->count());
        $this->assertSame(2, $xpath->query('//footer//use[contains(@href, "#tiktok")]')->count());
        $post = BlogPost::query()->create(['code' => 'quick-fix-blog-post', 'is_active' => true]);
        $post->translations()->create(['locale' => 'hr', 'title' => 'Naslov članka', 'slug' => 'naslov-clanka', 'meta_title' => 'SEO naslov članka']);
        $this->get(route('blog.index'))->assertOk()
            ->assertSee('<title>Novosti i objave</title>', false)
            ->assertSee('Novosti iz Herrere, savjeti i inspiracija za vaše projekte.')
            ->assertDontSee('Termol noviteti');
        $this->get(route('blog.show', ['slug' => 'naslov-clanka']))->assertOk()
            ->assertSee('<title>SEO naslov članka</title>', false);
    }

    public function test_redeploy_preserves_administrator_changes_and_does_not_duplicate_blog(): void
    {
        config(['commerce.b2b_only' => true]);
        $settings = app(SystemSettingsService::class);
        $settings->putMany([
            'store_brand_name' => 'Herrera',
            NavigationMenuService::SETTINGS_KEY => [['type' => 'blog', 'label' => 'Novosti', 'is_active' => false]],
        ]);
        $configuration = app(HerreraQuickFixConfigurationService::class);
        $this->assertTrue($configuration->apply());
        $navigation = $settings->get(NavigationMenuService::SETTINGS_KEY);
        $this->assertCount(1, $navigation);
        $this->assertSame('Novosti', $navigation[0]['label']);
        $this->assertTrue($navigation[0]['is_active']);

        $settings->putMany([
            'store_social_facebook_url' => 'https://www.facebook.com/new-company',
            'catalog_hide_out_of_stock_products' => false,
            'store_product_filter_attribute_group_codes' => ['custom-only'],
        ]);
        $this->assertFalse($configuration->apply());
        $this->assertSame('https://www.facebook.com/new-company', $settings->get('store_social_facebook_url'));
        $this->assertFalse($settings->get('catalog_hide_out_of_stock_products'));
        $this->assertSame(['custom-only'], $settings->get('store_product_filter_attribute_group_codes'));
    }

    public function test_setup_refuses_other_stores_without_writing_settings(): void
    {
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Another store');
        try {
            app(HerreraQuickFixConfigurationService::class)->apply();
            $this->fail('The setup must refuse another store.');
        } catch (\LogicException) {
            $this->assertDatabaseMissing('system_settings', ['key' => HerreraQuickFixConfigurationService::APPLIED_KEY]);
            $this->assertDatabaseMissing('system_settings', ['key' => 'catalog_hide_out_of_stock_products']);
        }
    }
}
