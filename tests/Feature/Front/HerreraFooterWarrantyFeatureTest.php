<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Front\StoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use App\Support\FooterContactPresenter;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HerreraFooterWarrantyFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'store_newsletter_enabled' => true,
            'store_newsletter_provider' => 'database',
            'store_newsletter_coupon_enabled' => false,
            'store_newsletter_title' => 'Prijavite se na newsletter',
            'store_newsletter_subtitle' => 'Primajte novosti o asortimanu i B2B ponudi.',
            'store_newsletter_consent_label' => 'Pristajem na primanje newslettera i pohranu email adrese.',
            'store_legal_warranty_enabled' => true,
            'store_footer_email_sales' => 'shop@herrera.hr',
            'store_footer_email_support' => 'shop@herrera.hr',
            'store_footer_address' => "Herrera d.o.o.\nSjedište 1\nOIB: 12345678901\n\nUredi:\nLokacija 2\n\nLogistički centar:\nLokacija 2",
        ]);
    }

    public function test_contact_presentation_merges_identical_emails_and_locations_without_losing_roles(): void
    {
        $this->assertSame(['shop@herrera.hr'], FooterContactPresenter::emails([
            'email_sales' => 'shop@herrera.hr', 'email_support' => ' SHOP@HERRERA.HR ',
        ]));
        $this->assertSame(['sale@example.test', 'support@example.test'], FooterContactPresenter::emails([
            'email_sales' => 'sale@example.test', 'email_support' => 'support@example.test',
        ]));
        $this->assertSame(["Herrera d.o.o.\nSjedište 1\nOIB: 12345678901", "Uredi / Logistički centar:\nLokacija 2"], FooterContactPresenter::addressBlocks(app(StoreSettingsService::class)->footer()['address']));
        $this->assertSame(["Uredi:\nA", "Skladište:\nB"], FooterContactPresenter::addressBlocks("Uredi:\r\nA\r\n\r\nSkladište:\r\nB"));

        $xpath = $this->xpath($this->get(route('home'))->assertOk()->getContent());
        foreach (['site-footer-contact-details', 'site-footer-mobile-links'] as $class) {
            $this->assertSame(1, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]//a[@href="mailto:shop@herrera.hr"]')->count());
        }
    }

    public function test_newsletter_has_required_unchecked_consent_and_no_retail_coupon_offer(): void
    {
        $response = $this->get(route('home'))->assertOk()
            ->assertSee('Prijavite se na newsletter')->assertSee('Pristajem na primanje newslettera')
            ->assertDontSee('BALI10')->assertDontSee('10%');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//form[@data-newsletter-form]//input[@name="newsletter_email" and @required]')->count());
        $this->assertSame(1, $xpath->query('//form[@data-newsletter-form]//input[@name="newsletter_accept_terms" and @required and not(@checked)]')->count());
    }

    public function test_newsletter_requires_consent_then_saves_once_without_a_b2b_coupon_email(): void
    {
        Mail::fake();
        app(SystemSettingsService::class)->put('store_email_enabled', true);
        $this->postJson(route('newsletter.subscribe'), ['newsletter_email' => 'buyer@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('newsletter_accept_terms');
        $this->assertDatabaseCount('newsletter_signups', 0);
        foreach (['buyer@example.test', 'BUYER@example.test'] as $email) {
            $this->postJson(route('newsletter.subscribe'), ['newsletter_email' => $email, 'newsletter_accept_terms' => '1'])
                ->assertOk()->assertJsonPath('type', 'success');
        }
        $this->assertDatabaseCount('newsletter_signups', 1);
        $this->assertDatabaseHas('newsletter_signups', ['email' => 'buyer@example.test', 'consent_accepted' => true, 'provider' => 'database']);
        Mail::assertNothingSent();
    }

    public function test_footer_opens_one_official_notice_with_a_real_non_javascript_fallback(): void
    {
        $response = $this->get(route('home'))->assertOk()
            ->assertSee('data-legal-warranty-notice="footer"', false)
            ->assertSee('Zakonsko jamstvo – najmanje 2 godine')
            ->assertSee('front-theme/scripts/legal-warranty.js', false)
            ->assertSee('https://europa.eu/youreurope/citizens/consumers/shopping/guarantees/index_hr.htm', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//dialog[@id="legal-warranty-modal"]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-legal-warranty-notice="footer"]/a[@href="'.asset('assets/legal/legal-guarantee-notice-hr-color.svg').'"]')->count());
        $this->assertSame('a22432ff287ae772fd7f1bc7198dce26cbcfba3b1c8c6fa207189c1163e2612e', hash_file('sha256', public_path('assets/legal/legal-guarantee-notice-hr-color.svg')));
    }

    public function test_product_and_approved_checkout_show_the_same_notice_without_exposing_guest_prices(): void
    {
        $product = Product::query()->create(['code' => 'WARRANTY-TEST', 'base_price' => 1234.56, 'stock_qty' => 10, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Warranty product', 'slug' => 'warranty-product']);
        $this->get(route('products.show', ['slug' => 'warranty-product']))->assertOk()
            ->assertSee('data-legal-warranty-notice="product"', false)->assertDontSee('1.234,56', false);
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'warranty', 'name' => 'Warranty', 'is_active' => true]);
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => 'Warranty business', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);
        $this->actingAs($user);
        app(CartService::class)->add($product, 1);
        $response = $this->get(route('checkout.create'))->assertOk()->assertSee('data-legal-warranty-notice="checkout"', false);
        $this->assertSame(1, $this->xpath($response->getContent())->query('//dialog[@id="legal-warranty-modal"]')->count());
    }

    public function test_notice_can_be_disabled_and_eu_url_rejects_unsafe_schemes(): void
    {
        app(SystemSettingsService::class)->putMany(['store_legal_warranty_enabled' => false, 'store_legal_warranty_eu_url' => 'javascript:alert(1)']);
        $this->get(route('home'))->assertOk()->assertDontSee('data-legal-warranty-notice', false)->assertDontSee('data-legal-warranty-modal', false);
        $this->assertStringStartsWith('https://europa.eu/', app(StoreSettingsService::class)->legalWarranty()['eu_url']);
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
