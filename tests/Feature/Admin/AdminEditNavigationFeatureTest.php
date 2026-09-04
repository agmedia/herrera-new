<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Settings\Local\ResourceManager;
use App\Livewire\Admin\Shipping\ShippingManager;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class AdminEditNavigationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_method_list_links_to_a_standalone_editor(): void
    {
        $admin = $this->makeAdmin();
        $method = $this->makePaymentMethod();
        $createUrl = route('admin.settings.local.resource.create', [
            'resource' => 'payment-methods',
            'search' => 'Invoice',
        ]);
        $editUrl = route('admin.settings.local.resource.edit', [
            'resource' => 'payment-methods',
            'record' => $method->id,
            'search' => 'Invoice',
        ]);
        $listUrl = route('admin.settings.local.resource', [
            'resource' => 'payment-methods',
            'search' => 'Invoice',
        ]);

        $this->actingAs($admin)
            ->get($listUrl)
            ->assertOk()
            ->assertSee('href="'.e($createUrl).'"', false)
            ->assertSee('href="'.e($editUrl).'"', false)
            ->assertDontSee('wire:model="form.name"', false)
            ->assertDontSee('wire:click="edit('.$method->id.')"', false);

        $this->actingAs($admin)
            ->get($createUrl)
            ->assertOk()
            ->assertSee('wire:model="form.name"', false)
            ->assertSee('href="'.e($listUrl).'"', false)
            ->assertDontSee('<table class="admin-items-table', false);

        $this->actingAs($admin)
            ->get($editUrl)
            ->assertOk()
            ->assertSee('wire:model="form.name"', false)
            ->assertSee('href="'.e($listUrl).'"', false)
            ->assertDontSee('<table class="admin-items-table', false)
            ->assertDontSee('wire:click="toggleActive('.$method->id.')"', false);
    }

    public function test_standalone_payment_method_creator_stores_the_record_and_redirects_to_the_list(): void
    {
        $admin = $this->makeAdmin();

        Livewire::withQueryParams([
            'search' => 'Invoice',
            'page' => 3,
        ])->actingAs($admin)
            ->test(ResourceManager::class, [
                'resource' => 'payment-methods',
                'createPage' => true,
            ])
            ->assertSet('editingId', null)
            ->set('form.code', 'new_payment_navigation_test')
            ->set('form.name', 'Novo testno plaćanje')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.settings.local.resource', [
                'resource' => 'payment-methods',
                'search' => 'Invoice',
                'page' => 3,
            ]))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('payment_methods', [
            'code' => 'new_payment_navigation_test',
            'name' => 'Novo testno plaćanje',
        ]);
    }

    public function test_standalone_payment_method_editor_updates_the_record_and_redirects_to_the_list(): void
    {
        $admin = $this->makeAdmin();
        $method = $this->makePaymentMethod();

        Livewire::actingAs($admin)
            ->test(ResourceManager::class, [
                'resource' => 'payment-methods',
                'recordId' => $method->id,
                'editPage' => true,
            ])
            ->assertSet('editingId', $method->id)
            ->set('form.name', 'Plaćanje po ponudi')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.settings.local.resource', [
                'resource' => 'payment-methods',
            ]))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('payment_methods', [
            'id' => $method->id,
            'name' => 'Plaćanje po ponudi',
        ]);
    }

    public function test_order_status_list_links_to_a_standalone_editor(): void
    {
        $admin = $this->makeAdmin();
        $status = $this->makeOrderStatus();
        $createUrl = route('admin.settings.local.resource.create', [
            'resource' => 'order-statuses',
        ]);
        $editUrl = route('admin.settings.local.resource.edit', [
            'resource' => 'order-statuses',
            'record' => $status->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.settings.local.resource', ['resource' => 'order-statuses']))
            ->assertOk()
            ->assertSee('href="'.$createUrl.'"', false)
            ->assertSee('href="'.$editUrl.'"', false)
            ->assertDontSee('wire:model="form.name"', false)
            ->assertDontSee('wire:click="edit('.$status->id.')"', false);

        $this->actingAs($admin)
            ->get($createUrl)
            ->assertOk()
            ->assertSee('wire:model="form.name"', false)
            ->assertDontSee('<table class="admin-items-table', false);

        $this->actingAs($admin)
            ->get($editUrl)
            ->assertOk()
            ->assertSee('wire:model="form.name"', false)
            ->assertDontSee('<table class="admin-items-table', false)
            ->assertDontSee('wire:click="toggleActive('.$status->id.')"', false);
    }

    public function test_unsupported_local_resources_do_not_get_a_create_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get('/admin/settings/local/shipping-methods/create')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/settings/local/not-a-resource/create')
            ->assertNotFound();
    }

    public function test_standalone_order_status_editor_updates_the_record_and_redirects_to_the_list(): void
    {
        $admin = $this->makeAdmin();
        $status = $this->makeOrderStatus();

        Livewire::actingAs($admin)
            ->test(ResourceManager::class, [
                'resource' => 'order-statuses',
                'recordId' => $status->id,
                'editPage' => true,
            ])
            ->assertSet('editingId', $status->id)
            ->set('form.name', 'Spremno za slanje')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.settings.local.resource', [
                'resource' => 'order-statuses',
            ]))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('order_statuses', [
            'id' => $status->id,
            'name' => 'Spremno za slanje',
        ]);
    }

    public function test_shipping_list_links_to_a_standalone_editor(): void
    {
        $admin = $this->makeAdmin();
        $method = $this->makeShippingMethod();
        $createUrl = route('admin.shipping.create', [
            'search' => 'Courier',
        ]);
        $editUrl = route('admin.shipping.edit', [
            'shippingMethod' => $method->id,
            'search' => 'Courier',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.shipping.index', [
                'tab' => 'methods',
                'search' => 'Courier',
            ]))
            ->assertOk()
            ->assertSee('Postavke / Lokalno / Dostava')
            ->assertSee('<table class="admin-items-table', false)
            ->assertSee('wire:key="shipping-method-'.$method->id.'"', false)
            ->assertDontSee('<article', false)
            ->assertSee('href="'.e($createUrl).'"', false)
            ->assertSee('href="'.$editUrl.'"', false)
            ->assertDontSee('wire:model="form.name"', false)
            ->assertDontSee('wire:click="edit('.$method->id.')"', false);

        $document = new \DOMDocument;
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $document->loadHTML((string) $response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        $xpath = new \DOMXPath($document);
        $sidebarShippingLinks = $xpath->query('//aside[@id="admin-sidebar"]//a[@href="'.route('admin.shipping.index').'"]');

        $this->assertNotFalse($sidebarShippingLinks);
        $this->assertSame(1, $sidebarShippingLinks->length);
        $this->assertSame(2, $xpath->query('ancestor::details', $sidebarShippingLinks->item(0))->length);

        $this->actingAs($admin)
            ->get($editUrl)
            ->assertOk()
            ->assertSee('wire:model="form.name"', false)
            ->assertSee('href="'.e(route('admin.shipping.index', [
                'tab' => 'methods',
                'search' => 'Courier',
            ])).'"', false)
            ->assertDontSee('wire:model.live.debounce.300ms="search"', false)
            ->assertDontSee('wire:click="toggleActive('.$method->id.')"', false);

        $this->actingAs($admin)
            ->get($createUrl)
            ->assertOk()
            ->assertSee('wire:model="form.name"', false)
            ->assertDontSee('<table class="admin-items-table', false)
            ->assertDontSee('wire:model.live.debounce.300ms="search"', false);
    }

    public function test_standalone_shipping_creator_stores_the_record_and_redirects_to_the_list(): void
    {
        $admin = $this->makeAdmin();
        $returnParameters = [
            'tab' => 'methods',
            'search' => 'Courier',
            'page' => 2,
        ];

        Livewire::withQueryParams([
            'search' => 'Courier',
            'page' => 2,
        ])->actingAs($admin)
            ->test(ShippingManager::class, [
                'createPage' => true,
            ])
            ->assertSet('editingId', null)
            ->set('form.code', 'new_shipping_navigation_test')
            ->set('form.name', 'Nova testna dostava')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.shipping.index', $returnParameters))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('shipping_methods', [
            'code' => 'new_shipping_navigation_test',
            'name' => 'Nova testna dostava',
        ]);
    }

    public function test_standalone_shipping_editor_updates_the_record_and_redirects_to_the_list(): void
    {
        $admin = $this->makeAdmin();
        $method = $this->makeShippingMethod();
        $returnParameters = [
            'tab' => 'methods',
            'search' => 'Courier',
            'page' => 2,
        ];

        Livewire::withQueryParams([
            'search' => 'Courier',
            'page' => 2,
        ])->actingAs($admin)
            ->test(ShippingManager::class, [
                'editPage' => true,
                'recordId' => $method->id,
            ])
            ->assertSet('editingId', $method->id)
            ->set('form.name', 'Brza dostava')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.shipping.index', $returnParameters))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('shipping_methods', [
            'id' => $method->id,
            'name' => 'Brza dostava',
        ]);
    }

    public function test_legacy_shipping_edit_query_redirects_to_the_standalone_editor(): void
    {
        $admin = $this->makeAdmin();
        $method = $this->makeShippingMethod();

        $this->actingAs($admin)
            ->get(route('admin.shipping.index', [
                'edit' => $method->id,
                'search' => 'Courier',
                'page' => 2,
            ]))
            ->assertRedirect(route('admin.shipping.edit', [
                'shippingMethod' => $method->id,
                'search' => 'Courier',
                'page' => 2,
            ]));
    }

    public function test_admin_access_alone_cannot_open_standalone_edit_pages(): void
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to('admin.access');
        Bouncer::refreshFor($user);

        $status = $this->makeOrderStatus();
        $paymentMethod = $this->makePaymentMethod();
        $method = $this->makeShippingMethod();

        $this->actingAs($user)
            ->get(route('admin.settings.local.resource.create', [
                'resource' => 'payment-methods',
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.settings.local.resource.edit', [
                'resource' => 'payment-methods',
                'record' => $paymentMethod->id,
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.settings.local.resource.edit', [
                'resource' => 'order-statuses',
                'record' => $status->id,
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.shipping.create'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.shipping.edit', [
                'shippingMethod' => $method->id,
            ]))
            ->assertForbidden();
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function makeOrderStatus(): OrderStatus
    {
        return OrderStatus::query()->create([
            'code' => 'awaiting_dispatch_test',
            'name' => 'Awaiting dispatch',
            'description' => null,
            'color' => 'blue',
            'is_default' => false,
            'is_paid' => false,
            'is_cancelled' => false,
            'is_active' => true,
            'sort_order' => 90,
            'settings' => null,
        ]);
    }

    private function makePaymentMethod(): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'code' => 'invoice_navigation_test',
            'name' => 'Invoice navigation test',
            'provider' => 'manual',
            'description' => null,
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'min_subtotal' => null,
            'max_subtotal' => null,
            'is_active' => true,
            'sort_order' => 90,
            'settings' => null,
        ]);
    }

    private function makeShippingMethod(): ShippingMethod
    {
        return ShippingMethod::query()->create([
            'code' => 'courier_navigation_test',
            'name' => 'Courier navigation test',
            'carrier' => 'manual',
            'service_type' => 'home_delivery',
            'pricing_type' => 'flat',
            'price' => 4.99,
            'allows_fragile' => true,
            'allows_oversized' => true,
            'allows_heavy' => true,
            'fragile_surcharge' => 0,
            'oversized_surcharge' => 0,
            'heavy_surcharge' => 0,
            'missing_measurements_policy' => 'allow',
            'is_active' => true,
            'sort_order' => 90,
            'settings' => null,
        ]);
    }
}
