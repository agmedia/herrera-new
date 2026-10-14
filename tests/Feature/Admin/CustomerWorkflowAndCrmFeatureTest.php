<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\B2BAccountManager;
use App\Livewire\Admin\User\CustomerCrmManager;
use App\Livewire\Admin\User\CustomerDetailsEditor;
use App\Livewire\Admin\User\Manager;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Models\User\CustomerSalesSession;
use App\Models\User\CustomerVisit;
use App\Services\B2B\B2BAccountService;
use App\Services\B2B\B2BApprovalWorkflow;
use App\Services\User\CustomerCrmService;
use App\Services\User\CustomerManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CustomerWorkflowAndCrmFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_request_requires_assignment_conversation_and_proposal_before_admin_approval(): void
    {
        $admin = $this->staff('admin');
        $rep = $this->staff('order_manager');
        $buyer = $this->buyer('Applicant', 'pending');
        $account = $buyer->b2bAccount;
        $account->update(['payload' => ['approval_workflow_required' => true]]);
        $group = $this->group();
        $review = ['status' => 'approved', 'customer_group_id' => $group->id, 'contract_starts_at' => '', 'contract_ends_at' => ''];
        $this->invalid(fn () => app(B2BAccountService::class)->review($account, $review, $admin), 'status');
        app(B2BApprovalWorkflow::class)->assign($account, $rep->id, $admin);
        $this->assertDatabaseHas('admin_customer_assignments', ['admin_user_id' => $rep->id, 'customer_user_id' => $buyer->id]);
        $this->assertSame('pending', $account->fresh()->status);
        Livewire::actingAs($rep)->test(B2BAccountManager::class)->call('selectAccount', $account->id)
            ->set('proposal', $this->proposal($group, ['sales_comment' => '']))->call('submitProposal')->assertHasErrors('proposal.sales_comment');
        Livewire::actingAs($rep)->test(B2BAccountManager::class)->call('selectAccount', $account->id)
            ->set('proposal', $this->proposal($group))->call('submitProposal')->assertHasNoErrors();
        $this->assertSame('pending', $account->fresh()->status);
        $this->assertSame($rep->id, $account->fresh()->proposed_by);
        $this->assertSame([], $buyer->customerGroups()->pluck('customer_groups.id')->all());
        Livewire::actingAs($admin)->test(B2BAccountManager::class)->call('selectAccount', $account->id)
            ->assertSet('form.customer_group_id', $group->id)->assertSet('form.payment_terms_days', 30)
            ->set('form.status', 'approved')->call('save')->assertHasNoErrors();
        $this->assertSame('approved', $account->fresh()->status);
        $this->assertSame($admin->id, $account->fresh()->reviewed_by);
        $this->assertSame([$group->id], $buyer->customerGroups()->pluck('customer_groups.id')->all());
    }

    public function test_assigned_representative_can_propose_and_record_crm_without_profile_edit_permission(): void
    {
        $admin = $this->staff('admin');
        $rep = $this->staff('order_manager');
        Bouncer::forbid($rep)->to('users.profile.update');
        Bouncer::refreshFor($rep);
        $this->assertFalse($rep->can('users.profile.update'));
        $buyer = $this->buyer('Limited Representative Applicant', 'pending');
        $group = $this->group();
        app(B2BApprovalWorkflow::class)->assign($buyer->b2bAccount, $rep->id, $admin);
        Livewire::actingAs($rep)->test(B2BAccountManager::class)->call('selectAccount', $buyer->b2bAccount->id)
            ->set('proposal', $this->proposal($group))->call('submitProposal')->assertHasNoErrors();
        Livewire::actingAs($rep)->test(CustomerCrmManager::class, ['customerId' => $buyer->id])->set('visit', $this->visit())
            ->call('saveVisit')->assertHasNoErrors();
        Livewire::actingAs($rep)->test(CustomerDetailsEditor::class, ['userId' => $buyer->id])->call('save')->assertForbidden();
        $this->assertDatabaseCount('customer_visits', 1);
        $this->assertSame('pending', $buyer->b2bAccount->fresh()->status);
    }

    public function test_sales_representative_cannot_approve_by_tampering_with_either_editor(): void
    {
        $admin = $this->staff('admin');
        $rep = $this->staff('order_manager');
        $buyer = $this->buyer('Tamper', 'pending');
        $group = $this->group();
        app(B2BApprovalWorkflow::class)->assign($buyer->b2bAccount, $rep->id, $admin);
        app(B2BApprovalWorkflow::class)->propose($buyer->b2bAccount->fresh(), $this->proposal($group), $rep);
        Livewire::actingAs($rep)->test(B2BAccountManager::class)->call('selectAccount', $buyer->b2bAccount->id)
            ->set('form.status', 'approved')->call('save')->assertForbidden();
        Livewire::actingAs($rep)->test(\App\Livewire\Admin\User\B2BUserProfileEditor::class, ['userId' => $buyer->id])
            ->set('b2b.status', 'approved')->set('b2b.customer_group_id', $group->id)->call('save')->assertForbidden();
        $this->assertSame('pending', $buyer->b2bAccount->fresh()->status);
    }

    public function test_reassignment_invalidates_pending_proposal_and_revokes_previous_rep_access(): void
    {
        $admin = $this->staff('admin');
        $first = $this->staff('order_manager');
        $second = $this->staff('order_manager');
        $buyer = $this->buyer('Reassignment', 'pending');
        $group = $this->group();
        app(B2BApprovalWorkflow::class)->assign($buyer->b2bAccount, $first->id, $admin);
        app(B2BApprovalWorkflow::class)->propose($buyer->b2bAccount->fresh(), $this->proposal($group), $first);
        app(CustomerManagementService::class)->assignRepresentatives([$buyer->id], [$second->id], $admin);
        $this->assertNull($buyer->b2bAccount->fresh()->proposed_at);
        $this->assertSame($second->id, $buyer->b2bAccount->fresh()->assigned_sales_rep_id);
        Livewire::actingAs($first)->test(B2BAccountManager::class)->call('selectAccount', $buyer->b2bAccount->id)->assertForbidden();
        Livewire::actingAs($second)->test(B2BAccountManager::class)->call('selectAccount', $buyer->b2bAccount->id)->assertHasNoErrors();
    }

    public function test_pending_account_selection_is_locked_against_browser_tampering(): void
    {
        $rep = $this->staff('order_manager');
        $foreign = $this->buyer('Private Applicant', 'pending');
        $foreign->b2bAccount()->update(['sales_comment' => 'Private commercial comment', 'proposed_at' => now()]);
        $component = Livewire::actingAs($rep)->test(B2BAccountManager::class)->assertDontSee('Private commercial comment');
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $component->set('selectedId', $foreign->b2bAccount->id);
    }

    public function test_stale_pending_model_cannot_reassign_an_account_that_was_already_approved(): void
    {
        $admin = $this->staff('admin');
        $rep = $this->staff('order_manager');
        $buyer = $this->buyer('Concurrent Approval', 'pending');
        $stale = $buyer->b2bAccount;
        B2BAccount::query()->whereKey($stale->id)->update(['status' => 'approved', 'sales_comment' => 'Keep reviewed comment']);
        try {
            app(B2BApprovalWorkflow::class)->assign($stale, $rep->id, $admin);
            $this->fail('An already approved application must not be reassigned with a stale model.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame('Keep reviewed comment', $stale->fresh()->sales_comment);
        $this->assertDatabaseCount('admin_customer_assignments', 0);
    }

    public function test_bulk_segments_preserve_pricing_and_unselected_customers(): void
    {
        $admin = $this->staff('admin');
        $buyer = $this->buyer('Chosen');
        $other = $this->buyer('Unchosen');
        $price = $this->group();
        $industry = $this->group('industry');
        $division = $this->group('division');
        $buyer->customerGroups()->attach([$price->id, $division->id]);
        Livewire::actingAs($admin)->test(Manager::class)->set('selectedCustomers', [(string) $buyer->id])
            ->set('bulkSegment', (string) $industry->id)->call('assignSelectedSegments')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing([$price->id, $industry->id, $division->id], $buyer->customerGroups()->pluck('customer_groups.id')->all());
        $this->assertSame([], $other->customerGroups()->pluck('customer_groups.id')->all());
        Livewire::actingAs($admin)->test(Manager::class)->set('selectedCustomers', [(string) $buyer->id])
            ->set('bulkSegment', (string) $industry->id)->set('segmentMode', 'remove')->call('assignSelectedSegments')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing([$price->id, $division->id], $buyer->customerGroups()->pluck('customer_groups.id')->all());
    }

    public function test_rep_cannot_bulk_reassign_their_customers_or_change_unassigned_customer(): void
    {
        $rep = $this->staff('order_manager');
        $assigned = $this->buyer('Assigned');
        $unassigned = $this->buyer('Unassigned');
        $this->assign($rep, $assigned);
        Livewire::actingAs($rep)->test(Manager::class)->set('selectedCustomers', [$assigned->id])
            ->set('bulkRepresentative', (string) $rep->id)->call('assignSelectedRepresentatives')->assertForbidden();
        Livewire::actingAs($rep)->test(CustomerDetailsEditor::class, ['userId' => $unassigned->id])->assertForbidden();
        Livewire::actingAs($rep)->test(CustomerCrmManager::class, ['customerId' => $unassigned->id])->assertForbidden();
        $this->assertDatabaseCount('customer_visits', 0);
    }

    public function test_directory_and_detail_editor_show_and_filter_explicit_customer_data(): void
    {
        $admin = $this->staff('admin');
        $rep = $this->staff('order_manager');
        $buyer = $this->buyer('Directory Company');
        $other = $this->buyer('Other Company');
        $this->assign($rep, $buyer);
        Livewire::actingAs($admin)->test(CustomerDetailsEditor::class, ['userId' => $buyer->id])->set('details', [
            'city' => 'Split', 'postal_code' => '21000', 'county' => 'Splitsko-dalmatinska', 'customer_type' => 'Instalater',
        ])->call('save')->assertHasNoErrors()->assertSee('Pregled rabata i podrabata');
        $this->assertDatabaseHas('user_profiles', ['user_id' => $buyer->id, 'county' => 'Splitsko-dalmatinska', 'customer_type' => 'Instalater']);
        $this->assertSame('Split', $buyer->b2bAccount->fresh()->city);
        $component = Livewire::actingAs($admin)->test(Manager::class);
        foreach (['city' => 'Split', 'postalCode' => '21000', 'county' => 'Splitsko', 'customerType' => 'Instalater', 'representative' => (string) $rep->id] as $field => $value) {
            $component->set($field, $value)->assertViewHas('rows', function ($rows) use ($field, $buyer) {
                $this->assertSame([$buyer->id], $rows->pluck('id')->all(), $field);

                return true;
            })->set($field, '');
        }
        $this->assertNull($other->fresh()->profile);
    }

    public function test_real_visits_are_distinct_from_phone_calls_and_account_logins(): void
    {
        $admin = $this->staff('admin');
        $buyer = $this->buyer('Visited');
        $phoneOnly = $this->buyer('Phone Only');
        app(CustomerCrmService::class)->recordVisit($buyer, $this->visit(), $admin);
        app(CustomerCrmService::class)->recordVisit($phoneOnly, $this->visit(['contact_type' => 'phone']), $admin);
        app(CustomerCrmService::class)->startSession($phoneOnly, $admin);
        Livewire::actingAs($admin)->test(Manager::class)->set('visit', 'visited')->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$buyer->id]);
        Livewire::actingAs($admin)->test(Manager::class)->set('visit', 'unvisited')->assertViewHas('rows', fn ($rows) => $rows->contains('id', $phoneOnly->id) && ! $rows->contains('id', $buyer->id));
    }

    public function test_crm_outcomes_require_actual_customer_order_or_explicit_quote_reference(): void
    {
        $admin = $this->staff('admin');
        $buyer = $this->buyer('Outcome');
        $other = $this->buyer('Wrong Order');
        $wrongOrder = $this->order($other, 125, 25);
        $service = app(CustomerCrmService::class);
        $this->invalid(fn () => $service->recordVisit($buyer, $this->visit(['outcome' => 'order']), $admin), 'order_id');
        $this->invalid(fn () => $service->recordVisit($buyer, $this->visit(['outcome' => 'order', 'order_id' => $wrongOrder->id]), $admin), 'order_id');
        $this->invalid(fn () => $service->recordVisit($buyer, $this->visit(['outcome' => 'quote', 'quote_reference' => ' ']), $admin), 'quote_reference');
        $quote = $service->recordVisit($buyer, $this->visit(['outcome' => 'quote', 'quote_reference' => 'ERP-PON-123']), $admin);
        $this->assertSame('ERP-PON-123', $quote->quote_reference);
        $order = $this->order($buyer, 125, 25);
        $visit = $service->recordVisit($buyer, $this->visit(['outcome' => 'order', 'order_id' => $order->id]), $admin);
        $this->assertSame($order->id, $visit->order_id);
        $this->assertDatabaseCount('customer_visits', 2);
    }

    public function test_per_rep_report_uses_actual_scoped_records_and_does_not_expose_other_reps(): void
    {
        $rep = $this->staff('order_manager');
        $otherRep = $this->staff('order_manager');
        $buyer = $this->buyer('My Report Company');
        $other = $this->buyer('Private Other Company');
        $this->assign($rep, $buyer);
        $this->assign($otherRep, $other);
        app(CustomerCrmService::class)->recordVisit($buyer, $this->visit(), $rep);
        app(CustomerCrmService::class)->recordVisit($other, $this->visit(['outcome' => 'quote', 'quote_reference' => 'SECRET-QUOTE']), $otherRep);
        Livewire::actingAs($rep)->test(CustomerCrmManager::class)->assertSee('My Report Company')->assertDontSee('Private Other Company')->assertDontSee('SECRET-QUOTE')
            ->assertViewHas('summary', fn ($rows) => $rows->sum('total') === 1)->set('representative', (string) $otherRep->id)->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
    }

    public function test_administrator_work_in_representative_role_is_attributed_and_excluded_from_default_report(): void
    {
        $root = $this->staff('superadmin');
        $rep = $this->staff('order_manager');
        $buyer = $this->buyer('Preview Activity Company');
        $this->assign($rep, $buyer);
        $this->actingAs($root)->post(route('admin.users.staff-impersonation.start', $rep))->assertRedirect();
        // Livewire's isolated test endpoint disables session middleware; use a real web request for actor attribution.
        \Illuminate\Support\Facades\Route::middleware('web')->post('/test/customer-crm-contact', function (\Illuminate\Http\Request $request) use ($buyer) {
            $visit = app(CustomerCrmService::class)->recordVisit($buyer, $request->all(), $request->user());

            return response()->json(['visit_id' => $visit->id]);
        });
        $this->postJson('/test/customer-crm-contact', $this->visit())->assertOk();
        $visit = CustomerVisit::query()->sole();
        $this->assertSame($rep->id, $visit->sales_rep_id);
        $this->assertSame($root->id, $visit->performed_by_id);
        $this->post(route('admin.staff-impersonation.stop'))->assertRedirect();
        Livewire::actingAs($root)->test(CustomerCrmManager::class)->assertViewHas('rows', fn ($rows) => $rows->isEmpty())
            ->set('includePreviews', true)->assertViewHas('rows', fn ($rows) => $rows->count() === 1)->assertSee('Unio administrator')->assertSee($root->name);
    }

    public function test_webshop_turnover_excludes_vat_cancelled_orders_and_other_currencies(): void
    {
        $admin = $this->staff('admin');
        $low = $this->buyer('Low Turnover');
        $high = $this->buyer('High Turnover');
        $this->order($low, 125, 25);
        $this->order($high, 250, 50);
        $cancelledStatus = \App\Models\Settings\Local\OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Cancelled', 'is_active' => true]);
        $this->order($low, 999, 0)->update(['status_id' => $cancelledStatus->id]);
        $this->order($low, 999, 0)->update(['currency_code' => 'USD']);
        Livewire::actingAs($admin)->test(Manager::class)->set('search', 'Turnover')->call('sort', 'webshop_turnover')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$high->id, $low->id] && (float) $rows->first()->webshop_turnover === 200.0);
    }

    public function test_online_login_session_is_recorded_and_finishes_without_faking_a_physical_visit(): void
    {
        $admin = $this->staff('admin');
        $buyer = $this->buyer('Online');
        $this->actingAs($admin)->post(route('admin.users.impersonate', $buyer))->assertRedirect();
        $session = CustomerSalesSession::query()->sole();
        $this->assertSame('open', $session->outcome);
        $this->assertDatabaseCount('customer_visits', 0);
        $this->post(route('front.impersonation.stop'))->assertRedirect();
        $this->assertSame('nothing', $session->fresh()->outcome);
        $this->assertNotNull($session->fresh()->ended_at);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['account_type' => 'staff', 'admin_login_enabled' => true]);
        Bouncer::assign($role)->to($user);

        return $user;
    }

    private function buyer(string $company, string $status = 'approved'): User
    {
        $buyer = User::factory()->create();
        Bouncer::assign('customer')->to($buyer);
        $buyer->b2bAccount()->create(['company_name' => $company, 'oib' => str_pad((string) $buyer->id, 11, '0', STR_PAD_LEFT), 'country_code' => 'HR', 'status' => $status]);

        return $buyer;
    }

    private function group(string $type = 'general'): CustomerGroup
    {
        return CustomerGroup::query()->create(['code' => uniqid('group-'), 'name' => 'Test '.$type, 'is_active' => true, 'payload' => ['classification_type' => $type]]);
    }

    private function assign(User $rep, User $customer): void
    {
        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $rep->id, 'customer_user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function proposal(CustomerGroup $group, array $overrides = []): array
    {
        return array_replace(['contacted_at' => now()->subMinute()->toDateTimeString(), 'sales_comment' => 'Razgovarao s kupcem i potvrdio poslovne podatke.', 'terms_note' => 'Predloženi standardni uvjeti za odabranu grupu.', 'customer_group_id' => $group->id, 'payment_terms_days' => 30, 'purchase_order_required' => false], $overrides);
    }

    private function visit(array $overrides = []): array
    {
        return array_replace(['visited_at' => now()->subMinute()->toDateTimeString(), 'contact_type' => 'visit', 'outcome' => 'nothing', 'note' => 'Predstavljen novi asortiman.', 'order_id' => null, 'quote_reference' => null], $overrides);
    }

    private function order(User $customer, float $gross, float $tax): Order
    {
        return Order::query()->create(['order_number' => uniqid('ORDER-'), 'user_id' => $customer->id, 'customer_name' => $customer->name, 'customer_email' => $customer->email, 'currency_code' => 'EUR', 'grand_total' => $gross, 'tax_total' => $tax, 'placed_at' => now()]);
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected validation error for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
