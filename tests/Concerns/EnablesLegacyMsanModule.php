<?php

namespace Tests\Concerns;

use App\Http\Controllers\Admin\MsanProductImageController;
use App\Models\Integrations\Msan\MsanSpecificationDefinition;
use Illuminate\Support\Facades\Route;

/**
 * Explicit test-only installation for regression-testing preserved supplier code.
 * None of these routes, grants or toggles are installed by the Herrera application.
 */
trait EnablesLegacyMsanModule
{
    protected function setUpEnablesLegacyMsanModule(): void
    {
        config(['integrations.msan.available' => true]);

        $abilities = ['view', 'settings.manage', 'sync.run', 'mapping.manage', 'import.manage'];
        $definitions = config('admin_acl.abilities');
        $grants = config('admin_acl.roles.admin');
        foreach ($abilities as $ability) {
            $name = 'integrations.msan.'.$ability;
            $definitions[] = ['name' => $name, 'title' => $name, 'group' => 'integrations.msan'];
            $grants[] = $name;
        }
        config(['admin_acl.abilities' => $definitions, 'admin_acl.roles.admin' => $grants]);

        $rules = [
            'admin.integrations.msan.settings' => ['view' => ['integrations.msan.settings.manage'], 'mutate' => ['integrations.msan.settings.manage']],
            'admin.integrations.msan.categories' => ['view' => ['integrations.msan.view'], 'mutate' => ['integrations.msan.mapping.manage']],
            'admin.integrations.msan.specifications.edit' => ['view' => ['integrations.msan.mapping.manage'], 'mutate' => ['integrations.msan.mapping.manage']],
            'admin.integrations.msan.specifications' => ['view' => ['integrations.msan.view'], 'mutate' => ['integrations.msan.mapping.manage']],
            'admin.integrations.msan.products.image' => ['view' => ['integrations.msan.view']],
            'admin.integrations.msan.products' => ['view' => ['integrations.msan.view'], 'mutate' => ['integrations.msan.import.manage']],
            'admin.integrations.msan.runs' => ['view' => ['integrations.msan.view'], 'mutate' => ['integrations.msan.sync.run']],
            'admin.integrations.msan.overview' => ['view' => ['integrations.msan.view'], 'mutate' => ['integrations.msan.sync.run']],
        ];
        config(['admin_authorization.route_rules' => $rules + config('admin_authorization.route_rules')]);

        Route::middleware(['web', 'admin.locale', 'auth', 'verified', 'admin.access', 'admin.maintenance-bypass', 'admin.ability'])
            ->prefix('admin/integrations/msan')->as('admin.integrations.msan.')
            ->group(function (): void {
                Route::view('/', 'admin.integrations.msan.overview')->name('overview');
                foreach (['settings', 'categories', 'specifications', 'products', 'runs'] as $page) {
                    Route::view($page, 'admin.integrations.msan.'.$page)->name($page);
                }
                Route::get('specifications/{definition}/edit', function (MsanSpecificationDefinition $definition) {
                    return view('admin.integrations.msan.specification-edit', compact('definition'));
                })->name('specifications.edit');
                Route::get('products/{product}/image', MsanProductImageController::class)
                    ->whereNumber('product')->middleware('throttle:120,1')->name('products.image');
            });
        Route::getRoutes()->refreshNameLookups();
    }
}
