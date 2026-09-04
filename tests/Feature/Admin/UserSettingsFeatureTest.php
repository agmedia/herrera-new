<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Settings\User\UserFeatures;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class UserSettingsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_user_settings_page(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin/settings/user')
            ->assertOk()
            ->assertSee(__('User Settings'));
    }

    public function test_editor_cannot_open_user_settings_page(): void
    {
        $editor = $this->makeUserWithRole('editor');

        $this->actingAs($editor)
            ->get('/admin/settings/user')
            ->assertForbidden();
    }

    public function test_loyalty_is_disabled_by_default_and_only_its_switch_is_shown(): void
    {
        $admin = $this->makeUserWithRole('admin');

        Livewire::actingAs($admin)
            ->test(UserFeatures::class)
            ->assertSet('form.user_loyalty_enabled', false)
            ->assertSet('form.loyalty_currency_value_per_point', 0.01)
            ->assertSet('form.loyalty_customer_group_ids', [])
            ->assertSee(__('Loyalty System'))
            ->assertDontSee(__('Loyalty Rules'))
            ->assertDontSee(__('Points Per Currency Unit'));
    }

    public function test_loyalty_rules_are_shown_when_feature_is_enabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', true);

        $admin = $this->makeUserWithRole('admin');

        Livewire::actingAs($admin)
            ->test(UserFeatures::class)
            ->assertSet('form.user_loyalty_enabled', true)
            ->assertSee(__('Loyalty System'))
            ->assertSee(__('Loyalty Rules'))
            ->assertSee(__('Points Per Currency Unit'));
    }

    public function test_admin_can_select_and_persist_eligible_loyalty_customer_groups(): void
    {
        $retail = CustomerGroup::query()->create([
            'code' => 'retail',
            'name' => 'Retail Customers',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 10,
        ]);
        $b2b = CustomerGroup::query()->create([
            'code' => 'b2b',
            'name' => 'B2B Customers',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 20,
        ]);
        $admin = $this->makeUserWithRole('admin');

        Livewire::actingAs($admin)
            ->test(UserFeatures::class)
            ->set('form.user_loyalty_enabled', true)
            ->set('form.loyalty_customer_group_ids', [$retail->id])
            ->assertSee('Retail Customers')
            ->assertSee('B2B Customers')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            [$retail->id],
            app(SystemSettingsService::class)->get('loyalty_customer_group_ids', [])
        );

        Livewire::actingAs($admin)
            ->test(UserFeatures::class)
            ->assertSet('form.loyalty_customer_group_ids', [$retail->id]);
    }

    public function test_admin_can_save_user_tracking_and_loyalty_switches(): void
    {
        $admin = $this->makeUserWithRole('admin');

        Livewire::actingAs($admin)
            ->test(UserFeatures::class)
            ->set('form.user_tracking_enabled', false)
            ->set('form.user_loyalty_enabled', true)
            ->set('form.loyalty_points_per_currency', 2.5)
            ->set('form.loyalty_currency_value_per_point', 0.02)
            ->set('form.loyalty_min_order_total', 50)
            ->set('form.loyalty_reversal_mode', 'separate_entry')
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(SystemSettingsService::class);

        $this->assertFalse((bool) $settings->get('user_tracking_enabled', true));
        $this->assertTrue((bool) $settings->get('user_loyalty_enabled', false));
        $this->assertSame(2.5, (float) $settings->get('loyalty_points_per_currency', 0));
        $this->assertSame(0.02, (float) $settings->get('loyalty_currency_value_per_point', 0));
        $this->assertSame(50.0, (float) $settings->get('loyalty_min_order_total', 0));
        $this->assertSame('separate_entry', (string) $settings->get('loyalty_reversal_mode', ''));
    }

    private function makeUserWithRole(string $role): User
    {
        Bouncer::role()->firstOrCreate(['name' => 'superadmin']);
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::role()->firstOrCreate(['name' => 'editor']);
        Bouncer::role()->firstOrCreate(['name' => 'customer']);

        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }
}
