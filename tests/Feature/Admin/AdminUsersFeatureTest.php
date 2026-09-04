<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\ActivityManager;
use App\Livewire\Admin\User\Form as UserForm;
use App\Livewire\Admin\User\Manager as UserManager;
use App\Livewire\Admin\User\NewsletterSignupManager;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Models\User\NewsletterSignup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminUsersFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_privileged_admin_can_open_users_index(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $response = $this->actingAs($admin)->get('/admin/users');

        $response
            ->assertOk()
            ->assertSee('Users');
    }

    public function test_editor_cannot_open_users_index(): void
    {
        $editor = $this->makeUserWithRole('editor');

        $response = $this->actingAs($editor)->get('/admin/users');

        $response->assertForbidden();
    }

    public function test_privileged_admin_can_open_user_groups_and_activity_pages(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $target = $this->makeUserWithRole('customer');
        $this->createNewsletterSignupsTable();

        $this->actingAs($admin)->get('/admin/users/groups')->assertOk()->assertSee('User Groups');
        $this->actingAs($admin)->get('/admin/users/activity')->assertOk()->assertSee('User Activity');
        $this->actingAs($admin)->get('/admin/users/newsletter')->assertOk()->assertSee('Newsletter Signups');
        $this->actingAs($admin)->get('/admin/users/'.$target->id.'/show')->assertOk()->assertSee('User Overview');
    }

    public function test_editor_cannot_open_user_groups_and_activity_pages(): void
    {
        $editor = $this->makeUserWithRole('editor');
        $target = $this->makeUserWithRole('customer');
        $this->createNewsletterSignupsTable();

        $this->actingAs($editor)->get('/admin/users/groups')->assertForbidden();
        $this->actingAs($editor)->get('/admin/users/activity')->assertForbidden();
        $this->actingAs($editor)->get('/admin/users/newsletter')->assertForbidden();
        $this->actingAs($editor)->get('/admin/users/'.$target->id.'/show')->assertForbidden();
    }

    public function test_admin_can_delete_newsletter_signup_from_manager(): void
    {
        $this->createNewsletterSignupsTable();

        $admin = $this->makeUserWithRole('admin');
        $signup = NewsletterSignup::query()->create([
            'email' => 'delete-newsletter@example.test',
            'source' => 'footer',
            'locale' => 'hr',
            'provider' => 'database',
            'sync_status' => 'synced',
            'consent_accepted' => true,
            'subscribed_at' => now(),
            'synced_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(NewsletterSignupManager::class)
            ->call('delete', $signup->id)
            ->assertDispatched('notify');

        $this->assertDatabaseMissing('newsletter_signups', [
            'id' => $signup->id,
        ]);
    }

    public function test_admin_can_edit_user_and_change_role(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $target = $this->makeUserWithRole('customer');

        Livewire::actingAs($admin)
            ->test(UserForm::class, ['userId' => $target->id])
            ->set('form.name', 'Edited User')
            ->set('form.email', 'edited.user@example.test')
            ->set('form.role', 'editor')
            ->set('form.email_verified', true)
            ->set('form.password', 'new-password-123')
            ->set('form.password_confirmation', 'new-password-123')
            ->call('save')
            ->assertRedirect(route('admin.users'));

        $target = $target->fresh();

        $this->assertSame('Edited User', $target?->name);
        $this->assertSame('edited.user@example.test', $target?->email);
        $this->assertNotNull($target?->email_verified_at);
        $this->assertTrue((bool) $target?->isA('editor'));
    }

    public function test_edit_form_prefers_superadmin_when_user_has_multiple_roles(): void
    {
        $admin = $this->makeUserWithRole('superadmin');
        $target = $this->makeUserWithRole('admin');
        Bouncer::assign('superadmin')->to($target);

        Livewire::actingAs($admin)
            ->test(UserForm::class, ['userId' => $target->id])
            ->assertSet('form.role', 'superadmin');
    }

    public function test_admin_cannot_manage_superadmin_user(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $superadmin = $this->makeUserWithRole('superadmin');

        $this->actingAs($admin)
            ->get('/admin/users/'.$superadmin->id.'/edit')
            ->assertForbidden();
    }

    public function test_role_select_hides_superadmin_for_admin_and_shows_for_superadmin(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $superadmin = $this->makeUserWithRole('superadmin');
        $customer = $this->makeUserWithRole('customer');

        Livewire::actingAs($admin)
            ->test(UserForm::class, ['userId' => $customer->id])
            ->assertDontSee('Super Administrator');

        Livewire::actingAs($superadmin)
            ->test(UserForm::class, ['userId' => $customer->id])
            ->assertSee('Super Administrator');
    }

    public function test_edit_form_prefers_lowest_role_id_when_multiple_roles_are_assigned(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $target = $this->makeUserWithRole('admin');

        Bouncer::role()->firstOrCreate(['name' => 'superadministrator'], ['title' => 'Super Administrator']);
        Bouncer::assign('superadministrator')->to($target);

        Livewire::actingAs($admin)
            ->test(UserForm::class, ['userId' => $target->id])
            ->assertSet('form.role', 'admin');
    }

    public function test_admin_can_update_profile_addresses_segments_and_activity_log(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $target = $this->makeUserWithRole('customer');

        $retail = CustomerGroup::query()->create([
            'code' => 'retail',
            'name' => 'Retail',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 10,
        ]);
        $vip = CustomerGroup::query()->create([
            'code' => 'vip',
            'name' => 'VIP',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 20,
        ]);

        Livewire::actingAs($admin)
            ->test(UserForm::class, ['userId' => $target->id])
            ->set('form.customer_groups', [$retail->id, $vip->id])
            ->set('form.profile.first_name', 'Filip')
            ->set('form.profile.last_name', 'Jankoski')
            ->set('form.profile.phone', '+38591111222')
            ->set('form.profile.company', 'Agmedia')
            ->set('form.profile.oib', '12345678901')
            ->set('form.billing_address.company', 'Agmedia Billing')
            ->set('form.billing_address.address_line_1', 'Billing Street 1')
            ->set('form.billing_address.city', 'Zagreb')
            ->set('form.billing_address.postal_code', '10000')
            ->set('form.shipping_address.address_line_1', 'Shipping Street 2')
            ->set('form.shipping_address.city', 'Kutina')
            ->set('form.shipping_address.postal_code', '44320')
            ->call('save')
            ->assertRedirect(route('admin.users'));

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $target->id,
            'first_name' => 'Filip',
            'last_name' => 'Jankoski',
            'phone' => '+38591111222',
            'company' => 'Agmedia',
            'oib' => '12345678901',
        ]);

        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $target->id,
            'type' => 'billing',
            'company' => 'Agmedia Billing',
            'address_line_1' => 'Billing Street 1',
            'city' => 'Zagreb',
            'postal_code' => '10000',
        ]);

        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $target->id,
            'type' => 'shipping',
            'company' => 'Agmedia Billing',
            'address_line_1' => 'Shipping Street 2',
            'city' => 'Kutina',
            'postal_code' => '44320',
        ]);

        $this->assertDatabaseHas('customer_group_user', [
            'user_id' => $target->id,
            'customer_group_id' => $retail->id,
        ]);
        $this->assertDatabaseHas('customer_group_user', [
            'user_id' => $target->id,
            'customer_group_id' => $vip->id,
        ]);

        $activity = Activity::query()
            ->where('log_name', 'admin_users')
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('updated', $activity->event);
        $this->assertContains('Retail', (array) $activity->getExtraProperty('groups'));
        $this->assertContains('VIP', (array) $activity->getExtraProperty('groups'));
    }

    public function test_users_index_and_show_include_loyalty_and_recent_orders_data_when_enabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', true);

        $admin = $this->makeUserWithRole('admin');
        $target = $this->makeUserWithRole('customer');
        $status = $this->createStatus('paid', 'Paid', true, true, false, 1);
        $order = $this->createOrder($status, $target, 'AG-USR-LOY-0001');

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Loyalty')
            ->assertSee('125 '.__('pts'))
            ->assertSee('/admin/users/loyalty?user_id='.$target->id, false);

        $this->actingAs($admin)
            ->get('/admin/users/'.$target->id.'/show')
            ->assertOk()
            ->assertSee('Loyalty')
            ->assertSee('AG-USR-LOY-0001')
            ->assertSee('125');

        Livewire::actingAs($admin)
            ->test(ActivityManager::class)
            ->assertSee(__('Loyalty Audit'));
    }

    public function test_loyalty_is_hidden_from_users_index_detail_and_sidebar_when_disabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', false);

        $admin = $this->makeUserWithRole('admin');
        $target = $this->makeUserWithRole('customer');
        $status = $this->createStatus('paid', 'Paid', true, true, false, 1);
        $this->createOrder($status, $target, 'AG-USR-NO-LOY-0001');

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertDontSee('href="'.route('admin.users.loyalty').'"', false);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->assertDontSee(__('Loyalty'))
            ->assertDontSee('/admin/users/loyalty', false);

        $this->actingAs($admin)
            ->get('/admin/users/'.$target->id.'/show')
            ->assertOk()
            ->assertSee('AG-USR-NO-LOY-0001')
            ->assertDontSee('Loyalty Ledger')
            ->assertDontSee('admin-section-title">Loyalty', false);
    }

    public function test_loyalty_links_and_details_are_limited_to_eligible_customer_groups(): void
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

        $admin = $this->makeUserWithRole('admin');
        $eligible = $this->makeUserWithRole('customer');
        $ineligible = $this->makeUserWithRole('customer');
        $eligible->customerGroups()->attach($retail->id);
        $ineligible->customerGroups()->attach($b2b->id);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->assertSee('/admin/users/loyalty?user_id='.$eligible->id, false)
            ->assertDontSee('/admin/users/loyalty?user_id='.$ineligible->id, false);

        $this->actingAs($admin)
            ->get('/admin/users/'.$eligible->id.'/show')
            ->assertOk()
            ->assertSee(route('admin.users.loyalty', ['user_id' => $eligible->id]), false)
            ->assertSee('admin-section-title">Loyalty', false);

        $this->actingAs($admin)
            ->get('/admin/users/'.$ineligible->id.'/show')
            ->assertOk()
            ->assertDontSee(route('admin.users.loyalty', ['user_id' => $ineligible->id]), false)
            ->assertDontSee('admin-section-title">Loyalty', false);
    }

    public function test_loyalty_activity_source_is_hidden_and_cannot_remain_selected_when_disabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', false);

        $admin = $this->makeUserWithRole('admin');

        activity('loyalty')
            ->causedBy($admin)
            ->event('hidden_loyalty_event')
            ->log('Hidden loyalty activity');

        Livewire::actingAs($admin)
            ->test(ActivityManager::class)
            ->assertDontSee(__('Loyalty Audit'))
            ->assertDontSee('Hidden loyalty activity')
            ->set('source', 'loyalty')
            ->assertSet('source', 'admin');
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

    private function createStatus(
        string $code,
        string $name,
        bool $isDefault = false,
        bool $isPaid = false,
        bool $isCancelled = false,
        int $sortOrder = 1
    ): OrderStatus {
        return OrderStatus::query()->create([
            'code' => $code,
            'name' => $name,
            'description' => null,
            'color' => 'slate',
            'is_default' => $isDefault,
            'is_paid' => $isPaid,
            'is_cancelled' => $isCancelled,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'settings' => null,
        ]);
    }

    private function createOrder(OrderStatus $status, User $user, string $number): Order
    {
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
}
