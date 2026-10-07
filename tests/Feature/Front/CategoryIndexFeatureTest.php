<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Category\CategoryTranslation;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
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

    public function test_herrera_directory_reuses_home_photography_and_icons_in_english_without_duplicate_heading(): void
    {
        config(['app.locale' => 'en', 'app.fallback_locale' => 'en',
            'herrera-category-photography.rasvjeta' => 'assets/brand/herrera-logo.svg']);
        app()->setLocale('en');
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $category = $this->createCategory('lighting', 'Lighting', 'lighting');
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG,
            'name' => 'Rasvjeta', 'slug' => 'rasvjeta']);
        $this->createCategory('hidden-child', 'Hidden child', 'hidden-child', $category->id);
        Category::query()->fixTree();

        $response = $this->get(route('categories.index'))->assertOk()
            ->assertSee('herrera-wide-catalog-main', false)
            ->assertSee('front-theme/styles/herrera-home-categories.css', false);
        $xpath = $this->xpath($response->getContent());
        $module = $xpath->query('//main//*[@data-herrera-category-module]')->item(0);
        $this->assertNotNull($module);
        $this->assertSame(1, $xpath->query('.//*[@data-category-card]', $module)->count());
        $card = $xpath->query('.//*[@data-category-card]', $module)->item(0);
        $this->assertSame((string) $category->id, $card->getAttribute('data-category-card'));
        $this->assertSame(route('categories.show', ['slug' => 'lighting']), $card->getAttribute('href'));
        $this->assertStringContainsString('Lighting', $card->textContent);
        $imageUrl = $xpath->query('.//img', $card)->item(0)->getAttribute('src');
        $this->assertSame(asset('assets/brand/herrera-logo.svg'), $imageUrl);
        $this->assertSame(1, $xpath->query('.//use[contains(@href, "#lightbulb")]', $card)->count());
        $this->assertSame(1, $xpath->query('//main//h1')->count());
        $this->assertSame(0, $xpath->query('.//*[contains(@class, "herrera-category-module-heading")]', $module)->count());
        $this->assertSame(0, $xpath->query('.//a[@href="'.route('categories.index').'"]', $module)->count());
        $this->assertSame(1, $xpath->query('.//*[@data-herrera-category-support]', $module)->count());
        $homeXpath = $this->xpath($this->get(route('home'))->assertOk()->getContent());
        $this->assertSame($imageUrl, $homeXpath->query('//*[@data-herrera-category-module]//*[@data-category-card="'.$category->id.'"]//img')->item(0)->getAttribute('src'));
    }

    public function test_other_store_directory_keeps_its_existing_cards_and_counts(): void
    {
        config(['app.locale' => 'en', 'app.fallback_locale' => 'en']);
        app()->setLocale('en');
        app(SystemSettingsService::class)->put('store_brand_name', 'Termol');
        $this->createCategory('generic-root', 'Generic root', 'generic-root');

        $this->get(route('categories.index'))->assertOk()->assertSee('category-index-card', false)
            ->assertSee('data-continuous-card-grid', false)
            ->assertDontSee('data-herrera-category-module', false)
            ->assertDontSee('data-herrera-category-support', false)
            ->assertDontSee('herrera-wide-catalog-main', false);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
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
