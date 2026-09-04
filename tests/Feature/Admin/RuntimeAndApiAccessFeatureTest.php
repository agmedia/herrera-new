<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use App\Support\AssetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class RuntimeAndApiAccessFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_runtime_and_api_pages(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin/settings/system/runtime')
            ->assertOk()
            ->assertSee('Runtime Controls');

        $this->actingAs($admin)
            ->get('/admin/settings/api')
            ->assertRedirect('/admin/settings/api/wholesale');

        $this->actingAs($admin)
            ->get('/admin/settings/api/wholesale')
            ->assertOk()
            ->assertSee('Wholesale API Settings');
    }

    public function test_editor_cannot_open_runtime_and_api_pages(): void
    {
        $editor = $this->makeUserWithRole('editor');

        $this->actingAs($editor)
            ->get('/admin/settings/system/runtime')
            ->assertForbidden();

        $this->actingAs($editor)
            ->get('/admin/settings/api')
            ->assertForbidden();

        $this->actingAs($editor)
            ->get('/admin/settings/api/wholesale')
            ->assertForbidden();
    }

    public function test_api_index_redirects_to_catalog_features_when_wholesale_feature_is_disabled(): void
    {
        $admin = $this->makeUserWithRole('admin');

        app(SystemSettingsService::class)->put('catalog_use_api', false);

        $this->actingAs($admin)
            ->get('/admin/settings/api')
            ->assertRedirect(route('admin.settings.system.catalog-features'));

        $this->actingAs($admin)
            ->get('/admin/settings/api/wholesale')
            ->assertRedirect(route('admin.settings.system.catalog-features'));
    }

    public function test_clearing_cache_changes_the_vite_asset_version(): void
    {
        $admin = $this->makeUserWithRole('superadmin');
        $assetVersion = app(AssetVersion::class);
        $versionBeforeClear = $assetVersion->current();

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee('?v='.rawurlencode($versionBeforeClear), false);

        $this->actingAs($admin)
            ->post(route('admin.system.cache.clear'))
            ->assertRedirect();

        $versionAfterClear = $assetVersion->current();

        $this->assertNotSame($versionBeforeClear, $versionAfterClear);

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee('?v='.rawurlencode($versionAfterClear), false);
    }

    private function makeUserWithRole(string $role): User
    {
        Bouncer::role()->firstOrCreate(['name' => 'superadmin']);
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::role()->firstOrCreate(['name' => 'editor']);
        Bouncer::role()->firstOrCreate(['name' => 'customer']);

        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }
}
