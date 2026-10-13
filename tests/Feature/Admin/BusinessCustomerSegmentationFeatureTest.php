<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\B2BUserProfileEditor;
use App\Livewire\Admin\User\GroupManager;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\B2B\B2BAccountService;
use App\Services\Pricing\ProductGroupPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class BusinessCustomerSegmentationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_prepare_industry_and_division_groups_without_default_or_customer_assignments(): void
    {
        $admin = $this->admin();
        foreach (['industry', 'division'] as $type) {
            Livewire::actingAs($admin)->test(GroupManager::class, ['createPage' => true])
                ->assertSee('Vrsta grupe')
                ->set('form.code', 'test-'.$type)->set('form.name', 'Test '.$type)
                ->set('form.classification_type', $type)->call('save')->assertHasNoErrors();
            $group = CustomerGroup::query()->where('code', 'test-'.$type)->sole();
            $this->assertSame($type, $group->classificationType());
            $this->assertFalse($group->is_default);
            $this->assertSame(0, $group->users()->count());
            Livewire::actingAs($admin)->test(GroupManager::class)->call('makeDefault', $group->id)
                ->assertHasErrors('form.classification_type');
            $this->assertFalse($group->fresh()->is_default);
        }
    }

    public function test_group_classification_preserves_import_metadata_and_rejects_converting_a_primary_price_group(): void
    {
        $admin = $this->admin();
        $group = $this->group('general');
        $group->update(['payload' => ['opencart' => ['source_id' => 42]]]);
        Livewire::actingAs($admin)->test(GroupManager::class)->call('edit', $group->id)
            ->set('form.name', 'Renamed General')->call('save')->assertHasNoErrors();
        $this->assertSame(42, $group->fresh()->payload['opencart']['source_id']);
        $this->buyer($group);
        Livewire::actingAs($admin)->test(GroupManager::class)->call('edit', $group->id)
            ->set('form.classification_type', 'industry')->set('form.is_default', false)
            ->call('save')->assertHasErrors('form.classification_type');
        $this->assertSame('general', $group->fresh()->classificationType());
    }

    public function test_only_assigned_active_business_segments_extend_b2b_action_audience_and_never_change_primary_pricing(): void
    {
        config(['commerce.b2b_only' => true]);
        $primary = $this->group('primary');
        $secondaryPriceGroup = $this->group('secondary-price');
        $industry = $this->group('industry', 'industry');
        $division = $this->group('division', 'division');
        $inactive = $this->group('inactive', 'industry');
        $inactive->update(['is_active' => false]);
        $buyer = $this->buyer($primary);
        $buyer->customerGroups()->attach([$secondaryPriceGroup->id, $industry->id, $division->id, $inactive->id]);
        $otherBuyer = $this->buyer($primary);
        $pending = $this->buyer($primary, 'pending');
        $pending->customerGroups()->attach($industry);
        $actions = [];
        foreach ([$primary, $secondaryPriceGroup, $industry, $division, $inactive] as $group) {
            $actions[$group->code] = CatalogAction::query()->create([
                'code' => 'action-'.$group->code, 'scope' => 'cart', 'type' => 'percentage', 'discount_value' => 10,
                'target_type' => 'all', 'audience_type' => 'user_group', 'customer_group_id' => $group->id, 'is_active' => true,
            ])->id;
        }
        $this->assertEqualsCanonicalizing(
            [$actions['primary'], $actions['industry'], $actions['division']],
            CatalogAction::query()->availableForAudience($buyer)->pluck('id')->all(),
        );
        $this->assertSame([$actions['primary']], CatalogAction::query()->availableForAudience($otherBuyer)->pluck('id')->all());
        $this->assertSame([], CatalogAction::query()->availableForAudience($pending)->pluck('id')->all());

        $product = Product::query()->create(['code' => 'segment-product', 'base_price' => 100, 'is_active' => true]);
        foreach ([[$primary, 75], [$industry, 1], [$secondaryPriceGroup, 2]] as [$group, $price]) {
            ProductGroupPrice::query()->create([
                'product_id' => $product->id, 'customer_group_id' => $group->id,
                'price' => $price, 'minimum_quantity' => 1, 'currency_code' => 'EUR', 'is_active' => true,
            ]);
        }
        $this->assertSame(75.0, app(ProductGroupPriceResolver::class)->resolve($product, $buyer)->price);
        $this->assertSame($primary->id, $buyer->b2bAccount->customer_group_id);
    }

    public function test_current_default_cannot_be_converted_to_business_segment_even_if_default_toggle_is_cleared(): void
    {
        $admin = $this->admin();
        $current = $this->group('current-default');
        $current->update(['is_default' => true]);
        $replacement = $this->group('replacement-default');
        $component = Livewire::actingAs($admin)->test(GroupManager::class)->call('edit', $current->id)
            ->set('form.classification_type', 'division')->set('form.is_default', false)
            ->call('save')->assertHasErrors('form.classification_type');
        $this->assertTrue($current->fresh()->is_default);
        $this->assertSame('general', $current->fresh()->classificationType());
        $component->call('makeDefault', $replacement->id)->call('edit', $current->id)
            ->set('form.classification_type', 'division')->call('save')->assertHasNoErrors();
        $this->assertTrue($replacement->fresh()->is_default);
        $this->assertFalse($current->fresh()->is_default);
        $this->assertSame('division', $current->fresh()->classificationType());
    }

    public function test_business_segment_is_not_a_valid_primary_b2b_price_group(): void
    {
        $admin = $this->admin();
        $primary = $this->group('primary');
        $industry = $this->group('industry', 'industry');
        $buyer = $this->buyer($primary);
        Livewire::actingAs($admin)->test(B2BUserProfileEditor::class, ['userId' => $buyer->id])
            ->assertViewHas('customerGroups', fn ($groups) => ! $groups->contains('id', $industry->id));
        try {
            app(B2BAccountService::class)->saveForUser($buyer, ['customer_group_id' => $industry->id], $admin);
            $this->fail('A business segment must not replace the primary price group.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_group_id', $exception->errors());
        }
        $this->assertSame($primary->id, $buyer->b2bAccount()->sole()->customer_group_id);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);

        return $admin;
    }

    private function group(string $code, string $classification = 'general'): CustomerGroup
    {
        return CustomerGroup::query()->create([
            'code' => $code, 'name' => ucfirst($code), 'is_active' => true,
            'payload' => ['classification_type' => $classification],
        ]);
    }

    private function buyer(CustomerGroup $group, string $status = 'approved'): User
    {
        $buyer = User::factory()->create();
        $buyer->b2bAccount()->create([
            'company_name' => 'Segment Company', 'oib' => str_pad((string) $buyer->id, 11, '0', STR_PAD_LEFT),
            'country_code' => 'HR', 'status' => $status, 'customer_group_id' => $group->id,
        ]);

        return $buyer;
    }
}
