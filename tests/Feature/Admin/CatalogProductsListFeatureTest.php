<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Manager as ProductManager;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CatalogProductsListFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_list_filters_by_state_stock_and_category(): void
    {
        $user = $this->makeAdminUser();

        $catalogRoot = new Category([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'catalog-root',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 10,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $catalogRoot->saveAsRoot();
        $catalogRoot->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'en',
            'name' => 'Catalog',
            'slug' => 'catalog',
            'description' => null,
            'meta_title' => null,
            'meta_description' => null,
            'payload' => null,
        ]);

        $targetCategory = new Category([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'target-cat',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 20,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $targetCategory->appendToNode($catalogRoot)->save();
        $targetCategory->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'en',
            'name' => 'Target Category',
            'slug' => 'target-category',
            'description' => null,
            'meta_title' => null,
            'meta_description' => null,
            'payload' => null,
        ]);

        $otherCategory = new Category([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'other-cat',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 30,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $otherCategory->appendToNode($catalogRoot)->save();
        $otherCategory->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'en',
            'name' => 'Other Category',
            'slug' => 'other-category',
            'description' => null,
            'meta_title' => null,
            'meta_description' => null,
            'payload' => null,
        ]);

        $matching = $this->makeProduct($user, 'P-FILTER-1', 'Filter Match', true, 8, 25);
        $outOfStock = $this->makeProduct($user, 'P-FILTER-2', 'Filter Out Of Stock', true, 0, 19);
        $inactive = $this->makeProduct($user, 'P-FILTER-3', 'Filter Inactive', false, 12, 29);

        $matching->categories()->attach($targetCategory->id, ['sort_order' => 0, 'is_primary' => true]);
        $outOfStock->categories()->attach($targetCategory->id, ['sort_order' => 0, 'is_primary' => true]);
        $inactive->categories()->attach($otherCategory->id, ['sort_order' => 0, 'is_primary' => true]);

        Livewire::actingAs($user)
            ->test(ProductManager::class)
            ->set('locale', 'en')
            ->set('stateFilter', 'active')
            ->set('stockFilter', 'in_stock')
            ->set('categoryFilter', (string) $targetCategory->id)
            ->assertSee('Filter Match')
            ->assertDontSee('Filter Out Of Stock')
            ->assertDontSee('Filter Inactive');
    }

    public function test_product_list_sorts_by_price_desc(): void
    {
        $user = $this->makeAdminUser();

        $this->makeProduct($user, 'P-SORT-1', 'Price Low', true, 10, 10);
        $this->makeProduct($user, 'P-SORT-2', 'Price High', true, 10, 30);
        $this->makeProduct($user, 'P-SORT-3', 'Price Mid', true, 10, 20);

        Livewire::actingAs($user)
            ->test(ProductManager::class)
            ->set('locale', 'en')
            ->set('sortBy', 'price_desc')
            ->assertSeeInOrder(['Price High', 'Price Mid', 'Price Low']);
    }

    public function test_product_list_shows_category_name_and_icon_actions(): void
    {
        $user = $this->makeAdminUser();
        $category = new Category([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'heat-pumps',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 10,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $category->saveAsRoot();
        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'en',
            'name' => 'Toplinske pumpe',
            'slug' => 'skriveni-slug-kategorije',
        ]);

        $product = $this->makeProduct($user, 'P-CATEGORY-NAME', 'Category Name Product', true, 8, 125);
        $product->translations()->where('locale', 'en')->update([
            'slug' => 'skriveni-slug-artikla',
        ]);
        $product->categories()->attach($category->id, [
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ProductManager::class)
            ->set('locale', 'en')
            ->assertSee('Toplinske pumpe')
            ->assertSee(__('Preview'))
            ->assertSeeHtml('href="'.route('products.show', ['slug' => 'skriveni-slug-artikla']).'"')
            ->assertSeeHtml('target="_blank"')
            ->assertSeeHtml('rel="noopener noreferrer"')
            ->assertSeeHtml('aria-label="'.__('Preview').': Category Name Product"')
            ->assertSeeHtml(asset('front-theme/fonts/sprites/solid.svg').'#eye')
            ->assertSeeHtml('aria-label="'.__('admin.common.edit').': Category Name Product"')
            ->assertSeeHtml(asset('front-theme/fonts/sprites/solid.svg').'#pen-to-square')
            ->assertSeeHtml('aria-label="'.__('admin.common.delete').': Category Name Product"')
            ->assertSeeHtml(asset('front-theme/fonts/sprites/solid.svg').'#trash')
            ->assertDontSee('skriveni-slug-kategorije');
    }

    public function test_admin_can_delete_product_from_list_and_cleanup_related_records(): void
    {
        $user = $this->makeAdminUser();
        $product = $this->makeProduct($user, 'P-DELETE-1', 'Delete Product', true, 4, 12);

        $action = CatalogAction::query()->create([
            'code' => 'delete-product-action',
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 5,
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'priority' => 10,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $action->targets()->create([
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'target_id' => $product->id,
            'sort_order' => 0,
        ]);

        $comment = Comment::query()->create([
            'commentable_type' => Product::class,
            'commentable_id' => $product->id,
            'user_id' => $user->id,
            'parent_id' => null,
            'author_name' => $user->name,
            'author_email' => $user->email,
            'locale' => 'en',
            'body' => 'Delete me',
            'rating' => 5,
            'status' => Comment::STATUS_APPROVED,
            'is_featured' => false,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'payload' => null,
        ]);

        Livewire::actingAs($user)
            ->test(ProductManager::class)
            ->call('delete', $product->id)
            ->assertDispatched('notify');

        $this->assertDatabaseMissing('products', [
            'id' => $product->id,
        ]);
        $this->assertDatabaseMissing('catalog_action_targets', [
            'action_id' => $action->id,
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'target_id' => $product->id,
        ]);
        $this->assertSoftDeleted('content_comments', [
            'id' => $comment->id,
        ]);
    }

    private function makeProduct(User $user, string $code, string $name, bool $isActive, int $stockQty, float $basePrice): Product
    {
        $product = Product::query()->create([
            'code' => $code,
            'sku' => $code.'-SKU',
            'manufacturer_id' => null,
            'is_active' => $isActive,
            'base_price' => $basePrice,
            'stock_qty' => $stockQty,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $product->translations()->create([
            'locale' => 'en',
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'excerpt' => null,
            'description' => null,
            'meta_title' => null,
            'meta_description' => null,
            'payload' => null,
        ]);

        return $product;
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
