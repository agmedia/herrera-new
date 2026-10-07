<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form;
use App\Models\Catalog\Category\Category;
use App\Models\Content\ContentBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class FeaturedCategoriesBlockAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_featured_categories_type_defaults_to_desktop_home_categories_placement(): void
    {
        $catalogCategory = $this->createCategory(
            Category::SCOPE_CATALOG,
            'catalog-category',
            'Catalog Category'
        );
        $this->createCategory(Category::SCOPE_BLOG, 'blog-category', 'Blog Category');

        Livewire::test(Form::class)
            ->set('form.type', 'featured_categories')
            ->assertSet('form.slot_placement', 'home.categories')
            ->assertSet('form.slot_frontend_variant', 'desktop')
            ->assertSet('form.category_source', 'all_root')
            ->assertSee('Sve glavne kategorije')
            ->set('form.category_source', 'manual')
            ->assertSee('Izdvojene kategorije')
            ->assertSee('Catalog Category')
            ->assertDontSee('Blog Category')
            ->set('pickerItemId', $catalogCategory->id)
            ->call('addSelectedItem')
            ->assertSet('form.selected_item_ids', [$catalogCategory->id]);
    }

    public function test_all_root_mode_can_be_saved_without_manual_items_and_reloaded(): void
    {
        $code = 'featured-auto-test-'.strtolower(\Illuminate\Support\Str::random(8));
        try {
            Livewire::test(Form::class)
                ->set('form.type', 'featured_categories')
                ->set('form.code', $code)
                ->set('form.name', 'All catalog categories')
                ->call('save')
                ->assertHasNoErrors()
                ->assertRedirect(route('admin.content.blocks'));

            $block = ContentBlock::query()->where('code', $code)->firstOrFail();
            $this->assertSame('all_root', $block->payload['category_source']);
            $this->assertSame(0, $block->items()->count());

            Livewire::test(Form::class, ['blockId' => $block->id])
                ->assertSet('form.category_source', 'all_root');
        } finally {
            File::delete(resource_path('views/front/content-blocks/instances/'.$code.'.blade.php'));
        }
    }

    public function test_legacy_block_defaults_to_manual_and_preserves_selection(): void
    {
        $category = $this->createCategory(Category::SCOPE_CATALOG, 'legacy-category', 'Legacy Category');
        $block = ContentBlock::query()->create([
            'code' => 'legacy-featured-categories',
            'name' => 'Legacy Featured Categories',
            'type' => 'featured_categories',
            'is_active' => true,
            'payload' => null,
        ]);
        $block->items()->create(['item_type' => 'category', 'item_id' => $category->id, 'sort_order' => 0]);

        Livewire::test(Form::class, ['blockId' => $block->id])
            ->assertSet('form.category_source', 'manual')
            ->assertSet('form.selected_item_ids', [$category->id])
            ->set('form.category_source', 'all_root')
            ->set('form.category_source', 'manual')
            ->assertSet('form.selected_item_ids', [$category->id]);
    }

    private function createCategory(string $scope, string $code, string $name): Category
    {
        $category = Category::query()->create([
            'scope' => $scope,
            'code' => $code,
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 0,
        ]);
        $category->translations()->create([
            'scope' => $scope,
            'locale' => 'en',
            'name' => $name,
            'slug' => $code,
        ]);

        return $category;
    }
}
