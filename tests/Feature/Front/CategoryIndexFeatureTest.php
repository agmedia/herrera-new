<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Category\CategoryTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryIndexFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_index_only_lists_root_categories_as_links(): void
    {
        config([
            'app.locale' => 'en',
            'app.fallback_locale' => 'en',
        ]);
        app()->setLocale('en');

        $root = $this->createCategory('root-category', 'Root category', 'root-category');
        $child = $this->createCategory('child-category', 'Child category', 'child-category', $root->id);

        Category::query()->fixTree();

        $response = $this->get('/categories');

        $response
            ->assertOk()
            ->assertViewHas('categories', fn ($categories): bool => $categories->pluck('id')->all() === [$root->id])
            ->assertSee('data-category-card="'.$root->id.'"', false)
            ->assertDontSee('data-category-card="'.$child->id.'"', false)
            ->assertSee('href="'.route('categories.show', ['slug' => 'root-category']).'"', false)
            ->assertSee('front-theme/styles/category-index.css', false);
    }

    private function createCategory(string $code, string $name, string $slug, ?int $parentId = null): Category
    {
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => $code,
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 1,
            'parent_id' => $parentId,
        ]);

        CategoryTranslation::query()->create([
            'category_id' => $category->id,
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'en',
            'name' => $name,
            'slug' => $slug,
            'description' => $name.' description',
        ]);

        return $category;
    }
}
