<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CatalogManufacturersFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_manufacturers_routes_are_blocked_when_feature_disabled(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->get('/admin/manufacturers');

        $response
            ->assertRedirect(route('admin.settings.system.catalog-features'))
            ->assertSessionHas('notify.type', 'warning');
    }

    public function test_product_form_saves_manufacturer_when_feature_enabled(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_manufacturers', true);

        $user = $this->makeAdminUser();
        $manufacturer = Manufacturer::query()->create([
            'code' => 'ag-brand',
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 10,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $manufacturer->translations()->create([
            'locale' => 'en',
            'name' => 'AG Brand',
            'slug' => 'ag-brand',
            'description' => null,
            'meta_title' => null,
            'meta_description' => null,
            'payload' => null,
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class)
            ->set('form.code', 'p-manufacturer-1')
            ->set('form.sku', 'SKU-MAN-1')
            ->set('form.is_active', true)
            ->set('form.manufacturer_id', $manufacturer->id)
            ->set('form.base_price', 19.99)
            ->set('form.stock_qty', 5)
            ->set('form.locale', 'en')
            ->set('form.name', 'Manufacturer Product')
            ->set('form.slug', 'manufacturer-product')
            ->call('save')
            ->assertRedirect(route('admin.products.edit', ['product' => 1, 'locale' => 'en']));

        $product = Product::query()->where('code', 'p-manufacturer-1')->first();

        $this->assertNotNull($product);
        $this->assertSame($manufacturer->id, $product->manufacturer_id);
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
