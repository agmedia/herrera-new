<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Page\InfoPage;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Front\CartService;
use App\Services\Front\StoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HerreraLegalPageLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false, 'app.locale' => 'hr']);
        app()->setLocale('hr');
    }

    public function test_legacy_terms_url_and_native_page_expose_the_imported_information(): void
    {
        $page = $this->page('herrera-oc-page-5', 'opci-uvjeti-koristenja', 'Opći uvjeti korištenja');
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => 'information', 'source_id' => '5', 'target_id' => $page->id, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('herrera_legacy_urls')->insert(['path' => '/opci-uvjeti-koristenja', 'path_hash' => hash('sha256', '/opci-uvjeti-koristenja'), 'locale' => 'hr', 'source_query' => 'information_id=5', 'status' => 'redirect', 'destination' => '/page/opci-uvjeti-koristenja', 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/opci-uvjeti-koristenja')->assertStatus(301)->assertRedirect('/page/opci-uvjeti-koristenja');
        $this->get('/page/opci-uvjeti-koristenja')->assertOk()->assertSee('Opći uvjeti korištenja')->assertSee('Sadržaj testne stranice');
        $this->get('/index.php?route=information/information&information_id=5')->assertStatus(301)->assertRedirect('/page/opci-uvjeti-koristenja');
    }

    public function test_checkout_terms_privacy_and_withdrawal_links_resolve_to_existing_local_pages(): void
    {
        $this->legalPages();
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->updateOrCreate(['code' => 'pickup'], ['name' => 'Preuzimanje', 'price' => 0, 'is_active' => true]);
        PaymentMethod::query()->updateOrCreate(['code' => 'bank'], ['name' => 'Uplata na račun', 'is_active' => true]);
        $product = Product::query()->create(['code' => 'LEGAL-TEST', 'sku' => 'LEGAL-TEST', 'base_price' => 10, 'stock_qty' => 5, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Testni proizvod', 'slug' => 'testni-proizvod']);
        app(CartService::class)->add($product, 1);

        $response = $this->get(route('checkout.create'))->assertOk();
        $this->assertSame([route('pages.show', ['slug' => 'opci-uvjeti-koristenja'])], $this->hrefs($response->getContent(), "//label[@for='accept-terms']//a/@href"));
        $legalUrls = $this->hrefs($response->getContent(), '//*[@data-checkout-legal-links]//a/@href');
        $this->assertSame([
            route('pages.show', ['slug' => 'nacin-placanja-i-dostava']),
            route('pages.show', ['slug' => 'pravila-privatnosti']),
            route('returns.create', ['returnRequestSlug' => 'forma-za-povrat-i-reklamacije']),
        ], $legalUrls);
        foreach ($legalUrls as $url) {
            $this->get($url)->assertOk();
        }
    }

    #[DataProvider('registrationRoutes')]
    public function test_registration_terms_link_uses_the_imported_herrera_page(string $route): void
    {
        $this->legalPages();
        $response = $this->get(route($route))->assertOk();
        $this->assertSame([route('pages.show', ['slug' => 'opci-uvjeti-koristenja'])], $this->hrefs($response->getContent(), "//label[.//input[@name='terms_accepted']]//a/@href"));
    }

    public static function registrationRoutes(): array
    {
        return ['standard registration' => ['front.auth.register'], 'business registration' => ['front.auth.b2b-register']];
    }

    public function test_legal_links_follow_localized_or_edited_slugs_of_the_published_source_page(): void
    {
        [$terms] = $this->legalPages();
        $terms->translations()->create(['locale' => 'en', 'title' => 'Terms of use', 'slug' => 'conditions-of-use', 'body_html' => '<p>Test terms</p>']);
        $this->page('terms-of-use', 'uvjeti-koristenja', 'Drugi uvjeti');
        app()->setLocale('en');
        $links = app(StoreSettingsService::class)->legalPages();
        $this->assertSame('Terms of use', $links['terms']['title']);
        $this->assertSame(route('pages.show', ['slug' => 'conditions-of-use']), $links['terms']['url']);

        app()->setLocale('hr');
        $terms->translations()->where('locale', 'hr')->update(['slug' => 'azurirani-opci-uvjeti']);
        $this->assertSame(route('pages.show', ['slug' => 'azurirani-opci-uvjeti']), app(StoreSettingsService::class)->legalPages()['terms']['url']);

        $terms->update(['published_at' => now()->addDay()]);
        $this->assertSame(route('pages.show', ['slug' => 'uvjeti-koristenja']), app(StoreSettingsService::class)->legalPages()['terms']['url']);
        $terms->update(['published_at' => null, 'is_active' => false]);
        $this->assertSame(route('pages.show', ['slug' => 'uvjeti-koristenja']), app(StoreSettingsService::class)->legalPages()['terms']['url']);
    }

    public function test_cookie_policy_repairs_only_missing_known_legacy_aliases_and_preserves_custom_links(): void
    {
        $this->legalPages();
        $settings = app(SystemSettingsService::class);
        $settings->put('store_cookie_consent_policy_url_translations', []);
        $settings->put('store_cookie_consent_policy_url', '/page/pravila-zastite-podataka-i-privatnosti');
        $this->assertSame(route('pages.show', ['slug' => 'pravila-privatnosti']), app(StoreSettingsService::class)->cookies()['policy_url']);

        $settings->put('store_cookie_consent_policy_url', 'https://example.test/custom-cookie-policy');
        $this->assertSame('https://example.test/custom-cookie-policy', app(StoreSettingsService::class)->cookies()['policy_url']);

        $this->page('privacy-policy', 'privatnost-podataka', 'Druga pravila privatnosti');
        $settings->put('store_cookie_consent_policy_url', '/page/privatnost-podataka');
        $this->assertSame('/page/privatnost-podataka', app(StoreSettingsService::class)->cookies()['policy_url']);
    }

    private function legalPages(): array
    {
        return [
            $this->page('herrera-oc-page-5', 'opci-uvjeti-koristenja', 'Opći uvjeti korištenja'),
            $this->page('herrera-oc-page-3', 'pravila-privatnosti', 'Pravila privatnosti'),
            $this->page('herrera-oc-page-7', 'nacin-placanja-i-dostava', 'Načini plaćanja i dostava'),
        ];
    }

    private function page(string $code, string $slug, string $title): InfoPage
    {
        $page = InfoPage::query()->create(['code' => $code, 'is_active' => true]);
        $page->translations()->create(['locale' => 'hr', 'title' => $title, 'slug' => $slug, 'body_html' => '<p>Sadržaj testne stranice</p>']);

        return $page;
    }

    private function hrefs(string $html, string $xpath): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return array_map(fn ($attribute) => $attribute->value, iterator_to_array((new DOMXPath($document))->query($xpath)));
    }
}
