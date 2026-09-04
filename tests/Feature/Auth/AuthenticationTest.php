<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.login');
    }

    public function test_login_screen_uses_the_configured_store_logo_and_recaptcha(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Test Shop',
            'store_brand_logo_path' => 'store-settings/test-shop-logo.png',
            'store_captcha_recaptcha_v3_enabled' => true,
            'store_captcha_recaptcha_v3_site_key' => 'test-site-key',
            'store_captcha_recaptcha_v3_secret_key' => 'test-secret-key',
            'store_captcha_recaptcha_v3_min_score' => 0.7,
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('data-store-brand-logo', false)
            ->assertSee('store-settings/test-shop-logo.png', false)
            ->assertSee('data-recaptcha-site-key="test-site-key"', false)
            ->assertSee('data-recaptcha-action="login_form"', false)
            ->assertSee('https://www.google.com/recaptcha/api.js?render=test-site-key', false);
    }

    public function test_login_validates_the_enabled_recaptcha(): void
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

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->set('recaptchaToken', 'login-token')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
            && $request['secret'] === 'test-secret-key'
            && $request['response'] === 'login-token');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_can_stay_signed_in_with_remember_me(): void
    {
        $user = User::factory()->create([
            'remember_token' => null,
        ]);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->set('form.remember', true)
            ->call('login')
            ->assertHasNoErrors()
            ->assertSet('form.remember', true)
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty($user->fresh()->getRememberToken());
        $this->assertTrue(Cookie::hasQueued(Auth::guard('web')->getRecallerName()));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password');

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_admin_dashboard_can_be_rendered_via_dashboard_redirect(): void
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertRedirect('/admin/dashboard');

        $adminResponse = $this->get('/admin');
        $adminResponse->assertRedirect('/admin/dashboard');

        $dashboardResponse = $this->get('/admin/dashboard');
        $dashboardResponse->assertOk()->assertSee(__('Sales Overview'));
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('layout.navigation');

        $component->call('logout');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
