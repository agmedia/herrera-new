<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Sales\Order\Manager as OrderManager;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Services\Analytics\CustomerPurchaseStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CustomerIdentityPresentationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_display_uses_company_sources_without_changing_contact_or_staff_names(): void
    {
        $buyer = User::factory()->create(['name' => 'Contact Person']);
        $buyer->b2bAccount()->create(['company_name' => '  B2B Company  ', 'oib' => '11000000001']);
        $buyer->profile()->create(['company' => 'Profile Company']);
        $buyer->addresses()->create(['type' => 'billing', 'company' => 'Billing Company']);
        $buyer->addresses()->create(['type' => 'shipping', 'company' => 'Shipping Company']);
        $this->assertSame('B2B Company', $buyer->fresh()->customerDisplayName());
        $buyer->b2bAccount()->update(['company_name' => ' ']);
        $this->assertSame('Profile Company', $buyer->fresh()->customerDisplayName());
        $buyer->profile()->update(['company' => ' ']);
        $this->assertSame('Billing Company', $buyer->fresh()->customerDisplayName());
        $buyer->addresses()->where('type', 'billing')->update(['company' => ' ']);
        $this->assertSame('Contact Person', $buyer->fresh()->customerDisplayName());
        $this->assertSame('Contact Person', $buyer->fresh()->name);
        $buyer->forceFill(['account_type' => 'staff'])->save();
        $buyer->profile()->update(['company' => 'Employer Company']);
        $this->assertSame('Contact Person', $buyer->fresh()->customerDisplayName());
    }

    public function test_current_company_is_primary_and_searchable_in_statistics_with_contact_preserved(): void
    {
        $admin = $this->admin();
        $buyer = User::factory()->create(['name' => 'Statistics Contact']);
        $buyer->b2bAccount()->create(['company_name' => 'Current Company 50%_!', 'oib' => '11000000001']);
        $this->order($buyer, 'OLD-COMPANY-ORDER', 'Old Company', 125);

        $statistics = app(CustomerPurchaseStatistics::class);
        $this->assertSame(125.0, $statistics->summary(['currency' => 'EUR', 'search' => '50%_!'])['order_value']);
        $this->assertSame('Current Company 50%_!', $statistics->customers(['currency' => 'EUR'])->sole()->company);
        $this->actingAs($admin)->get(route('admin.users.statistics', ['user_id' => $buyer->id, 'dateFrom' => '', 'dateTo' => '']))
            ->assertOk()->assertSeeInOrder(['Current Company 50%_!', 'Kontakt: Statistics Contact']);
        $this->get(route('admin.users.show', $buyer))->assertOk()->assertSee('Current Company 50%_!')->assertSee('Statistics Contact');
    }

    public function test_statistics_use_only_one_preferred_current_billing_company_without_multiplying_orders(): void
    {
        $buyer = User::factory()->create(['name' => 'Billing Only Contact']);
        $buyer->addresses()->create(['type' => 'billing', 'company' => 'Other Billing Company']);
        $buyer->addresses()->create(['type' => 'billing', 'company' => 'Preferred Billing 50%_!', 'is_default' => true]);
        $buyer->addresses()->create(['type' => 'billing', 'company' => 'Later Default Billing', 'is_default' => true]);
        $buyer->addresses()->create(['type' => 'shipping', 'company' => 'Shipping Only Company']);
        $this->order($buyer, 'BILLING-ONLY-1', '', 125);
        $this->order($buyer, 'BILLING-ONLY-2', 'Old Historical Company', 50);

        $statistics = app(CustomerPurchaseStatistics::class);
        $summary = $statistics->summary(['currency' => 'EUR', 'search' => '50%_!']);
        $customer = $statistics->customers(['currency' => 'EUR'])->sole();
        $this->assertSame('Preferred Billing 50%_!', $buyer->fresh()->customerDisplayName());
        $this->assertSame('Preferred Billing 50%_!', $customer->company);
        $this->assertSame(2, $summary['orders']);
        $this->assertSame(175.0, $summary['order_value']);
        $this->assertEquals(2, $customer->order_count);
        $this->assertEquals(175, $customer->order_value);
        $this->assertSame(0, $statistics->summary(['currency' => 'EUR', 'search' => 'Shipping Only Company'])['orders']);
    }

    public function test_b2b_company_missing_uses_neutral_identity_and_preserves_contact_while_b2c_keeps_personal_name(): void
    {
        $admin = $this->admin();
        $buyer = User::factory()->create(['name' => 'Missing Company Contact']);
        $order = $this->order($buyer, 'MISSING-COMPANY', ' ', 25);
        config(['commerce.b2b_only' => true]);
        $this->assertSame('Tvrtka bez naziva', $buyer->fresh()->customerDisplayName());
        $this->assertSame('Tvrtka bez naziva', $order->customerDisplayName());
        $this->actingAs($admin)->get(route('admin.users.statistics', ['dateFrom' => '', 'dateTo' => '']))
            ->assertOk()->assertSeeInOrder(['Tvrtka bez naziva', 'Kontakt: Missing Company Contact']);
        $this->assertSame('Missing Company Contact', $buyer->fresh()->name);
        $this->assertSame('Missing Company Contact', $order->fresh()->customer_name);
        config(['commerce.b2b_only' => false]);
        $this->assertSame('Missing Company Contact', $buyer->fresh()->customerDisplayName());
        $this->assertSame('Missing Company Contact', $order->customerDisplayName());
    }

    public function test_order_list_search_and_sort_use_historical_company_and_keep_contact_visible(): void
    {
        $admin = $this->admin();
        $buyer = User::factory()->create(['name' => 'Order Contact']);
        $second = $this->order($buyer, 'ORDER-Z', 'Z Company', 25, 'A Contact');
        $first = $this->order($buyer, 'ORDER-A', 'A Company', 25, 'Z Contact');

        Livewire::actingAs($admin)->test(OrderManager::class)->call('sort', 'customer_name')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$first->id, $second->id])
            ->set('search', 'A Company')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$first->id])
            ->assertSeeInOrder(['A Company', 'Kontakt: Z Contact']);
        $this->actingAs($admin)->get(route('admin.orders.show', $first))->assertOk()
            ->assertSeeInOrder(['A Company', 'Kontakt: Z Contact']);
        $this->assertSame('Z Contact', $first->fresh()->customer_name);
    }

    public function test_impersonation_and_customer_dashboard_show_company_and_separate_contact(): void
    {
        $admin = $this->admin();
        $buyer = User::factory()->create(['name' => 'Preview Contact']);
        Bouncer::assign('customer')->to($buyer);
        $buyer->profile()->create(['company' => 'Preview Company']);
        $this->actingAs($admin)->post(route('admin.users.impersonate', $buyer))->assertRedirect();
        $this->get(route('account.dashboard'))->assertOk()
            ->assertSeeInOrder(['Prijavljeni ste kao kupac:', 'Preview Company', 'Kontakt: Preview Contact']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);

        return $admin;
    }

    private function order(User $buyer, string $number, string $company, float $total, ?string $contact = null): Order
    {
        return Order::query()->create([
            'order_number' => $number, 'user_id' => $buyer->id, 'customer_name' => $contact ?? $buyer->name,
            'customer_email' => $buyer->email, 'billing_company' => $company, 'currency_code' => 'EUR',
            'grand_total' => $total, 'tax_total' => 0, 'placed_at' => now(),
        ]);
    }
}
