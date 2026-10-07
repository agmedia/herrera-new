<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\B2BUserProfileEditor;
use App\Livewire\Admin\User\Form as UserForm;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Models\User\UserAddress;
use App\Services\Pricing\B2BAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class B2BUserProfileEditorFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
    }

    public function test_panel_is_near_the_top_of_existing_user_editor_and_does_not_create_or_approve_on_load(): void
    {
        [$admin, $user, $group] = $this->users();
        $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()
            ->assertSeeInOrder(['data-b2b-user-profile-editor', 'Kreiraj B2B profil', __('Core Data')], false);

        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->assertSet('b2b.company_name', 'Existing Company')
            ->assertSet('b2b.oib', '12345678901')
            ->assertSet('b2b.phone', '+385 1 234 567')
            ->assertSet('b2b.address_line_1', 'Saved Street 1')
            ->assertSet('b2b.country_code', 'HR')
            ->assertSet('b2b.customer_group_id', $group->id)
            ->assertSet('b2b.status', B2BAccount::STATUS_PENDING)
            ->assertSee('Kreiraj B2B profil');

        $this->assertDatabaseMissing('b2b_accounts', ['user_id' => $user->id]);
        $this->assertNull(app(B2BAccessService::class)->approvedAccount($user->fresh()));
    }

    public function test_ambiguous_or_inactive_segments_do_not_choose_a_primary_group(): void
    {
        [$admin, $user, $group] = $this->users();
        $other = CustomerGroup::query()->create(['code' => 'other', 'name' => 'Other', 'is_active' => true]);
        $user->customerGroups()->attach($other);
        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->assertSet('b2b.customer_group_id', null);
        $user->customerGroups()->detach($other);
        $group->update(['is_active' => false]);
        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->assertSet('b2b.customer_group_id', null);
    }

    public function test_creating_with_the_default_pending_status_never_enables_b2b_access(): void
    {
        [$admin, $user] = $this->users();
        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->call('save')->assertHasNoErrors()->assertDispatched('notify')
            ->assertSee('B2B profil je kreiran.')->assertSee('Spremi B2B profil');
        $this->assertDatabaseHas('b2b_accounts', ['user_id' => $user->id, 'status' => B2BAccount::STATUS_PENDING, 'customer_group_id' => null]);
        $this->assertNull(app(B2BAccessService::class)->approvedAccount($user->fresh()));
        $this->actingAs($user)->get(route('account.b2b.quick-order'))->assertForbidden();
    }

    public function test_explicit_approval_creates_one_profile_and_enables_quick_order_without_changing_identity(): void
    {
        [$admin, $user, $group] = $this->users();
        $identity = $user->only(['name', 'email', 'password']);
        $verifiedAt = $user->getRawOriginal('email_verified_at');
        $roles = $user->roles->pluck('id')->all();
        $extra = CustomerGroup::query()->create(['code' => 'extra', 'name' => 'Extra', 'is_active' => true]);
        $user->customerGroups()->attach($extra);
        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->set('b2b.status', B2BAccount::STATUS_APPROVED)
            ->set('b2b.customer_group_id', $group->id)
            ->call('save')->assertHasNoErrors()
            ->assertSee('B2B pristup je aktivan')
            ->assertSee('Otvori B2B račun')
            ->call('save')->assertHasNoErrors();
        $user = $user->fresh();
        $this->assertSame($identity, $user->only(array_keys($identity)));
        $this->assertSame($verifiedAt, $user->getRawOriginal('email_verified_at'));
        $this->assertSame($roles, $user->roles->pluck('id')->all());
        $this->assertDatabaseCount('b2b_accounts', 1);
        $this->assertDatabaseHas('customer_group_user', ['user_id' => $user->id, 'customer_group_id' => $extra->id]);
        $this->assertNotNull(app(B2BAccessService::class)->approvedAccount($user));
        $this->actingAs($user)->get(route('account.orders'))->assertOk()->assertSee('Brza narudžba');
        $this->get(route('account.b2b.quick-order'))->assertOk();
    }

    public function test_editing_existing_profile_keeps_archives_and_quick_order_draft(): void
    {
        [$admin, $user, $group] = $this->users();
        $account = B2BAccount::query()->create([
            'user_id' => $user->id, 'company_name' => 'Existing B2B Company', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id,
            'erp_customer_id' => 'ERP-1', 'erp_company_code' => 'ERP-CO',
            'payload' => ['legacy' => 'retain'], 'quick_order_draft' => [['product_id' => 123, 'quantity' => 4]],
        ]);
        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->assertSet('accountId', $account->id)->assertSet('b2b.erp_customer_id', 'ERP-1')
            ->assertSee('Spremi B2B profil')
            ->set('b2b.contract_number', 'CONTRACT-1')->call('save')->assertHasNoErrors();
        $this->assertSame(['legacy' => 'retain'], $account->fresh()->payload);
        $this->assertSame([['product_id' => 123, 'quantity' => 4]], $account->fresh()->quick_order_draft);
        $this->assertSame('ERP-1', $account->fresh()->erp_customer_id);
        $this->assertDatabaseCount('b2b_accounts', 1);
    }

    public function test_approval_requires_an_active_primary_group_and_valid_business_fields(): void
    {
        [$admin, $user, $group] = $this->users();
        $editor = Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->set('b2b.status', B2BAccount::STATUS_APPROVED)
            ->set('b2b.customer_group_id', null)->set('b2b.company_name', '')->set('b2b.oib', '123')
            ->call('save')->assertHasErrors(['b2b.customer_group_id', 'b2b.company_name', 'b2b.oib']);
        $group->update(['is_active' => false]);
        $editor->set('b2b.customer_group_id', $group->id)->set('b2b.company_name', 'Valid Company')->set('b2b.oib', '12345678901')
            ->call('save')->assertHasErrors(['b2b.customer_group_id']);
        $this->assertDatabaseCount('b2b_accounts', 0);
    }

    public function test_duplicate_oib_and_reversed_contract_dates_do_not_save(): void
    {
        [$admin, $user] = $this->users();
        $other = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $other->id, 'company_name' => 'Other', 'oib' => '12345678901']);
        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->set('b2b.contract_starts_at', '2026-10-10')->set('b2b.contract_ends_at', '2026-10-01')
            ->call('save')->assertHasErrors(['b2b.oib', 'b2b.contract_ends_at']);
        $this->assertDatabaseMissing('b2b_accounts', ['user_id' => $user->id]);
    }

    public function test_contract_end_without_a_start_date_can_be_saved(): void
    {
        [$admin, $user] = $this->users();
        $end = now()->addYear()->format('Y-m-d');
        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->set('b2b.contract_ends_at', $end)->call('save')->assertHasNoErrors();
        $this->assertSame($end, $user->fresh()->b2bAccount->contract_ends_at->format('Y-m-d'));
    }

    public function test_profile_permission_without_admin_access_is_read_only_for_this_workflow(): void
    {
        [, $user] = $this->users();
        $viewer = User::factory()->create();
        Bouncer::allow($viewer)->to('users.profile.update');
        Livewire::actingAs($viewer)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->assertSee('Imate pravo pregleda')->call('save')->assertForbidden();
        $this->assertDatabaseMissing('b2b_accounts', ['user_id' => $user->id]);
    }

    public function test_read_only_reviewer_cannot_save_and_customer_cannot_read(): void
    {
        [, $user] = $this->users();
        $viewer = User::factory()->create();
        Bouncer::allow($viewer)->to('users.list.view');
        Livewire::actingAs($viewer)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->assertSee('Imate pravo pregleda')->assertDontSee('Kreiraj B2B profil')
            ->call('save')->assertForbidden();
        Livewire::actingAs($user)->test(B2BUserProfileEditor::class, ['userId' => $user->id])->assertForbidden();
        $this->assertDatabaseMissing('b2b_accounts', ['user_id' => $user->id]);
    }

    public function test_service_validation_errors_are_attached_to_the_matching_editor_field(): void
    {
        [$admin, $user] = $this->users();
        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->set('b2b.country_code', '12')->call('save')->assertHasErrors(['b2b.country_code']);
        $this->assertDatabaseMissing('b2b_accounts', ['user_id' => $user->id]);
    }

    public function test_saved_business_fields_sync_to_parent_without_losing_unrelated_unsaved_changes(): void
    {
        [$admin, $user, $group] = $this->users();
        $extra = CustomerGroup::query()->create(['code' => 'extra-parent', 'name' => 'Extra parent', 'is_active' => true]);
        $parent = Livewire::actingAs($admin)->test(UserForm::class, ['userId' => $user->id])
            ->set('form.name', 'Unsaved name')->set('form.email', 'unsaved@example.test')
            ->set('form.role', 'editor')->set('form.email_verified', false)
            ->set('form.password', 'unsaved-password')->set('form.password_confirmation', 'unsaved-password')
            ->set('form.customer_groups', [(string) $extra->id])
            ->set('form.profile.first_name', 'Unsaved first')->set('form.profile.last_name', 'Unsaved last')
            ->set('form.profile.birthday', '1990-01-02')->set('form.profile.newsletter_opt_in', true)
            ->set('form.billing_address.first_name', 'Unsaved billing first')
            ->set('form.shipping_address.address_line_1', 'Unsaved shipping street');

        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id])
            ->set('b2b.status', B2BAccount::STATUS_APPROVED)->set('b2b.customer_group_id', $group->id)
            ->set('b2b.company_name', 'Saved B2B Company')->set('b2b.oib', '98765432101')
            ->set('b2b.phone', '+385 99 123 456')->set('b2b.address_line_1', 'Saved B2B Street')
            ->call('save')->assertHasNoErrors()->assertDispatched('b2b-user-profile-saved', userId: $user->id);

        $parent->dispatch('b2b-user-profile-saved', userId: $user->id)
            ->assertSet('form.profile.company', 'Saved B2B Company')->assertSet('form.profile.oib', '98765432101')
            ->assertSet('form.profile.phone', '+385 99 123 456')->assertSet('form.billing_address.address_line_1', 'Saved B2B Street')
            ->assertSet('form.customer_groups', [(string) $extra->id, (string) $group->id])
            ->assertSet('form.name', 'Unsaved name')->assertSet('form.email', 'unsaved@example.test')
            ->assertSet('form.role', 'editor')->assertSet('form.email_verified', false)
            ->assertSet('form.password', 'unsaved-password')->assertSet('form.password_confirmation', 'unsaved-password')
            ->assertSet('form.profile.first_name', 'Unsaved first')->assertSet('form.profile.last_name', 'Unsaved last')
            ->assertSet('form.profile.birthday', '1990-01-02')->assertSet('form.profile.newsletter_opt_in', true)
            ->assertSet('form.billing_address.first_name', 'Unsaved billing first')
            ->assertSet('form.shipping_address.address_line_1', 'Unsaved shipping street')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.users'));
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'company' => 'Saved B2B Company', 'oib' => '98765432101', 'phone' => '+385 99 123 456']);
        $this->assertDatabaseHas('user_addresses', ['user_id' => $user->id, 'type' => UserAddress::TYPE_BILLING, 'address_line_1' => 'Saved B2B Street']);
        $this->assertDatabaseHas('customer_group_user', ['user_id' => $user->id, 'customer_group_id' => $group->id]);
    }

    public function test_an_event_for_another_user_does_not_replace_parent_form_fields(): void
    {
        [$admin, $user] = $this->users();
        Livewire::actingAs($admin)->test(UserForm::class, ['userId' => $user->id])
            ->set('form.profile.company', 'Unsaved local company')
            ->dispatch('b2b-user-profile-saved', userId: $admin->id)
            ->assertSet('form.profile.company', 'Unsaved local company');
    }

    public function test_parent_form_target_cannot_be_changed_before_the_sync_event(): void
    {
        [$admin, $user] = $this->users();
        $parent = Livewire::actingAs($admin)->test(UserForm::class, ['userId' => $user->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $parent->set('userId', $admin->id);
    }

    public function test_non_superadmin_cannot_manage_a_superadmin_business_account(): void
    {
        [$admin] = $this->users();
        $target = User::factory()->create();
        Bouncer::assign('superadmin')->to($target);
        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $target->id])->assertForbidden();
    }

    #[DataProvider('lockedProperties')]
    public function test_target_identifiers_cannot_be_tampered_with(string $property): void
    {
        [$admin, $user] = $this->users();
        $editor = Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $user->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $editor->set($property, 999999);
    }

    public static function lockedProperties(): array
    {
        return [['userId'], ['accountId']];
    }

    private function users(): array
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        $user = User::factory()->create();
        Bouncer::assign('customer')->to($user);
        $user->profile()->create(['company' => 'Existing Company', 'oib' => '12345678901', 'phone' => '+385 1 234 567']);
        UserAddress::query()->create([
            'user_id' => $user->id, 'type' => UserAddress::TYPE_BILLING, 'company' => 'Billing Company',
            'address_line_1' => 'Saved Street 1', 'postal_code' => '10000', 'city' => 'Zagreb', 'country_code' => 'HR',
        ]);
        $group = CustomerGroup::query()->create(['code' => 'b2b40', 'name' => 'B2B40', 'is_active' => true]);
        $user->customerGroups()->attach($group);

        return [$admin, $user, $group];
    }
}
