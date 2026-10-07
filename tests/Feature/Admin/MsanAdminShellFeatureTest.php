<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Integrations\Msan\CategoryMappingManager;
use App\Livewire\Admin\Integrations\Msan\Dashboard;
use App\Livewire\Admin\Integrations\Msan\ProductSelectionManager;
use App\Livewire\Admin\Integrations\Msan\RunHistoryManager;
use App\Livewire\Admin\Integrations\Msan\SettingsForm;
use App\Livewire\Admin\Integrations\Msan\SpecificationMappingManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class MsanAdminShellFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_msan_admin_endpoints_are_not_installed_in_herrera(): void
    {
        $this->actingAs($this->admin());
        foreach (['overview' => '', 'settings' => '/settings', 'categories' => '/categories', 'specifications' => '/specifications', 'products' => '/products', 'runs' => '/runs', 'products.image' => '/products/1/image', 'specifications.edit' => '/specifications/1/edit'] as $name => $path) {
            $this->assertFalse(Route::has('admin.integrations.msan.'.$name));
            $this->get('/admin/integrations/msan'.$path)->assertNotFound();
        }
        $this->assertTrue(Route::has('admin.integrations.eprel.settings'));
        $this->assertTrue(Route::has('admin.integrations.eprel.catalog'));
    }

    public function test_admin_role_receives_standalone_eprel_permission_not_msan_permissions(): void
    {
        $admin = $this->admin();
        $editor = User::factory()->create();
        Bouncer::assign('editor')->to($editor);
        $this->assertTrue($admin->can('integrations.eprel.settings.manage'));
        $this->assertFalse($editor->can('integrations.eprel.settings.manage'));
        // Historical Bouncer records may remain; project defaults no longer grant them.
        foreach (['view', 'settings.manage', 'sync.run', 'mapping.manage', 'import.manage'] as $ability) {
            $this->assertNotContains('integrations.msan.'.$ability, config('admin_acl.roles.admin'));
            $this->assertNotContains('integrations.msan.'.$ability, array_column(config('admin_acl.abilities'), 'name'));
        }
    }

    public function test_historical_msan_permissions_cannot_restore_supplier_navigation(): void
    {
        $admin = $this->admin();
        Bouncer::allow($admin)->to(['integrations.msan.view', 'integrations.msan.settings.manage']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.integrations.eprel.settings'), false)
            ->assertSee(route('admin.integrations.eprel.catalog'), false)
            ->assertDontSee('/admin/integrations/msan', false);
    }

    public function test_legacy_supplier_livewire_components_are_rejected_even_for_superadmin(): void
    {
        $user = User::factory()->create();
        Bouncer::assign('superadmin')->to($user);
        $this->actingAs($user);
        foreach ([Dashboard::class, SettingsForm::class, CategoryMappingManager::class, ProductSelectionManager::class, RunHistoryManager::class, SpecificationMappingManager::class] as $component) {
            Livewire::test($component)->assertNotFound();
        }
        $this->get(route('admin.integrations.eprel.settings'))->assertOk();
    }

    public function test_help_and_manual_only_advertise_eprel_for_integrations(): void
    {
        $manual = json_encode(config('admin_manual'));
        $help = json_encode(config('admin_help'));
        $this->assertStringNotContainsString('M SAN', $manual);
        $this->assertStringNotContainsString('integrations.msan', $manual);
        $this->assertStringNotContainsString('M SAN', $help);
        $this->assertStringNotContainsString('integrations.msan', $help);
        $this->assertStringContainsString('admin.integrations.eprel.catalog', $manual);
        $this->assertStringContainsString('admin.integrations.eprel.settings', $manual);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
