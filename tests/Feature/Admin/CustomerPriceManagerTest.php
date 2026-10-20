<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\CustomerPriceManager;
use App\Livewire\Admin\User\Form as UserForm;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\CatalogGroupDiscountRule;
use App\Models\Catalog\Pricing\CustomerDiscountRule;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CustomerPriceManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
    }

    public function test_customer_has_a_pricing_tab_and_switching_preserves_unsaved_profile_fields(): void
    {
        [$admin, $customer] = $this->fixture();

        Livewire::actingAs($admin)->test(UserForm::class, ['userId' => $customer->id])
            ->assertSet('tab', 'profile')->assertSee('B2B cjenik')
            ->set('form.name', 'Unsaved customer name')
            ->set('form.email', 'unsaved@example.test')
            ->set('form.profile.phone', '+385 99 111 222')
            ->set('form.billing_address.address_line_1', 'Unsaved billing street')
            ->call('switchTab', 'pricing')->assertSet('tab', 'pricing')
            ->assertSeeLivewire(CustomerPriceManager::class)
            ->assertSee('B2B cjenik kupca')
            ->call('switchTab', 'profile')->assertSet('tab', 'profile')
            ->assertSet('form.name', 'Unsaved customer name')
            ->assertSet('form.email', 'unsaved@example.test')
            ->assertSet('form.profile.phone', '+385 99 111 222')
            ->assertSet('form.billing_address.address_line_1', 'Unsaved billing street');

        $this->actingAs($admin)->get(route('admin.users.edit', ['user' => $customer, 'tab' => 'pricing']))
            ->assertOk()->assertSee('data-customer-price-manager', false)->assertSee('B2B cjenik kupca');
        $this->assertNotSame('Unsaved customer name', $customer->fresh()->name);
    }

    public function test_customer_category_and_supplier_rule_can_be_created_edited_and_deleted_without_changing_the_group(): void
    {
        [$admin, $customer, $group, $catalog, $product, $category, $manufacturer] = $this->fixture();
        $otherCustomer = $this->buyer($group);
        $inheritedEntries = $catalog->entries()->get()->map->getAttributes()->all();

        $manager = Livewire::actingAs($admin)->test(CustomerPriceManager::class, ['userId' => $customer->id])
            ->assertSee('B2B50')->assertSee('Naslijeđeni uvjeti primarne grupe')
            ->call('newRule')->assertSet('showRuleForm', true)
            ->set('ruleForm.name', 'Portwest radne cipele 60 %')
            ->set('ruleForm.percent', '60,1250')
            ->set('ruleForm.category_ids', [(string) $category->id])
            ->set('ruleForm.manufacturer_ids', [(string) $manufacturer->id])
            ->call('saveRule')->assertHasNoErrors()->assertSet('showRuleForm', false)
            ->assertSee('Portwest radne cipele 60 %')->assertSee('60.125 %')
            ->assertSee('39,8750 EUR')->assertSee('50,0000 EUR');

        $rule = CustomerDiscountRule::query()->sole();
        $this->assertSame($customer->id, $rule->user_id);
        $this->assertSame([$category->id], $rule->category_ids);
        $this->assertSame([$manufacturer->id], $rule->manufacturer_ids);
        $this->assertSame(39.875, app(PriceCatalogResolver::class)->resolve($product, $customer)->price);
        $this->assertSame(50.0, app(PriceCatalogResolver::class)->resolve($product, $otherCustomer)->price);

        $manager->call('editRule', $rule->id)->assertSet('ruleId', $rule->id)
            ->assertSet('ruleForm.percent', '60.1250')
            ->set('ruleForm.percent', '65')->call('saveRule')->assertHasNoErrors()
            ->assertSee('35,0000 EUR');
        $this->assertSame('65.0000', $rule->fresh()->percent);
        $manager->call('deleteRule', $rule->id)->assertHasNoErrors()
            ->assertSee('Kupac nema posebnih popusta.');

        $this->assertSoftDeleted($rule);
        $this->assertSame(0, CustomerDiscountRule::query()->count());
        $this->assertSame($group->id, $customer->fresh()->b2bAccount->customer_group_id);
        $this->assertSame($inheritedEntries, $catalog->entries()->get()->map->getAttributes()->all());
        $this->assertSame(50.0, app(PriceCatalogResolver::class)->resolve($product, $customer)->price);
    }

    public function test_personalizing_an_inherited_rule_only_prepares_a_copy_until_saved(): void
    {
        [$admin, $customer, $group, $catalog, , $category, $manufacturer, $inherited] = $this->fixture();
        $original = $inherited->fresh()->getAttributes();

        $manager = Livewire::actingAs($admin)->test(CustomerPriceManager::class, ['userId' => $customer->id])
            ->assertSee('Naslijeđeni popust 50 %')->assertSee('Prilagodi kupcu')
            ->call('personalizeRule', $inherited->id)
            ->assertSet('showRuleForm', true)->assertSet('ruleId', null)
            ->assertSet('ruleForm.name', 'Naslijeđeni popust 50 %')
            ->assertSet('ruleForm.percent', '50.0000')
            ->assertSet('ruleForm.category_ids', [$category->id])
            ->assertSet('ruleForm.manufacturer_ids', [$manufacturer->id])
            ->assertSet('ruleForm.starts_at', '')->assertSet('ruleForm.ends_at', '');

        $this->assertDatabaseCount('customer_discount_rules', 0);
        $this->assertSame($original, $inherited->fresh()->getAttributes());
        $manager->set('ruleForm.percent', '60')->call('saveRule')->assertHasNoErrors();
        $this->assertDatabaseHas('customer_discount_rules', ['user_id' => $customer->id, 'percent' => '60.0000']);
        $this->assertSame($original, $inherited->fresh()->getAttributes());
        $this->assertSame(PriceCatalog::ACTIVE, $catalog->fresh()->status);
        $this->assertSame($group->id, $customer->fresh()->b2bAccount->customer_group_id);
    }

    #[DataProvider('foreignRuleOperations')]
    public function test_another_customers_rule_cannot_be_edited_or_deleted(string $method): void
    {
        [$admin, $customer, $group] = $this->fixture();
        $otherCustomer = $this->buyer($group);
        $rule = $this->rule($otherCustomer);

        try {
            Livewire::actingAs($admin)->test(CustomerPriceManager::class, ['userId' => $customer->id])
                ->assertDontSee('Other customer agreement')->call($method, $rule->id);
            $this->fail('A rule belonging to another customer must not be accessible.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(CustomerDiscountRule::class, $exception->getModel());
        }
        $this->assertModelExists($rule);
        $this->assertSame($otherCustomer->id, $rule->fresh()->user_id);
    }

    public static function foreignRuleOperations(): array
    {
        return [['editRule'], ['deleteRule']];
    }

    #[DataProvider('readOnlyMutations')]
    public function test_a_pricing_viewer_can_read_but_cannot_mutate_customer_pricing(string $method): void
    {
        [, $customer, , , , , , $inherited] = $this->fixture();
        $rule = $this->rule($customer);
        $viewer = User::factory()->create();
        Bouncer::allow($viewer)->to('admin.access');
        Bouncer::allow($viewer)->to('catalog.b2b_prices.view');
        $arguments = match ($method) {
            'editRule', 'deleteRule' => [$rule->id],
            'personalizeRule' => [$inherited->id],
            default => [],
        };

        Livewire::actingAs($viewer)->test(CustomerPriceManager::class, ['userId' => $customer->id])
            ->assertSee('Other customer agreement')
            ->assertDontSee('wire:click="newRule"', false)
            ->assertDontSee('wire:click="editRule(', false)
            ->assertDontSee('wire:click="deleteRule(', false)
            ->call($method, ...$arguments)->assertForbidden();
        $this->assertDatabaseCount('customer_discount_rules', 1);
        $this->assertSame('60.0000', $rule->fresh()->percent);
    }

    public static function readOnlyMutations(): array
    {
        return [['newRule'], ['saveRule'], ['editRule'], ['deleteRule'], ['personalizeRule']];
    }

    public function test_customer_without_b2b_profile_sees_guidance_and_cannot_save_a_discount(): void
    {
        [$admin] = $this->fixture();
        $customer = User::factory()->create();
        Livewire::actingAs($admin)->test(CustomerPriceManager::class, ['userId' => $customer->id])
            ->assertSee('Najprije kreirajte i spremite B2B profil')
            ->assertDontSee('wire:click="newRule"', false)
            ->set('ruleForm.name', 'Premature discount')->set('ruleForm.percent', '60')
            ->call('saveRule')->assertHasErrors('ruleForm.customer');
        $this->assertDatabaseCount('customer_discount_rules', 0);
    }

    public function test_invalid_discount_errors_are_attached_to_the_rule_form(): void
    {
        [$admin, $customer] = $this->fixture();
        Livewire::actingAs($admin)->test(CustomerPriceManager::class, ['userId' => $customer->id])
            ->call('newRule')->set('ruleForm.name', '')->set('ruleForm.percent', '101')
            ->call('saveRule')->assertHasErrors(['ruleForm.name', 'ruleForm.percent']);
        $this->assertDatabaseCount('customer_discount_rules', 0);
    }

    public function test_customer_cannot_open_the_admin_pricing_component(): void
    {
        [, $customer] = $this->fixture();
        Livewire::actingAs($customer)->test(CustomerPriceManager::class, ['userId' => $customer->id])
            ->assertForbidden();
    }

    private function fixture(): array
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        $group = CustomerGroup::query()->create(['code' => 'b2b50', 'name' => 'B2B50', 'is_active' => true]);
        $customer = $this->buyer($group);
        $manufacturer = Manufacturer::query()->create(['code' => 'Portwest', 'is_active' => true]);
        $category = Category::query()->create(['code' => 'Radne cipele', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $product = Product::query()->create(['code' => 'SHOE-01', 'sku' => 'SHOE-01', 'manufacturer_id' => $manufacturer->id, 'base_price' => '100', 'is_active' => true]);
        $product->categories()->attach($category);
        $catalog = app(PriceCatalogService::class)->createDraft('Aktivni B2B cjenik', $admin);
        foreach ([PriceCatalogEntry::BASE => '100', PriceCatalogEntry::GROUP => '50'] as $kind => $price) {
            PriceCatalogEntry::query()->create([
                'price_catalog_id' => $catalog->id, 'source_key' => 'fixture:'.$kind,
                'product_id' => $product->id, 'kind' => $kind, 'price' => $price,
                'customer_group_id' => $kind === PriceCatalogEntry::GROUP ? $group->id : null,
                'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true,
            ]);
        }
        $inherited = CatalogGroupDiscountRule::query()->create([
            'price_catalog_id' => $catalog->id, 'name' => 'Naslijeđeni popust 50 %', 'percent' => 50,
            'customer_group_ids' => [$group->id], 'manufacturer_ids' => [$manufacturer->id],
            'category_ids' => [$category->id], 'include_descendants' => true, 'excluded_product_ids' => [],
            'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'priority' => 0, 'is_active' => true,
        ]);
        app(PriceCatalogService::class)->activate($catalog, $admin);

        return [$admin, $customer, $group, $catalog, $product, $category, $manufacturer, $inherited];
    }

    private function buyer(CustomerGroup $group): User
    {
        $customer = User::factory()->create();
        Bouncer::assign('customer')->to($customer);
        B2BAccount::query()->create([
            'user_id' => $customer->id, 'company_name' => 'Test Company '.$customer->id,
            'oib' => str_pad((string) $customer->id, 11, '0', STR_PAD_LEFT),
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id,
        ]);

        return $customer;
    }

    private function rule(User $customer): CustomerDiscountRule
    {
        return CustomerDiscountRule::query()->create([
            'user_id' => $customer->id, 'name' => 'Other customer agreement', 'percent' => 60,
            'manufacturer_ids' => [], 'category_ids' => [], 'excluded_product_ids' => [],
            'include_descendants' => true, 'priority' => 0, 'is_active' => true,
        ]);
    }
}
