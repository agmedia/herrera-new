<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Pricing\PriceCatalogManager;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\PriceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class B2BGroupDiscountUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_screen_shows_existing_customer_groups_not_the_raw_price_table(): void
    {
        [$admin, $catalog] = $this->fixture();
        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->assertSet('tab', 'groups')
            ->assertSee('Sve grupe kupaca')
            ->assertSee('B2B20')
            ->call('switchTab', 'rules')
            ->assertSee('Popusti po grupama kupaca')
            ->assertSee('Uvezene ugovorene i akcijske cijene su sačuvane.')
            ->assertDontSee('Pretraži stavke, SKU, naziv ili kupca')
            ->call('switchTab', 'product')->assertSee('Cijene grupa za jedan artikl')
            ->call('switchTab', 'check')->assertSee('Provjeri stvarnu cijenu kupca')
            ->call('switchTab', 'advanced')->assertSee('Pretraži stavke, SKU, naziv ili kupca');
        $this->assertSame('draft', $catalog->fresh()->status);
    }

    public function test_percent_preview_and_save_do_not_publish_and_keep_four_decimals(): void
    {
        [$admin, $catalog, $product, $first, $second] = $this->fixture();
        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('newRule')
            ->assertSee('wire:ignore.self', false)
            ->set('ruleForm.name', 'Popust 60 %')
            ->set('ruleForm.percent', '60')
            ->set('ruleForm.customer_group_ids', [$first->id, $second->id])
            ->call('previewRule')->assertHasNoErrors()
            ->assertSet('rulePreview.product_count', 1)
            ->assertSet('rulePreview.entry_count', 2)
            ->assertSet('rulePreview.samples.0.price', '8.1920')
            ->call('saveRule')->assertHasNoErrors()->assertSet('showRuleForm', false);
        $this->assertDatabaseHas('catalog_price_entries', ['price_catalog_id' => $catalog->id, 'product_id' => $product->id, 'kind' => PriceCatalogEntry::GROUP_DISCOUNT, 'price' => '8.1920', 'customer_group_id' => $first->id]);
        $this->assertSame(1, $catalog->discountRules()->count());
        $this->assertNull(\Illuminate\Support\Facades\DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
        $this->assertSame('draft', $catalog->fresh()->status);
    }

    public function test_invalid_rule_and_empty_group_selection_are_rejected(): void
    {
        [$admin, $catalog] = $this->fixture();
        Livewire::actingAs($admin)->test(PriceCatalogManager::class)->call('newRule')
            ->set('ruleForm.name', 'Nevaljano')->set('ruleForm.percent', '120')
            ->call('saveRule')->assertHasErrors(['percent', 'customer_group_ids']);
        $this->assertSame(0, $catalog->discountRules()->count());
    }

    public function test_per_product_grid_prefills_prices_and_saves_decimal_comma(): void
    {
        [$admin, $catalog, $product, $first, $second] = $this->fixture();
        $source = $this->entry($catalog, $product, 'group', '18.4320', $first);
        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('switchTab', 'product')->set('groupProductId', (string) $product->id)
            ->assertSet('groupProduct.base_price', '20.4800')
            ->assertSet('groupPrices.'.$first->id, '18.4320')
            ->set('groupPrices.'.$second->id, '16,3840')
            ->call('saveGroupPrices')->assertHasNoErrors()
            ->assertSet('groupPrices.'.$second->id, '16.3840');
        $this->assertSame('18.4320', $source->fresh()->price);
        $this->assertDatabaseHas('catalog_price_entries', ['source_key' => 'manual-group:'.$product->id.':'.$second->id, 'price' => '16.3840']);
        $this->assertDatabaseMissing('catalog_price_entries', ['source_key' => 'manual-group:'.$product->id.':'.$first->id]);
    }

    public function test_blank_group_price_does_not_delete_existing_price(): void
    {
        [$admin, $catalog, $product, $first] = $this->fixture();
        $source = $this->entry($catalog, $product, 'group', '18.4320', $first);
        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->set('groupProductId', (string) $product->id)
            ->set('groupPrices.'.$first->id, '')
            ->call('saveGroupPrices')->assertHasNoErrors()
            ->assertSet('groupPrices.'.$first->id, '18.4320');
        $this->assertSame('18.4320', $source->fresh()->price);
    }

    public function test_published_catalog_is_read_only_in_the_simple_screen(): void
    {
        [$admin, $catalog, , $group] = $this->fixture();
        app(PriceCatalogService::class)->activate($catalog, $admin);
        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $group->id)
            ->assertSee('Ovaj cjenik je zaključan.')
            ->assertDontSee('wire:click="newRule"', false)
            ->call('newRule')->assertHasErrors(['catalog']);
        $this->assertSame(0, $catalog->discountRules()->count());
    }

    public function test_read_only_pricing_role_cannot_add_rules_or_group_prices(): void
    {
        [, $catalog, $product, $first] = $this->fixture();
        $viewer = User::factory()->create();
        Bouncer::allow($viewer)->to('admin.access');
        Bouncer::allow($viewer)->to('catalog.b2b_prices.view');
        Livewire::actingAs($viewer)->test(PriceCatalogManager::class)->call('newRule')->assertForbidden();
        Livewire::actingAs($viewer)->test(PriceCatalogManager::class)
            ->set('groupProductId', (string) $product->id)->set('groupPrices.'.$first->id, '1')
            ->call('saveGroupPrices')->assertForbidden();
        $this->assertSame(0, $catalog->discountRules()->count());
    }

    private function fixture(): array
    {
        config(['commerce.b2b_only' => true]);
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        $catalog = app(PriceCatalogService::class)->createDraft('Radni B2B cjenik', $admin);
        $product = Product::query()->create(['code' => 'PA58BKR', 'sku' => 'PA58BKR', 'base_price' => '20.4800', 'is_active' => true]);
        $first = CustomerGroup::query()->create(['code' => 'b2b10', 'name' => 'B2B10', 'is_active' => true]);
        $second = CustomerGroup::query()->create(['code' => 'b2b20', 'name' => 'B2B20', 'is_active' => true]);
        $this->entry($catalog, $product, 'base', '20.4800');

        return [$admin, $catalog, $product, $first, $second];
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price, ?CustomerGroup $group = null): PriceCatalogEntry
    {
        return PriceCatalogEntry::query()->create(['price_catalog_id' => $catalog->id, 'source_key' => 'fixture:'.$kind.':'.($group?->id ?? 'base'), 'product_id' => $product->id, 'kind' => $kind, 'customer_group_id' => $group?->id, 'price' => $price, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
    }
}
