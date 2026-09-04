<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form;
use App\Models\Catalog\Category\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Izdvojene kategorije')
            ->assertSee('Catalog Category')
            ->assertDontSee('Blog Category')
            ->set('pickerItemId', $catalogCategory->id)
            ->call('addSelectedItem')
            ->assertSet('form.selected_item_ids', [$catalogCategory->id]);
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
