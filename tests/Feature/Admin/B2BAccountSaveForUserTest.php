<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\B2B\B2BAccountService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class B2BAccountSaveForUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_conversion_approves_existing_user_and_enables_quick_order(): void
    {
        config(['commerce.b2b_only' => true]);
        $admin = $this->admin();
        $user = User::factory()->create();
        Bouncer::assign('customer')->to($user);
        $group = $this->group('main');
        $extra = $this->group('extra');
        $user->customerGroups()->attach($extra);
        $user->profile()->create([
            'first_name' => 'Ana', 'last_name' => 'Kupac', 'phone' => 'OLD-PHONE',
            'newsletter_opt_in' => true, 'payload' => ['personal' => 'keep'],
        ]);
        $billing = $user->addresses()->create([
            'type' => 'billing', 'first_name' => 'Ana', 'last_name' => 'Kupac',
            'address_line_1' => 'Old billing', 'city' => 'Zagreb', 'country_code' => 'HR',
            'phone' => 'OLD-PHONE', 'state' => 'State', 'is_default' => true,
            'payload' => ['billing' => 'keep'],
        ]);
        $shipping = $user->addresses()->create([
            'type' => 'shipping', 'address_line_1' => 'Shipping stays', 'city' => 'Split',
            'country_code' => 'HR', 'is_default' => true, 'payload' => ['shipping' => 'keep'],
        ]);
        $otherBilling = $user->addresses()->create([
            'type' => 'billing', 'company' => 'Other business address', 'city' => 'Rijeka',
            'country_code' => 'HR', 'is_default' => false, 'payload' => ['secondary' => 'keep'],
        ]);
        $shippingBefore = $shipping->fresh()->getAttributes();
        $otherBillingBefore = $otherBilling->fresh()->getAttributes();
        $userBefore = $user->fresh()->getAttributes();

        $account = app(B2BAccountService::class)->saveForUser($user, $this->data([
            'status' => 'approved', 'customer_group_id' => $group->id,
            'address_line_1' => 'Company Street 1', 'country_code' => 'hr',
        ]), $admin);

        $this->assertSame('approved', $account->status);
        $this->assertSame($group->id, $account->customer_group_id);
        $this->assertSame($group->id, $account->requested_customer_group_id);
        $this->assertSame($admin->id, $account->reviewed_by);
        $this->assertSame($admin->id, $account->payload['admin_creation']['actor_id']);
        $this->assertNotNull($account->requested_at);
        $this->assertSame('HR', $account->country_code);
        $this->assertSame($userBefore, $user->fresh()->getAttributes());
        $this->assertTrue($user->fresh()->isA('customer'));
        $this->assertEqualsCanonicalizing([$group->id, $extra->id], $user->customerGroups()->pluck('customer_groups.id')->all());
        $profile = $user->profile()->firstOrFail();
        $this->assertSame('Ana', $profile->first_name);
        $this->assertSame('Kupac', $profile->last_name);
        $this->assertSame('OLD-PHONE', $profile->phone);
        $this->assertTrue($profile->newsletter_opt_in);
        $this->assertSame(['personal' => 'keep'], $profile->payload);
        $this->assertSame('Company d.o.o.', $profile->company);
        $this->assertSame('Company Street 1', $billing->fresh()->address_line_1);
        $this->assertSame('OLD-PHONE', $billing->fresh()->phone);
        $this->assertSame('State', $billing->fresh()->state);
        $this->assertSame(['billing' => 'keep'], $billing->fresh()->payload);
        $this->assertSame($shippingBefore, $shipping->fresh()->getAttributes());
        $this->assertSame($otherBillingBefore, $otherBilling->fresh()->getAttributes());
        $this->assertDatabaseHas('activity_log', ['event' => 'b2b_reviewed', 'causer_id' => $admin->id, 'subject_id' => $user->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'b2b_profile_saved', 'causer_id' => $admin->id, 'subject_id' => $user->id]);
        $this->actingAs($user)->get(route('account.b2b.quick-order'))->assertOk();
    }

    public function test_membership_alone_does_not_implicitly_approve_created_account(): void
    {
        config(['commerce.b2b_only' => true]);
        $user = User::factory()->create();
        $user->customerGroups()->attach($this->group('segment'));
        $account = app(B2BAccountService::class)->saveForUser($user, $this->data(), $this->admin());

        $this->assertSame('pending', $account->status);
        $this->assertNull($account->customer_group_id);
        $this->assertNull($account->requested_customer_group_id);
        $this->actingAs($user)->get(route('account.b2b.quick-order'))->assertForbidden();
    }

    public function test_repeated_save_preserves_account_history_erp_draft_and_previous_memberships(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $oldGroup = $this->group('old-main');
        $newGroup = $this->group('new-main');
        $extra = $this->group('extra');
        $user->customerGroups()->attach([$oldGroup->id, $extra->id]);
        $oldPivot = $user->customerGroups()->whereKey($oldGroup->id)->firstOrFail()->pivot->getAttributes();
        $account = $user->b2bAccount()->create(array_merge($this->data(), [
            'status' => 'approved', 'customer_group_id' => $oldGroup->id,
            'requested_customer_group_id' => $oldGroup->id, 'requested_at' => '2025-01-01 12:00:00',
            'erp_customer_id' => ' ERP-123 ', 'erp_company_code' => 'COMPANY',
            'contract_number' => 'KEEP', 'contract_starts_at' => '2025-01-01',
            'contract_ends_at' => '2030-01-01', 'payment_terms_days' => 45,
            'purchase_order_required' => true, 'phone' => 'Account phone',
            'status_reason' => ' Legacy reason ', 'country_code' => 'hr',
            'payload' => ['opencart' => ['customer_id' => 88]],
            'quick_order_draft' => ['items' => [['product_id' => 42, 'quantity' => 3]]],
        ]));
        $original = $account->fresh()->getAttributes();
        $result = app(B2BAccountService::class)->saveForUser($user, ['customer_group_id' => $newGroup->id, 'phone' => ''], $admin);
        $again = app(B2BAccountService::class)->saveForUser($user, ['company_name' => 'Edited business'], $admin);

        $this->assertSame($account->id, $result->id);
        $this->assertSame($account->id, $again->id);
        $this->assertDatabaseCount('b2b_accounts', 1);
        $this->assertSame('approved', $again->status);
        $this->assertSame($newGroup->id, $again->customer_group_id);
        $this->assertNull($again->phone);
        foreach (['oib', 'vat_id', 'country_code', 'requested_customer_group_id', 'requested_at', 'erp_customer_id', 'erp_company_code', 'contract_number', 'contract_starts_at', 'contract_ends_at', 'payment_terms_days', 'purchase_order_required', 'status_reason', 'payload', 'quick_order_draft'] as $key) {
            $this->assertSame($original[$key], $again->getAttributes()[$key], $key);
        }
        $this->assertEqualsCanonicalizing([$oldGroup->id, $newGroup->id, $extra->id], $user->customerGroups()->pluck('customer_groups.id')->all());
        $this->assertSame($oldPivot, $user->customerGroups()->whereKey($oldGroup->id)->firstOrFail()->pivot->getAttributes());
    }

    public function test_duplicate_oib_is_friendly_and_atomic_for_creation_and_update(): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create()->b2bAccount()->create($this->data());
        $target = User::factory()->create();
        $target->profile()->create(['company' => 'Unchanged', 'payload' => ['keep' => true]]);
        $profileBefore = $target->profile()->firstOrFail()->getAttributes();
        $this->assertInvalid(fn () => app(B2BAccountService::class)->saveForUser($target, $this->data(['company_name' => 'New']), $admin), 'oib');
        $this->assertDatabaseCount('b2b_accounts', 1);
        $this->assertSame($profileBefore, $target->profile()->firstOrFail()->getAttributes());
        $this->assertDatabaseCount('user_addresses', 0);
        $this->assertSame(0, Activity::query()->where('event', 'b2b_profile_saved')->count());

        $account = $target->b2bAccount()->create($this->data(['oib' => '99999999999']));
        $before = $account->fresh()->getAttributes();
        $this->assertInvalid(fn () => app(B2BAccountService::class)->saveForUser($target, ['oib' => $existing->oib, 'company_name' => 'Changed'], $admin), 'oib');
        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertSame($profileBefore, $target->profile()->firstOrFail()->getAttributes());
    }

    public function test_approval_requires_an_active_primary_group(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $inactive = $this->group('inactive', false);
        foreach ([null, $inactive->id, 999999] as $groupId) {
            $this->assertInvalid(fn () => app(B2BAccountService::class)->saveForUser($target, $this->data(['status' => 'approved', 'customer_group_id' => $groupId]), $admin), 'customer_group_id');
        }
        $this->assertDatabaseCount('b2b_accounts', 0);
        $this->assertDatabaseCount('user_profiles', 0);
    }

    public function test_invalid_dates_oib_country_and_text_limits_leave_no_partial_profile(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        foreach ([
            ['oib', ['oib' => '123456789012']],
            ['oib', ['oib' => ['12345678901']]],
            ['company_name', ['company_name' => str_repeat('a', 192)]],
            ['phone', ['phone' => str_repeat('a', 81)]],
            ['country_code', ['country_code' => '12']],
            ['status', ['status' => 'unknown']],
            ['contract_starts_at', ['contract_starts_at' => 'not-a-date']],
            ['contract_ends_at', ['contract_starts_at' => '2026-10-06', 'contract_ends_at' => '2026-10-05']],
            ['payment_terms_days', ['payment_terms_days' => 366]],
        ] as [$field, $change]) {
            $this->assertInvalid(fn () => app(B2BAccountService::class)->saveForUser($target, $this->data($change), $admin), $field);
        }
        $this->assertDatabaseCount('b2b_accounts', 0);
        $this->assertDatabaseCount('user_profiles', 0);
        $this->assertDatabaseCount('user_addresses', 0);
    }

    public function test_contract_same_day_is_valid_and_end_only_contract_is_valid(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $account = app(B2BAccountService::class)->saveForUser($target, $this->data([
            'contract_starts_at' => '2026-10-06', 'contract_ends_at' => '2026-10-06',
        ]), $admin);
        $this->assertSame('2026-10-06', $account->contract_ends_at->format('Y-m-d'));
        $account = app(B2BAccountService::class)->saveForUser($target, [
            'contract_starts_at' => '', 'contract_ends_at' => '2026-12-31',
        ], $admin);
        $this->assertNull($account->contract_starts_at);
        $this->assertSame('2026-12-31', $account->contract_ends_at->format('Y-m-d'));
    }

    public function test_account_with_inactive_group_can_be_suspended_without_losing_memberships(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $inactive = $this->group('inactive', false);
        $target->customerGroups()->attach($inactive);
        $account = $target->b2bAccount()->create($this->data([
            'status' => 'approved', 'customer_group_id' => $inactive->id,
        ]));
        $result = app(B2BAccountService::class)->saveForUser($target, ['status' => 'suspended'], $admin);
        $this->assertSame($account->id, $result->id);
        $this->assertSame('suspended', $result->status);
        $this->assertNull($result->customer_group_id);
        $this->assertSame([$inactive->id], $target->customerGroups()->pluck('customer_groups.id')->all());
    }

    public function test_view_only_or_profile_ability_without_admin_access_cannot_save(): void
    {
        $target = User::factory()->create();
        $viewer = User::factory()->create();
        Bouncer::allow($viewer)->to('admin.access');
        Bouncer::allow($viewer)->to('users.list.view');
        $profileOnly = User::factory()->create();
        Bouncer::allow($profileOnly)->to('users.profile.update');
        foreach ([$viewer, $profileOnly] as $actor) {
            try {
                app(B2BAccountService::class)->saveForUser($target, $this->data(), $actor);
                $this->fail('A read-only actor cannot create a B2B account.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('b2b_accounts', 0);
            }
        }
    }

    public function test_existing_profile_update_permissions_allow_save_without_admin_role(): void
    {
        $actor = User::factory()->create();
        Bouncer::allow($actor)->to('admin.access');
        Bouncer::allow($actor)->to('users.profile.update');
        $target = User::factory()->create();
        $account = app(B2BAccountService::class)->saveForUser($target, $this->data(), $actor);
        $this->assertSame($target->id, $account->user_id);
    }

    public function test_admin_cannot_change_superadmin_business_profile_but_superadmin_can(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        Bouncer::assign('superadmin')->to($target);
        try {
            app(B2BAccountService::class)->saveForUser($target, $this->data(), $admin);
            $this->fail('An admin cannot manage a superadmin.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('b2b_accounts', 0);
        }
        $account = app(B2BAccountService::class)->saveForUser($target, $this->data(), $target);
        $this->assertSame($target->id, $account->user_id);
    }

    public function test_omitted_legacy_oib_is_preserved_and_untrusted_metadata_cannot_overwrite_history(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $account = $target->b2bAccount()->create($this->data([
            'oib' => 'legacy-invalid', 'payload' => ['legacy' => true],
            'quick_order_draft' => ['items' => [42]], 'requested_at' => '2020-01-01 00:00:00',
        ]));
        $result = app(B2BAccountService::class)->saveForUser($target, [
            'company_name' => 'Edited legacy business', 'user_id' => $admin->id,
            'payload' => ['overwrite' => true], 'quick_order_draft' => [],
            'requested_at' => now(), 'reviewed_by' => $target->id,
        ], $admin);
        $this->assertSame($account->id, $result->id);
        $this->assertSame($target->id, $result->user_id);
        $this->assertSame('legacy-invalid', $result->oib);
        $this->assertSame(['legacy' => true], $result->payload);
        $this->assertSame(['items' => [42]], $result->quick_order_draft);
        $this->assertSame('2020-01-01 00:00:00', $result->requested_at->format('Y-m-d H:i:s'));
        $this->assertSame($admin->id, $result->reviewed_by);
        $this->assertInvalid(fn () => app(B2BAccountService::class)->saveForUser($target, ['oib' => 'legacy-invalid'], $admin), 'oib');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function group(string $code, bool $active = true): CustomerGroup
    {
        return CustomerGroup::query()->create(['code' => $code, 'name' => $code, 'is_active' => $active]);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['company_name' => 'Company d.o.o.', 'oib' => '12345678901', 'country_code' => 'HR'], $overrides);
    }

    private function assertInvalid(callable $save, string $field): void
    {
        try {
            $save();
            $this->fail('Expected validation error for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            if ($field === 'oib' && str_contains($exception->getMessage(), 'već postoji')) {
                $this->assertStringContainsString('B2B račun s ovim OIB-om već postoji', $exception->getMessage());
            }
        }
    }
}
