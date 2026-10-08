<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\B2BAccountManager;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class B2BAccountManagerFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_component_alias_wins_over_volt_when_class_convention_cannot_autoload(): void
    {
        // Linux cannot autoload B2BAccountManager.php as the convention-generated
        // B2bAccountManager. Force that missing-class path on every filesystem;
        // Volt still finds the matching Blade view and would replace the class.
        config(['livewire.class_namespace' => 'App\\MissingConventionLivewire']);
        $this->assertInstanceOf(B2BAccountManager::class, Livewire::new('admin.user.b2b-account-manager'));

        $admin = $this->user('superadmin');
        $this->account($this->user('customer'), ['company_name' => 'Linux resolution company']);
        $this->actingAs($admin)->get(route('admin.users.b2b'))->assertOk()
            ->assertSee('Linux resolution company')->assertSee('B2B zahtjevi i računi');
    }

    public function test_populated_pending_accounts_render_without_exposing_privileged_impersonation_actions(): void
    {
        $admin = $this->user('superadmin');
        $customer = $this->user('customer');
        $privileged = $this->user('editor');
        $this->account($customer, ['company_name' => 'Pending customer company']);
        $this->account($privileged, ['company_name' => 'Pending privileged company']);

        $this->actingAs($admin)->get(route('admin.users.b2b'))->assertOk()
            ->assertSee('Pending customer company')->assertSee('Pending privileged company')
            ->assertSee('action="'.route('admin.users.impersonate', ['user' => $customer->id]).'"', false)
            ->assertDontSee('action="'.route('admin.users.impersonate', ['user' => $privileged->id]).'"', false);
    }

    public function test_approved_account_can_be_found_and_opened_from_its_deep_link(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');
        $group = CustomerGroup::query()->create(['code' => 'b2b-manager', 'name' => 'Manager price group', 'is_active' => true]);
        $account = $this->account($customer, [
            'status' => B2BAccount::STATUS_APPROVED,
            'company_name' => 'Approved customer company',
            'customer_group_id' => $group->id,
            'contract_number' => 'MANAGER-42',
            'contract_starts_at' => now()->subDay(),
            'contract_ends_at' => now()->addYear(),
        ]);

        Livewire::actingAs($admin)->test(B2BAccountManager::class)
            ->set('statusFilter', 'all')->set('search', 'Approved customer company')
            ->assertSee('Approved customer company')->assertSee('Manager price group');
        $this->actingAs($admin)->get(route('admin.users.b2b', ['account' => $account->id]))->assertOk()
            ->assertSee('Obrada B2B računa')->assertSee('MANAGER-42');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }

    private function account(User $user, array $attributes = []): B2BAccount
    {
        return B2BAccount::query()->create(array_merge([
            'user_id' => $user->id,
            'status' => B2BAccount::STATUS_PENDING,
            'company_name' => 'Pending test company',
            'oib' => str_pad((string) $user->id, 11, '0', STR_PAD_LEFT),
            'country_code' => 'HR',
            'requested_at' => now(),
        ], $attributes));
    }
}
