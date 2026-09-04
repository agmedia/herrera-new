<?php

namespace Tests\Feature\Front;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
