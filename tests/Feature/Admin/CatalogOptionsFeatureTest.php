<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Option\Form as OptionForm;
use App\Livewire\Admin\Catalog\Option\Manager as OptionManager;
use App\Livewire\Admin\Catalog\Option\ValueManager;
use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CatalogOptionsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_routes_are_blocked_when_feature_disabled(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->get('/admin/options');

        $response
            ->assertRedirect(route('admin.settings.system.catalog-features'))
            ->assertSessionHas('notify.type', 'warning');
    }

    public function test_product_form_saves_without_option_assignment_when_feature_enabled(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $user = $this->makeAdminUser();

        Livewire::actingAs($user)
            ->test(ProductForm::class)
            ->set('form.code', 'p-option-1')
            ->set('form.sku', 'SKU-OPT-1')
            ->set('form.is_active', true)
            ->set('form.base_price', 19.99)
            ->set('form.stock_qty', 5)
            ->set('form.locale', 'en')
            ->set('form.name', 'Option Product')
            ->set('form.slug', 'option-product')
            ->call('save')
            ->assertRedirect();

        $product = Product::query()->where('code', 'p-option-1')->first();

        $this->assertNotNull($product);
        $this->assertSame('SKU-OPT-1', $product->sku);
    }

    public function test_option_form_can_store_product_page_visibility_setting(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $user = $this->makeAdminUser();

        Livewire::actingAs($user)
            ->test(OptionForm::class)
            ->set('form.code', 'color-filter-only')
            ->set('form.type', Option::TYPE_SELECT)
            ->set('form.is_active', true)
            ->set('form.show_on_product_page', false)
            ->set('form.locale', 'en')
            ->set('form.name', 'Color')
            ->set('form.slug', 'color')
            ->call('save')
            ->assertRedirect(route('admin.options', ['locale' => 'en']));

        $option = Option::query()->where('code', 'color-filter-only')->first();

        $this->assertNotNull($option);
        $this->assertFalse($option->showsOnProductPage());
    }

    public function test_option_value_manager_can_store_swatch_image_path(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);
        Storage::fake('public');

        $user = $this->makeAdminUser();
        $option = Option::query()->create([
            'code' => 'color',
            'type' => Option::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 1,
            'payload' => null,
        ]);

        $option->translations()->create([
            'locale' => 'en',
            'name' => 'Color',
            'slug' => 'color',
            'description' => null,
            'payload' => null,
        ]);

        Livewire::actingAs($user)
            ->test(ValueManager::class, ['optionId' => $option->id])
            ->set('locale', 'en')
            ->set('form.code', 'red')
            ->set('form.is_active', true)
            ->set('form.sort_order', 10)
            ->set('form.name', 'Red')
            ->set('form.slug', 'red')
            ->set('swatchImageUpload', UploadedFile::fake()->image('red-swatch.png', 48, 48))
            ->call('save')
            ->assertHasNoErrors();

        $value = $option->values()->first();

        $this->assertNotNull($value);
        $swatchPath = (string) data_get($value->payload, 'swatch_image_path', '');

        $this->assertNotSame('', $swatchPath);
        Storage::disk('public')->assertExists($swatchPath);
    }

    public function test_admin_can_delete_option_from_manager_and_remove_swatch_files(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);
        Storage::fake('public');

        $user = $this->makeAdminUser();
        $option = Option::query()->create([
            'code' => 'delete-color',
            'type' => Option::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 1,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $option->translations()->create([
            'locale' => 'en',
            'name' => 'Delete Color',
            'slug' => 'delete-color',
            'description' => null,
            'payload' => null,
        ]);

        Livewire::actingAs($user)
            ->test(ValueManager::class, ['optionId' => $option->id])
            ->set('locale', 'en')
            ->set('form.code', 'delete-red')
            ->set('form.is_active', true)
            ->set('form.sort_order', 10)
            ->set('form.name', 'Delete Red')
            ->set('form.slug', 'delete-red')
            ->set('swatchImageUpload', UploadedFile::fake()->image('delete-red.png', 48, 48))
            ->call('save')
            ->assertHasNoErrors();

        $swatchPath = (string) data_get($option->values()->first()?->payload, 'swatch_image_path', '');
        $this->assertNotSame('', $swatchPath);
        Storage::disk('public')->assertExists($swatchPath);

        Livewire::actingAs($user)
            ->test(OptionManager::class)
            ->call('delete', $option->id)
            ->assertDispatched('notify');

        $this->assertDatabaseMissing('catalog_options', [
            'id' => $option->id,
        ]);
        $this->assertDatabaseMissing('catalog_option_values', [
            'option_id' => $option->id,
        ]);
        Storage::disk('public')->assertMissing($swatchPath);
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
