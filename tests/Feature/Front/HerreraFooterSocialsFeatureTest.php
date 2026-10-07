<?php

namespace Tests\Feature\Front;

use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraFooterSocialsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_footer_urls_take_precedence_and_keep_linkedin_and_x_fallbacks(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'store_social_facebook_url' => 'https://www.facebook.com/footer-company',
            'store_social_instagram_url' => 'https://www.instagram.com/footer-company',
            'store_social_tiktok_url' => 'https://www.tiktok.com/@footer-company',
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => ['socials' => [
                ['network' => 'facebook', 'url' => 'https://www.facebook.com/topbar-company'],
                ['network' => 'linkedin', 'url' => 'https://www.linkedin.com/company/footer-company'],
                ['network' => 'twitter', 'url' => 'https://x.com/footer-company'],
                ['network' => 'youtube', 'url' => 'https://www.youtube.com/inactive-company', 'is_active' => false],
            ]],
        ]);

        $response = $this->get(route('home'))->assertOk()
            ->assertDontSee('https://www.youtube.com/inactive-company', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//*[contains(@class, "site-top-bar-socials")]/a[@href="https://www.facebook.com/topbar-company"]')->count());
        $this->assertSame(0, $xpath->query('//footer//a[@href="https://www.facebook.com/topbar-company"]')->count());
        $expected = [
            'https://www.facebook.com/footer-company',
            'https://www.instagram.com/footer-company',
            'https://www.tiktok.com/@footer-company',
            'https://www.linkedin.com/company/footer-company',
            'https://x.com/footer-company',
        ];
        foreach (['site-footer-links', 'site-footer-mobile-links'] as $container) {
            $links = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.$container.' ")]//*[contains(@class, "herrera-footer-socials")]/a');
            $this->assertSame($expected, array_map(fn ($node): string => $node->getAttribute('href'), iterator_to_array($links)));
            $this->assertSame(['Facebook', 'Instagram', 'TikTok', 'LinkedIn', 'X / Twitter'],
                array_map(fn ($node): string => $node->getAttribute('aria-label'), iterator_to_array($links)));
            foreach (['facebook-f', 'instagram', 'tiktok', 'linkedin-in', 'x-twitter'] as $icon) {
                $this->assertSame(1, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.$container.' ")]//*[contains(@class, "herrera-footer-socials")]//use[contains(@href, "#'.$icon.'")]')->count());
            }
        }
    }

    public function test_disabled_footer_networks_are_not_reenabled_by_active_topbar_profiles(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'store_social_facebook_url' => 'https://www.facebook.com/disabled-footer',
            'store_social_instagram_url' => 'https://www.instagram.com/disabled-footer',
            'store_social_tiktok_url' => 'https://www.tiktok.com/@disabled-footer',
            'store_social_youtube_url' => 'https://www.youtube.com/disabled-footer',
            'store_footer_social_facebook_enabled' => false,
            'store_footer_social_instagram_enabled' => false,
            'store_footer_social_tiktok_enabled' => false,
            'store_footer_social_youtube_enabled' => false,
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => ['socials' => [
                ['network' => 'facebook', 'url' => 'https://www.facebook.com/disabled-topbar'],
                ['network' => 'instagram', 'url' => 'https://www.instagram.com/disabled-topbar'],
                ['network' => 'youtube', 'url' => 'https://www.youtube.com/disabled-topbar'],
                ['network' => 'linkedin', 'url' => 'https://www.linkedin.com/company/footer-company'],
                ['network' => 'twitter', 'url' => 'https://x.com/footer-company'],
            ]],
        ]);

        $response = $this->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(3, $xpath->query('//*[contains(@class, "site-top-bar-socials")]/a[contains(@href, "disabled-topbar")]')->count());
        $this->assertSame(0, $xpath->query('//footer//a[contains(@href, "disabled-topbar")]')->count());
        foreach (['site-footer-links', 'site-footer-mobile-links'] as $container) {
            $links = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.$container.' ")]//*[contains(@class, "herrera-footer-socials")]/a');
            $this->assertSame(['https://www.linkedin.com/company/footer-company', 'https://x.com/footer-company'],
                array_map(fn ($node): string => $node->getAttribute('href'), iterator_to_array($links)));
        }
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
