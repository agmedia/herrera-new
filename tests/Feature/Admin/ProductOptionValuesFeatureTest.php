<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Livewire\Admin\Catalog\Product\OptionValuesManager;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ProductOptionValuesFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_option_values_route_is_blocked_when_options_feature_is_disabled(): void
    {
        $user = $this->makeAdminUser();
        $product = $this->createProduct($user);

        $response = $this->actingAs($user)->get('/admin/products/'.$product->id.'/options');

        $response
            ->assertRedirect(route('admin.settings.system.catalog-features'))
            ->assertSessionHas('notify.type', 'warning');
    }

    public function test_option_values_manager_saves_linked_rows_for_product(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $user = $this->makeAdminUser();
        $product = $this->createProduct($user);

        $color = $this->createOption($user, 'color', 'Color', 'color');
        $size = $this->createOption($user, 'size', 'Size', 'size');

        $black = $this->createOptionValue($user, $color, 'black', 'Black', 'black');
        $red = $this->createOptionValue($user, $color, 'red', 'Red', 'red');
        $small = $this->createOptionValue($user, $size, 's', 'S', 's');
        $large = $this->createOptionValue($user, $size, 'l', 'L', 'l');

        Livewire::actingAs($user)
            ->test(OptionValuesManager::class, ['productId' => $product->id])
            ->set('selectedOptionIds', [$color->id, $size->id])
            ->call('saveOptionGroups')
            ->assertHasNoErrors()
            ->set('mode', 'linked')
            ->set('primaryOptionId', $color->id)
            ->set('secondaryOptionId', $size->id)
            ->set('rows', [
                [
                    'option_value_id' => $small->id,
                    'parent_option_value_id' => $black->id,
                    'sku' => 'P-OPT-1-BLACK-S',
                    'stock_qty' => 6,
                    'price_override' => '99.90',
                    'is_active' => true,
                ],
                [
                    'option_value_id' => $large->id,
                    'parent_option_value_id' => $red->id,
                    'sku' => 'P-OPT-1-RED-L',
                    'stock_qty' => 3,
                    'price_override' => '104.50',
                    'is_active' => true,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($product->fresh()->options()->whereKey($color->id)->exists());
        $this->assertTrue($product->fresh()->options()->whereKey($size->id)->exists());
        $this->assertSame(2, ProductOptionValue::query()->where('product_id', $product->id)->count());

        $this->assertDatabaseHas('catalog_product_option_values', [
            'product_id' => $product->id,
            'option_value_id' => $small->id,
            'parent_option_value_id' => $black->id,
            'mode' => 'linked',
            'sku' => 'P-OPT-1-BLACK-S',
            'stock_qty' => 6,
        ]);

        $this->assertDatabaseHas('catalog_product_option_values', [
            'product_id' => $product->id,
            'option_value_id' => $large->id,
            'parent_option_value_id' => $red->id,
            'mode' => 'linked',
            'sku' => 'P-OPT-1-RED-L',
            'stock_qty' => 3,
        ]);
    }

    public function test_option_values_manager_hides_rows_from_other_option_groups_in_single_mode(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $user = $this->makeAdminUser();
        $product = $this->createProduct($user);

        $size = $this->createOption($user, 'size', 'Size', 'size');
        $color = $this->createOption($user, 'color', 'Color', 'color');

        $small = $this->createOptionValue($user, $size, 's', 'S', 's');
        $skin = $this->createOptionValue($user, $color, 'skin', 'Skin', 'skin');

        $product->options()->sync([
            $size->id => [
                'is_required' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        ProductOptionValue::query()->create([
            'product_id' => $product->id,
            'option_value_id' => $small->id,
            'parent_option_value_id' => null,
            'mode' => 'single',
            'sku' => 'P-OPT-1-S',
            'stock_qty' => 6,
            'price_override' => 0,
            'sort_order' => 0,
            'is_active' => true,
            'combination_hash' => hash('sha256', 's:'.$small->id),
        ]);

        ProductOptionValue::query()->create([
            'product_id' => $product->id,
            'option_value_id' => $skin->id,
            'parent_option_value_id' => null,
            'mode' => 'single',
            'sku' => null,
            'stock_qty' => 0,
            'price_override' => null,
            'sort_order' => 1,
            'is_active' => true,
            'combination_hash' => hash('sha256', 's:'.$skin->id),
        ]);

        Livewire::actingAs($user)
            ->test(OptionValuesManager::class, ['productId' => $product->id])
            ->assertSet('mode', 'single')
            ->assertCount('rows', 1)
            ->assertSet('rows.0.option_value_id', $small->id);
    }

    public function test_product_form_shows_filter_only_option_values(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $user = $this->makeAdminUser();
        $product = $this->createProduct($user);

        $color = $this->createOption($user, 'color', 'Color', 'color');
        $color->forceFill([
            'payload' => [
                Option::PAYLOAD_SHOW_ON_PRODUCT_PAGE => false,
            ],
        ])->save();

        $skin = $this->createOptionValue($user, $color, 'skin', 'Skin', 'skin');
        $product->options()->attach($color->id, [
            'is_required' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ProductOptionValue::query()->create([
            'product_id' => $product->id,
            'option_value_id' => $skin->id,
            'parent_option_value_id' => null,
            'mode' => 'single',
            'sku' => null,
            'stock_qty' => 0,
            'price_override' => null,
            'sort_order' => 0,
            'is_active' => true,
            'combination_hash' => hash('sha256', 's:'.$skin->id),
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'catalog')
            ->assertSee('Filter-only values on this product')
            ->assertSee('Color: Skin');
    }

    public function test_filter_only_option_values_can_be_edited_without_removing_size_rows(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $user = $this->makeAdminUser();
        $product = $this->createProduct($user);

        $size = $this->createOption($user, 'size', 'Size', 'size');
        $color = $this->createOption($user, 'color', 'Color', 'color');
        $color->forceFill([
            'payload' => [
                Option::PAYLOAD_SHOW_ON_PRODUCT_PAGE => false,
            ],
        ])->save();

        $small = $this->createOptionValue($user, $size, 's', 'S', 's');
        $skin = $this->createOptionValue($user, $color, 'skin', 'Skin', 'skin');
        $black = $this->createOptionValue($user, $color, 'black', 'Black', 'black');

        $product->options()->sync([
            $size->id => [
                'is_required' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            $color->id => [
                'is_required' => false,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        ProductOptionValue::query()->create([
            'product_id' => $product->id,
            'option_value_id' => $small->id,
            'parent_option_value_id' => null,
            'mode' => 'single',
            'sku' => 'P-OPT-1-S',
            'stock_qty' => 6,
            'price_override' => 0,
            'sort_order' => 0,
            'is_active' => true,
            'combination_hash' => hash('sha256', 's:'.$small->id),
        ]);

        ProductOptionValue::query()->create([
            'product_id' => $product->id,
            'option_value_id' => $skin->id,
            'parent_option_value_id' => null,
            'mode' => 'filter',
            'sku' => null,
            'stock_qty' => 0,
            'price_override' => null,
            'sort_order' => 1,
            'is_active' => true,
            'combination_hash' => hash('sha256', 'filter:'.$color->id.':'.$skin->id),
        ]);

        Livewire::actingAs($user)
            ->test(OptionValuesManager::class, ['productId' => $product->id])
            ->assertSee('Filter-only option values')
            ->assertSet('filterOnlyOptions.0.selected_value_id', $skin->id)
            ->assertCount('rows', 1)
            ->assertSet('rows.0.option_value_id', $small->id)
            ->set('filterOnlyOptions.0.selected_value_id', $black->id)
            ->call('saveFilterOnlyOptions')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('catalog_product_option_values', [
            'product_id' => $product->id,
            'option_value_id' => $small->id,
            'mode' => 'single',
            'sku' => 'P-OPT-1-S',
            'stock_qty' => 6,
        ]);

        $this->assertDatabaseMissing('catalog_product_option_values', [
            'product_id' => $product->id,
            'option_value_id' => $skin->id,
        ]);

        $this->assertDatabaseHas('catalog_product_option_values', [
            'product_id' => $product->id,
            'option_value_id' => $black->id,
            'mode' => 'filter',
            'sku' => null,
            'stock_qty' => 0,
        ]);
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function createProduct(User $user): Product
    {
        $product = Product::query()->create([
            'code' => 'p-opt-1',
            'sku' => 'P-OPT-1',
            'is_active' => true,
            'base_price' => 89.00,
            'stock_qty' => 25,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $product->translations()->create([
            'locale' => 'en',
            'name' => 'Option Product',
            'slug' => 'option-product',
            'excerpt' => null,
            'description' => null,
            'meta_title' => null,
            'meta_description' => null,
            'payload' => null,
        ]);

        return $product;
    }

    private function createOption(User $user, string $code, string $name, string $slug): Option
    {
        $option = Option::query()->create([
            'code' => $code,
            'type' => Option::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 10,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $option->translations()->create([
            'locale' => 'en',
            'name' => $name,
            'slug' => $slug,
            'description' => null,
            'payload' => null,
        ]);

        return $option;
    }

    private function createOptionValue(User $user, Option $option, string $code, string $name, string $slug): OptionValue
    {
        $value = OptionValue::query()->create([
            'option_id' => $option->id,
            'code' => $code,
            'is_active' => true,
            'sort_order' => 10,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $value->translations()->create([
            'locale' => 'en',
            'name' => $name,
            'slug' => $slug,
            'payload' => null,
        ]);

        return $value;
    }
}
