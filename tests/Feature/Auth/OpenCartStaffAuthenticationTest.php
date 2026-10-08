<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\User\LegacyCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class OpenCartStaffAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('staffIdentities')]
    public function test_admin_login_resolves_staff_by_username_or_email_without_merging_customer(string $identity): void
    {
        $customer = User::factory()->create(['email' => 'shared@example.test', 'password' => 'Customer-password123!']);
        $staff = $this->staff(['email' => $customer->email, 'password' => 'Staff-password123!']);

        Volt::test('pages.auth.login')
            ->set('form.email', $identity === 'username' ? '  OPERATOR  ' : 'SHARED@example.test')
            ->set('form.password', 'Staff-password123!')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($staff);
        $this->assertTrue(Hash::check('Customer-password123!', $customer->fresh()->password));
    }

    public static function staffIdentities(): array
    {
        return [['username'], ['email']];
    }

    #[DataProvider('legacyStaffLogins')]
    public function test_original_admin_password_is_upgraded_through_actual_login(string $algorithm, string $identity): void
    {
        $staff = $this->staff(['email' => 'staff@example.test']);
        $legacy = $this->legacy($staff, $algorithm);

        Volt::test('pages.auth.login')
            ->set('form.email', $identity === 'username' ? $staff->admin_username : $staff->email)
            ->set('form.password', 'Original-password123!')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($staff);
        $this->assertTrue(Hash::check('Original-password123!', $staff->fresh()->password));
        $this->assertNotNull($legacy->fresh()->retired_at);
        $this->assertNull($legacy->fresh()->legacy_hash);
        $this->assertNull($legacy->fresh()->legacy_salt);
    }

    public static function legacyStaffLogins(): array
    {
        return [['salted_sha1', 'username'], ['salted_sha1', 'email'], ['md5', 'username'], ['md5', 'email']];
    }

    public function test_storefront_email_uses_customer_and_never_tries_same_email_staff_password(): void
    {
        $customer = User::factory()->create(['email' => 'shared@example.test', 'password' => 'Customer-password123!']);
        $this->staff(['email' => $customer->email, 'password' => 'Staff-password123!']);

        $this->post(route('front.auth.login.store'), [
            'email' => $customer->email, 'password' => 'Staff-password123!',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('front.auth.login.store'), [
            'email' => $customer->email, 'password' => 'Customer-password123!',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($customer);
    }

    public function test_storefront_preserves_staff_email_login_when_no_customer_exists(): void
    {
        $staff = $this->staff(['password' => 'Staff-password123!']);

        $this->post(route('front.auth.login.store'), [
            'email' => $staff->email, 'password' => 'Staff-password123!',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertAuthenticatedAs($staff);
    }

    public function test_customer_password_cannot_authenticate_in_admin_login(): void
    {
        $customer = User::factory()->create(['password' => 'Customer-password123!']);

        Volt::test('pages.auth.login')
            ->set('form.email', $customer->email)
            ->set('form.password', 'Customer-password123!')
            ->call('login')
            ->assertHasErrors('form.email')
            ->assertNoRedirect();

        $this->assertGuest();
    }

    #[DataProvider('disabledPasswords')]
    public function test_disabled_staff_cannot_use_native_or_legacy_password(string $password): void
    {
        $staff = $this->staff(['password' => 'Native-password123!', 'admin_login_enabled' => false]);
        $legacy = $this->legacy($staff, 'salted_sha1');

        Volt::test('pages.auth.login')
            ->set('form.email', $staff->admin_username)
            ->set('form.password', $password)
            ->call('login')
            ->assertHasErrors('form.email');

        $this->assertFalse(Auth::getProvider()->validateCredentials($staff, ['password' => $password]));
        $this->assertFalse(Auth::attempt(['email' => $staff->email, 'password' => $password]));
        $this->assertGuest();
        $this->assertNull($legacy->fresh()->retired_at);
        $this->assertTrue(Hash::check('Native-password123!', $staff->fresh()->password));
    }

    public static function disabledPasswords(): array
    {
        return [['Native-password123!'], ['Original-password123!']];
    }

    public function test_disabled_staff_loses_session_remember_and_admin_access_even_with_superadmin_role(): void
    {
        $staff = $this->staff(['remember_token' => 'existing-remember-token']);
        Bouncer::assign('superadmin')->to($staff);
        $staff->forceFill(['admin_login_enabled' => false])->save();

        $this->assertNull(Auth::getProvider()->retrieveById($staff->id));
        $this->assertNull(Auth::getProvider()->retrieveByToken($staff->id, 'existing-remember-token'));

        $this->actingAs($staff)->get('/admin/dashboard')->assertForbidden();
    }

    public function test_staff_profile_password_change_retires_legacy_and_does_not_change_same_email_customer(): void
    {
        $customer = User::factory()->create(['email' => 'shared@example.test', 'password' => 'Customer-password123!']);
        $staff = $this->staff(['email' => $customer->email]);
        $legacy = $this->legacy($staff, 'salted_sha1');
        $staff->forceFill(['password' => 'Reset-password123!'])->save();
        $this->assertNotNull($legacy->fresh()->retired_at);
        $this->assertNull($legacy->fresh()->legacy_hash);
        $this->assertTrue(Hash::check('Customer-password123!', $customer->fresh()->password));
        $this->assertFalse(Auth::attempt(['login' => $staff->admin_username, 'account_type' => 'staff', 'password' => 'Original-password123!']));
        $this->assertTrue(Auth::attempt(['login' => $staff->admin_username, 'account_type' => 'staff', 'password' => 'Reset-password123!']));
    }

    public function test_migration_classifies_existing_privileged_users_without_changing_passwords(): void
    {
        $staff = User::factory()->create();
        $customer = User::factory()->create();
        Bouncer::assign('admin')->to($staff);
        $nativePassword = $staff->password;
        $migration = require database_path('migrations/2026_10_08_140000_add_staff_identity_to_users.php');

        $migration->down();
        $migration->up();

        $this->assertSame('staff', $staff->fresh()->account_type);
        $this->assertSame('customer', $customer->fresh()->account_type);
        $this->assertSame($nativePassword, $staff->fresh()->password);
        $this->assertTrue($staff->fresh()->admin_login_enabled);
        $this->assertNull($staff->fresh()->admin_username);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['account_type' => 'staff', 'admin_username' => 'operator']);
    }

    private function legacy(User $staff, string $algorithm): LegacyCredential
    {
        $salt = 'TestLegacySalt';
        $password = 'Original-password123!';

        return LegacyCredential::query()->create([
            'user_id' => $staff->id, 'enabled' => true,
            'legacy_hash' => $algorithm === 'md5' ? md5($password) : sha1($salt.sha1($salt.sha1($password))),
            'legacy_salt' => $salt,
        ]);
    }
}
