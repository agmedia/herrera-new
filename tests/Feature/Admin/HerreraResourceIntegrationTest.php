<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class HerreraResourceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_component_authorization_respects_an_explicit_admin_denial(): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        Bouncer::allow('admin')->to('admin.access');
        Bouncer::forbid('admin')->to('content.supplier_catalogs.manage');
        Bouncer::refresh();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        \App\Support\SupplierCatalogAccess::authorize($admin);
    }

    public function test_resource_navigation_is_available_on_desktop_and_mobile_without_duplicating_configured_links(): void
    {
        foreach ([false, true] as $mobile) {
            $html = view('front.partials.resource-navigation', ['resourceMobile' => $mobile, 'mainNavigation' => []])->render();
            $this->assertStringContainsString(route('supplier-catalogs.index'), $html);
            $this->assertStringContainsString(route('references.index'), $html);

            $configured = [['url' => '/help', 'children' => [['url' => '/katalozi?brand=braytron']]]];
            $html = view('front.partials.resource-navigation', ['resourceMobile' => $mobile, 'mainNavigation' => $configured])->render();
            $this->assertStringNotContainsString(route('supplier-catalogs.index'), $html);
            $this->assertStringContainsString(route('references.index'), $html);
        }
    }

    public function test_additive_feature_permissions_preserve_explicit_denials_and_existing_custom_abilities(): void
    {
        $admin = User::factory()->create();
        $rep = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        Bouncer::assign('order_manager')->to($rep);
        Bouncer::allow('admin')->to('custom.existing.permission');
        Bouncer::forbid('admin')->to('sales.offers.send');

        $migration = require database_path('migrations/2026_10_14_123000_register_customer_offer_and_content_abilities.php');
        $migration->up();
        $migration->up();
        Bouncer::refresh();

        $this->assertTrue($admin->can('custom.existing.permission'));
        $this->assertTrue($admin->can('content.supplier_catalogs.manage'));
        $this->assertTrue($admin->can('content.references.create'));
        $this->assertFalse($admin->can('sales.offers.send'));
        $this->assertTrue($rep->can('sales.offers.view'));
        $this->assertTrue($rep->can('sales.offers.manage'));
        $this->assertTrue($rep->can('sales.offers.send'));
        $this->assertFalse($rep->can('content.supplier_catalogs.manage'));
        $this->assertFalse($rep->can('content.references.create'));
    }
}
