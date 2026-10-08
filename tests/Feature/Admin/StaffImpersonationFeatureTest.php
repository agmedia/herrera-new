<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class StaffImpersonationFeatureTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('authorizedActors')]
    public function test_authorized_actor_can_preview_only_order_manager_permissions_and_return(string $role): void
    {
        $admin = $this->user($role);
        if ($role === 'access_manager') {
            Bouncer::allow($admin)->to(['admin.access', 'users.access.manage', 'users.list.view']);
        }
        $staff = $this->user('order_manager');

        $this->actingAs($admin)->post(route('admin.users.staff-impersonation.start', $staff))
            ->assertRedirect(route('admin.orders'))
            ->assertSessionHas('admin.staff_impersonation.admin_id', $admin->id)
            ->assertSessionHas('admin.staff_impersonation.staff_id', $staff->id);

        $this->assertAuthenticatedAs($staff);
        $this->get(route('admin.orders'))->assertOk();
        $this->get(route('admin.users.access'))->assertForbidden();

        $this->post(route('admin.staff-impersonation.stop'))
            ->assertRedirect(route('admin.users'))
            ->assertSessionMissing('admin.staff_impersonation');
        $this->assertAuthenticatedAs($admin);

        $events = Activity::query()->where('log_name', 'staff-support')->orderBy('id')->get();
        $this->assertSame(['staff_impersonation.started', 'staff_impersonation.stopped'], $events->pluck('event')->all());
        foreach ($events as $event) {
            $this->assertSame($admin->id, $event->causer_id);
            $this->assertSame($staff->id, $event->subject_id);
            $this->assertSame($admin->id, $event->properties->get('original_admin_id'));
        }
    }

    public static function authorizedActors(): array
    {
        return [['superadmin'], ['access_manager']];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_other_accounts_cannot_start_staff_preview(string $role): void
    {
        $actor = $this->user($role);
        $staff = $this->user('order_manager');

        $this->actingAs($actor)->post(route('admin.users.staff-impersonation.start', $staff))
            ->assertForbidden()->assertSessionMissing('admin.staff_impersonation');
        $this->assertAuthenticatedAs($actor);
    }

    public static function unauthorizedActors(): array
    {
        return [['admin'], ['editor'], ['order_manager'], ['customer']];
    }

    #[DataProvider('invalidTargets')]
    public function test_privileged_nonstaff_inactive_and_self_targets_are_blocked(string $reason): void
    {
        $admin = $this->user('superadmin');
        $staff = $reason === 'self' ? $admin : $this->user('order_manager');
        if (in_array($reason, ['superadmin', 'admin', 'editor'], true)) {
            Bouncer::assign($reason)->to($staff);
        } elseif ($reason === 'inactive') {
            $staff->forceFill(['admin_login_enabled' => false])->save();
        } elseif ($reason === 'customer') {
            $staff->forceFill(['account_type' => 'customer'])->save();
        } elseif ($reason === 'access_manager') {
            Bouncer::allow($staff)->to('users.access.manage');
        } elseif ($reason === 'other_staff') {
            Bouncer::retract('order_manager')->from($staff);
        }

        $this->actingAs($admin)->post(route('admin.users.staff-impersonation.start', $staff))
            ->assertForbidden()->assertSessionMissing('admin.staff_impersonation');
        $this->assertAuthenticatedAs($admin);
    }

    public static function invalidTargets(): array
    {
        return [['superadmin'], ['admin'], ['editor'], ['inactive'], ['customer'], ['access_manager'], ['other_staff'], ['self']];
    }

    public function test_nested_staff_and_customer_previews_are_blocked_without_losing_original_identity(): void
    {
        $admin = $this->user('superadmin');
        $staff = $this->user('order_manager');
        $otherStaff = $this->user('order_manager');
        $customer = $this->user('customer');
        $this->actingAs($admin)->post(route('admin.users.staff-impersonation.start', $staff))->assertRedirect();

        $this->post(route('admin.users.staff-impersonation.start', $otherStaff))->assertStatus(409);
        $this->post(route('admin.users.impersonate', $customer))->assertStatus(409);
        $this->assertSame($admin->id, session('admin.staff_impersonation.admin_id'));
        $this->assertAuthenticatedAs($staff);
    }

    public function test_start_rotates_session_isolates_cart_and_stop_restores_original_state_and_remember_tokens(): void
    {
        $admin = $this->user('superadmin');
        $staff = $this->user('order_manager');
        $admin->forceFill(['remember_token' => 'original-admin-remember'])->save();
        $staff->forceFill(['remember_token' => 'original-staff-remember'])->save();
        $front = ['cart' => ['items' => ['41:0' => ['quantity' => 3]]], 'checkout' => ['last_order_id' => 25]];
        $oldSession = session()->getId();

        $this->actingAs($admin)->withSession(['front' => $front, 'auth.password_confirmed_at' => now()->timestamp])
            ->post(route('admin.users.staff-impersonation.start', $staff))
            ->assertSessionMissing('front')->assertSessionMissing('auth.password_confirmed_at');
        $this->assertNotSame($oldSession, session()->getId());

        $previewSession = session()->getId();
        $this->withSession(['front' => ['cart' => ['staff-preview-cart']]])
            ->post(route('admin.staff-impersonation.stop'))
            ->assertSessionHas('front', $front)->assertSessionMissing('admin.staff_impersonation');
        $this->assertNotSame($previewSession, session()->getId());
        $this->assertSame('original-admin-remember', $admin->fresh()->remember_token);
        $this->assertSame('original-staff-remember', $staff->fresh()->remember_token);
        $this->assertAuthenticatedAs($admin);
    }

    #[DataProvider('unavailableOriginals')]
    public function test_original_account_is_not_restored_if_deleted_disabled_or_permissions_revoked(string $reason): void
    {
        $admin = $this->user('superadmin');
        $staff = $this->user('order_manager');
        $this->actingAs($admin)->post(route('admin.users.staff-impersonation.start', $staff))->assertRedirect();
        if ($reason === 'deleted') {
            $admin->delete();
        } elseif ($reason === 'disabled') {
            $admin->forceFill(['admin_login_enabled' => false])->save();
        } else {
            Bouncer::retract('superadmin')->from($admin);
            Bouncer::refreshFor($admin);
        }

        $this->post(route('admin.staff-impersonation.stop'))
            ->assertRedirect(route('login'))->assertSessionMissing('admin.staff_impersonation');
        $this->assertGuest();
        $this->assertFalse(Activity::query()->where('event', 'staff_impersonation.stopped')->firstOrFail()->properties->get('restored'));
    }

    public static function unavailableOriginals(): array
    {
        return [['deleted'], ['disabled'], ['revoked']];
    }

    #[DataProvider('unavailableTargets')]
    public function test_return_stays_available_when_preview_target_is_disabled_or_deleted(string $reason): void
    {
        $admin = $this->user('superadmin');
        $staff = $this->user('order_manager');
        $this->actingAs($admin)->post(route('admin.users.staff-impersonation.start', $staff))->assertRedirect();
        if ($reason === 'deleted') {
            $staff->delete();
        } else {
            $staff->forceFill(['admin_login_enabled' => false])->save();
        }
        Auth::forgetGuards();

        $this->post(route('admin.staff-impersonation.stop'))
            ->assertRedirect(route('admin.users'))->assertSessionMissing('admin.staff_impersonation');
        $this->assertAuthenticatedAs($admin);
    }

    public static function unavailableTargets(): array
    {
        return [['deleted'], ['disabled']];
    }

    public function test_return_refuses_missing_or_mismatched_session_identity_and_get_requests(): void
    {
        $admin = $this->user('superadmin');
        $staff = $this->user('order_manager');
        $otherStaff = $this->user('order_manager');
        $this->actingAs($admin)->post(route('admin.staff-impersonation.stop'))->assertStatus(409);
        $this->post(route('admin.users.staff-impersonation.start', $staff))->assertRedirect();
        $this->actingAs($otherStaff)->post(route('admin.staff-impersonation.stop'))->assertForbidden();
        $this->assertSame($admin->id, session('admin.staff_impersonation.admin_id'));
        $this->get(route('admin.staff-impersonation.stop'))->assertNotFound();
        $this->get(route('admin.users.staff-impersonation.start', $staff))->assertNotFound();
    }

    public function test_http_and_livewire_logout_restore_original_administrator(): void
    {
        $admin = $this->user('superadmin');
        $staff = $this->user('order_manager');
        $this->actingAs($admin)->post(route('admin.users.staff-impersonation.start', $staff))->assertRedirect();
        $this->post(route('logout'))->assertRedirect(route('admin.users'));
        $this->assertAuthenticatedAs($admin);

        $this->post(route('admin.users.staff-impersonation.start', $staff))->assertRedirect();
        Volt::test('layout.navigation')->call('logout')->assertRedirect(route('admin.users'));
        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('admin.staff_impersonation'));
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['account_type' => $role === 'customer' ? 'customer' : 'staff']);
        Bouncer::role()->firstOrCreate(['name' => $role]);
        Bouncer::assign($role)->to($user);

        return $user;
    }
}
