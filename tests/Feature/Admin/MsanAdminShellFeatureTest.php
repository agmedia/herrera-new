<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class MsanAdminShellFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        'admin.integrations.msan.overview' => 'admin/integrations/msan',
        'admin.integrations.msan.settings' => 'admin/integrations/msan/settings',
        'admin.integrations.msan.categories' => 'admin/integrations/msan/categories',
        'admin.integrations.msan.specifications' => 'admin/integrations/msan/specifications',
        'admin.integrations.msan.products' => 'admin/integrations/msan/products',
        'admin.integrations.msan.runs' => 'admin/integrations/msan/runs',
    ];

    private const ABILITIES = [
        'integrations.msan.view',
        'integrations.msan.settings.manage',
        'integrations.msan.sync.run',
        'integrations.msan.mapping.manage',
        'integrations.msan.import.manage',
    ];

    public function test_msan_admin_routes_are_registered_with_expected_names_and_paths(): void
    {
        foreach (self::ROUTES as $name => $uri) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route [{$name}].");
            $this->assertSame($uri, $route->uri());
            $this->assertContains('GET', $route->methods());
        }
    }

    public function test_admin_role_receives_all_msan_abilities_but_editor_does_not(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $editor = $this->makeUserWithRole('editor');

        foreach (self::ABILITIES as $ability) {
            $this->assertTrue($admin->can($ability), "Admin is missing [{$ability}].");
            $this->assertFalse($editor->can($ability), "Editor unexpectedly has [{$ability}].");
        }
    }

    public function test_editor_is_forbidden_from_every_msan_admin_route_before_page_rendering(): void
    {
        $editor = $this->makeUserWithRole('editor');

        foreach (array_values(self::ROUTES) as $uri) {
            $this->actingAs($editor)
                ->get('/'.$uri)
                ->assertForbidden();
        }
    }

    public function test_admin_can_render_every_msan_module_page(): void
    {
        $admin = $this->makeUserWithRole('admin');

        foreach (array_values(self::ROUTES) as $uri) {
            $this->actingAs($admin)
                ->get('/'.$uri)
                ->assertOk()
                ->assertSee('M SAN');
        }
    }

    public function test_product_category_filter_exposes_a_clear_search_prompt(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.integrations.msan.products'))
            ->assertOk()
            ->assertSee('data-tom-placeholder="'.__('Pretraži M SAN kategorije...').'"', false)
            ->assertSee('msan-product-category-select', false)
            ->assertSee('.ts-wrapper.msan-product-category-select .ts-dropdown-content', false);
    }

    public function test_settings_only_user_sees_msan_settings_navigation_but_cannot_open_read_pages(): void
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to([
            'admin.access',
            'integrations.msan.settings.manage',
        ]);
        Bouncer::refreshFor($user);

        $settingsUrl = route('admin.integrations.msan.settings');
        $overviewUrl = route('admin.integrations.msan.overview');
        $specificationsUrl = route('admin.integrations.msan.specifications');

        $this->actingAs($user)
            ->get($settingsUrl)
            ->assertOk()
            ->assertSee('href="'.$settingsUrl.'"', false)
            ->assertDontSee('href="'.$overviewUrl.'"', false)
            ->assertSee(__('Postavke'))
            ->assertDontSee(__('Mapiranje kategorija'))
            ->assertDontSee('href="'.$specificationsUrl.'"', false)
            ->assertDontSee(__('Odabir artikala'))
            ->assertDontSee(__('Izvršavanja'));

        foreach (['overview', 'categories', 'specifications', 'products', 'runs'] as $route) {
            $this->actingAs($user)
                ->get(route('admin.integrations.msan.'.$route))
                ->assertForbidden();
        }
    }

    public function test_msan_sidebar_entry_is_visible_only_to_users_with_view_ability(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $editor = $this->makeUserWithRole('editor');
        $overviewUrl = route('admin.integrations.msan.overview');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(__('Integracije'))
            ->assertSee($overviewUrl, false);

        $this->actingAs($editor)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee($overviewUrl, false);
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }
}
