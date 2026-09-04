<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Action\Form as ActionForm;
use App\Livewire\Admin\Catalog\Action\Manager as ActionManager;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Catalog\ActionResolverService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CatalogActionsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_actions_routes_are_blocked_when_feature_disabled(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_actions', false);

        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->get('/admin/actions');

        $response
            ->assertRedirect(route('admin.settings.system.catalog-features'))
            ->assertSessionHas('notify.type', 'warning');
    }

    public function test_admin_can_create_product_action_with_target_assignment(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_actions', true);

        $user = $this->makeAdminUser();
        $category = $this->createCatalogCategory($user);
        $product = $this->createProduct($user);
        $product->categories()->sync([
            $category->id => ['sort_order' => 0, 'is_primary' => true],
        ]);

        Livewire::actingAs($user)
            ->test(ActionForm::class)
            ->set('form.code', 'promo-test-10')
            ->set('form.locale', 'en')
            ->set('form.is_active', true)
            ->set('form.scope', CatalogAction::SCOPE_PRODUCT)
            ->set('form.type', CatalogAction::TYPE_PERCENTAGE)
            ->set('form.discount_value', '10')
            ->set('form.target_type', CatalogAction::TARGET_PRODUCT)
            ->set('form.target_ids', [$product->id])
            ->set('form.audience_type', CatalogAction::AUDIENCE_ALL)
            ->set('form.title', 'Promo Test 10')
            ->set('form.description', 'Testing action save.')
            ->call('save')
            ->assertRedirect(route('admin.actions', ['locale' => 'en']));

        $action = CatalogAction::query()
            ->where('code', 'promo-test-10')
            ->first();

        $this->assertNotNull($action);
        $this->assertSame(CatalogAction::TYPE_PERCENTAGE, $action->type);
        $this->assertTrue($action->targets()->where('target_id', $product->id)->exists());
        $this->assertSame(
            'Promo Test 10',
            (string) $action->translation('en')->first()?->title
        );
    }

    public function test_admin_can_create_cart_discount_coupon_action(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_actions', true);

        $user = $this->makeAdminUser();

        Livewire::actingAs($user)
            ->test(ActionForm::class)
            ->set('form.code', 'cart-bali-10')
            ->set('form.locale', 'en')
            ->set('form.is_active', true)
            ->set('form.scope', CatalogAction::SCOPE_CART)
            ->set('form.type', CatalogAction::TYPE_PERCENTAGE)
            ->set('form.discount_value', '10')
            ->set('form.min_subtotal', '0.01')
            ->set('form.target_type', CatalogAction::TARGET_ALL)
            ->set('form.audience_type', CatalogAction::AUDIENCE_ALL)
            ->set('form.coupon_code', 'bali10')
            ->set('form.title', 'Cart BALI10')
            ->call('save')
            ->assertRedirect(route('admin.actions', ['locale' => 'en']));

        $this->assertDatabaseHas('catalog_actions', [
            'code' => 'cart-bali-10',
            'scope' => CatalogAction::SCOPE_CART,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'coupon_code' => 'BALI10',
        ]);
    }

    public function test_admin_can_delete_action_from_manager(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_actions', true);

        $user = $this->makeAdminUser();
        $product = $this->createProduct($user, code: 'delete-action-product', sku: 'DEL-ACT-1');

        $action = CatalogAction::query()->create([
            'code' => 'delete-action',
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'priority' => 10,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $action->translations()->create([
            'locale' => 'en',
            'title' => 'Delete Action',
        ]);
        $action->targets()->create([
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'target_id' => $product->id,
            'sort_order' => 0,
        ]);

        Livewire::actingAs($user)
            ->test(ActionManager::class)
            ->call('delete', $action->id)
            ->assertDispatched('notify');

        $this->assertDatabaseMissing('catalog_actions', [
            'id' => $action->id,
        ]);
        $this->assertDatabaseMissing('catalog_action_translations', [
            'action_id' => $action->id,
        ]);
        $this->assertDatabaseMissing('catalog_action_targets', [
            'action_id' => $action->id,
        ]);
    }

    public function test_action_resolver_selects_best_price_action(): void
    {
        $user = $this->makeAdminUser();
        $product = $this->createProduct($user, code: 'resolver-product', sku: 'RES-1', basePrice: 100.00);

        $globalAction = CatalogAction::query()->create([
            'code' => 'global-five',
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 5,
            'target_type' => CatalogAction::TARGET_ALL,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'priority' => 10,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $globalAction->translations()->create([
            'locale' => 'en',
            'title' => 'Global 5%',
        ]);

        $productAction = CatalogAction::query()->create([
            'code' => 'product-ten',
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'priority' => 20,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $productAction->translations()->create([
            'locale' => 'en',
            'title' => 'Product 10%',
        ]);
        $productAction->targets()->create([
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'target_id' => $product->id,
            'sort_order' => 0,
        ]);

        $resolver = app(ActionResolverService::class);
        $resolved = $resolver->resolveProductAction($product);

        $this->assertNotNull($resolved);
        $this->assertSame($productAction->id, $resolved->id);
        $this->assertSame(90.0, $resolver->applyToPrice(100.0, $resolved));
    }

    public function test_admin_can_create_action_targeted_to_user_group(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_actions', true);

        $admin = $this->makeAdminUser();
        $group = CustomerGroup::query()->create([
            'code' => 'vip',
            'name' => 'VIP',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 10,
        ]);

        Livewire::actingAs($admin)
            ->test(ActionForm::class)
            ->set('form.code', 'promo-vip-15')
            ->set('form.locale', 'en')
            ->set('form.scope', CatalogAction::SCOPE_PRODUCT)
            ->set('form.type', CatalogAction::TYPE_PERCENTAGE)
            ->set('form.discount_value', '15')
            ->set('form.target_type', CatalogAction::TARGET_ALL)
            ->set('form.audience_type', CatalogAction::AUDIENCE_USER_GROUP)
            ->set('form.customer_group_id', $group->id)
            ->set('form.title', 'VIP 15%')
            ->call('save')
            ->assertRedirect(route('admin.actions', ['locale' => 'en']));

        $this->assertDatabaseHas('catalog_actions', [
            'code' => 'promo-vip-15',
            'audience_type' => CatalogAction::AUDIENCE_USER_GROUP,
            'customer_group_id' => $group->id,
        ]);
    }

    public function test_action_resolver_applies_user_group_audience(): void
    {
        $admin = $this->makeAdminUser();
        $product = $this->createProduct($admin, code: 'resolver-group-product', sku: 'RES-G-1', basePrice: 100.00);

        $vip = CustomerGroup::query()->create([
            'code' => 'vip',
            'name' => 'VIP',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 10,
        ]);

        $vipUser = User::factory()->create();
        $nonVipUser = User::factory()->create();
        $vipUser->customerGroups()->sync([$vip->id]);

        $allAction = CatalogAction::query()->create([
            'code' => 'global-five-group-test',
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 5,
            'target_type' => CatalogAction::TARGET_ALL,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'priority' => 10,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $allAction->translations()->create([
            'locale' => 'en',
            'title' => 'All users 5%',
        ]);

        $vipAction = CatalogAction::query()->create([
            'code' => 'vip-ten-group-test',
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_ALL,
            'audience_type' => CatalogAction::AUDIENCE_USER_GROUP,
            'customer_group_id' => $vip->id,
            'is_active' => true,
            'priority' => 20,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $vipAction->translations()->create([
            'locale' => 'en',
            'title' => 'VIP users 10%',
        ]);

        $resolver = app(ActionResolverService::class);
        $vipResolved = $resolver->resolveProductAction($product, $vipUser);
        $nonVipResolved = $resolver->resolveProductAction($product, $nonVipUser);

        $this->assertNotNull($vipResolved);
        $this->assertSame($vipAction->id, $vipResolved->id);

        $this->assertNotNull($nonVipResolved);
        $this->assertSame($allAction->id, $nonVipResolved->id);
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function createCatalogCategory(User $user): Category
    {
        $category = new Category([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'test-category',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 0,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $category->saveAsRoot();

        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'en',
            'name' => 'Test Category',
            'slug' => 'test-category',
        ]);

        return $category;
    }

    private function createProduct(
        User $user,
        string $code = 'test-product',
        string $sku = 'TEST-1',
        float $basePrice = 19.99
    ): Product {
        $product = Product::query()->create([
            'code' => $code,
            'sku' => $sku,
            'is_active' => true,
            'base_price' => $basePrice,
            'stock_qty' => 5,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $product->translations()->create([
            'locale' => 'en',
            'name' => 'Test Product',
            'slug' => $code,
        ]);

        return $product;
    }
}
