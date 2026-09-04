<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Pricing\B2BPriceRuleForm;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\B2BPriceRule;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\ProductGroupPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class B2BPriceRulesFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_group_rule_for_selected_brands(): void
    {
        $user = $this->makeAdminUser();
        $group = $this->makeCustomerGroup('partners');
        $manufacturer = Manufacturer::query()->create([
            'code' => 'termol-brand',
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 10,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(B2BPriceRuleForm::class)
            ->set('form.code', 'partners-brand-15')
            ->set('form.name', 'Partneri - brend 15%')
            ->set('form.customer_group_id', $group->id)
            ->set('form.calculation_type', B2BPriceRule::TYPE_PERCENTAGE_DISCOUNT)
            ->set('form.value', 15)
            ->set('form.target_type', B2BPriceRule::TARGET_MANUFACTURER)
            ->set('form.target_ids', [$manufacturer->id])
            ->set('form.minimum_quantity', 5)
            ->set('form.currency_code', 'EUR')
            ->set('form.priority', 30)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.b2b-prices'));

        $rule = B2BPriceRule::query()->where('code', 'PARTNERS-BRAND-15')->firstOrFail();

        $this->assertDatabaseHas('catalog_b2b_price_rules', [
            'id' => $rule->id,
            'customer_group_id' => $group->id,
            'target_type' => B2BPriceRule::TARGET_MANUFACTURER,
            'minimum_quantity' => 5,
            'priority' => 30,
        ]);
        $this->assertDatabaseHas('catalog_b2b_price_rule_targets', [
            'rule_id' => $rule->id,
            'target_type' => B2BPriceRule::TARGET_MANUFACTURER,
            'target_id' => $manufacturer->id,
        ]);
    }

    public function test_resolver_uses_most_specific_matching_rule_and_honours_quantity(): void
    {
        $user = User::factory()->create();
        $group = $this->makeCustomerGroup('wholesale');
        $user->customerGroups()->attach($group);

        $manufacturer = Manufacturer::query()->create([
            'code' => 'brand-a',
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 10,
        ]);
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'category-a',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 10,
        ]);
        $product = Product::query()->create([
            'code' => 'specific-price-product',
            'sku' => 'SPECIFIC-PRICE-PRODUCT',
            'manufacturer_id' => $manufacturer->id,
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 100,
        ]);
        $product->categories()->attach($category->id, [
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $this->createPercentageRule($group, 'all-5', B2BPriceRule::TARGET_ALL, 5);
        $this->createPercentageRule(
            $group,
            'brand-10',
            B2BPriceRule::TARGET_MANUFACTURER,
            10,
            [$manufacturer->id],
        );
        $this->createPercentageRule(
            $group,
            'category-15',
            B2BPriceRule::TARGET_CATEGORY,
            15,
            [$category->id],
        );
        $productRule = $this->createPercentageRule(
            $group,
            'product-20',
            B2BPriceRule::TARGET_PRODUCT,
            20,
            [$product->id],
            minimumQuantity: 10,
        );

        $resolver = app(ProductGroupPriceResolver::class);
        $belowThreshold = $resolver->resolve($product, $user, 5);
        $atThreshold = $resolver->resolve($product, $user, 10);

        $this->assertSame(85.0, $belowThreshold?->price);
        $this->assertSame('b2b_rule', $belowThreshold?->source_type);
        $this->assertSame(80.0, $atThreshold?->price);
        $this->assertSame($productRule->id, $atThreshold?->rule_id);
    }

    public function test_admin_can_create_an_individual_contract_price_for_approved_b2b_customer(): void
    {
        $admin = $this->makeAdminUser();
        $customer = User::factory()->create();
        B2BAccount::query()->create([
            'user_id' => $customer->id,
            'status' => B2BAccount::STATUS_APPROVED,
            'company_name' => 'Individualni kupac d.o.o.',
            'oib' => '12345678901',
            'country_code' => 'HR',
            'requested_at' => now(),
            'reviewed_at' => now(),
        ]);
        $product = Product::query()->create([
            'code' => 'individual-price-product',
            'sku' => 'INDIVIDUAL-PRICE-PRODUCT',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 100,
        ]);

        Livewire::actingAs($admin)
            ->test(B2BPriceRuleForm::class)
            ->set('form.code', 'individual-fixed-price')
            ->set('form.name', 'Individualna ugovorena cijena')
            ->set('form.audience_type', 'customer')
            ->set('form.user_id', $customer->id)
            ->set('form.contract_number', 'UG-IND-2026')
            ->set('form.calculation_type', B2BPriceRule::TYPE_FIXED_PRICE)
            ->set('form.value', 62.5)
            ->set('form.target_type', B2BPriceRule::TARGET_PRODUCT)
            ->set('form.target_ids', [$product->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.b2b-prices'));

        $this->assertDatabaseHas('catalog_b2b_price_rules', [
            'code' => 'INDIVIDUAL-FIXED-PRICE',
            'user_id' => $customer->id,
            'customer_group_id' => null,
            'contract_number' => 'UG-IND-2026',
            'value' => 62.5,
        ]);
    }

    public function test_direct_product_group_price_has_priority_over_inherited_rules(): void
    {
        $user = User::factory()->create();
        $group = $this->makeCustomerGroup('direct');
        $user->customerGroups()->attach($group);
        $product = Product::query()->create([
            'code' => 'direct-price-product',
            'sku' => 'DIRECT-PRICE-PRODUCT',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 100,
        ]);
        $this->createPercentageRule($group, 'product-30', B2BPriceRule::TARGET_PRODUCT, 30, [$product->id]);
        $direct = ProductGroupPrice::query()->create([
            'product_id' => $product->id,
            'customer_group_id' => $group->id,
            'minimum_quantity' => 1,
            'price' => 72.5,
            'currency_code' => 'EUR',
            'is_active' => true,
        ]);

        $resolved = app(ProductGroupPriceResolver::class)->resolve($product, $user, 1);

        $this->assertSame('product_group_price', $resolved?->source_type);
        $this->assertSame($direct->id, $resolved?->group_price_id);
        $this->assertSame(72.5, $resolved?->price);
    }

    public function test_admin_b2b_pages_are_available_to_admin_role(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('admin.b2b-prices'))
            ->assertOk()
            ->assertSee('B2B cjenici');

        $this->actingAs($user)
            ->get(route('admin.b2b-prices.create'))
            ->assertOk()
            ->assertSee('Novo B2B pravilo');
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function makeCustomerGroup(string $code): CustomerGroup
    {
        return CustomerGroup::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 10,
        ]);
    }

    /**
     * @param  array<int, int>  $targetIds
     */
    private function createPercentageRule(
        CustomerGroup $group,
        string $code,
        string $targetType,
        float $percentage,
        array $targetIds = [],
        int $minimumQuantity = 1,
    ): B2BPriceRule {
        $rule = B2BPriceRule::query()->create([
            'code' => $code,
            'name' => $code,
            'customer_group_id' => $group->id,
            'calculation_type' => B2BPriceRule::TYPE_PERCENTAGE_DISCOUNT,
            'value' => $percentage,
            'target_type' => $targetType,
            'minimum_quantity' => $minimumQuantity,
            'currency_code' => 'EUR',
            'priority' => 0,
            'is_active' => true,
        ]);

        foreach ($targetIds as $index => $targetId) {
            $rule->targets()->create([
                'target_type' => $targetType,
                'target_id' => $targetId,
                'sort_order' => $index,
            ]);
        }

        return $rule;
    }
}
