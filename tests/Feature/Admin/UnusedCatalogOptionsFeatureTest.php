<?php

namespace Tests\Feature\Admin;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\User;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class UnusedCatalogOptionsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_unused_options_are_disabled_and_removed_from_product_pages_and_navigation(): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        $product = Product::query()->create(['code' => 'WITHOUT-OPTIONS']);
        $option = Option::query()->create(['code' => 'size', 'type' => 'select']);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'small']);
        $settings = app(SystemSettingsService::class);
        $settings->put('catalog_use_options', true);
        $this->assertTrue(app(CatalogFeatureService::class)->useOptions());

        $this->runMigration();

        $this->assertFalse(app(CatalogFeatureService::class)->useOptions());
        $this->assertDatabaseHas('catalog_options', ['id' => $option->id]);
        $this->assertDatabaseHas('catalog_option_values', ['id' => $value->id]);

        foreach (['/admin/products', '/admin/products/create', '/admin/products/'.$product->id.'/edit'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertDontSee('href="'.route('admin.options').'"', false)
                ->assertDontSee('href="'.route('admin.products.options', ['product' => $product->id]).'"', false)
                ->assertDontSee(__('Product Option Values'))
                ->assertDontSee(__('Manage Option Values'))
                ->assertDontSee(__('Opcije').':');
        }

        foreach (['/admin/options', '/admin/products/'.$product->id.'/options'] as $url) {
            $this->actingAs($admin)->get($url)
                ->assertRedirect(route('admin.settings.system.catalog-features'));
        }

        $this->runMigration();
        $this->assertFalse(app(CatalogFeatureService::class)->useOptions());
    }

    public function test_assigned_option_groups_keep_the_module_enabled(): void
    {
        $product = Product::query()->create(['code' => 'WITH-OPTION-GROUP']);
        $option = Option::query()->create(['code' => 'size', 'type' => 'select']);
        $product->options()->attach($option->id);
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $this->runMigration();

        $this->assertTrue(app(CatalogFeatureService::class)->useOptions());
        $this->assertTrue($product->options()->whereKey($option->id)->exists());
    }

    public function test_existing_option_value_rows_keep_the_module_enabled_even_without_a_group_assignment(): void
    {
        $product = Product::query()->create(['code' => 'WITH-OPTION-VALUE']);
        $option = Option::query()->create(['code' => 'size', 'type' => 'select']);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'small']);
        $row = ProductOptionValue::query()->create([
            'product_id' => $product->id,
            'option_value_id' => $value->id,
            'mode' => 'single',
            'combination_hash' => hash('sha256', 'single:'.$value->id),
        ]);
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $this->runMigration();

        $this->assertTrue(app(CatalogFeatureService::class)->useOptions());
        $this->assertDatabaseHas('catalog_product_option_values', ['id' => $row->id]);
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_07_180000_disable_unused_catalog_options.php');
        $migration->up();
    }
}
