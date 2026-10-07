<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class AdminPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('adminPages')]
    public function test_admin_pages_render_in_croatian_and_reject_guests_and_customers(string $path): void
    {
        Http::preventStrayRequests();
        config(['app.locale' => 'hr']);
        app(SystemSettingsService::class)->putMany([
            'catalog_use_attributes' => true,
            'catalog_use_manufacturers' => true,
            'catalog_use_actions' => true,
            'catalog_use_blog' => true,
            'catalog_use_api' => true,
            'catalog_use_options' => false,
        ]);

        $this->get($path)->assertRedirect(route('login'));

        $customer = User::factory()->create();
        Bouncer::assign('customer')->to($customer);
        $this->actingAs($customer)->get($path)->assertForbidden();

        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        $this->actingAs($admin)->get($path)->assertOk()
            ->assertSee('id="admin-sidebar"', false)
            ->assertDontSee('href="'.route('admin.options').'"', false);

        Http::assertNothingSent();
    }

    public static function adminPages(): array
    {
        $paths = [
            '/admin/dashboard',
            '/admin/help',
            '/admin/categories',
            '/admin/categories/create',
            '/admin/products',
            '/admin/products/create',
            '/admin/manufacturers',
            '/admin/manufacturers/create',
            '/admin/attributes',
            '/admin/attributes/groups/create',
            '/admin/actions',
            '/admin/actions/create',
            '/admin/b2b-prices',
            '/admin/b2b-prices/catalogs',
            '/admin/b2b-prices/create',
            '/admin/orders',
            '/admin/withdrawals',
            '/admin/shipping',
            '/admin/shipping/create',
            '/admin/users',
            '/admin/users/statistics',
            '/admin/users/b2b',
            '/admin/users/newsletter',
            '/admin/users/groups',
            '/admin/users/groups/create',
            '/admin/users/access',
            '/admin/users/activity',
            '/admin/content/blog',
            '/admin/content/blog/create',
            '/admin/content/pages',
            '/admin/content/pages/create',
            '/admin/content/faqs',
            '/admin/content/faqs/create',
            '/admin/content/comments',
            '/admin/content/blocks',
            '/admin/content/blocks/create',
            '/admin/content/navigation',
            '/admin/content/slots',
            '/admin/content/slots/create',
            '/admin/settings/local/payment-methods',
            '/admin/settings/local/geo-zones',
            '/admin/settings/local/geo-zone-countries',
            '/admin/settings/local/regions',
            '/admin/settings/local/currencies',
            '/admin/settings/local/tax-rates',
            '/admin/settings/local/order-statuses',
            '/admin/settings/local/languages',
            '/admin/settings/system/store-settings',
            '/admin/settings/system/catalog-features',
            '/admin/settings/system/admin-appearance-controls',
            '/admin/settings/system/withdrawal-settings',
            '/admin/settings/system/runtime',
            '/admin/settings/api/wholesale',
            '/admin/settings/user',
            '/admin/integrations/eprel/settings',
            '/admin/integrations/eprel/catalog',
            '/admin/integrations/stock',
            '/admin/integrations/eracuni',
            '/admin/integrations/spreadsheet',
            '/admin/integrations/media',
        ];

        $cases = [];
        foreach ($paths as $path) {
            $cases[$path] = [$path];
        }

        return $cases;
    }
}
