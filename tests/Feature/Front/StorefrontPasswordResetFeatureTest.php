<?php

namespace Tests\Feature\Front;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StorefrontPasswordResetFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_login_links_to_the_password_reset_request(): void
    {
        $this->get(route('front.auth.login'))
            ->assertOk()
            ->assertSee(route('front.auth.password.request'), false)
            ->assertSee(__('ui.auth.login.forgot_password'));
    }

    public function test_password_reset_request_renders_in_the_responsive_storefront(): void
    {
        $this->get(route('front.auth.password.request'))
            ->assertOk()
            ->assertSee('auth-form-card', false)
            ->assertSee(__('ui.auth.forgot.form_title'));

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile')
            ->get(route('front.auth.password.request'))
            ->assertOk()
            ->assertSee('auth-form-card', false)
            ->assertSee(__('ui.auth.forgot.form_title'));

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile')
            ->get(route('front.auth.password.reset', [
                'token' => 'preview-token',
                'email' => 'customer@example.test',
            ]))
            ->assertOk()
            ->assertSee('auth-form-card', false)
            ->assertSee(__('ui.auth.reset.form_title'));
    }

    public function test_customer_can_request_link_and_reset_password_through_storefront(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $token = null;

        $this->post(route('front.auth.password.email'), [
            'email' => $user->email,
        ])
            ->assertRedirect()
            ->assertSessionHas('status', __('ui.auth.forgot.status'));

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use ($user, &$token): bool {
                $token = $notification->token;
                $resetUrl = (string) $notification->toMail($user)->actionUrl;

                $this->assertStringContainsString('/auth/reset-password/'.$token, $resetUrl);
                $this->assertStringContainsString('email='.urlencode($user->email), $resetUrl);

                return true;
            }
        );

        $this->assertNotNull($token);

        $this->get(route('front.auth.password.reset', [
            'token' => $token,
            'email' => $user->email,
        ]))
            ->assertOk()
            ->assertSee(__('ui.auth.reset.form_title'));

        $this->post(route('front.auth.password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'New-secure-password-123!',
            'password_confirmation' => 'New-secure-password-123!',
        ])
            ->assertRedirect(route('front.auth.login'))
            ->assertSessionHas('status', __('ui.auth.reset.status'));

        $this->assertTrue(Hash::check('New-secure-password-123!', $user->fresh()->password));
    }

    public function test_password_reset_request_does_not_reveal_unknown_accounts(): void
    {
        Notification::fake();

        $this->post(route('front.auth.password.email'), [
            'email' => 'unknown@example.test',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', __('ui.auth.forgot.status'))
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }
}
