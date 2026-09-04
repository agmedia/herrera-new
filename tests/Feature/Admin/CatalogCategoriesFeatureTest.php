<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Category\Form as CategoryForm;
use App\Models\Catalog\Category\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CatalogCategoriesFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_form_saves_catalog_page_visibility_settings(): void
    {
        config(['app.locale' => 'en']);
        app()->setLocale('en');

        $user = $this->makeAdminUser();

        Livewire::actingAs($user)
            ->test(CategoryForm::class)
            ->set('form.scope', Category::SCOPE_CATALOG)
            ->set('form.locale', 'en')
            ->set('form.code', 'landing-men')
            ->set('form.name', 'Landing Men')
            ->set('form.slug', 'landing-men')
            ->set('form.is_active', true)
            ->set('form.show_in_menu', true)
            ->set('form.catalog_show_filters', false)
            ->set('form.catalog_show_products', false)
            ->call('save')
            ->assertRedirect(route('admin.categories', [
                'scope' => Category::SCOPE_CATALOG,
                'locale' => 'en',
            ]));

        $category = Category::query()->where('code', 'landing-men')->first();

        $this->assertNotNull($category);
        $this->assertSame([
            Category::PAYLOAD_SHOW_FILTERS => false,
            Category::PAYLOAD_SHOW_PRODUCTS => false,
        ], $category->payload);
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
