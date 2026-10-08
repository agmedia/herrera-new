<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CustomerPasswordBrokerIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_email_customer_recovery_never_sends_staff_recovery(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['email' => 'shared@example.test']);
        $staff = User::factory()->create(['email' => $customer->email, 'account_type' => 'staff']);

        Volt::test('pages.auth.forgot-password')->set('email', $customer->email)
            ->call('sendPasswordResetLink')->assertHasNoErrors();

        Notification::assertSentTo($customer, ResetPassword::class);
        Notification::assertNotSentTo($staff, ResetPassword::class);
    }

    public function test_public_broker_cannot_resolve_staff_even_with_explicit_staff_scope_or_staff_only_email(): void
    {
        Notification::fake();
        $staff = User::factory()->create(['account_type' => 'staff']);

        $this->assertNull(Password::broker()->getUser(['email' => $staff->email]));
        $this->assertNull(Password::broker()->getUser(['email' => $staff->email, 'account_type' => 'staff']));
        $this->assertSame(Password::INVALID_USER, Password::sendResetLink(['email' => $staff->email]));
        Notification::assertNothingSent();
    }

    public function test_customer_token_cannot_be_reused_to_reset_same_email_staff_but_still_resets_customer(): void
    {
        $customer = User::factory()->create(['email' => 'shared@example.test', 'password' => 'Customer-original123!']);
        $staff = User::factory()->create(['email' => $customer->email, 'account_type' => 'staff', 'password' => 'Staff-original123!']);
        $token = Password::createToken($customer);
        $credentials = [
            'email' => $customer->email, 'token' => $token,
            'password' => 'Customer-reset123!', 'password_confirmation' => 'Customer-reset123!',
        ];
        $reset = static function (User $user, string $password): void {
            $user->forceFill(['password' => $password])->save();
        };

        $this->assertSame(Password::INVALID_USER, Password::reset($credentials + ['account_type' => 'staff'], $reset));
        $this->assertTrue(Hash::check('Staff-original123!', $staff->fresh()->password));
        $this->assertSame(Password::PASSWORD_RESET, Password::reset($credentials, $reset));
        $this->assertTrue(Hash::check('Customer-reset123!', $customer->fresh()->password));
        $this->assertTrue(Hash::check('Staff-original123!', $staff->fresh()->password));
    }
}
