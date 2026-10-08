<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CommercePagePresentationTest extends TestCase
{
    use RefreshDatabase;

    private const DESKTOP_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    public function test_desktop_cart_uses_the_wide_commerce_layout_and_shared_styles(): void
    {
        $this
            ->withHeaders(['User-Agent' => self::DESKTOP_USER_AGENT])
            ->get('/cart')
            ->assertOk()
            ->assertSee('commerce-body cart-commerce-body', false)
            ->assertSee('class="commerce-main"', false)
            ->assertSee('front-theme/styles/commerce-pages.css', false)
            ->assertSee('front-theme/scripts/cart-quantity.js', false)
            ->assertSee('commerce-primary-action', false);
    }

    public function test_desktop_account_pages_use_accessible_commerce_navigation_and_forms(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this
            ->actingAs($user)
            ->withHeaders(['User-Agent' => self::DESKTOP_USER_AGENT])
            ->get('/account')
            ->assertOk()
            ->assertSee('commerce-body account-commerce-body', false)
            ->assertSee('class="account-layout"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('front-theme/styles/commerce-pages.css', false);

        $this
            ->actingAs($user)
            ->withHeaders(['User-Agent' => self::DESKTOP_USER_AGENT])
            ->get('/account/profile')
            ->assertOk()
            ->assertSee('for="account-email"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('commerce-primary-action', false);
    }

    public function test_cart_and_checkout_reserve_square_space_for_portrait_product_thumbnails(): void
    {
        $product = Product::query()->create(['code' => 'STABLE-THUMB', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 5]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Portrait thumbnail', 'slug' => 'portrait-thumbnail']);
        $product->addMedia(UploadedFile::fake()->image('portrait-thumb.jpg', 200, 300))->toMediaCollection('product_main');
        $this->withSession(['front.cart.items' => [$product->id.':0' => ['product_id' => $product->id, 'product_option_value_id' => null, 'quantity' => 1]]]);

        foreach (['/cart' => 2, '/checkout' => 1] as $url => $expectedImages) {
            $response = $this->get($url)->assertOk();
            $dom = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $images = (new \DOMXPath($dom))->query('//*[contains(concat(" ", normalize-space(@class), " "), " commerce-product-thumb ")]//img');
            $this->assertSame($expectedImages, $images->count());
            foreach ($images as $thumbnail) {
                $this->assertSame('100', $thumbnail->getAttribute('width'));
                $this->assertSame('100', $thumbnail->getAttribute('height'));
                $this->assertStringContainsString('portrait-thumb', $thumbnail->getAttribute('src'));
            }
        }
    }

    public function test_return_request_form_uses_the_shared_commerce_form_treatment(): void
    {
        $this
            ->withHeaders(['User-Agent' => self::DESKTOP_USER_AGENT])
            ->get('/forma-za-povrat-i-reklamacije')
            ->assertOk()
            ->assertSee('commerce-body returns-commerce-body', false)
            ->assertSee('class="returns-layout"', false)
            ->assertSee('returns-form-card', false)
            ->assertSee('commerce-primary-action', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('front-theme/styles/commerce-pages.css', false);
    }

    public function test_login_register_and_contact_forms_use_the_shared_form_background(): void
    {
        foreach ([
            '/auth/login' => 'auth-form-card',
            '/auth/register' => 'auth-form-card',
            '/contact' => 'contact-form-card',
        ] as $url => $cardClass) {
            $this
                ->withHeaders(['User-Agent' => self::DESKTOP_USER_AGENT])
                ->get($url)
                ->assertOk()
                ->assertSee($cardClass, false)
                ->assertSee('commerce-primary-action', false)
                ->assertSee('front-theme/styles/commerce-pages.css', false);
        }
    }
}
