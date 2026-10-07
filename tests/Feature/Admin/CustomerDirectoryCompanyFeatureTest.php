<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\Manager;
use App\Models\User;
use App\Models\User\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CustomerDirectoryCompanyFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2b_company_and_oib_are_the_primary_customer_identity_with_contact_details_preserved(): void
    {
        $admin = $this->admin();
        $buyer = $this->buyer('directory-b2b', 'Directory Contact');
        $buyer->b2bAccount()->create(['company_name' => 'Primary B2B Company', 'oib' => '11000000001']);
        $buyer->profile()->create(['company' => 'Superseded Profile Company', 'oib' => '22000000002']);
        $this->address($buyer, UserAddress::TYPE_BILLING, 'Superseded Billing Company', '33000000003');

        Livewire::actingAs($admin)->test(Manager::class)
            ->assertSeeInOrder(['Primary B2B Company', '11000000001', 'Directory Contact', $buyer->email])
            ->assertDontSee('Superseded Profile Company')
            ->assertDontSee('22000000002')
            ->assertDontSee('Superseded Billing Company')
            ->assertDontSee('33000000003')
            ->assertSee(route('admin.users.show', $buyer), false)
            ->assertSee(route('admin.users.edit', $buyer), false);
    }

    public function test_empty_sources_fall_back_to_profile_then_billing_company_and_oib(): void
    {
        $admin = $this->admin();
        $profileBuyer = $this->buyer('directory-profile', 'Profile Contact');
        $profileBuyer->b2bAccount()->create(['company_name' => '   ', 'oib' => '   ']);
        $profileBuyer->profile()->create(['company' => 'Profile Fallback Company', 'oib' => '44000000004']);
        $this->address($profileBuyer, UserAddress::TYPE_BILLING, 'Ignored Billing Company', '55000000005');
        $billingBuyer = $this->buyer('directory-billing', 'Billing Contact');
        $billingBuyer->profile()->create(['company' => '   ', 'oib' => '   ']);
        $this->address($billingBuyer, UserAddress::TYPE_BILLING, 'Billing Fallback Company', '66000000006');
        $this->address($billingBuyer, UserAddress::TYPE_SHIPPING, 'Ignored Shipping Company', '77000000007');
        $personalBuyer = $this->buyer('directory-personal', 'Personal Customer');

        Livewire::actingAs($admin)->test(Manager::class)
            ->assertSee('Profile Fallback Company')
            ->assertSee('44000000004')
            ->assertSee('Billing Fallback Company')
            ->assertSee('66000000006')
            ->assertSee('Bez naziva tvrtke')
            ->assertSee('Personal Customer')
            ->assertSee($personalBuyer->email)
            ->assertDontSee('Ignored Billing Company')
            ->assertDontSee('55000000005')
            ->assertDontSee('Ignored Shipping Company')
            ->assertDontSee('77000000007');
    }

    public function test_customer_search_finds_company_and_oib_from_each_supported_source(): void
    {
        $admin = $this->admin();
        $b2bBuyer = $this->buyer('search-b2b', 'B2B Search Contact');
        $b2bBuyer->b2bAccount()->create(['company_name' => 'Distinct B2B Partner', 'oib' => '11000000001']);
        $profileBuyer = $this->buyer('search-profile', 'Profile Search Contact');
        $profileBuyer->profile()->create(['company' => 'Distinct Profile Partner', 'oib' => '22000000002']);
        $billingBuyer = $this->buyer('search-billing', 'Billing Search Contact');
        $this->address($billingBuyer, UserAddress::TYPE_BILLING, 'Distinct Billing Partner', '33000000003');
        $this->buyer('search-unrelated', 'Unrelated Contact');
        $component = Livewire::actingAs($admin)->test(Manager::class);

        foreach ([
            ['Distinct B2B Partner', $b2bBuyer->id], ['11000000001', $b2bBuyer->id],
            ['Distinct Profile Partner', $profileBuyer->id], ['22000000002', $profileBuyer->id],
            ['Distinct Billing Partner', $billingBuyer->id], ['33000000003', $billingBuyer->id],
            [$profileBuyer->name, $profileBuyer->id], [$billingBuyer->email, $billingBuyer->id],
        ] as [$search, $expectedId]) {
            $component->set('search', $search)
                ->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [$expectedId]);
        }
    }

    public function test_search_treats_wildcards_and_the_escape_character_as_literal_company_text(): void
    {
        $admin = $this->admin();
        $literalBuyer = $this->buyer('search-literal', 'Literal Contact');
        $literalBuyer->profile()->create(['company' => 'Directory 50%_! Supplies', 'oib' => '44000000004']);
        $otherBuyer = $this->buyer('search-unmatched', 'Other Contact');
        $otherBuyer->profile()->create(['company' => 'Directory 50ABZ Supplies', 'oib' => '55000000005']);
        $component = Livewire::actingAs($admin)->test(Manager::class);

        foreach (['%', '_', '!', '50%_!'] as $search) {
            $component->set('search', $search)
                ->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [$literalBuyer->id]);
        }
    }

    public function test_shipping_identity_does_not_match_company_or_oib_search(): void
    {
        $admin = $this->admin();
        $buyer = $this->buyer('search-address-types', 'Address Contact');
        $this->address($buyer, UserAddress::TYPE_BILLING, 'Buyer Billing Identity', '88000000008');
        $this->address($buyer, UserAddress::TYPE_SHIPPING, 'Delivery Only Identity', '99000000009');
        $component = Livewire::actingAs($admin)->test(Manager::class);

        foreach (['Delivery Only Identity', '99000000009'] as $search) {
            $component->set('search', $search)
                ->assertViewHas('rows', fn ($rows): bool => $rows->isEmpty());
        }
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Directory Administrator', 'email' => 'directory-admin@example.test']);
        Bouncer::assign('admin')->to($admin);

        return $admin;
    }

    private function buyer(string $key, string $name): User
    {
        $buyer = User::factory()->create(['name' => $name, 'email' => $key.'@example.test']);
        Bouncer::assign('customer')->to($buyer);

        return $buyer;
    }

    private function address(User $buyer, string $type, string $company, string $oib): void
    {
        $buyer->addresses()->create([
            'type' => $type, 'company' => $company, 'oib' => $oib, 'address_line_1' => 'Test Street 1',
            'postal_code' => '10000', 'city' => 'Zagreb', 'country_code' => 'HR', 'is_default' => true,
        ]);
    }
}
