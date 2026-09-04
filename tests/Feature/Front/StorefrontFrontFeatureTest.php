<?php

namespace Tests\Feature\Front;

use App\Http\Controllers\Front\CatalogController;
use App\Mail\ContractWithdrawalAdminMail;
use App\Mail\ContractWithdrawalReceiptMail;
use App\Mail\NewsletterCouponMail;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Category\CategoryTranslation;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Manufacturer\ManufacturerTranslation;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Catalog\Product\ProductTranslation;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Blog\BlogPostTranslation;
use App\Models\Content\ContentBlock;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Page\InfoPageTranslation;
use App\Models\Content\Support\Comment;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Models\User\UserAddress;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class StorefrontFrontFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_pretty_storefront_routes_are_available(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category, $categorySlug] = $this->seedCategory();
        [$product, $productSlug] = $this->seedProduct($category->id);
        [$manufacturer, $manufacturerSlug] = $this->seedManufacturer();
        [$post, $postSlug] = $this->seedBlogPost();
        [$page, $pageSlug] = $this->seedInfoPage();

        app(SystemSettingsService::class)->putMany([
            'catalog_use_manufacturers' => true,
            'catalog_use_blog' => true,
        ]);

        $this->get('/shop')->assertOk();
        $this->get('/categories')->assertOk();
        $this->get('/category/'.$categorySlug)->assertOk();
        $this->get('/product/'.$productSlug)->assertOk();
        $this->get('/brendovi')->assertOk();
        $this->get('/manufacturer/'.$manufacturerSlug)->assertOk();
        $this->get('/blog')->assertOk();
        $this->get('/blog/'.$postSlug)->assertOk();
        $this->get('/page/'.$pageSlug)->assertOk();
        $this->get('/contact')->assertOk();
        $this->get('/cart')->assertOk();

        $this->assertNotNull($product);
        $this->assertNotNull($manufacturer);
        $this->assertNotNull($post);
        $this->assertNotNull($page);
    }

    public function test_blog_post_renders_connected_products_in_the_shared_lined_style(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category] = $this->seedCategory();
        [$product, $productSlug] = $this->seedProduct($category->id);
        [$post, $postSlug] = $this->seedBlogPost();

        $post->update([
            'payload' => [
                'related_product_ids' => [$product->id],
            ],
        ]);

        app(SystemSettingsService::class)->put('catalog_use_blog', true);

        $this->get('/blog/'.$postSlug)
            ->assertOk()
            ->assertSee('data-blog-related-products', false)
            ->assertSee('data-blog-related-products-splide', false)
            ->assertSee('data-product-card-lined', false)
            ->assertSee('visibility: visible', false)
            ->assertDontSee('>Editorial<', false)
            ->assertDontSee('section-heading-line', false)
            ->assertSee('mb-[0.9rem] block h-1 w-11', false)
            ->assertSee('text-3xl font-extrabold', false)
            ->assertSee($productSlug);

        $this->assertSame('Povezani proizvodi', trans('ui.product.related', [], 'hr'));
    }

    public function test_blog_list_uses_landscape_cards_and_detail_keeps_the_original_cover_ratio(): void
    {
        $this->useEnglishStorefrontLocale();

        [$post, $postSlug] = $this->seedBlogPost();
        $cover = $post
            ->addMedia(UploadedFile::fake()->image('original-blog-cover.jpg', 1200, 800))
            ->toMediaCollection('blog_cover');

        app(SystemSettingsService::class)->put('catalog_use_blog', true);

        $this->get('/blog')
            ->assertOk()
            ->assertSee('aspect-[3/2]', false)
            ->assertDontSee('aspect-[3/4]', false)
            ->assertSee($cover->getUrl(), false);

        $this->get('/blog/'.$postSlug)
            ->assertOk()
            ->assertSee('src="'.$cover->getUrl().'"', false)
            ->assertSee('class="mx-auto block h-auto max-w-full"', false)
            ->assertDontSee('cover_1600x2133', false);
    }

    public function test_manufacturer_page_uses_the_shared_catalog_header_filters_and_product_cards(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category] = $this->seedCategory();
        [$manufacturer, $manufacturerSlug] = $this->seedManufacturer();
        [$cheapProduct, $cheapSlug] = $this->seedProduct($category->id);
        [$matchingProduct, $matchingSlug] = $this->seedProduct($category->id);
        [$otherProduct, $otherSlug] = $this->seedProduct($category->id);

        $cheapProduct->update([
            'manufacturer_id' => $manufacturer->id,
            'base_price' => 19.99,
        ]);
        $matchingProduct->update([
            'manufacturer_id' => $manufacturer->id,
            'base_price' => 89.99,
        ]);
        $otherProduct->update(['base_price' => 99.99]);

        app(SystemSettingsService::class)->put('catalog_use_manufacturers', true);

        $this->get('/manufacturer/'.$manufacturerSlug.'?price_min=50')
            ->assertOk()
            ->assertSee('class="front-soft-hero', false)
            ->assertSee('href="'.route('manufacturers.index').'"', false)
            ->assertSee('class="catalog-desktop-sidebar', false)
            ->assertSee('data-desktop-filter-form', false)
            ->assertSee('front-theme/styles/category-catalog.css', false)
            ->assertSee('data-catalog-grid', false)
            ->assertSee('data-product-card-lined', false)
            ->assertSee($matchingSlug)
            ->assertDontSee($cheapSlug)
            ->assertDontSee($otherSlug)
            ->assertDontSee('Back to manufacturers');
    }

    public function test_cart_coupon_applies_cart_discount_to_items_only(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $product->update([
            'base_price' => 100.00,
            'stock_qty' => 10,
        ]);

        CatalogAction::query()->create([
            'code' => 'cart-bali-10',
            'scope' => CatalogAction::SCOPE_CART,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_ALL,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'coupon_code' => 'BALI10',
            'min_subtotal' => 0.01,
            'is_active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->post('/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertRedirect();

        $this->post('/cart/coupon', [
            'coupon_code' => 'bali10',
        ])
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('status', __('ui.cart.status.coupon_applied'));

        $summary = app(\App\Services\Front\CartService::class)->summary();

        $this->assertSame(200.0, (float) $summary['subtotal']);
        $this->assertSame(20.0, (float) $summary['cart_discount_total']);
        $this->assertSame(20.0, (float) $summary['discount_total']);
        $this->assertSame(180.0, (float) $summary['grand_total']);
    }

    public function test_cart_update_reports_when_requested_quantity_exceeds_stock(): void
    {
        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);
        $product->update(['stock_qty' => 1]);

        $this->post('/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertRedirect();

        $this->patch('/cart/items/'.$product->id, [
            'quantity' => 3,
        ])
            ->assertRedirect()
            ->assertSessionHas('warning', __('ui.cart.status.adjusted_with_stock', ['stock' => 1]))
            ->assertSessionMissing('status');

        $line = app(\App\Services\Front\CartService::class)->raw();

        $this->assertSame(1, (int) collect($line)->first()['quantity']);
    }

    public function test_desktop_announcement_bar_uses_saved_scroll_and_color_settings(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_announcement_enabled' => true,
            'store_announcement_text' => 'Spring promo',
            'store_announcement_scroll_enabled' => true,
            'store_announcement_scroll_duration_seconds' => 24,
            'store_announcement_background_color' => '#123456',
            'store_announcement_text_color' => '#abcdef',
        ]);

        $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            ])
            ->get('/')
            ->assertOk()
            ->assertSee('Spring promo')
            ->assertSee('store-announcement-bar is-scrolling', false)
            ->assertSee(route('front.storefront.styles'), false)
            ->assertSee('data-storefront-settings', false)
            ->assertDontSee('data-storefront-critical-styles', false)
            ->assertDontSee('<style', false)
            ->assertDontSee('style="background-color: #123456', false);

        $this->get('/storefront-settings.css')
            ->assertOk()
            ->assertSee('--store-announcement-background-color:#123456', false)
            ->assertSee('--store-announcement-text-color:#abcdef', false)
            ->assertSee('--store-announcement-duration:24s', false);
    }

    public function test_desktop_benefits_bar_uses_admin_text_and_renders_bold_markers_safely(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_benefits_bar_enabled' => true,
            'store_benefits_bar_item_1' => 'Više od **50 000 proizvoda** u ponudi',
            'store_benefits_bar_item_2' => 'Plaćanje karticama do **12 rata bez naknada**',
            'store_benefits_bar_item_3' => '**Dostava** u roku **3-5 radnih dana**',
            'store_footer_contact_title' => 'Kontakt i podrška',
            'store_footer_contact_intro' => 'Webshop upiti i informacije',
            'store_footer_phone' => '+385 91 600 1958',
            'store_schema_address_street' => 'Lapovačka 11A',
            'store_schema_address_postal_code' => '32100',
            'store_schema_address_city' => 'Vinkovci',
        ]);

        $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            ])
            ->get('/')
            ->assertOk()
            ->assertSee('store-benefits-bar', false)
            ->assertSee('site-footer-shell storefront-header-container', false)
            ->assertSee('site-footer-benefits', false)
            ->assertSee('<strong>50 000 proizvoda</strong>', false)
            ->assertSee('<strong>12 rata bez naknada</strong>', false)
            ->assertSee('<strong>Dostava</strong>', false)
            ->assertSee('<strong>3-5 radnih dana</strong>', false)
            ->assertSee('Kontakt i podrška')
            ->assertSee('Webshop upiti i informacije')
            ->assertSee('+385 91 600 1958')
            ->assertSee('Lapovačka 11A, 32100 Vinkovci')
            ->assertSee('assets/payments/ag-footer-logo.svg', false)
            ->assertDontSee('Web by: AG media.');
    }

    public function test_contact_form_stores_message(): void
    {
        $this->post('/contact', [
            'name' => 'Front Tester',
            'email' => 'front@example.test',
            'phone' => '+38591000000',
            'subject' => 'Wholesale inquiry',
            'message' => 'Please contact me with available B2B pricing details.',
            'accept_terms' => 1,
        ])->assertRedirect('/contact');

        $this->assertDatabaseHas('contact_messages', [
            'email' => 'front@example.test',
            'subject' => 'Wholesale inquiry',
            'status' => 'new',
        ]);
    }

    public function test_return_request_form_is_available_at_seo_url(): void
    {
        $this->get('/forma-za-povrat-i-reklamacije')
            ->assertOk()
            ->assertSee((string) __('return_request.form.full_name'))
            ->assertSee((string) __('return_request.form.email'))
            ->assertSee((string) __('return_request.form.order_number'))
            ->assertSee((string) __('return_request.form.items'))
            ->assertSee((string) __('return_request.form.received_date'))
            ->assertSee((string) __('return_request.form.note'));
    }

    public function test_contract_withdrawal_requires_review_then_stores_evidence_and_emails_both_parties(): void
    {
        Mail::fake();

        app(SystemSettingsService::class)->putMany([
            'store_withdrawal_admin_email' => 'withdrawals@example.test',
            'store_withdrawal_return_address' => 'Example Store, Main Street 1, Zagreb',
        ]);

        $review = $this->post('/forma-za-povrat-i-reklamacije', [
            'full_name' => 'Ana Horvat',
            'email' => 'buyer@example.test',
            'phone' => '+385 91 000 0000',
            'address_line' => 'Ilica 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'HR',
            'order_number' => 'R-1001',
            'contract_date' => now()->subDays(8)->toDateString(),
            'received_date' => now()->subDays(5)->toDateString(),
            'items' => 'T-shirt size M',
            'note' => '',
        ])
            ->assertOk()
            ->assertSee(__('return_request.review.confirm'))
            ->assertSee('R-1001');

        $this->assertDatabaseCount('contract_withdrawals', 0);
        preg_match('/name="draft_token" value="([^"]+)"/', $review->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $this->post('/forma-za-povrat-i-reklamacije/confirm', [
            'draft_token' => $matches[1],
        ])
            ->assertRedirect('/forma-za-povrat-i-reklamacije')
            ->assertSessionHas('withdrawal_reference');

        $this->assertDatabaseHas('contract_withdrawals', [
            'email' => 'buyer@example.test',
            'order_number' => 'R-1001',
            'full_name' => 'Ana Horvat',
            'items' => 'T-shirt size M',
            'status' => 'received',
        ]);

        Mail::assertSent(ContractWithdrawalReceiptMail::class, static fn ($mail): bool => $mail->hasTo('buyer@example.test')
            && $mail->withdrawal->order_number === 'R-1001'
            && $mail->withdrawal->consumer_notified_at !== null
        );
        Mail::assertSent(ContractWithdrawalAdminMail::class, static fn ($mail): bool => $mail->hasTo('withdrawals@example.test')
            && $mail->withdrawal->order_number === 'R-1001'
        );
    }

    public function test_return_request_form_uses_enabled_recaptcha_settings(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_captcha_recaptcha_v3_enabled' => true,
            'store_captcha_recaptcha_v3_site_key' => 'test-site-key',
            'store_captcha_recaptcha_v3_secret_key' => 'test-secret-key',
            'store_captcha_recaptcha_v3_min_score' => 0.7,
        ]);

        $this->get('/forma-za-povrat-i-reklamacije')
            ->assertOk()
            ->assertSee('data-recaptcha-site-key="test-site-key"', false)
            ->assertSee('data-recaptcha-action="contract_withdrawal_form"', false)
            ->assertSee('https://www.google.com/recaptcha/api.js?render=test-site-key', false);

        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.9,
                'action' => 'contract_withdrawal_form',
            ]),
        ]);

        $review = $this->post('/forma-za-povrat-i-reklamacije', [
            'full_name' => 'Captcha Buyer',
            'email' => 'captcha-buyer@example.test',
            'address_line' => 'Test Street 2',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'HR',
            'order_number' => 'R-2002',
            'items' => 'Socks size L',
            'note' => '',
            'recaptcha_token' => 'token-123',
        ])->assertOk();

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
            && $request['secret'] === 'test-secret-key'
            && $request['response'] === 'token-123');

        $this->assertDatabaseCount('contract_withdrawals', 0);
        $review->assertSee(__('return_request.review.confirm'));
    }

    public function test_front_auth_forms_use_enabled_recaptcha_settings(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_captcha_recaptcha_v3_enabled' => true,
            'store_captcha_recaptcha_v3_site_key' => 'test-site-key',
            'store_captcha_recaptcha_v3_secret_key' => 'test-secret-key',
            'store_captcha_recaptcha_v3_min_score' => 0.7,
        ]);

        $this->get('/auth/login')
            ->assertOk()
            ->assertSee('data-recaptcha-site-key="test-site-key"', false)
            ->assertSee('data-recaptcha-action="login_form"', false)
            ->assertSee('https://www.google.com/recaptcha/api.js?render=test-site-key', false);

        $this->get('/auth/register')
            ->assertOk()
            ->assertSee('data-recaptcha-site-key="test-site-key"', false)
            ->assertSee('data-recaptcha-action="register_form"', false)
            ->assertSee('https://www.google.com/recaptcha/api.js?render=test-site-key', false);

        $mobileHeaders = [
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        ];

        $this
            ->withHeaders($mobileHeaders)
            ->get('/auth/login')
            ->assertOk()
            ->assertSee('data-recaptcha-action="login_form"', false);

        $this
            ->withHeaders($mobileHeaders)
            ->get('/auth/register')
            ->assertOk()
            ->assertSee('data-recaptcha-action="register_form"', false);
    }

    public function test_front_login_validates_enabled_recaptcha(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_captcha_recaptcha_v3_enabled' => true,
            'store_captcha_recaptcha_v3_site_key' => 'test-site-key',
            'store_captcha_recaptcha_v3_secret_key' => 'test-secret-key',
            'store_captcha_recaptcha_v3_min_score' => 0.7,
        ]);

        $user = User::factory()->create();

        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.9,
                'action' => 'login_form',
            ]),
        ]);

        $this->post('/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'recaptcha_token' => 'login-token',
        ])->assertRedirect(route('account.dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
            && $request['secret'] === 'test-secret-key'
            && $request['response'] === 'login-token');
    }

    public function test_front_register_validates_enabled_recaptcha(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_captcha_recaptcha_v3_enabled' => true,
            'store_captcha_recaptcha_v3_site_key' => 'test-site-key',
            'store_captcha_recaptcha_v3_secret_key' => 'test-secret-key',
            'store_captcha_recaptcha_v3_min_score' => 0.7,
        ]);

        Bouncer::role()->firstOrCreate(['name' => 'customer'], ['title' => 'Customer']);

        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.9,
                'action' => 'register_form',
            ]),
        ]);

        $this->post('/auth/register', [
            'first_name' => 'Captcha',
            'last_name' => 'Buyer',
            'email' => 'captcha-buyer@example.test',
            'phone' => '+385 91 555 1234',
            'address_line_1' => 'Ilica 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'HR',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'recaptcha_token' => 'register-token',
        ])->assertRedirect(route('account.dashboard', absolute: false));

        $this->assertAuthenticated();

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
            && $request['secret'] === 'test-secret-key'
            && $request['response'] === 'register-token');

        $this->assertDatabaseHas('users', [
            'name' => 'Captcha Buyer',
            'email' => 'captcha-buyer@example.test',
        ]);
    }

    public function test_front_registration_collects_and_saves_the_complete_default_address(): void
    {
        Bouncer::role()->firstOrCreate(['name' => 'customer'], ['title' => 'Customer']);

        $inactiveDefault = CustomerGroup::query()->create([
            'code' => 'inactive-default',
            'name' => 'Inactive Default',
            'is_active' => false,
            'is_default' => true,
            'sort_order' => 1,
        ]);
        $firstActiveDefault = CustomerGroup::query()->create([
            'code' => 'first-active-default',
            'name' => 'First Active Default',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 10,
        ]);
        $laterActiveDefault = CustomerGroup::query()->create([
            'code' => 'later-active-default',
            'name' => 'Later Active Default',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 20,
        ]);

        $this->get('/auth/register')
            ->assertOk()
            ->assertSee('data-address-autofill', false)
            ->assertSee('data-address-scope="billing"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('name="address_line_1"', false)
            ->assertSee('name="postal_code"', false)
            ->assertSee('data-address-postal', false)
            ->assertSee('name="city"', false)
            ->assertSee('data-address-city', false)
            ->assertSee('name="country_code"', false)
            ->assertSee('data-address-country', false)
            ->assertSee('name="terms_accepted"', false)
            ->assertSee('/page/uvjeti-koristenja', false)
            ->assertSee('front-theme/scripts/address-autofill.js', false)
            ->assertDontSee('name="address_line_2"', false);

        $this->from('/auth/register')
            ->post('/auth/register', [
                'first_name' => 'Ivana',
                'last_name' => 'Marić',
                'email' => 'ivana.maric@example.test',
                'phone' => '+385 91 555 1234',
                'address_line_1' => 'Vukovarska 10',
                'postal_code' => '21000',
                'city' => 'Split',
                'country_code' => 'HR',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertRedirect('/auth/register')
            ->assertSessionHasErrors('terms_accepted');

        $response = $this->post('/auth/register', [
            'first_name' => 'Ivana',
            'last_name' => 'Marić',
            'email' => 'ivana.maric@example.test',
            'phone' => '+385 91 555 1234',
            'address_line_1' => 'Vukovarska 10',
            'postal_code' => '21000',
            'city' => 'Split',
            'country_code' => 'HR',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => '1',
        ]);

        $user = User::query()->where('email', 'ivana.maric@example.test')->firstOrFail();

        $response->assertRedirect(route('account.dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'first_name' => 'Ivana',
            'last_name' => 'Marić',
            'phone' => '+385 91 555 1234',
        ]);
        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $user->id,
            'type' => 'billing',
            'first_name' => 'Ivana',
            'last_name' => 'Marić',
            'phone' => '+385 91 555 1234',
            'address_line_1' => 'Vukovarska 10',
            'address_line_2' => null,
            'postal_code' => '21000',
            'city' => 'Split',
            'country_code' => 'HR',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('customer_group_user', [
            'user_id' => $user->id,
            'customer_group_id' => $firstActiveDefault->id,
        ]);
        $this->assertDatabaseMissing('customer_group_user', [
            'user_id' => $user->id,
            'customer_group_id' => $inactiveDefault->id,
        ]);
        $this->assertDatabaseMissing('customer_group_user', [
            'user_id' => $user->id,
            'customer_group_id' => $laterActiveDefault->id,
        ]);
    }

    public function test_shop_search_matches_product_sku(): void
    {
        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $product->update(['sku' => 'FIND-ME-123']);

        $query = Product::query()->where('is_active', true);

        $controller = app(CatalogController::class);
        $method = new \ReflectionMethod($controller, 'applyProductSearch');
        $method->setAccessible(true);
        $method->invoke($controller, $query, 'en', 'en', 'FIND-ME-123');

        $this->assertSame([$product->id], $query->pluck('id')->all());
    }

    public function test_category_search_matches_product_sku(): void
    {
        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $product->update(['sku' => 'CAT-FIND-456']);

        $query = Product::query()
            ->where('is_active', true)
            ->whereHas('categories', fn ($categoryQuery) => $categoryQuery->whereKey($category->id));

        $controller = app(CatalogController::class);
        $method = new \ReflectionMethod($controller, 'applyProductSearch');
        $method->setAccessible(true);
        $method->invoke($controller, $query, 'en', 'en', 'CAT-FIND-456');

        $this->assertSame([$product->id], $query->pluck('id')->all());
    }

    public function test_category_pagination_uses_the_exact_configured_page_size_with_five_columns(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category, $categorySlug] = $this->seedCategory();

        foreach (range(1, 25) as $index) {
            $this->seedProduct($category->id);
        }

        app(SystemSettingsService::class)->put('front_category_products_per_page_desktop', 24);

        $response = $this->get('/category/'.$categorySlug.'?cols=5');

        $response->assertOk();

        $products = $response->viewData('products');

        $this->assertSame(24, $products->perPage());
        $this->assertCount(24, $products->items());
        $this->assertSame(2, $products->lastPage());
    }

    public function test_category_grid_controls_render_responsive_column_sync_hooks(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category, $categorySlug] = $this->seedCategory();
        $this->seedProduct($category->id);

        $this->get('/category/'.$categorySlug.'?cols=5')
            ->assertOk()
            ->assertSee('data-catalog-grid-cols="3"', false)
            ->assertSee('data-catalog-grid-cols="4"', false)
            ->assertSee('data-catalog-grid-cols="5"', false)
            ->assertSee('catalog-grid-toggle--five', false)
            ->assertDontSee('hidden 2xl:inline-flex', false);
    }

    public function test_search_autocomplete_is_not_available_when_disabled(): void
    {
        app(SystemSettingsService::class)->put('store_search_autocomplete_enabled', false);

        $this->getJson('/search/autocomplete?q=prod')
            ->assertNotFound();
    }

    public function test_search_autocomplete_returns_product_name_sku_price_and_old_price_when_available(): void
    {
        $this->useEnglishStorefrontLocale();
        app(SystemSettingsService::class)->put('store_search_autocomplete_enabled', true);

        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $product->update([
            'sku' => 'AUTO-123',
            'base_price' => 100,
        ]);

        $product->translations()->where('locale', 'en')->update([
            'name' => 'Autocomplete Product',
            'excerpt' => 'Autocomplete excerpt',
            'description' => 'Autocomplete description',
        ]);

        $action = CatalogAction::query()->create([
            'code' => 'auto-'.strtolower((string) str()->random(6)),
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 20,
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $action->targets()->create([
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'target_id' => $product->id,
        ]);

        $this->getJson('/search/autocomplete?q=Auto')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.name', 'Autocomplete Product')
            ->assertJsonPath('items.0.sku', 'AUTO-123')
            ->assertJsonPath('items.0.price', '80.00 €')
            ->assertJsonPath('items.0.old_price', '100.00 €')
            ->assertJsonPath('items.0.has_discount', true);
    }

    public function test_search_autocomplete_returns_configured_result_groups_limits_and_product_details(): void
    {
        $this->useEnglishStorefrontLocale();
        app(SystemSettingsService::class)->putMany([
            'store_search_autocomplete_enabled' => true,
            'store_search_autocomplete_products_enabled' => true,
            'store_search_autocomplete_categories_enabled' => true,
            'store_search_autocomplete_manufacturers_enabled' => true,
            'store_search_autocomplete_blog_enabled' => true,
            'store_search_autocomplete_products_limit' => 1,
            'store_search_autocomplete_categories_limit' => 1,
            'store_search_autocomplete_manufacturers_limit' => 1,
            'store_search_autocomplete_blog_limit' => 1,
            'store_search_autocomplete_show_product_image' => false,
            'store_search_autocomplete_show_product_brand' => true,
            'store_search_autocomplete_show_product_sku' => false,
            'store_search_autocomplete_show_product_price' => false,
            'catalog_use_manufacturers' => true,
            'catalog_use_blog' => true,
        ]);

        [$category, $categorySlug] = $this->seedCategory();
        $category->translations()->where('locale', 'en')->update(['name' => 'Searchable Category One']);
        [$secondCategory] = $this->seedCategory();
        $secondCategory->translations()->where('locale', 'en')->update(['name' => 'Searchable Category Two']);

        [$product] = $this->seedProduct($category->id);
        $product->translations()->where('locale', 'en')->update(['name' => 'Searchable Product One']);
        [$secondProduct] = $this->seedProduct($category->id);
        $secondProduct->translations()->where('locale', 'en')->update(['name' => 'Searchable Product Two']);

        [$manufacturer, $manufacturerSlug] = $this->seedManufacturer();
        $manufacturer->translations()->where('locale', 'en')->update(['name' => 'Searchable Brand']);
        $product->update(['manufacturer_id' => $manufacturer->id]);
        $secondProduct->update(['manufacturer_id' => $manufacturer->id]);

        [$post, $postSlug] = $this->seedBlogPost();
        $post->translations()->where('locale', 'en')->update(['title' => 'Searchable Blog Story']);

        $response = $this->getJson('/search/autocomplete?q=Searchable');

        $response
            ->assertOk()
            ->assertJsonPath('total', 6)
            ->assertJsonCount(1, 'groups.products.items')
            ->assertJsonPath('groups.products.total', 2)
            ->assertJsonPath('groups.products.items.0.brand', 'Searchable Brand')
            ->assertJsonPath('groups.products.items.0.sku', null)
            ->assertJsonPath('groups.products.items.0.image_url', null)
            ->assertJsonPath('groups.products.items.0.price', null)
            ->assertJsonCount(1, 'groups.categories.items')
            ->assertJsonPath('groups.categories.total', 2)
            ->assertJsonPath('groups.categories.items.0.url', route('categories.show', ['slug' => $categorySlug]))
            ->assertJsonCount(1, 'groups.manufacturers.items')
            ->assertJsonPath('groups.manufacturers.items.0.url', route('manufacturers.show', ['slug' => $manufacturerSlug]))
            ->assertJsonCount(1, 'groups.blog.items')
            ->assertJsonPath('groups.blog.items.0.url', route('blog.show', ['slug' => $postSlug]));

        app(SystemSettingsService::class)->putMany([
            'store_search_autocomplete_categories_enabled' => false,
            'store_search_autocomplete_show_product_brand' => false,
        ]);

        $this->getJson('/search/autocomplete?q=Searchable')
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('groups.products.items.0.brand', null)
            ->assertJsonPath('groups.categories.total', 0)
            ->assertJsonCount(0, 'groups.categories.items');

        $this->assertNotNull($secondProduct);
    }

    public function test_home_renders_configured_navigation_with_subcategories(): void
    {
        $this->useEnglishStorefrontLocale();

        [$parent, $parentSlug] = $this->seedCategory();

        $child = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'child-'.strtolower((string) str()->random(5)),
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 2,
            'parent_id' => $parent->id,
        ]);

        CategoryTranslation::query()->create([
            'category_id' => $child->id,
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'en',
            'name' => 'Child menu category',
            'slug' => 'child-menu-category',
            'description' => 'Child description',
        ]);

        app(SystemSettingsService::class)->put(NavigationMenuService::SETTINGS_KEY, [
            [
                'type' => 'category',
                'label' => 'Hrana i namirnice',
                'category_id' => $parent->id,
                'page_id' => 0,
                'url' => '',
                'open_in_new_tab' => false,
                'show_dropdown' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Hrana i namirnice')
            ->assertSee('Child menu category')
            ->assertSee('/category/'.$parentSlug, false);
    }

    public function test_catalog_navigation_renders_five_column_cascading_mega_menu(): void
    {
        $this->useEnglishStorefrontLocale();

        $parentId = null;
        $rootCategory = null;
        foreach (range(1, 5) as $depth) {
            $category = Category::query()->create([
                'scope' => Category::SCOPE_CATALOG,
                'code' => 'mega-level-'.$depth,
                'is_active' => true,
                'show_in_menu' => true,
                'sort_order' => $depth,
                'parent_id' => $parentId,
            ]);

            CategoryTranslation::query()->create([
                'category_id' => $category->id,
                'scope' => Category::SCOPE_CATALOG,
                'locale' => 'en',
                'name' => 'Mega category level '.$depth,
                'slug' => 'mega-category-level-'.$depth,
                'description' => '',
            ]);

            if ($depth === 1) {
                $rootCategory = $category;
            }

            $parentId = $category->id;
        }

        $rootIcon = UploadedFile::fake()->image('mega-category-icon.jpg', 320, 320);
        $rootCategory
            ->addMedia($rootIcon->getPathname())
            ->preservingOriginal()
            ->toMediaCollection('category_icon');

        app(SystemSettingsService::class)->put(NavigationMenuService::SETTINGS_KEY, [[
            'type' => 'catalog',
            'label_translations' => ['en' => 'Products'],
            'url_translations' => ['en' => '/categories'],
            'show_dropdown' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]]);

        $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            ])
            ->get('/')
            ->assertOk()
            ->assertSee('data-catalog-mega', false)
            ->assertSee('data-catalog-mega-max-columns="5"', false)
            ->assertSee('class="catalog-mega-columns catalog-mega-columns-5"', false)
            ->assertSee('data-catalog-mega-column="4"', false)
            ->assertSee('class="catalog-mega-item has-image"', false)
            ->assertSee('class="catalog-mega-item-thumb"', false)
            ->assertSee('class="desktop-mobile-menu-thumb"', false)
            ->assertSee('data-mobile-menu-toggle', false)
            ->assertSee('data-mobile-menu-toggle-open', false)
            ->assertSee('data-mobile-menu-toggle-close', false)
            ->assertSee('h-10 w-10', false)
            ->assertSee('data-mobile-nav-link', false)
            ->assertSee('/category/mega-category-level-1', false)
            ->assertSee('Mega category level 1')
            ->assertSee('Mega category level 5');
    }

    public function test_home_renders_instagram_curated_grid_block(): void
    {
        $block = ContentBlock::query()->create([
            'code' => 'home-instagram-widget',
            'name' => 'Home Instagram Widget',
            'type' => 'instagram_curated_grid',
            'is_active' => true,
            'payload' => null,
        ]);

        $block->translations()->create([
            'locale' => 'hr',
            'title' => 'Prati nas na Instagramu',
            'subtitle' => '@kozo_bodywear',
            'body_html' => null,
            'cta_label' => 'Otvori profil',
            'cta_url' => 'https://www.instagram.com/kozo_bodywear/',
            'payload' => null,
        ]);

        $block->translations()->create([
            'locale' => 'en',
            'title' => 'Follow us on Instagram',
            'subtitle' => '@kozo_bodywear',
            'body_html' => null,
            'cta_label' => 'Open profile',
            'cta_url' => 'https://www.instagram.com/kozo_bodywear/',
            'payload' => null,
        ]);

        $block->slots()->create([
            'placement' => 'home.bottom',
            'frontend_variant' => 'all',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 999,
            'is_active' => true,
        ]);

        $image = UploadedFile::fake()->image('instagram-widget.jpg', 1080, 1080);

        $block->addMedia($image->getPathname())
            ->usingName('Instagram widget image')
            ->usingFileName('instagram-widget.jpg')
            ->withCustomProperties([
                'link_url' => ['hr' => 'https://www.instagram.com/p/demo-post/', 'en' => 'https://www.instagram.com/p/demo-post/'],
                'link_url_value' => 'https://www.instagram.com/p/demo-post/',
                'caption' => ['hr' => 'Demo Instagram post caption', 'en' => 'Demo Instagram post caption'],
            ])
            ->toMediaCollection('block_slides');

        $this->get('/')
            ->assertOk()
            ->assertSee('Prati nas na Instagramu')
            ->assertSee('@kozo_bodywear')
            ->assertSee('https://www.instagram.com/kozo_bodywear/', false)
            ->assertSee('https://www.instagram.com/p/demo-post/', false);
    }

    public function test_home_renders_material_and_craftsmanship_block(): void
    {
        $block = ContentBlock::query()->create([
            'code' => 'home-material-craftsmanship',
            'name' => 'Home Material & Craftsmanship',
            'type' => 'material_craftsmanship',
            'is_active' => true,
            'payload' => null,
        ]);

        $block->translations()->create([
            'locale' => 'hr',
            'title' => 'Micromodal ili Giza pamuk?',
            'subtitle' => 'Dva premium osjećaja za kupce koji žele birati prema materijalu, ne samo prema modelu.',
            'body_html' => null,
            'cta_label' => 'Pogledaj premium modele',
            'cta_url' => '/shop',
            'payload' => [
                'material_craftsmanship' => [
                    'expand_label' => 'Vidi više',
                    'materials' => [
                        'micromodal' => [
                            'eyebrow' => 'Za svilenkast feel',
                            'title' => 'Micromodal',
                            'intro' => 'Lagan, vrlo mekan i elastičan materijal.',
                            'body_1' => 'Micromodal detalj prvi.',
                            'body_2' => 'Micromodal detalj drugi.',
                            'bullets' => ['svilenkast dodir', 'vrlo elastičan', 'hipoalergen'],
                        ],
                        'giza' => [
                            'eyebrow' => 'Za clean cotton feel',
                            'title' => 'Giza pamuk',
                            'intro' => 'Fini egipatski pamuk.',
                            'body_1' => 'Giza detalj prvi.',
                            'body_2' => 'Giza detalj drugi.',
                            'bullets' => ['prozračan osjećaj', 'izrazito upijajući', 'dugotrajno pletivo'],
                        ],
                    ],
                ],
            ],
        ]);

        $block->slots()->create([
            'placement' => 'home.after_products',
            'frontend_variant' => 'desktop',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 220,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Micromodal ili Giza pamuk?')
            ->assertSee('Micromodal')
            ->assertSee('Giza pamuk')
            ->assertSee('Vidi više')
            ->assertSee('/shop', false);
    }

    public function test_material_and_craftsmanship_block_uses_payload_texts_and_uploaded_icons(): void
    {
        $block = ContentBlock::query()->create([
            'code' => 'home-material-craftsmanship-custom',
            'name' => 'Home Material & Craftsmanship Custom',
            'type' => 'material_craftsmanship',
            'is_active' => true,
            'payload' => null,
        ]);

        $block->translations()->create([
            'locale' => 'hr',
            'title' => 'Materijali iz admina',
            'subtitle' => 'Podnaslov iz admina.',
            'body_html' => null,
            'cta_label' => null,
            'cta_url' => null,
            'payload' => [
                'material_craftsmanship' => [
                    'expand_label' => 'Otvori detalje',
                    'materials' => [
                        'micromodal' => [
                            'eyebrow' => 'Admin modal eyebrow',
                            'title' => 'Admin micromodal',
                            'intro' => '',
                            'body_1' => 'Admin micromodal prvi tekst.',
                            'body_2' => 'Admin micromodal drugi tekst.',
                            'bullets' => [
                                'Admin svilenkast dodir',
                                'Admin elasticnost',
                                'Admin hipoalergen',
                            ],
                        ],
                        'giza' => [
                            'eyebrow' => 'Admin giza eyebrow',
                            'title' => 'Admin Giza',
                            'intro' => 'Admin giza uvod.',
                            'body_1' => 'Admin giza prvi tekst.',
                            'body_2' => 'Admin giza drugi tekst.',
                            'bullets' => [
                                'Admin prozracan',
                                'Admin upojnost',
                                'Admin dugotrajno',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $block->slots()->create([
            'placement' => 'home.after_products',
            'frontend_variant' => 'desktop',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 220,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Materijali iz admina')
            ->assertSee('Otvori detalje')
            ->assertSee('Admin micromodal')
            ->assertSee('Admin dugotrajno')
            ->assertDontSee('Lagan, vrlo mekan i elastičan materijal za one koji žele gladak osjećaj uz tijelo.')
            ->assertSee('front-theme/images/GIZA_PAMUK.svg', false)
            ->assertSee('front-theme/images/MIKROMODAL.svg', false)
            ->assertSee('front-theme/images/SVILENKASTI_DODIR.svg', false)
            ->assertSee('front-theme/images/DUGOTRAJAN.svg', false);
    }

    public function test_products_carousel_keeps_blank_subtitle_empty(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $image = UploadedFile::fake()->image('carousel-product.jpg', 1200, 1600);
        $product->addMedia($image->getPathname())
            ->usingName('carousel-product')
            ->usingFileName('carousel-product.jpg')
            ->toMediaCollection('product_main');

        $block = ContentBlock::query()->create([
            'code' => 'home-products-blank-subtitle',
            'name' => 'Home Products Blank Subtitle',
            'type' => 'products_carousel',
            'is_active' => true,
            'payload' => null,
        ]);

        $block->translations()->create([
            'locale' => 'en',
            'title' => 'New arrivals',
            'subtitle' => '',
            'body_html' => null,
            'cta_label' => null,
            'cta_url' => null,
            'payload' => null,
        ]);

        $block->translations()->create([
            'locale' => 'hr',
            'title' => 'Novo',
            'subtitle' => 'Fallback subtitle',
            'body_html' => null,
            'cta_label' => null,
            'cta_url' => null,
            'payload' => null,
        ]);

        $block->items()->create([
            'item_type' => 'product',
            'item_id' => $product->id,
            'sort_order' => 1,
        ]);

        $block->slots()->create([
            'placement' => 'home.after_products',
            'frontend_variant' => 'desktop',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 10,
            'is_active' => true,
        ]);

        app(SystemSettingsService::class)->put('store_product_desktop_default_cols', 5);

        $this->get('/')
            ->assertOk()
            ->assertSee('New arrivals')
            ->assertSee('data-products-carousel-splide', false)
            ->assertSee('const preferredDesktopPerPage = 5;', false)
            ->assertDontSee('Fallback subtitle');

        app(SystemSettingsService::class)->put('store_product_desktop_default_cols', 4);

        $this->get('/?cols=5')
            ->assertOk()
            ->assertSee('const preferredDesktopPerPage = 5;', false);
    }

    public function test_home_renders_footer_newsletter_validation_hooks(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-newsletter-form', false)
            ->assertSee('data-newsletter-email', false)
            ->assertSee('data-newsletter-error', false)
            ->assertSee((string) __('ui.front.desktop.newsletter.validation.email_required'))
            ->assertSee((string) __('ui.front.desktop.newsletter.validation.email_invalid'))
            ->assertSee((string) __('ui.front.desktop.newsletter.validation.accept_terms'));
    }

    public function test_newsletter_form_requires_email_and_gdpr_consent(): void
    {
        $this->from('/')
            ->post('/newsletter/subscribe', [])
            ->assertRedirect('/')
            ->assertSessionHasErrorsIn('newsletter', [
                'newsletter_email',
                'newsletter_accept_terms',
            ]);
    }

    public function test_newsletter_form_stores_signup_when_database_provider_is_selected(): void
    {
        $this->createNewsletterSignupsTable();

        app(SystemSettingsService::class)->putMany([
            'store_newsletter_provider' => 'database',
        ]);

        $this->from('/')
            ->post('/newsletter/subscribe', [
                'newsletter_email' => 'newsletter@example.test',
                'newsletter_accept_terms' => '1',
            ])
            ->assertRedirect('/')
            ->assertSessionHas('status', (string) __('ui.front.desktop.newsletter.status.subscribed'));

        $this->assertDatabaseHas('newsletter_signups', [
            'email' => 'newsletter@example.test',
            'provider' => 'database',
            'sync_status' => 'synced',
            'consent_accepted' => 1,
        ]);
    }

    public function test_footer_newsletter_uses_enabled_recaptcha_settings(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_captcha_recaptcha_v3_enabled' => true,
            'store_captcha_recaptcha_v3_site_key' => 'test-site-key',
            'store_captcha_recaptcha_v3_secret_key' => 'test-secret-key',
            'store_captcha_recaptcha_v3_min_score' => 0.7,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-recaptcha-site-key="test-site-key"', false)
            ->assertSee('data-recaptcha-action="newsletter_footer"', false)
            ->assertSee('https://www.google.com/recaptcha/api.js?render=test-site-key', false);
    }

    public function test_newsletter_form_validates_enabled_recaptcha(): void
    {
        $this->createNewsletterSignupsTable();

        app(SystemSettingsService::class)->putMany([
            'store_newsletter_provider' => 'database',
            'store_captcha_recaptcha_v3_enabled' => true,
            'store_captcha_recaptcha_v3_site_key' => 'test-site-key',
            'store_captcha_recaptcha_v3_secret_key' => 'test-secret-key',
            'store_captcha_recaptcha_v3_min_score' => 0.7,
        ]);

        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.9,
                'action' => 'newsletter_footer',
            ]),
        ]);

        $this->postJson('/newsletter/subscribe', [
            'newsletter_email' => 'captcha-newsletter@example.test',
            'newsletter_accept_terms' => '1',
            'recaptcha_token' => 'newsletter-token',
        ], [
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertOk();

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
            && $request['secret'] === 'test-secret-key'
            && $request['response'] === 'newsletter-token');

        $this->assertDatabaseHas('newsletter_signups', [
            'email' => 'captcha-newsletter@example.test',
            'provider' => 'database',
            'sync_status' => 'synced',
        ]);
    }

    public function test_newsletter_form_sends_bali10_coupon_email_when_email_is_enabled(): void
    {
        $this->createNewsletterSignupsTable();

        Mail::fake();

        app(SystemSettingsService::class)->putMany([
            'store_newsletter_provider' => 'database',
            'store_email_enabled' => true,
            'store_brand_name' => 'KOZO',
        ]);

        $this->from('/')
            ->post('/newsletter/subscribe', [
                'newsletter_email' => 'newsletter@example.test',
                'newsletter_accept_terms' => '1',
            ])
            ->assertRedirect('/')
            ->assertSessionHas('status', (string) __('ui.front.desktop.newsletter.status.subscribed'));

        Mail::assertSent(NewsletterCouponMail::class, function (NewsletterCouponMail $mail): bool {
            return $mail->hasTo('newsletter@example.test')
                && $mail->couponCode === 'BALI10'
                && $mail->storeName === 'KOZO'
                && str_contains($mail->render(), 'BALI10');
        });
    }

    public function test_newsletter_form_syncs_mailchimp_without_local_database_storage(): void
    {
        $this->createNewsletterSignupsTable();

        app(SystemSettingsService::class)->putMany([
            'store_newsletter_provider' => 'mailchimp',
            'store_newsletter_mailchimp_api_key' => 'test-key-us6',
            'store_newsletter_mailchimp_list_id' => 'audience-123',
        ]);

        Http::fake([
            'https://us6.api.mailchimp.com/3.0/lists/audience-123/members/*' => Http::response([
                'id' => 'mailchimp-member-1',
            ], 200),
        ]);

        $this->from('/')
            ->post('/newsletter/subscribe', [
                'newsletter_email' => 'newsletter@example.test',
                'newsletter_accept_terms' => '1',
            ])
            ->assertRedirect('/')
            ->assertSessionHas('status', (string) __('ui.front.desktop.newsletter.status.subscribed'));

        $this->assertDatabaseMissing('newsletter_signups', [
            'email' => 'newsletter@example.test',
        ]);

        Http::assertSentCount(1);
    }

    public function test_newsletter_form_returns_json_for_ajax_submission(): void
    {
        $this->createNewsletterSignupsTable();

        app(SystemSettingsService::class)->putMany([
            'store_newsletter_provider' => 'database',
        ]);

        $this->postJson('/newsletter/subscribe', [
            'newsletter_email' => 'ajax-newsletter@example.test',
            'newsletter_accept_terms' => '1',
        ], [
            'X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertOk()
            ->assertJson([
                'message' => (string) __('ui.front.desktop.newsletter.status.subscribed'),
                'type' => 'success',
            ]);

        $this->assertDatabaseHas('newsletter_signups', [
            'email' => 'ajax-newsletter@example.test',
            'provider' => 'database',
            'sync_status' => 'synced',
            'consent_accepted' => 1,
        ]);
    }

    public function test_category_page_includes_sastav_filter_and_filters_products(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category, $categorySlug] = $this->seedCategory();
        [$bambooProduct, $bambooSlug] = $this->seedProduct($category->id);
        [$linenProduct, $linenSlug] = $this->seedProduct($category->id);

        $bambooAttribute = Attribute::query()->create([
            'code' => 'sastav-bamboo',
            'group_code' => 'sastav',
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $bambooAttribute->translations()->create([
            'locale' => 'en',
            'group_name' => 'Composition',
            'name' => 'Bamboo',
            'slug' => 'sastav-bamboo',
            'description' => null,
            'payload' => null,
        ]);

        $linenAttribute = Attribute::query()->create([
            'code' => 'sastav-linen',
            'group_code' => 'sastav',
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $linenAttribute->translations()->create([
            'locale' => 'en',
            'group_name' => 'Composition',
            'name' => 'Linen',
            'slug' => 'sastav-linen',
            'description' => null,
            'payload' => null,
        ]);

        $bambooProduct->attributes()->attach($bambooAttribute->id, [
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $linenProduct->attributes()->attach($linenAttribute->id, [
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->attachProductSizeOptions($bambooProduct, ['S', 'M']);
        $this->attachProductSizeOptions($linenProduct, ['M', 'L']);

        $bambooName = 'Product '.$bambooSlug;
        $linenName = 'Product '.$linenSlug;

        $this->get('/category/'.$categorySlug)
            ->assertOk()
            ->assertSee('Composition')
            ->assertSee('name="attr_sastav"', false)
            ->assertSee('Bamboo')
            ->assertSee('Linen')
            ->assertSee($bambooName)
            ->assertSee($linenName);

        $categoryResponse = $this->get('/category/'.$categorySlug);
        $categoryHtml = $categoryResponse->getContent();
        $this->assertIsString($categoryHtml);
        $this->assertNotFalse(strpos($categoryHtml, 'name="size"'));
        $this->assertNotFalse(strpos($categoryHtml, 'name="attr_sastav"'));
        $this->assertLessThan(
            strpos($categoryHtml, 'name="attr_sastav"'),
            strpos($categoryHtml, 'name="size"')
        );

        $this->get('/category/'.$categorySlug.'?attr_sastav='.$bambooAttribute->id)
            ->assertOk()
            ->assertSee('Composition')
            ->assertSee('Bamboo')
            ->assertSee($bambooName)
            ->assertDontSee($linenName);
    }

    public function test_product_cards_render_dominant_material_between_name_and_price(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category] = $this->seedCategory();
        [$product, $productSlug] = $this->seedProduct($category->id);
        $composition = '95% Micromodal, 5% Elastane';
        $materialLabel = 'Micromodal';

        $this->attachProductAttribute($product, 'material', 'Material', $composition, 1);

        $response = $this->get('/shop')
            ->assertOk()
            ->assertSee($materialLabel);

        $html = $response->getContent();
        $this->assertIsString($html);

        $namePosition = strpos($html, 'Product '.$productSlug);
        $materialPosition = strpos($html, $materialLabel);
        $pricePosition = strpos($html, '49.99 €');

        $this->assertNotFalse($namePosition);
        $this->assertNotFalse($materialPosition);
        $this->assertNotFalse($pricePosition);
        $this->assertLessThan($materialPosition, $namePosition);
        $this->assertLessThan($pricePosition, $materialPosition);
    }

    public function test_product_card_price_is_refreshed_between_requests(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $this->get('/shop')
            ->assertOk()
            ->assertSee('49.99 €');

        $product->update(['base_price' => 79.99]);

        $this->get('/shop')
            ->assertOk()
            ->assertSee('79.99 €');
    }

    public function test_shop_listing_falls_back_to_gallery_when_main_image_file_is_missing(): void
    {
        $this->useEnglishStorefrontLocale();

        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $mainImage = UploadedFile::fake()->image('missing-main.jpg', 1200, 1600);
        $galleryImage = UploadedFile::fake()->image('gallery-fallback.jpg', 1200, 1600);

        $mainMedia = $product->addMedia($mainImage->getPathname())
            ->usingName('missing-main')
            ->usingFileName('missing-main.jpg')
            ->toMediaCollection('product_main');

        $galleryMedia = $product->addMedia($galleryImage->getPathname())
            ->usingName('gallery-fallback')
            ->usingFileName('gallery-fallback.jpg')
            ->toMediaCollection('product_gallery');

        @unlink($mainMedia->getPath());

        $this->get('/shop')
            ->assertOk()
            ->assertSee('gallery-fallback.jpg', false)
            ->assertSee('data-product-image="'.$galleryMedia->getUrl().'"', false);
    }

    public function test_account_routes_are_prefixed_and_require_authenticated_verified_user(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->get('/account/orders')->assertRedirect('/login');
        $this->get('/account/profile')->assertRedirect('/login');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/account')->assertOk();
        $this->actingAs($user)->get('/account/orders')->assertOk();
        $this->actingAs($user)
            ->get('/account/profile')
            ->assertOk()
            ->assertDontSee('name="state"', false)
            ->assertDontSee('id="shipping-company"', false);

        $this
            ->actingAs($user)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            ])
            ->get('/account/profile')
            ->assertOk()
            ->assertDontSee('name="state"', false)
            ->assertDontSee('id="shipping-company"', false);
    }

    public function test_shipping_address_company_always_follows_the_billing_address(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        UserAddress::query()->create([
            'user_id' => $user->id,
            'type' => UserAddress::TYPE_BILLING,
            'company' => 'Billing Company',
            'address_line_1' => 'Billing Street 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'HR',
        ]);

        $this->actingAs($user)
            ->put(route('account.addresses.update', ['type' => UserAddress::TYPE_SHIPPING]), [
                'company' => 'Ignored Shipping Company',
                'address_line_1' => 'Shipping Street 2',
                'postal_code' => '44320',
                'city' => 'Kutina',
                'country_code' => 'HR',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $user->id,
            'type' => UserAddress::TYPE_SHIPPING,
            'company' => 'Billing Company',
        ]);

        $this->actingAs($user)
            ->put(route('account.addresses.update', ['type' => UserAddress::TYPE_BILLING]), [
                'company' => 'Updated Billing Company',
                'address_line_1' => 'Billing Street 1',
                'postal_code' => '10000',
                'city' => 'Zagreb',
                'country_code' => 'HR',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $user->id,
            'type' => UserAddress::TYPE_SHIPPING,
            'company' => 'Updated Billing Company',
        ]);
    }

    public function test_disabled_loyalty_is_hidden_from_account_navigation_dashboard_and_direct_route(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', false);

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $desktopResponse = $this->actingAs($user)->get('/account');

        $desktopResponse
            ->assertOk()
            ->assertSee(__('ui.account.dashboard.subtitle_without_loyalty'))
            ->assertSee('class="grid gap-5 md:grid-cols-2"', false)
            ->assertDontSee('id="loyalty"', false)
            ->assertDontSee(__('ui.account.nav.loyalty'));

        $this->actingAs($user)
            ->get('/account/loyalty')
            ->assertNotFound();

        $mobileResponse = $this
            ->actingAs($user)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            ])
            ->get('/account');

        $mobileResponse
            ->assertOk()
            ->assertDontSee(__('ui.account.nav.loyalty'))
            ->assertDontSee(__('ui.account.dashboard.cards.disabled'));
    }

    public function test_disabled_loyalty_total_is_hidden_from_account_order_history(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', false);

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $order = $this->createAccountOrder($user, 'ACCOUNT-NO-LOYALTY-1');
        $order->totals()->create([
            'code' => 'loyalty_redemption',
            'title' => 'Archived reward discount',
            'value' => -10,
            'sort_order' => 650,
            'payload' => null,
        ]);

        $this->actingAs($user)
            ->get('/account/orders/'.$order->order_number)
            ->assertOk()
            ->assertDontSee('Archived reward discount');
    }

    public function test_enabled_loyalty_is_visible_in_account_and_order_history(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', true);

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $order = $this->createAccountOrder($user, 'ACCOUNT-LOYALTY-1');
        $order->totals()->create([
            'code' => 'loyalty_redemption',
            'title' => 'Archived reward discount',
            'value' => -10,
            'sort_order' => 650,
            'payload' => null,
        ]);

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('id="loyalty"', false)
            ->assertSee(__('ui.account.nav.loyalty'));

        $this->actingAs($user)
            ->get('/account/loyalty')
            ->assertOk();

        $this->actingAs($user)
            ->get('/account/orders/'.$order->order_number)
            ->assertOk()
            ->assertSee('Archived reward discount');
    }

    public function test_enabled_loyalty_is_visible_only_to_eligible_customer_groups(): void
    {
        $retail = CustomerGroup::query()->create([
            'code' => 'retail-loyalty',
            'name' => 'Retail Loyalty',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 10,
        ]);
        $b2b = CustomerGroup::query()->create([
            'code' => 'b2b-no-loyalty',
            'name' => 'B2B No Loyalty',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 20,
        ]);

        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_customer_group_ids' => [$retail->id],
        ]);

        $eligible = User::factory()->create(['email_verified_at' => now()]);
        $ineligible = User::factory()->create(['email_verified_at' => now()]);
        $eligible->customerGroups()->attach($retail->id);
        $ineligible->customerGroups()->attach($b2b->id);

        $this->actingAs($eligible)
            ->get('/account')
            ->assertOk()
            ->assertSee('id="loyalty"', false)
            ->assertSee(__('ui.account.nav.loyalty'));
        $this->actingAs($eligible)
            ->get('/account/loyalty')
            ->assertOk();

        $this->actingAs($ineligible)
            ->get('/account')
            ->assertOk()
            ->assertDontSee('id="loyalty"', false)
            ->assertDontSee(__('ui.account.nav.loyalty'));
        $this->actingAs($ineligible)
            ->get('/account/loyalty')
            ->assertNotFound();

        $eligibleOrder = $this->createAccountOrder($eligible, 'ACCOUNT-GROUP-YES');
        $eligibleOrder->totals()->create([
            'code' => 'loyalty_redemption',
            'title' => 'Eligible reward discount',
            'value' => -10,
            'sort_order' => 650,
            'payload' => null,
        ]);
        $ineligibleOrder = $this->createAccountOrder($ineligible, 'ACCOUNT-GROUP-NO');
        $ineligibleOrder->totals()->create([
            'code' => 'loyalty_redemption',
            'title' => 'Ineligible reward discount',
            'value' => -10,
            'sort_order' => 650,
            'payload' => null,
        ]);

        $this->actingAs($eligible)
            ->get('/account/orders/'.$eligibleOrder->order_number)
            ->assertOk()
            ->assertSee('Eligible reward discount');
        $this->actingAs($ineligible)
            ->get('/account/orders/'.$ineligibleOrder->order_number)
            ->assertOk()
            ->assertDontSee('Ineligible reward discount');
    }

    public function test_checkout_account_registration_assigns_the_first_active_default_customer_group(): void
    {
        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $inactiveDefault = CustomerGroup::query()->create([
            'code' => 'checkout-inactive-default',
            'name' => 'Checkout Inactive Default',
            'is_active' => false,
            'is_default' => true,
            'sort_order' => 1,
        ]);
        $firstActiveDefault = CustomerGroup::query()->create([
            'code' => 'checkout-first-default',
            'name' => 'Checkout First Default',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 10,
        ]);
        $laterActiveDefault = CustomerGroup::query()->create([
            'code' => 'checkout-later-default',
            'name' => 'Checkout Later Default',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 20,
        ]);

        Currency::query()->create([
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => 'EUR',
            'symbol_position' => 'left',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        OrderStatus::query()->create([
            'code' => 'new',
            'name' => 'New',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        ShippingMethod::query()->create([
            'code' => 'standard',
            'name' => 'Standard Shipping',
            'price' => 4.99,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        PaymentMethod::query()->create([
            'code' => 'bank',
            'name' => 'Bank Transfer',
            'provider' => 'bank',
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->post('/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertRedirect();

        $response = $this->post('/checkout', [
            'customer_first_name' => 'Checkout',
            'customer_last_name' => 'Buyer',
            'customer_email' => 'checkout-account@example.test',
            'customer_phone' => '+38591000003',
            'billing_first_name' => 'Checkout',
            'billing_last_name' => 'Buyer',
            'billing_address_line_1' => 'Main Street 1',
            'billing_postal_code' => '10000',
            'billing_city' => 'Zagreb',
            'billing_country_code' => 'HR',
            'ship_to_different_address' => '0',
            'shipping_method_code' => 'standard',
            'payment_method_code' => 'bank',
            'register_account' => '1',
            'register_password' => 'Password123!',
            'register_password_confirmation' => 'Password123!',
            'accept_terms' => '1',
        ]);

        $user = User::query()->where('email', 'checkout-account@example.test')->firstOrFail();
        $order = Order::query()->where('user_id', $user->id)->firstOrFail();

        $response->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('customer_group_user', [
            'user_id' => $user->id,
            'customer_group_id' => $firstActiveDefault->id,
        ]);
        $this->assertDatabaseMissing('customer_group_user', [
            'user_id' => $user->id,
            'customer_group_id' => $inactiveDefault->id,
        ]);
        $this->assertDatabaseMissing('customer_group_user', [
            'user_id' => $user->id,
            'customer_group_id' => $laterActiveDefault->id,
        ]);
    }

    public function test_checkout_creates_order_with_pretty_checkout_routes(): void
    {
        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        User::factory()->create([
            'email' => 'jane@example.test',
        ]);

        Currency::query()->create([
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => 'EUR',
            'symbol_position' => 'left',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        OrderStatus::query()->create([
            'code' => 'new',
            'name' => 'New',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ShippingMethod::query()->create([
            'code' => 'standard',
            'name' => 'Standard Shipping',
            'price' => 4.99,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        PaymentMethod::query()->create([
            'code' => 'bank',
            'name' => 'Bank Transfer',
            'provider' => 'bank',
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->post('/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertRedirect();

        $response = $this->post('/checkout', [
            'customer_first_name' => 'Jane',
            'customer_last_name' => 'Doe',
            'customer_email' => 'jane@example.test',
            'customer_phone' => '+38591000002',

            'billing_first_name' => 'Jane',
            'billing_last_name' => 'Doe',
            'billing_company' => 'AG Test',
            'billing_oib' => '12345678901',
            'billing_vat_id' => 'HR12345678901',
            'billing_address_line_1' => 'Main Street 1',
            'billing_address_line_2' => '',
            'billing_postal_code' => '10000',
            'billing_city' => 'Zagreb',
            'billing_country_code' => 'HR',

            'ship_to_different_address' => '1',
            'shipping_first_name' => 'Janet',
            'shipping_last_name' => 'Doe',
            'shipping_company' => 'Do not save this company',
            'shipping_address_line_1' => 'Side Street 2',
            'shipping_postal_code' => '10000',
            'shipping_city' => 'Zagreb',
            'shipping_country_code' => 'HR',

            'shipping_method_code' => 'standard',
            'payment_method_code' => 'bank',
            'customer_note' => 'Please ring bell on delivery.',
            // Password managers may submit hidden registration fields even when
            // the guest did not opt into account registration.
            'register_password' => 'short',
            'register_password_confirmation' => 'different',
            'accept_terms' => '1',
        ]);

        $order = \App\Models\Sales\Order\Order::query()->first();

        $this->assertNotNull($order);

        $response->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]));

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_email' => 'jane@example.test',
            'billing_company' => 'AG Test',
            'shipping_company' => 'AG Test',
            'billing_state' => '',
            'shipping_state' => '',
            'item_qty' => 2,
            'user_id' => null,
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->assertDatabaseMissing('orders', [
            'customer_email' => 'missing@example.test',
        ]);
    }

    public function test_checkout_renders_the_wide_accessible_ui_on_desktop_and_the_reduced_form_on_mobile(): void
    {
        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        ShippingMethod::query()->create([
            'code' => 'standard-ui',
            'name' => 'Standard Shipping',
            'price' => 4.99,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        PaymentMethod::query()->create([
            'code' => 'bank-ui',
            'name' => 'Bank Transfer',
            'provider' => 'bank',
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->post('/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertRedirect();

        $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            ])
            ->get('/checkout')
            ->assertOk()
            ->assertSee('front-theme/styles/checkout.css', false)
            ->assertSee('class="checkout-shell"', false)
            ->assertSee('class="checkout-layout" novalidate', false)
            ->assertSee('checkout-primary-button', false)
            ->assertSee('data-boxnow-selection-summary aria-live="polite"', false)
            ->assertSeeInOrder([
                'data-boxnow-open',
                'data-boxnow-selection-summary',
                'data-boxnow-selected',
                'data-boxnow-selected-address',
            ], false)
            ->assertSee('data-gls-dpm-selection-summary aria-live="polite"', false)
            ->assertSeeInOrder([
                'data-gls-dpm-open',
                'data-gls-dpm-selection-summary',
                'data-gls-dpm-selected',
                'data-gls-dpm-selected-address',
            ], false)
            ->assertSee('autocomplete="billing given-name"', false)
            ->assertSee('aria-controls="shipping-address-fields"', false)
            ->assertSee('items-center justify-start', false)
            ->assertSee('lg:justify-between', false)
            ->assertSee('href="'.route('pages.show', ['slug' => 'uvjeti-koristenja']).'"', false)
            ->assertSee(__('ui.auth.register.terms_link'))
            ->assertDontSee('name="billing_state"', false)
            ->assertDontSee('name="shipping_state"', false)
            ->assertDontSee('name="shipping_company"', false);

    }

    public function test_category_shows_only_option_filters_available_in_that_category_scope(): void
    {
        $this->useEnglishStorefrontLocale();
        [$categoryA, $slugA] = $this->seedCategory();
        [$categoryB, $slugB] = $this->seedCategory();
        [$productA] = $this->seedProduct($categoryA->id);
        [$productB] = $this->seedProduct($categoryB->id);

        $user = User::factory()->create();

        $colorOption = Option::query()->create([
            'code' => 'color-scope',
            'type' => Option::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $colorOption->translations()->create([
            'locale' => 'en',
            'name' => 'Color Scope',
            'slug' => 'color-scope',
        ]);

        $sizeOption = Option::query()->create([
            'code' => 'size-scope',
            'type' => Option::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 2,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $sizeOption->translations()->create([
            'locale' => 'en',
            'name' => 'Size Scope',
            'slug' => 'size-scope',
        ]);

        $black = OptionValue::query()->create([
            'option_id' => $colorOption->id,
            'code' => 'black-scope',
            'is_active' => true,
            'sort_order' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $black->translations()->create([
            'locale' => 'en',
            'name' => 'Black Scope',
            'slug' => 'black-scope',
        ]);

        $medium = OptionValue::query()->create([
            'option_id' => $sizeOption->id,
            'code' => 'm-scope',
            'is_active' => true,
            'sort_order' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $medium->translations()->create([
            'locale' => 'en',
            'name' => 'M Scope',
            'slug' => 'm-scope',
        ]);

        ProductOptionValue::query()->create([
            'product_id' => $productA->id,
            'option_value_id' => $black->id,
            'parent_option_value_id' => null,
            'mode' => 'single',
            'combination_hash' => hash('sha256', 's:'.$black->id),
            'sku' => 'A-BLACK',
            'stock_qty' => 10,
            'is_active' => true,
            'sort_order' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        ProductOptionValue::query()->create([
            'product_id' => $productB->id,
            'option_value_id' => $medium->id,
            'parent_option_value_id' => null,
            'mode' => 'single',
            'combination_hash' => hash('sha256', 's:'.$medium->id),
            'sku' => 'B-M',
            'stock_qty' => 10,
            'is_active' => true,
            'sort_order' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        app(SystemSettingsService::class)->put('store_product_filter_option_ids', [$colorOption->id, $sizeOption->id]);

        $this->get('/category/'.$slugA)
            ->assertOk()
            ->assertSee('Color Scope')
            ->assertDontSee('Size Scope');

        $this->get('/category/'.$slugB)
            ->assertOk()
            ->assertSee('Size Scope')
            ->assertDontSee('Color Scope');
    }

    public function test_catalog_hides_empty_category_and_manufacturer_filter_values(): void
    {
        $this->useEnglishStorefrontLocale();
        [$categoryWithProducts, $categoryWithProductsSlug] = $this->seedCategory();
        [, $emptyCategorySlug] = $this->seedCategory();
        [$manufacturerWithProducts, $manufacturerWithProductsSlug] = $this->seedManufacturer();
        [, $emptyManufacturerSlug] = $this->seedManufacturer();
        [$product] = $this->seedProduct($categoryWithProducts->id);

        $product->update(['manufacturer_id' => $manufacturerWithProducts->id]);
        app(SystemSettingsService::class)->put('catalog_use_manufacturers', true);

        $this->get('/shop')
            ->assertOk()
            ->assertSee($categoryWithProductsSlug)
            ->assertDontSee($emptyCategorySlug)
            ->assertSee($manufacturerWithProductsSlug)
            ->assertDontSee($emptyManufacturerSlug);
    }

    public function test_attribute_facets_only_offer_values_compatible_with_other_selected_groups(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();
        [$blackProduct, $blackProductSlug] = $this->seedProduct($category->id);
        [$steelProduct, $steelProductSlug] = $this->seedProduct($category->id);

        $black = $this->attachProductAttribute($blackProduct, 'boja', 'Color', 'Facet Black', 1);
        $this->attachProductAttribute($blackProduct, 'materijal', 'Material', 'Facet Plastic', 1);
        $this->attachProductAttribute($steelProduct, 'boja', 'Color', 'Facet White', 2);
        $this->attachProductAttribute($steelProduct, 'materijal', 'Material', 'Facet Stainless Steel', 2);

        app(SystemSettingsService::class)->put(
            'store_product_filter_attribute_group_codes',
            ['boja', 'materijal']
        );

        $this->get('/category/'.$categorySlug.'?attr_boja='.$black->id)
            ->assertOk()
            ->assertSee($blackProductSlug)
            ->assertDontSee($steelProductSlug)
            ->assertSee('Facet Plastic')
            ->assertDontSee('Facet Stainless Steel');
    }

    public function test_category_manufacturer_filter_is_limited_to_manufacturers_with_products_in_scope(): void
    {
        $this->useEnglishStorefrontLocale();
        [$categoryA, $categorySlugA] = $this->seedCategory();
        [$categoryB] = $this->seedCategory();
        [$manufacturerA, $manufacturerSlugA] = $this->seedManufacturer();
        [$manufacturerB, $manufacturerSlugB] = $this->seedManufacturer();
        [$productA] = $this->seedProduct($categoryA->id);
        [$productB] = $this->seedProduct($categoryB->id);

        $productA->update(['manufacturer_id' => $manufacturerA->id]);
        $productB->update(['manufacturer_id' => $manufacturerB->id]);
        app(SystemSettingsService::class)->put('catalog_use_manufacturers', true);

        $this->get('/category/'.$categorySlugA)
            ->assertOk()
            ->assertSee($manufacturerSlugA)
            ->assertDontSee($manufacturerSlugB);
    }

    public function test_manufacturer_page_only_shows_option_and_attribute_values_used_by_that_manufacturer(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$manufacturerA, $manufacturerSlugA] = $this->seedManufacturer();
        [$manufacturerB] = $this->seedManufacturer();
        [$productA] = $this->seedProduct($category->id);
        [$productB] = $this->seedProduct($category->id);

        $productA->update(['manufacturer_id' => $manufacturerA->id]);
        $productB->update(['manufacturer_id' => $manufacturerB->id]);

        $finishOption = $this->createProductOption('Scoped Finish');
        $this->attachOptionValueToProduct($productA, $finishOption, 'Brand A Finish');
        $this->attachOptionValueToProduct($productB, $finishOption, 'Brand B Finish', 2);
        $this->attachProductAttribute($productA, 'scoped_material', 'Scoped Material', 'Brand A Material', 1);
        $this->attachProductAttribute($productB, 'scoped_material', 'Scoped Material', 'Brand B Material', 2);

        app(SystemSettingsService::class)->putMany([
            'catalog_use_manufacturers' => true,
            'store_product_filter_option_ids' => [$finishOption->id],
            'store_product_filter_attribute_group_codes' => ['scoped_material'],
        ]);

        $this->get('/manufacturer/'.$manufacturerSlugA)
            ->assertOk()
            ->assertSee('Brand A Finish')
            ->assertDontSee('Brand B Finish')
            ->assertSee('Brand A Material')
            ->assertDontSee('Brand B Material');
    }

    public function test_available_only_scope_hides_filter_values_used_only_by_out_of_stock_products(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();
        [$availableProduct] = $this->seedProduct($category->id);
        [$unavailableProduct] = $this->seedProduct($category->id);
        $availabilityOption = $this->createProductOption('Availability Facet');

        $availableValue = $this->attachOptionValueToProduct($availableProduct, $availabilityOption, 'Available Facet Value');
        $unavailableValue = $this->attachOptionValueToProduct($unavailableProduct, $availabilityOption, 'Unavailable Facet Value', 2);
        $unavailableProduct->update(['stock_qty' => 0]);
        ProductOptionValue::query()
            ->where('product_id', $unavailableProduct->id)
            ->where('option_value_id', $unavailableValue->id)
            ->update(['stock_qty' => 0]);

        app(SystemSettingsService::class)->put('store_product_filter_option_ids', [$availabilityOption->id]);

        $this->get('/category/'.$categorySlug.'?available_only=1')
            ->assertOk()
            ->assertSee('Available Facet Value')
            ->assertDontSee('Unavailable Facet Value');

        $this->assertNotNull($availableValue);
    }

    public function test_filter_only_option_is_hidden_on_product_page_but_stays_available_in_category_filter(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();
        [$redProduct, $redSlug] = $this->seedProduct($category->id);
        [$blueProduct, $blueSlug] = $this->seedProduct($category->id);

        $this->attachProductSizeOptions($redProduct, ['M']);
        $this->attachProductSizeOptions($blueProduct, ['M']);

        $colorOption = $this->createProductOption('Color', false);
        $redValue = $this->attachOptionValueToProduct($redProduct, $colorOption, 'Red', 1);
        $this->attachOptionValueToProduct($blueProduct, $colorOption, 'Blue', 1);

        app(SystemSettingsService::class)->put('store_product_filter_option_ids', [$colorOption->id]);

        $this->get('/product/'.$redSlug)
            ->assertOk()
            ->assertSee('M')
            ->assertDontSee('data-size-label="Red"', false)
            ->assertDontSee('data-size-label="Blue"', false);

        $this->get('/category/'.$categorySlug)
            ->assertOk()
            ->assertSee('Color')
            ->assertSee('data-filter-kind="color"', false)
            ->assertSee('data-filter-count="1"', false)
            ->assertSee('Red')
            ->assertSee('Blue');

        $this->get('/category/'.$categorySlug.'?opt_'.$colorOption->id.'='.$redValue->id)
            ->assertOk()
            ->assertSee($redSlug)
            ->assertDontSee($blueSlug);
    }

    public function test_category_default_sort_groups_products_by_color_order(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();
        [$redProduct, $redSlug] = $this->seedProduct($category->id);
        [$whiteProduct, $whiteSlug] = $this->seedProduct($category->id);
        [$blackProduct, $blackSlug] = $this->seedProduct($category->id);

        $colorOption = $this->createProductOption('Color', false);
        $this->attachOptionValueToProduct($redProduct, $colorOption, 'Red', 1);
        $this->attachOptionValueToProduct($whiteProduct, $colorOption, 'White', 2);
        $this->attachOptionValueToProduct($blackProduct, $colorOption, 'Black', 3);

        app(SystemSettingsService::class)->put('store_product_filter_option_ids', [$colorOption->id]);

        $this->get('/category/'.$categorySlug)
            ->assertOk()
            ->assertSee('Default order')
            ->assertSeeInOrder([
                '/product/'.$whiteSlug,
                '/product/'.$redSlug,
                '/product/'.$blackSlug,
            ], false)
            ->assertSeeInOrder([
                'White',
                'Red',
                'Black',
            ]);

        $this->get('/category/'.$categorySlug.'?sort=newest')
            ->assertOk()
            ->assertSeeInOrder([
                '/product/'.$blackSlug,
                '/product/'.$whiteSlug,
                '/product/'.$redSlug,
            ], false);
    }

    public function test_category_color_filter_uses_uploaded_swatch_image_when_available(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $colorOption = $this->createProductOption('Color', false);
        $redValue = $this->attachOptionValueToProduct($product, $colorOption, 'Red', 1);
        $redValue->update([
            'payload' => [
                'swatch_image_path' => 'catalog/option-values/swatch/red-swatch.png',
            ],
        ]);

        app(SystemSettingsService::class)->put('store_product_filter_option_ids', [$colorOption->id]);

        $this->get('/category/'.$categorySlug)
            ->assertOk()
            ->assertSee('data-filter-kind="color"', false)
            ->assertSee('data-filter-swatch=', false)
            ->assertSee('red-swatch.png', false);
    }

    public function test_product_detail_uses_filter_color_swatches_for_color_variant_links(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$blackProduct, $blackSlug] = $this->seedProduct($category->id);
        [$redProduct, $redSlug] = $this->seedProduct($category->id);

        $blackProduct->update(['payload' => ['source' => ['mpn' => 'BRZ-1']]]);
        $redProduct->update(['payload' => ['source' => ['mpn' => 'BRZ-1']]]);
        $this->attachProductSizeOptions($blackProduct, ['M']);
        $this->attachProductSizeOptions($redProduct, ['M']);

        $colorOption = $this->createProductOption('Color', false);
        $this->attachOptionValueToProduct($blackProduct, $colorOption, 'Black', 1);
        $redValue = $this->attachOptionValueToProduct($redProduct, $colorOption, 'Red', 2);
        $redValue->update([
            'payload' => [
                'swatch_image_path' => 'catalog/option-values/swatch/red-swatch.png',
            ],
        ]);

        $this->get('/product/'.$blackSlug)
            ->assertOk()
            ->assertSee('data-product-color-variants', false)
            ->assertSee('Color variants')
            ->assertSee('/product/'.$redSlug, false)
            ->assertSee('data-color-variant-label="Black"', false)
            ->assertSee('data-color-variant-label="Red"', false)
            ->assertSee('red-swatch.png', false)
            ->assertSee('aria-current="true"', false)
            ->assertDontSee('data-size-label="Red"', false);
    }

    public function test_filter_only_option_does_not_require_selection_on_add_to_cart(): void
    {
        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $colorOption = $this->createProductOption('Color', false);
        $this->attachOptionValueToProduct($product, $colorOption, 'Red', 1);

        $this->post('/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertRedirect();

        $this->assertSame(1, app(\App\Services\Front\CartService::class)->summary()['item_qty']);
    }

    public function test_product_detail_hides_out_of_stock_option_values(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $this->attachProductSizeOptions($product, ['S', 'L'], [
            'S' => 4,
            'L' => 0,
        ]);
        $product->update(['stock_qty' => 4]);

        $this->get('/product/'.$slug)
            ->assertOk()
            ->assertSee('data-size-label="S"', false)
            ->assertDontSee('data-size-label="L"', false)
            ->assertSee('front-theme/styles/product-detail.css', false)
            ->assertSee('product-detail-breadcrumb', false)
            ->assertSee('data-product-detail-lower', false)
            ->assertSee('product-detail-quantity-control', false)
            ->assertSee('id="product-detail-cart-form-'.$product->id.'"', false)
            ->assertSee('data-product-floating-cart', false)
            ->assertSee('data-product-floating-qty-input', false)
            ->assertSee('data-product-floating-submit', false)
            ->assertSee('class="product-information-panel" open', false)
            ->assertSee('solid.svg#bag-shopping', false)
            ->assertDontSee('<style', false)
            ->assertDontSee(' style=', false);
    }

    public function test_product_detail_exposes_option_price_overrides_for_live_price_updates(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $this->attachProductSizeOptions($product, ['S', 'XXL']);

        ProductOptionValue::query()
            ->where('product_id', $product->id)
            ->whereHas('optionValue.translations', fn ($query) => $query->where('name', 'XXL'))
            ->firstOrFail()
            ->update(['price_override' => 79.99]);

        $this->get('/product/'.$slug)
            ->assertOk()
            ->assertSee('data-product-default-price-current="49.99 €"', false)
            ->assertSee('data-product-default-price-net="49.99 €"', false)
            ->assertSee('data-option-price-current="79.99 €"', false)
            ->assertSee('data-option-price-net="79.99 €"', false)
            ->assertSee('data-option-price-current-value="79.99"', false);
    }

    public function test_product_detail_shows_the_included_vat_rate_below_the_price(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $taxRate = TaxRate::query()->create([
            'code' => 'vat-25-product-detail',
            'name' => 'VAT 25%',
            'rate_type' => 'percent',
            'rate' => 25,
            'priority' => 1,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product->update(['tax_rate_id' => $taxRate->id]);

        $this->get('/product/'.$slug)
            ->assertOk()
            ->assertSee('class="product-detail-tax-note"', false)
            ->assertSee('Price excluding VAT: <span data-product-price-net>49.99 €</span>', false)
            ->assertSee('VAT is included in the price (25%)')
            ->assertSeeInOrder([
                'VAT is included in the price (25%)',
                'Price excluding VAT',
            ]);
    }

    public function test_shop_card_hides_out_of_stock_option_values(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $this->attachProductSizeOptions($product, ['S', 'L'], [
            'S' => 2,
            'L' => 0,
        ]);
        $product->update(['stock_qty' => 2]);

        $this->get('/shop')
            ->assertOk()
            ->assertSee('data-option-label="S"', false)
            ->assertDontSee('data-option-label="L"', false);
    }

    public function test_product_detail_shows_unavailable_when_all_option_values_are_out_of_stock(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $this->attachProductSizeOptions($product, ['S', 'M'], [
            'S' => 0,
            'M' => 0,
        ]);
        $product->update(['stock_qty' => 0]);

        $this->get('/product/'.$slug)
            ->assertOk()
            ->assertSee('Unavailable')
            ->assertSee('data-size-label="S"', false)
            ->assertSee('data-size-label="M"', false);
    }

    public function test_add_to_cart_returns_unavailable_when_all_option_values_are_out_of_stock(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $this->attachProductSizeOptions($product, ['S', 'M'], [
            'S' => 0,
            'M' => 0,
        ]);
        $product->update(['stock_qty' => 0]);

        $this->postJson('/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Product option is unavailable or out of stock.');
    }

    public function test_product_detail_renders_active_attribute_panels_in_requested_order_without_store_check_button(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $this->attachProductAttribute($product, 'garancija', 'Guarantee Label', '2 year guarantee', 30);
        $this->attachProductAttribute($product, 'sastav', 'Composition Label', '100% cotton', 10);
        $this->attachProductAttribute($product, 'kvaliteta', 'Quality Label', 'Premium stitching', 20);
        $this->attachProductAttribute($product, 'origin', 'Origin Label', 'Croatia', 40);

        $this->get('/product/'.$slug)
            ->assertOk()
            ->assertDontSee('Check store availability')
            ->assertSeeInOrder(['Composition Label', 'Quality Label', 'Guarantee Label'])
            ->assertSee('100% cotton')
            ->assertSee('Premium stitching')
            ->assertSee('2 year guarantee')
            ->assertSee('Origin Label')
            ->assertSee('Croatia')
            ->assertSeeInOrder(['Composition Label', 'Quality Label', 'Guarantee Label', 'Origin Label']);
    }

    public function test_product_detail_shows_only_admin_enabled_shipping_and_payment_methods(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [, $slug] = $this->seedProduct($category->id);

        ShippingMethod::query()->create([
            'code' => 'product-page-active-shipping',
            'name' => 'Enabled product page shipping',
            'description' => 'Visible delivery description.',
            'price' => 4.99,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        ShippingMethod::query()->create([
            'code' => 'product-page-disabled-shipping',
            'name' => 'Disabled product page shipping',
            'price' => 9.99,
            'is_active' => false,
            'sort_order' => 2,
        ]);
        ShippingMethod::query()->create([
            'code' => 'product-page-weight-shipping',
            'name' => 'Weight based product page shipping',
            'pricing_type' => 'weight_tiers',
            'price' => 0,
            'is_active' => true,
            'sort_order' => 3,
        ]);
        ShippingMethod::query()->create([
            'code' => 'product-page-quote-shipping',
            'name' => 'Quote product page shipping',
            'pricing_type' => 'quote',
            'price' => 0,
            'is_active' => true,
            'sort_order' => 4,
        ]);
        PaymentMethod::query()->create([
            'code' => 'product-page-active-payment',
            'name' => 'Enabled product page payment',
            'provider' => 'bank',
            'description' => 'Visible payment description.',
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        PaymentMethod::query()->create([
            'code' => 'product-page-disabled-payment',
            'name' => 'Disabled product page payment',
            'provider' => 'bank',
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'is_active' => false,
            'sort_order' => 2,
        ]);
        PaymentMethod::query()->updateOrCreate(
            ['code' => 'quote_request'],
            [
                'name' => 'Checkout-only quote request',
                'provider' => 'manual_quote',
                'fee_type' => 'fixed',
                'fee_value' => 0,
                'is_active' => true,
                'sort_order' => 3,
            ],
        );
        PaymentMethod::query()->updateOrCreate(
            ['code' => 'corvus'],
            [
                'name' => 'Configured CorvusPay',
                'provider' => 'corvuspay',
                'fee_type' => 'fixed',
                'fee_value' => 0,
                'is_active' => true,
                'sort_order' => 4,
                'settings' => [
                    'corvus_store_id' => 'test-store',
                    'corvus_secret_key' => 'test-secret',
                ],
            ],
        );

        $this->get('/product/'.$slug)
            ->assertOk()
            ->assertSee('data-product-purchase-information', false)
            ->assertSee('Basic information')
            ->assertSee('Enabled product page shipping')
            ->assertSee('Visible delivery description.')
            ->assertDontSee('Disabled product page shipping')
            ->assertSeeInOrder([
                'Weight based product page shipping',
                'Calculated at checkout based on the address and weight.',
            ])
            ->assertSeeInOrder([
                'Quote product page shipping',
                'The shipping price is confirmed after the request is submitted.',
            ])
            ->assertSee('Enabled product page payment')
            ->assertSee('Visible payment description.')
            ->assertDontSee('Disabled product page payment')
            ->assertDontSee('Checkout-only quote request')
            ->assertSee('Configured CorvusPay');
    }

    public function test_product_detail_renders_material_feature_labels_under_icons(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $this->attachProductAttribute($product, 'sastav', 'Composition Label', '95% Micromodal, 5% Elastane', 10);

        $this->get('/product/'.$slug)
            ->assertOk()
            ->assertSee('assets/payments/SVILENKASTI_DODIR.svg', false)
            ->assertSee('assets/payments/ELASTICNOST.svg', false)
            ->assertSee('assets/payments/HIPOALERGEN.svg', false)
            ->assertSeeInOrder(['95% Micromodal, 5% Elastane', 'Svilenkast', 'Elastičan', 'Hipoalergen']);
    }

    public function test_product_detail_renders_swipe_aids_and_a_single_row_gallery_thumbnail_strip(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $recentlyViewedIds = [];
        foreach (range(1, 3) as $index) {
            [$relatedProduct] = $this->seedProduct($category->id);
            $recentlyViewedIds[] = $relatedProduct->id;
        }

        foreach (range(1, 7) as $index) {
            $image = UploadedFile::fake()->image("gallery-image-{$index}.jpg", 900, 900);
            $product
                ->addMedia($image->getPathname())
                ->usingName("Gallery image {$index}")
                ->usingFileName("gallery-image-{$index}.jpg")
                ->toMediaCollection($index === 1 ? 'product_main' : 'product_gallery');
        }

        $response = $this
            ->withSession(['front_recently_viewed_products' => $recentlyViewedIds])
            ->get('/product/'.$slug)
            ->assertOk()
            ->assertSee('data-product-gallery-thumbnails', false)
            ->assertSee('data-related-products-splide', false)
            ->assertSee('data-product-products-widget-swipe-hint', false);

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertSame(7, substr_count($html, 'data-gallery-nav='));
        $this->assertSame(2, substr_count($html, 'data-product-products-widget-swipe-hint'));
        $this->assertSame(2, substr_count($html, 'mb-[0.9rem] block h-1 w-11'));
        $this->assertSame(2, substr_count($html, 'text-3xl font-extrabold'));
    }

    public function test_mobile_product_detail_renders_attribute_panels_in_requested_order(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $this->attachProductAttribute($product, 'kvaliteta', 'Quality Label', 'Premium finish', 20);
        $this->attachProductAttribute($product, 'sastav', 'Composition Label', '95% cotton / 5% elastane', 10);
        $this->attachProductAttribute($product, 'garancija', 'Guarantee Label', 'Quality guarantee included', 30);

        $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            ])
            ->get('/product/'.$slug)
            ->assertOk()
            ->assertSeeInOrder(['Composition Label', 'Quality Label', 'Guarantee Label'])
            ->assertSee('95% cotton / 5% elastane')
            ->assertSee('Premium finish')
            ->assertSee('Quality guarantee included');
    }

    public function test_product_review_summary_links_render_on_detail_and_shop_cards(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        Comment::query()->create([
            'commentable_type' => Product::class,
            'commentable_id' => $product->id,
            'author_name' => 'Anna',
            'author_email' => 'anna@example.test',
            'locale' => 'en',
            'body' => 'Great fit and fabric.',
            'rating' => 5,
            'status' => Comment::STATUS_APPROVED,
            'is_featured' => true,
        ]);

        Comment::query()->create([
            'commentable_type' => Product::class,
            'commentable_id' => $product->id,
            'author_name' => 'Mia',
            'author_email' => 'mia@example.test',
            'locale' => 'en',
            'body' => 'Very comfortable.',
            'rating' => 4,
            'status' => Comment::STATUS_APPROVED,
            'is_featured' => false,
        ]);

        $this->get('/product/'.$slug)
            ->assertOk()
            ->assertSee('href="#product-comments"', false)
            ->assertSee('2 reviews');

        $this->get('/shop')
            ->assertOk()
            ->assertSee('/product/'.$slug.'#product-comments', false)
            ->assertSee('2 reviews');
    }

    public function test_desktop_product_detail_uses_size_guide_man_for_male_products_with_options(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedMaleCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $this->attachProductSizeOptions($product, ['M', 'L']);
        $this->seedSizeGuidePage('size-guide-man', 'Men size guide content');

        $this->get('/product/'.$slug)
            ->assertOk()
            ->assertSee('data-size-guide-open', false)
            ->assertSee('Men size guide content');
    }

    public function test_mobile_product_detail_renders_size_guide_when_options_exist(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $this->attachProductSizeOptions($product, ['S', 'M']);
        $this->seedSizeGuidePage('size-guide-women', 'Women size guide content');

        $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            ])
            ->get('/product/'.$slug)
            ->assertOk()
            ->assertSee('data-size-guide-open', false)
            ->assertSee('Women size guide content');
    }

    public function test_product_detail_hides_size_guide_when_product_has_no_options(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category] = $this->seedCategory();
        [$product, $slug] = $this->seedProduct($category->id);

        $this->seedSizeGuidePage('size-guide-women', 'Women size guide content');

        $this->get('/product/'.$slug)
            ->assertOk()
            ->assertDontSee('data-size-guide-open', false)
            ->assertDontSee('Women size guide content');
    }

    public function test_category_can_hide_filters_and_products_via_category_settings(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();
        [$product, $productSlug] = $this->seedProduct($category->id);

        $category->update([
            'payload' => [
                Category::PAYLOAD_SHOW_FILTERS => false,
                Category::PAYLOAD_SHOW_PRODUCTS => false,
            ],
        ]);

        $response = $this->get('/category/'.$categorySlug);

        $response
            ->assertOk()
            ->assertDontSee('aria-controls="category-mobile-filter-panel"', false)
            ->assertDontSee('data-desktop-filter-form', false)
            ->assertDontSee('data-catalog-grid', false)
            ->assertDontSee('Product '.$productSlug)
            ->assertDontSee((string) $product->sku);
    }

    public function test_category_page_price_filter_filters_products(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();
        [$cheapProduct, $cheapSlug] = $this->seedProduct($category->id);
        [$expensiveProduct, $expensiveSlug] = $this->seedProduct($category->id);

        $cheapProduct->update([
            'base_price' => 19.99,
        ]);

        $expensiveProduct->update([
            'base_price' => 89.99,
        ]);

        $this->get('/category/'.$categorySlug.'?price_min=50')
            ->assertOk()
            ->assertSee('data-price-filter-root', false)
            ->assertDontSee($cheapSlug)
            ->assertSee($expensiveSlug);
    }

    public function test_category_page_available_only_filter_hides_products_without_stock(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();
        [$availableProduct, $availableSlug] = $this->seedProduct($category->id);
        [$unavailableProduct, $unavailableSlug] = $this->seedProduct($category->id);

        $availableProduct->update(['stock_qty' => 3]);
        $unavailableProduct->update(['stock_qty' => 0]);

        $this->get('/category/'.$categorySlug.'?available_only=1')
            ->assertOk()
            ->assertSee('name="available_only"', false)
            ->assertSee($availableSlug)
            ->assertDontSee($unavailableSlug);
    }

    public function test_category_page_promo_only_filter_shows_only_products_with_active_sale_action(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();
        [$regularProduct, $regularSlug] = $this->seedProduct($category->id);
        [$promoProduct, $promoSlug] = $this->seedProduct($category->id);

        CatalogAction::query()->create([
            'code' => 'promo-'.strtolower((string) str()->random(6)),
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 20,
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ])->targets()->create([
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'target_id' => $promoProduct->id,
            'sort_order' => 0,
        ]);

        $this->get('/category/'.$categorySlug.'?promo_only=1')
            ->assertOk()
            ->assertSee('data-price-range-root', false)
            ->assertDontSee($regularSlug)
            ->assertSee($promoSlug);
    }

    public function test_category_page_disables_promo_toggle_when_no_promotional_products_are_available(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();
        [$product] = $this->seedProduct($category->id);

        $this->assertNotNull($product);

        $this->get('/category/'.$categorySlug)
            ->assertOk()
            ->assertSeeInOrder([
                'name="promo_only"',
                'disabled',
                'data-price-range-promo',
            ], false);
    }

    public function test_category_editorial_tiles_block_renders_on_targeted_category(): void
    {
        $this->useEnglishStorefrontLocale();
        [$category, $categorySlug] = $this->seedCategory();

        $block = ContentBlock::query()->create([
            'code' => 'category-editorial-test',
            'name' => 'Category Editorial Test',
            'type' => 'category_editorial_tiles',
            'is_active' => true,
            'payload' => null,
        ]);

        $block->translations()->create([
            'locale' => 'en',
            'title' => null,
            'subtitle' => null,
            'cta_label' => null,
            'cta_url' => null,
            'payload' => null,
        ]);

        $block->slots()->create([
            'placement' => 'category.top',
            'frontend_variant' => 'all',
            'target_type' => 'category',
            'target_ref' => $categorySlug,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $image = UploadedFile::fake()->image('tile-1.jpg', 900, 1200);

        $media = $block
            ->addMedia($image->getPathname())
            ->usingName('tile-1')
            ->usingFileName('tile-1.jpg')
            ->withCustomProperties([
                'block_title' => ['en' => 'Nightwear'],
                'link_url' => ['en' => '/category/nightwear'],
                'alt' => ['en' => 'Nightwear'],
            ])
            ->toMediaCollection('block_slides');

        $this->assertNotNull($media);

        $this->get('/category/'.$categorySlug)
            ->assertOk()
            ->assertSee('Nightwear')
            ->assertSee('/category/nightwear', false);
    }

    /**
     * @return array{Category,string}
     */
    private function seedCategory(): array
    {
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'cat-'.strtolower((string) str()->random(6)),
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 1,
        ]);

        $slug = 'category-'.strtolower((string) str()->random(6));

        CategoryTranslation::query()->create([
            'category_id' => $category->id,
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'en',
            'name' => 'Category '.$slug,
            'slug' => $slug,
            'description' => 'Category description',
        ]);

        return [$category, $slug];
    }

    /**
     * @return array{Category,string}
     */
    private function seedMaleCategory(): array
    {
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'men-'.strtolower((string) str()->random(6)),
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 1,
        ]);

        $slug = 'men-category-'.strtolower((string) str()->random(6));

        CategoryTranslation::query()->create([
            'category_id' => $category->id,
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'en',
            'name' => 'Men category',
            'slug' => $slug,
            'description' => 'Men category description',
        ]);

        return [$category, $slug];
    }

    /**
     * @return array{Product,string}
     */
    private function seedProduct(int $categoryId): array
    {
        $product = Product::query()->create([
            'code' => 'prod-'.strtolower((string) str()->random(6)),
            'sku' => 'SKU-'.strtoupper((string) str()->random(5)),
            'is_active' => true,
            'base_price' => 49.99,
            'stock_qty' => 15,
        ]);

        $slug = 'product-'.strtolower((string) str()->random(6));

        ProductTranslation::query()->create([
            'product_id' => $product->id,
            'locale' => 'en',
            'name' => 'Product '.$slug,
            'slug' => $slug,
            'excerpt' => 'Product excerpt',
            'description' => 'Product body',
        ]);

        $product->categories()->sync([$categoryId => ['sort_order' => 1, 'is_primary' => true]]);

        return [$product, $slug];
    }

    private function attachProductAttribute(Product $product, string $groupCode, string $groupName, string $name, int $sortOrder): Attribute
    {
        $attribute = Attribute::query()->create([
            'code' => $groupCode.'-'.strtolower((string) str()->random(6)),
            'group_code' => $groupCode,
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);

        $attribute->translations()->create([
            'locale' => 'en',
            'group_name' => $groupName,
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'description' => null,
            'payload' => null,
        ]);

        $product->attributes()->attach($attribute->id, [
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $attribute;
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function attachProductSizeOptions(Product $product, array $labels, array $stockByLabel = []): void
    {
        $option = Option::query()->create([
            'code' => 'size-'.strtolower((string) str()->random(6)),
            'type' => Option::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $option->translations()->create([
            'locale' => 'en',
            'name' => 'Size',
            'slug' => 'size-'.strtolower((string) str()->random(6)),
            'description' => null,
            'payload' => null,
        ]);

        $option->products()->attach($product->id, [
            'is_required' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (array_values($labels) as $index => $label) {
            $value = OptionValue::query()->create([
                'option_id' => $option->id,
                'code' => 'size-'.strtolower((string) str($label)->slug()->value()).'-'.strtolower((string) str()->random(4)),
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);

            $value->translations()->create([
                'locale' => 'en',
                'name' => $label,
                'slug' => str($label)->slug()->value().'-'.strtolower((string) str()->random(4)),
                'payload' => null,
            ]);

            ProductOptionValue::query()->create([
                'product_id' => $product->id,
                'option_value_id' => $value->id,
                'parent_option_value_id' => null,
                'mode' => 'single',
                'sku' => 'OPT-'.strtoupper((string) str()->random(4)),
                'stock_qty' => max(0, (int) ($stockByLabel[$label] ?? 5)),
                'price_override' => null,
                'sort_order' => $index + 1,
                'is_active' => true,
                'combination_hash' => hash('sha256', $product->id.'-'.$value->id.'-single'),
                'payload' => null,
            ]);
        }
    }

    private function createProductOption(string $name, bool $showOnProductPage = true): Option
    {
        $option = Option::query()->create([
            'code' => str($name)->slug()->value().'-'.strtolower((string) str()->random(6)),
            'type' => Option::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 1,
            'payload' => [
                Option::PAYLOAD_SHOW_ON_PRODUCT_PAGE => $showOnProductPage,
            ],
        ]);

        $option->translations()->create([
            'locale' => 'en',
            'name' => $name,
            'slug' => str($name)->slug()->value().'-'.strtolower((string) str()->random(6)),
            'description' => null,
            'payload' => null,
        ]);

        return $option;
    }

    private function attachOptionValueToProduct(Product $product, Option $option, string $label, int $sortOrder = 1): OptionValue
    {
        $option->products()->syncWithoutDetaching([
            $product->id => [
                'is_required' => true,
                'sort_order' => $sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $value = OptionValue::query()->create([
            'option_id' => $option->id,
            'code' => str($option->code.'-'.$label)->slug()->value().'-'.strtolower((string) str()->random(4)),
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);

        $value->translations()->create([
            'locale' => 'en',
            'name' => $label,
            'slug' => str($label)->slug()->value().'-'.strtolower((string) str()->random(4)),
            'payload' => null,
        ]);

        ProductOptionValue::query()->create([
            'product_id' => $product->id,
            'option_value_id' => $value->id,
            'parent_option_value_id' => null,
            'mode' => 'single',
            'sku' => 'OPT-'.strtoupper((string) str()->random(4)),
            'stock_qty' => 5,
            'price_override' => null,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'combination_hash' => hash('sha256', $product->id.'-'.$value->id.'-single'),
            'payload' => null,
        ]);

        return $value;
    }

    private function seedSizeGuidePage(string $code, string $bodyHtml): void
    {
        $page = InfoPage::query()->create([
            'code' => $code,
            'layout' => 'default',
            'is_active' => true,
            'published_at' => now()->subDay(),
            'sort_order' => 1,
        ]);

        InfoPageTranslation::query()->create([
            'page_id' => $page->id,
            'locale' => 'en',
            'title' => 'Size guide',
            'slug' => $code,
            'excerpt' => null,
            'body_html' => '<p>'.$bodyHtml.'</p>',
        ]);
    }

    private function createAccountOrder(User $user, string $number): Order
    {
        $status = OrderStatus::query()->create([
            'code' => 'new-'.strtolower($number),
            'name' => 'New',
            'description' => null,
            'color' => 'slate',
            'is_default' => true,
            'is_paid' => false,
            'is_cancelled' => false,
            'is_active' => true,
            'sort_order' => 1,
            'settings' => null,
        ]);

        return Order::query()->create([
            'order_number' => $number,
            'status_id' => $status->id,
            'user_id' => $user->id,
            'source' => 'web',
            'locale' => 'en',
            'currency_code' => 'EUR',
            'currency_rate' => 1,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'customer_phone' => '+38591000111',
            'billing_first_name' => 'Test',
            'billing_last_name' => 'User',
            'billing_address_line_1' => 'Street 1',
            'billing_postal_code' => '10000',
            'billing_city' => 'Zagreb',
            'billing_country_code' => 'HR',
            'shipping_first_name' => 'Test',
            'shipping_last_name' => 'User',
            'shipping_address_line_1' => 'Street 1',
            'shipping_postal_code' => '10000',
            'shipping_city' => 'Zagreb',
            'shipping_country_code' => 'HR',
            'payment_method_code' => 'bank',
            'payment_method_name' => 'Bank Transfer',
            'shipping_method_code' => 'standard',
            'shipping_method_name' => 'Standard Shipping',
            'item_qty' => 1,
            'subtotal' => 99.90,
            'shipping_total' => 4.99,
            'payment_fee_total' => 0,
            'discount_total' => 0,
            'tax_total' => 20,
            'grand_total' => 124.89,
            'customer_note' => null,
            'admin_note' => null,
            'payload' => null,
            'placed_at' => now(),
            'paid_at' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    private function useEnglishStorefrontLocale(): void
    {
        config([
            'app.locale' => 'en',
            'app.fallback_locale' => 'en',
        ]);

        app()->setLocale('en');
    }

    private function createNewsletterSignupsTable(): void
    {
        if (Schema::hasTable('newsletter_signups')) {
            return;
        }

        Schema::create('newsletter_signups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->unique();
            $table->string('source', 50)->default('footer');
            $table->string('locale', 12)->default('hr');
            $table->string('provider', 20)->default('none')->index();
            $table->string('sync_status', 20)->default('skipped')->index();
            $table->boolean('consent_accepted')->default(false);
            $table->string('provider_reference')->nullable();
            $table->text('provider_error')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('subscribed_at')->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array{Manufacturer,string}
     */
    private function seedManufacturer(): array
    {
        $manufacturer = Manufacturer::query()->create([
            'code' => 'man-'.strtolower((string) str()->random(6)),
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $slug = 'manufacturer-'.strtolower((string) str()->random(6));

        ManufacturerTranslation::query()->create([
            'manufacturer_id' => $manufacturer->id,
            'locale' => 'en',
            'name' => 'Manufacturer '.$slug,
            'slug' => $slug,
            'description' => 'Manufacturer description',
        ]);

        return [$manufacturer, $slug];
    }

    /**
     * @return array{BlogPost,string}
     */
    private function seedBlogPost(): array
    {
        $post = BlogPost::query()->create([
            'code' => 'blog-'.strtolower((string) str()->random(6)),
            'is_active' => true,
            'published_at' => now()->subDay(),
            'sort_order' => 1,
        ]);

        $slug = 'blog-'.strtolower((string) str()->random(6));

        BlogPostTranslation::query()->create([
            'post_id' => $post->id,
            'locale' => 'en',
            'title' => 'Blog '.$slug,
            'slug' => $slug,
            'excerpt' => 'Blog excerpt',
            'body_html' => '<p>Blog body</p>',
        ]);

        return [$post, $slug];
    }

    /**
     * @return array{InfoPage,string}
     */
    private function seedInfoPage(): array
    {
        $page = InfoPage::query()->create([
            'code' => 'page-'.strtolower((string) str()->random(6)),
            'layout' => 'default',
            'is_active' => true,
            'published_at' => now()->subDay(),
            'sort_order' => 1,
        ]);

        $slug = 'page-'.strtolower((string) str()->random(6));

        InfoPageTranslation::query()->create([
            'page_id' => $page->id,
            'locale' => 'en',
            'title' => 'Page '.$slug,
            'slug' => $slug,
            'excerpt' => 'Page excerpt',
            'body_html' => '<p>Page body</p>',
        ]);

        return [$page, $slug];
    }
}
