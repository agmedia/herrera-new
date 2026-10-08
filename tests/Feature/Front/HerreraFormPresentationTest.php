<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Page\InfoPage;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraFormPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Herrera']);
        foreach (['terms-of-use', 'privacy-policy', 'shipping-payment'] as $code) {
            $page = InfoPage::query()->create(['code' => $code, 'is_active' => true, 'published_at' => now()->subDay()]);
            $page->translations()->create(['locale' => 'hr', 'title' => $code, 'slug' => $code]);
        }
    }

    public function test_auth_required_fields_and_accepted_terms_are_marked_in_the_rendered_labels(): void
    {
        foreach (['/auth/login', '/auth/register', '/auth/b2b-register', '/auth/forgot-password', '/auth/reset-password/preview-token'] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('front-theme/styles/herrera-forms.css', false);
            $xpath = $this->xpath($response->getContent());
            $this->assertNativeRequiredFieldsAreMarked($xpath);
            $this->assertFieldMarked($xpath, 'remember', false, false);
            foreach ($xpath->query('//main//a[contains(@href, "/page/terms-of-use")]') as $link) {
                $this->assertStringContainsString('store-text-link', $link->getAttribute('class'));
            }
        }
    }

    public function test_contact_and_withdrawal_keep_optional_fields_unmarked(): void
    {
        $contact = $this->xpath($this->get('/contact')->assertOk()->getContent());
        $this->assertNativeRequiredFieldsAreMarked($contact);
        foreach (['phone', 'subject'] as $field) {
            $this->assertFieldMarked($contact, $field, false);
        }
        $this->assertFieldMarked($contact, 'accept_terms', true);

        $returns = $this->xpath($this->get('/forma-za-povrat-i-reklamacije')->assertOk()->getContent());
        $this->assertNativeRequiredFieldsAreMarked($returns);
        foreach (['phone', 'contract_date', 'received_date', 'note'] as $field) {
            $this->assertFieldMarked($returns, $field, false);
        }
    }

    public function test_footer_newsletter_marks_email_and_required_consent_with_a_visible_label(): void
    {
        $xpath = $this->xpath($this->get('/contact')->assertOk()->getContent());
        $email = $xpath->query('//*[@data-newsletter-form]//input[@name="newsletter_email"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $email);
        $this->assertTrue($email->hasAttribute('required'));
        $emailLabel = $xpath->query('//label[@for="'.$email->getAttribute('id').'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $emailLabel);
        $this->assertStringNotContainsString('sr-only', $emailLabel->getAttribute('class'));
        $this->assertSame(1, $xpath->query('.//span[contains(@class, "store-form-required")]', $emailLabel)->count());

        $consent = $xpath->query('//*[@data-newsletter-form]//input[@name="newsletter_accept_terms"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $consent);
        $this->assertTrue($consent->hasAttribute('required'));
        $this->assertSame(1, $xpath->query('ancestor::label[1]//span[contains(@class, "store-form-required")]', $consent)->count());
    }

    public function test_profile_marks_required_identity_and_address_fields_but_not_preferences(): void
    {
        $user = User::factory()->create();
        $xpath = $this->xpath($this->actingAs($user)->get(route('account.profile'))->assertOk()->getContent());
        $this->assertNativeRequiredFieldsAreMarked($xpath);
        foreach (['first_name', 'phone', 'company', 'newsletter_opt_in', 'gdpr_marketing_opt_in', 'gdpr_personalization_opt_in'] as $field) {
            $this->assertFieldMarked($xpath, $field, false);
        }
    }

    public function test_checkout_marks_conditional_shipping_and_registration_but_not_newsletter_or_optional_invoice_fields(): void
    {
        $product = $this->product();
        ShippingMethod::query()->create(['code' => 'standard-ui', 'name' => 'Standard', 'price' => 4.99, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank-ui', 'name' => 'Bank', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
        $this->post('/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertRedirect();
        $xpath = $this->xpath($this->get('/checkout')->assertOk()->getContent());
        $this->assertNativeRequiredFieldsAreMarked($xpath);
        foreach (['shipping_first_name', 'shipping_last_name', 'shipping_address_line_1', 'shipping_postal_code', 'shipping_city', 'shipping_country_code', 'register_password', 'register_password_confirmation'] as $field) {
            $this->assertFieldMarked($xpath, $field, true);
        }
        foreach (['newsletter_opt_in', 'billing_company', 'billing_oib', 'customer_note', 'shipping_vat_id'] as $field) {
            $this->assertFieldMarked($xpath, $field, false);
        }
        $legalLinks = $xpath->query('//*[@data-checkout-legal-links]/a');
        $this->assertGreaterThan(0, $legalLinks->count());
        foreach ($legalLinks as $link) {
            $this->assertStringContainsString('store-text-link', $link->getAttribute('class'));
        }
    }

    public function test_review_author_fields_are_required_for_guests_and_optional_for_signed_in_users(): void
    {
        $this->product();
        $guest = $this->xpath($this->get('/product/form-presentation-product')->assertOk()->getContent());
        foreach (['author_name', 'author_email', 'body'] as $field) {
            $this->assertFieldMarked($guest, $field, true);
        }
        $this->assertFieldMarked($guest, 'rating', false);

        $signedIn = $this->xpath($this->actingAs(User::factory()->create())->get('/product/form-presentation-product')->assertOk()->getContent());
        $this->assertFieldMarked($signedIn, 'author_name', false);
        $this->assertFieldMarked($signedIn, 'author_email', false);
        $this->assertFieldMarked($signedIn, 'body', true);
    }

    private function product(): Product
    {
        $product = Product::query()->create(['code' => 'FORM-PRESENTATION', 'is_active' => true, 'base_price' => 49.99, 'stock_qty' => 15]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Form presentation product', 'slug' => 'form-presentation-product']);

        return $product;
    }

    private function assertNativeRequiredFieldsAreMarked(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//main//*[self::input or self::select or self::textarea][@required and not(@type="hidden")]') as $field) {
            $this->assertFieldMarked($xpath, $field->getAttribute('name'), true);
        }
    }

    private function assertFieldMarked(DOMXPath $xpath, string $name, bool $marked, bool $mustExist = true): void
    {
        $field = $xpath->query('//main//*[self::input or self::select or self::textarea][@name="'.$name.'"]')->item(0);
        if (! $field && ! $mustExist) {
            return;
        }
        $this->assertInstanceOf(DOMElement::class, $field, $name);
        $label = $field->getAttribute('id') !== ''
            ? $xpath->query('//label[@for="'.$field->getAttribute('id').'"]')->item(0)
            : $xpath->query('ancestor::label[1]', $field)->item(0);
        if ($field->getAttribute('type') === 'radio') {
            $label = $xpath->query('ancestor::fieldset[1]/legend', $field)->item(0);
        }
        $this->assertInstanceOf(DOMElement::class, $label, $name.' label');
        $count = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " store-form-required ") and @aria-hidden="true"]', $label)->count();
        $this->assertSame($marked ? 1 : 0, $count, $name);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }
}
