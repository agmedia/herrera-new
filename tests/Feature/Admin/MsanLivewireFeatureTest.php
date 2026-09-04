<?php

namespace Tests\Feature\Admin;

use App\Jobs\Integrations\Msan\RepublishMsanSpecificationDefinitionJob;
use App\Livewire\Admin\Integrations\Msan\CategoryMappingManager;
use App\Livewire\Admin\Integrations\Msan\Dashboard;
use App\Livewire\Admin\Integrations\Msan\ProductSelectionManager;
use App\Livewire\Admin\Integrations\Msan\RunHistoryManager;
use App\Livewire\Admin\Integrations\Msan\SettingsForm;
use App\Livewire\Admin\Integrations\Msan\SpecificationMappingManager;
use App\Models\Catalog\Category\Category;
use App\Models\Integrations\Msan\MsanCategory;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSpecificationDefinition;
use App\Models\Integrations\Msan\MsanSyncRun;
use App\Models\User;
use App\Services\Integrations\Msan\MsanCatalogSyncCoordinator;
use App\Services\Integrations\Msan\MsanCatalogSyncService;
use App\Services\Integrations\Msan\MsanCertificateService;
use App\Services\Integrations\Msan\MsanImportCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class MsanLivewireFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_map_multiple_msan_categories_to_one_local_category_and_ignore_another(): void
    {
        $admin = $this->makeAdmin();
        $localCategory = $this->createLocalCategory('grijanje', 'Grijanje');
        $first = $this->createMsanCategory('MSAN-HEAT-1', 'Radijatori');
        $second = $this->createMsanCategory('MSAN-HEAT-2', 'Konvektori');
        $ignored = $this->createMsanCategory('MSAN-OLD', 'Stari program');
        $eprelCachedProduct = $this->createMsanProduct('EPREL-CACHED', 'Artikl s EPREL stanjem', 'Test');
        $eprelCachedProduct->categories()->attach($first->id);
        $eprelCachedProduct->forceFill([
            'eprel_match_status' => MsanProduct::EPREL_EXACT,
            'eprel_identifier_checksum' => str_repeat('c', 64),
            'eprel_checked_at' => now(),
        ])->save();

        $component = Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->assertSee('Radijatori')
            ->assertSee('Konvektori')
            ->call('openEditor', $first->id)
            ->set('localCategoryId', (string) $localCategory->id)
            ->set('eprelProductGroup', 'spaceheaters')
            ->set('energyRequirement', MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED)
            ->call('saveMapping')
            ->assertHasNoErrors()
            ->call('openEditor', $second->id)
            ->set('localCategoryId', (string) $localCategory->id)
            ->call('saveMapping')
            ->assertHasNoErrors()
            ->call('ignoreCategory', $ignored->id);

        $component->set('status', 'mapped')
            ->assertSee('Radijatori')
            ->assertSee('Konvektori')
            ->assertDontSee('Stari program');

        $this->assertDatabaseHas('msan_category_mappings', [
            'msan_category_id' => $first->id,
            'local_category_id' => $localCategory->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => 'spaceheaters',
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED,
            'updated_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('msan_category_mappings', [
            'msan_category_id' => $second->id,
            'local_category_id' => $localCategory->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
        ]);
        $this->assertDatabaseHas('msan_category_mappings', [
            'msan_category_id' => $ignored->id,
            'local_category_id' => null,
            'status' => MsanCategoryMapping::STATUS_IGNORED,
            'eprel_product_group' => null,
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_INHERIT,
        ]);
        $this->assertDatabaseHas('msan_products', [
            'id' => $eprelCachedProduct->id,
            'eprel_match_status' => MsanProduct::EPREL_PENDING,
            'eprel_identifier_checksum' => null,
            'eprel_checked_at' => null,
        ]);
    }

    public function test_exact_name_automatch_maps_only_unique_local_names(): void
    {
        $admin = $this->makeAdmin();
        $uniqueLocal = $this->createLocalCategory('bojleri', 'Bojleri');
        $duplicateSupplierLocal = $this->createLocalCategory('radijatori', 'Radijatori');
        $this->createLocalCategory('duplikat-a', 'Duplikat');
        $this->createLocalCategory('duplikat-b', 'Duplikat');
        $uniqueMsan = $this->createMsanCategory('MSAN-BOJLERI', '  BOJLERI ');
        $duplicateMsan = $this->createMsanCategory('MSAN-DUPLIKAT', 'Duplikat');
        $duplicateSupplierFirst = $this->createMsanCategory('MSAN-RAD-1', 'Radijatori');
        $duplicateSupplierSecond = $this->createMsanCategory('MSAN-RAD-2', ' radijatori ');

        Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->call('autoMatchExactNames')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('msan_category_mappings', [
            'msan_category_id' => $uniqueMsan->id,
            'local_category_id' => $uniqueLocal->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
        ]);
        $this->assertDatabaseMissing('msan_category_mappings', [
            'msan_category_id' => $duplicateMsan->id,
        ]);
        $this->assertDatabaseMissing('msan_category_mappings', [
            'msan_category_id' => $duplicateSupplierFirst->id,
            'local_category_id' => $duplicateSupplierLocal->id,
        ]);
        $this->assertDatabaseMissing('msan_category_mappings', [
            'msan_category_id' => $duplicateSupplierSecond->id,
            'local_category_id' => $duplicateSupplierLocal->id,
        ]);
    }

    public function test_category_branch_import_creates_hidden_local_tree_and_maps_zero_item_descendants(): void
    {
        $admin = $this->makeAdmin();
        $root = $this->createMsanCategory('MSAN-TREE', 'Kućanski uređaji');
        $root->update(['product_count' => 4]);
        $child = $this->createMsanCategory('MSAN-TREE-OVENS', 'Pećnice');
        $child->update([
            'parent_external_id' => $root->external_id,
            'path' => 'Kućanski uređaji > Pećnice',
            'product_count' => 0,
        ]);
        $grandchild = $this->createMsanCategory('MSAN-TREE-BUILTIN', 'Ugradbene pećnice');
        $grandchild->update([
            'parent_external_id' => $child->external_id,
            'path' => 'Kućanski uređaji > Pećnice > Ugradbene pećnice',
            'product_count' => 1,
        ]);
        $product = $this->createMsanProduct('TREE-PRODUCT', 'Testna pećnica', 'Test');
        $product->categories()->attach([$child->id, $grandchild->id]);

        $component = Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->call('openEditor', $root->id)
            ->assertSee('Nema jednake kategorije u webshopu')
            ->assertSee('Uvezi kategoriju i podkategorije');

        $this->assertSame(3, $component->viewData('branchImportSuggestion')['category_count']);
        $this->assertSame(2, $component->viewData('branchImportSuggestion')['descendant_count']);
        $this->assertSame(1, $component->viewData('branchImportSuggestion')['product_count']);

        $component
            ->call('importCategoryBranch')
            ->assertHasNoErrors()
            ->assertSet('editingCategoryId', null)
            ->assertDispatched('notify');

        $rootLocal = $root->fresh()->mapping->localCategory;
        $childLocal = $child->fresh()->mapping->localCategory;
        $grandchildLocal = $grandchild->fresh()->mapping->localCategory;

        $this->assertNull($rootLocal->parent_id);
        $this->assertSame($rootLocal->id, $childLocal->parent_id);
        $this->assertSame($childLocal->id, $grandchildLocal->parent_id);
        $this->assertFalse($rootLocal->is_active);
        $this->assertFalse($rootLocal->show_in_menu);
        $this->assertSame('MSAN-TREE', data_get($rootLocal->payload, 'supplier_sources.msan.external_id'));
        $this->assertDatabaseHas('category_translations', [
            'category_id' => $rootLocal->id,
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'hr',
            'name' => 'Kućanski uređaji',
        ]);

        foreach ([$root, $child, $grandchild] as $source) {
            $this->assertDatabaseHas('msan_category_mappings', [
                'msan_category_id' => $source->id,
                'status' => MsanCategoryMapping::STATUS_MAPPED,
                'updated_by' => $admin->id,
            ]);
        }
    }

    public function test_category_branch_import_uses_the_mapped_msan_parent_as_destination(): void
    {
        $admin = $this->makeAdmin();
        $localParent = $this->createLocalCategory('racunala', 'Računala');
        $sourceParent = $this->createMsanCategory('MSAN-COMPUTERS', 'Računala');
        $sourceChild = $this->createMsanCategory('MSAN-LAPTOPS', 'Prijenosna računala');
        $sourceChild->update([
            'parent_external_id' => $sourceParent->external_id,
            'path' => 'Računala > Prijenosna računala',
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $sourceParent->id,
            'local_category_id' => $localParent->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'updated_by' => $admin->id,
        ]);

        Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->call('openEditor', $sourceChild->id)
            ->assertSet('branchImportParentId', (string) $localParent->id)
            ->assertSee('Smjesti ispod kategorije')
            ->call('importCategoryBranch')
            ->assertHasNoErrors()
            ->assertDispatched('notify');

        $this->assertSame(
            $localParent->id,
            $sourceChild->fresh()->mapping->localCategory->parent_id,
        );
    }

    public function test_category_mapping_rejects_an_unsupported_eprel_product_group(): void
    {
        $admin = $this->makeAdmin();
        $localCategory = $this->createLocalCategory('klima-eprel', 'Klima uređaji');
        $msanCategory = $this->createMsanCategory('MSAN-EPREL', 'Klima uređaji');

        Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->call('openEditor', $msanCategory->id)
            ->set('localCategoryId', (string) $localCategory->id)
            ->set('eprelProductGroup', '../nepodrzana-grupa')
            ->call('saveMapping')
            ->assertHasErrors('eprelProductGroup');

        $this->assertDatabaseMissing('msan_category_mappings', [
            'msan_category_id' => $msanCategory->id,
        ]);
    }

    public function test_category_filters_and_saved_mappings_but_not_editor_drafts_survive_component_remounts(): void
    {
        $admin = $this->makeAdmin();
        $localCategory = $this->createLocalCategory('dizalice-topline', 'Dizalice topline');
        $msanCategory = $this->createMsanCategory('MSAN-HEAT-PUMPS', 'Dizalice topline');

        Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->set('searchInput', 'Dizalice')
            ->call('applySearch')
            ->set('status', 'all')
            ->call('openEditor', $msanCategory->id)
            ->set('localCategoryId', (string) $localCategory->id)
            ->set('eprelProductGroup', 'spaceheaters')
            ->set('energyRequirement', MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED)
            ->set('searchInput', 'Neprimijenjeni nacrt pretrage');

        $remounted = Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->assertSet('search', 'Dizalice')
            ->assertSet('searchInput', 'Dizalice')
            ->assertSet('status', 'all')
            ->assertSet('editingCategoryId', null)
            ->assertSet('localCategoryId', '')
            ->assertSet('eprelProductGroup', '')
            ->assertSet('energyRequirement', MsanCategoryMapping::ENERGY_REQUIREMENT_INHERIT)
            ->call('openEditor', $msanCategory->id)
            ->set('localCategoryId', (string) $localCategory->id)
            ->set('eprelProductGroup', 'spaceheaters')
            ->set('energyRequirement', MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED)
            ->call('saveMapping')
            ->assertHasNoErrors();

        $remounted->assertSet('editingCategoryId', null);

        Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->assertSet('search', 'Dizalice')
            ->assertSet('searchInput', 'Dizalice')
            ->assertSet('status', 'all')
            ->assertSet('editingCategoryId', null)
            ->call('openEditor', $msanCategory->id)
            ->assertSet('localCategoryId', (string) $localCategory->id)
            ->assertSet('eprelProductGroup', 'spaceheaters')
            ->assertSet('energyRequirement', MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED);

        $this->assertDatabaseHas('msan_category_mappings', [
            'msan_category_id' => $msanCategory->id,
            'local_category_id' => $localCategory->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => 'spaceheaters',
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED,
            'updated_by' => $admin->id,
        ]);
    }

    public function test_category_results_exclude_stale_rows_and_search_the_category_path(): void
    {
        $admin = $this->makeAdmin();
        $current = $this->createMsanCategory('MSAN-CURRENT-OVEN', 'Aktualne pećnice');
        $current->update(['path' => 'Kućanski uređaji / Pećnice']);
        $stale = $this->createMsanCategory('MSAN-STALE-OVEN', 'Zastarjele pećnice');
        $stale->update([
            'path' => 'Kućanski uređaji / Stare pećnice',
            'is_stale' => true,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->set('status', 'all')
            ->set('searchInput', 'Kućanski')
            ->call('applySearch')
            ->assertHasNoErrors('searchInput')
            ->assertSee('Aktualne pećnice')
            ->assertDontSee('Zastarjele pećnice')
            ->assertSee('Aktualne kategorije složene su po stablu')
            ->assertSee('Primijenjeni filtri pamte se');

        $this->assertSame([$current->id], $component->viewData('categories')->pluck('id')->all());
        $this->assertSame(1, $component->viewData('statusCounts')['all']);
        $this->assertSame(1, $component->viewData('statusCounts')['unmapped']);
    }

    public function test_category_product_filter_excludes_empty_categories_persists_and_resets_pagination(): void
    {
        $admin = $this->makeAdmin();
        app(\App\Services\Settings\SystemSettingsService::class)->put('admin_items_per_page', 1);
        $empty = $this->createMsanCategory('MSAN-EMPTY', 'Alfa prazna kategorija');
        $withProducts = $this->createMsanCategory('MSAN-WITH-PRODUCTS', 'Beta kategorija s artiklima');
        $withProducts->update(['product_count' => 3]);

        $component = Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->call('clearFilters')
            ->assertSet('withProductsOnly', false)
            ->assertSee('Samo s artiklima')
            ->assertSeeHtml('id="msan-categories-with-products-only"')
            ->assertSeeHtml('type="checkbox"')
            ->assertSeeHtml('wire:model.live="withProductsOnly"');

        $this->assertSame(2, $component->viewData('categories')->total());
        $component
            ->set('paginators.msanCategoryMappingsPage', 2)
            ->set('withProductsOnly', true)
            ->assertSet('paginators.msanCategoryMappingsPage', 1)
            ->assertDontSee($empty->name)
            ->assertSee($withProducts->name);

        $this->assertSame(
            [$withProducts->id],
            $component->viewData('categories')->getCollection()->pluck('id')->all(),
        );

        Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->assertSet('withProductsOnly', true)
            ->assertDontSee($empty->name)
            ->assertSee($withProducts->name)
            ->call('clearFilters')
            ->assertSet('withProductsOnly', false);

        Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->assertSet('withProductsOnly', false);
    }

    public function test_category_results_follow_alphabetical_tree_order_instead_of_product_count(): void
    {
        $admin = $this->makeAdmin();
        app(\App\Services\Settings\SystemSettingsService::class)->put('admin_items_per_page', 5);
        $rows = [
            ['A', 'Alfa', null, 'Alfa', 1],
            ['A-2', 'Zadnja Alfa grana', 'A', 'Alfa > Zadnja Alfa grana', 900],
            ['A-1', 'Prva Alfa grana', 'A', 'Alfa > Prva Alfa grana', 2],
            ['A-1-1', 'Duboka Alfa grana', 'A-1', 'Alfa > Prva Alfa grana > Duboka Alfa grana', 800],
            ['B', 'Beta', null, 'Beta', 999],
            ['B-1', 'Beta grana', 'B', 'Beta > Beta grana', 700],
        ];

        foreach ($rows as [$externalId, $name, $parentExternalId, $path, $productCount]) {
            MsanCategory::query()->create([
                'external_id' => $externalId,
                'name' => $name,
                'parent_external_id' => $parentExternalId,
                'path' => $path,
                'product_count' => $productCount,
                'last_seen_at' => now(),
                'is_stale' => false,
            ]);
        }

        $component = Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->set('status', 'unmapped')
            ->assertSee('Glavna kategorija')
            ->assertSee('Podkategorija · razina 1')
            ->assertSee('Podkategorija · razina 2')
            ->assertSeeHtml('data-msan-category-depth="0"')
            ->assertSeeHtml('data-msan-category-depth="2"');

        $this->assertSame(
            ['A', 'A-1', 'A-1-1', 'A-2', 'B'],
            $component->viewData('categories')->getCollection()->pluck('external_id')->all(),
        );

        $component->set('paginators.msanCategoryMappingsPage', 2);

        $this->assertSame(
            ['B-1'],
            $component->viewData('categories')->getCollection()->pluck('external_id')->all(),
        );
    }

    public function test_product_bulk_selection_persists_only_filtered_products_with_a_mapped_category(): void
    {
        $admin = $this->makeAdmin();
        $localCategory = $this->createLocalCategory('klima', 'Klima uređaji');
        $mappedCategory = $this->createMsanCategory('MSAN-CLIMA', 'Klima uređaji');
        $unmappedCategory = $this->createMsanCategory('MSAN-OTHER', 'Ostalo');
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $mappedCategory->id,
            'local_category_id' => $localCategory->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'updated_by' => $admin->id,
        ]);

        $eligible = $this->createMsanProduct('AC-100', 'Klima Alpha', 'Alpha');
        $eligible->update(['image_url' => 'https://b2b.msan.hr/private/admin-tracker.jpg']);
        $filteredOut = $this->createMsanProduct('AC-200', 'Klima Beta', 'Beta');
        $unmapped = $this->createMsanProduct('OTHER-100', 'Klima bez kategorije', 'Alpha');
        $alreadyImported = $this->createMsanProduct('AC-IMPORTED', 'Već uvezena klima', 'Alpha');
        $alreadyImported->update(['import_status' => MsanProduct::IMPORT_IMPORTED]);
        $eligible->categories()->attach($mappedCategory->id);
        $filteredOut->categories()->attach($mappedCategory->id);
        $unmapped->categories()->attach($unmappedCategory->id);
        $alreadyImported->categories()->attach($mappedCategory->id);

        $component = Livewire::actingAs($admin)
            ->test(ProductSelectionManager::class)
            ->assertDontSee('https://b2b.msan.hr/private/admin-tracker.jpg', escape: false)
            ->set('brand', 'Alpha')
            ->call('selectFiltered')
            ->assertDispatched('notify');

        $this->assertTrue($eligible->fresh()->selected);
        $this->assertFalse($filteredOut->fresh()->selected);
        $this->assertFalse($unmapped->fresh()->selected);
        $this->assertFalse($alreadyImported->fresh()->selected);
        $this->assertSame(1, $component->viewData('filteredEligibleCount'));

        $component->call('toggleSelection', $alreadyImported->id)
            ->assertDispatched('notify', type: 'info')
            ->assertSee('Artikl je već uvezen');

        $this->assertFalse($alreadyImported->fresh()->selected);

        $coordinator = Mockery::mock(MsanImportCoordinator::class);
        $coordinator->shouldReceive('queueSelected')
            ->once()
            ->with($admin->id)
            ->andReturn(new MsanSyncRun);
        $this->app->instance(MsanImportCoordinator::class, $coordinator);

        $component->call('queueSelectedImport');

        $component->set('categoryId', (string) $unmappedCategory->id)
            ->call('toggleSelection', $unmapped->id)
            ->assertDispatched('notify', type: 'warning');

        $this->assertFalse($unmapped->fresh()->selected);

        $component->call('clearFilters')
            ->set('brand', 'Alpha')
            ->call('deselectFiltered');

        $this->assertFalse($eligible->fresh()->selected);
    }

    public function test_product_selection_survives_remount_without_moving_selected_rows_ahead_of_name_order(): void
    {
        $admin = $this->makeAdmin();
        $localCategory = $this->createLocalCategory('redoslijed', 'Kategorija redoslijeda');
        $mappedCategory = $this->createMsanCategory('MSAN-ORDER', 'Kategorija redoslijeda');
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $mappedCategory->id,
            'local_category_id' => $localCategory->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'updated_by' => $admin->id,
        ]);

        $alphabeticallyFirst = $this->createMsanProduct('ORDER-A', 'Alfa neodabrani artikl', 'Test');
        $selectedLater = $this->createMsanProduct('ORDER-Z', 'Zulu odabrani artikl', 'Test');
        $alphabeticallyFirst->categories()->attach($mappedCategory->id);
        $selectedLater->categories()->attach($mappedCategory->id);

        Livewire::actingAs($admin)
            ->test(ProductSelectionManager::class)
            ->call('toggleSelection', $selectedLater->id);

        $remounted = Livewire::actingAs($admin)->test(ProductSelectionManager::class);
        $renderedProducts = $remounted->viewData('products')->getCollection();

        $this->assertTrue($selectedLater->fresh()->selected);
        $this->assertTrue((bool) $renderedProducts->firstWhere('id', $selectedLater->id)?->selected);
        $this->assertSame(
            [$alphabeticallyFirst->name, $selectedLater->name],
            $renderedProducts->pluck('name')->all(),
        );
    }

    public function test_product_selection_only_offers_retryable_import_statuses(): void
    {
        $admin = $this->makeAdmin();
        $localCategory = $this->createLocalCategory('statusi-uvoza', 'Statusi uvoza');
        $mappedCategory = $this->createMsanCategory('MSAN-IMPORT-STATUSES', 'Statusi uvoza');
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $mappedCategory->id,
            'local_category_id' => $localCategory->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'updated_by' => $admin->id,
        ]);

        $products = collect([
            MsanProduct::IMPORT_PENDING,
            MsanProduct::IMPORT_FAILED,
            MsanProduct::IMPORT_SKIPPED,
            MsanProduct::IMPORT_IMPORTED,
            MsanProduct::IMPORT_QUEUED,
            MsanProduct::IMPORT_IMPORTING,
        ])->mapWithKeys(function (string $status) use ($mappedCategory): array {
            $product = $this->createMsanProduct('STATUS-'.strtoupper($status), 'Artikl '.$status, 'Statusi');
            $product->update(['import_status' => $status]);
            $product->categories()->attach($mappedCategory->id);

            return [$status => $product];
        });

        $component = Livewire::actingAs($admin)
            ->test(ProductSelectionManager::class)
            ->call('clearFilters')
            ->set('brand', 'Statusi')
            ->call('selectFiltered')
            ->assertDispatched('notify')
            ->assertSee('Već uvezen');

        $this->assertSame(6, $component->viewData('filteredCount'));
        $this->assertSame(3, $component->viewData('filteredEligibleCount'));
        $this->assertSame(3, $component->viewData('filteredSelectedCount'));

        foreach (MsanProduct::IMPORT_READY_STATUSES as $status) {
            $this->assertTrue($products->get($status)->fresh()->selected, $status);
        }
        foreach ([MsanProduct::IMPORT_IMPORTED, MsanProduct::IMPORT_QUEUED, MsanProduct::IMPORT_IMPORTING] as $status) {
            $this->assertFalse($products->get($status)->fresh()->selected, $status);
        }

        $component->call('toggleSelection', $products->get(MsanProduct::IMPORT_IMPORTED)->id)
            ->assertDispatched('notify', type: 'info');

        $this->assertFalse($products->get(MsanProduct::IMPORT_IMPORTED)->fresh()->selected);
    }

    public function test_product_filters_survive_remount_and_clear_filters_resets_the_session_state(): void
    {
        $admin = $this->makeAdmin();
        $category = $this->createMsanCategory('MSAN-FILTERS', 'Filtrirana kategorija');
        $this->createMsanProduct('FILTER-100', 'Klima Filter', 'Filter Brand');

        Livewire::actingAs($admin)
            ->test(ProductSelectionManager::class)
            ->set('searchInput', 'Klima')
            ->call('applySearch')
            ->set('categoryId', (string) $category->id)
            ->set('brand', 'Filter Brand')
            ->set('availability', 'available')
            ->set('selection', 'selected')
            ->set('importStatus', MsanProduct::IMPORT_FAILED);

        $remounted = Livewire::actingAs($admin)
            ->test(ProductSelectionManager::class)
            ->assertSet('search', 'Klima')
            ->assertSet('searchInput', 'Klima')
            ->assertSet('categoryId', (string) $category->id)
            ->assertSet('brand', 'Filter Brand')
            ->assertSet('availability', 'available')
            ->assertSet('selection', 'selected')
            ->assertSet('importStatus', MsanProduct::IMPORT_FAILED)
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('searchInput', '')
            ->assertSet('categoryId', '')
            ->assertSet('brand', '')
            ->assertSet('availability', 'all')
            ->assertSet('selection', 'all')
            ->assertSet('importStatus', 'all');

        Livewire::actingAs($admin)
            ->test(ProductSelectionManager::class)
            ->assertSet('search', '')
            ->assertSet('searchInput', '')
            ->assertSet('categoryId', '')
            ->assertSet('brand', '')
            ->assertSet('availability', 'all')
            ->assertSet('selection', 'all')
            ->assertSet('importStatus', 'all');
    }

    public function test_product_category_filter_exposes_every_current_category_without_a_backend_display_limit(): void
    {
        $admin = $this->makeAdmin();
        $currentCategoryIds = collect(range(1, 12))
            ->map(fn (int $index): int => $this->createMsanCategory(
                'MSAN-FILTER-CATEGORY-'.$index,
                'Aktualna kategorija '.$index,
            )->id)
            ->all();
        $staleCategory = $this->createMsanCategory('MSAN-STALE-FILTER-CATEGORY', 'Zastarjela kategorija');
        $staleCategory->update(['is_stale' => true]);

        Cache::forget(MsanCatalogSyncService::ADMIN_FILTER_OPTIONS_CACHE_KEY);

        $categories = Livewire::actingAs($admin)
            ->test(ProductSelectionManager::class)
            ->viewData('categories');

        $this->assertCount(12, $categories);
        $this->assertEqualsCanonicalizing($currentCategoryIds, collect($categories)->pluck('id')->all());
        $this->assertNotContains($staleCategory->id, collect($categories)->pluck('id')->all());
    }

    public function test_product_search_waits_for_submit_and_uses_index_friendly_prefix_matching(): void
    {
        $admin = $this->makeAdmin();
        $prefixMatch = $this->createMsanProduct('AC-100', 'Klima Alpha', 'Alpha');
        $containsOnly = $this->createMsanProduct('AC-200', 'Premium Klima', 'Beta');

        Livewire::actingAs($admin)
            ->test(ProductSelectionManager::class)
            ->set('searchInput', 'K')
            ->call('applySearch')
            ->assertHasErrors('searchInput')
            ->set('searchInput', 'Klima')
            ->assertSee($prefixMatch->name)
            ->assertSee($containsOnly->name)
            ->call('applySearch')
            ->assertHasNoErrors('searchInput')
            ->assertSee($prefixMatch->name)
            ->assertDontSee($containsOnly->name);
    }

    public function test_run_history_is_paginated_read_only_and_filters_by_kind_and_status(): void
    {
        $admin = $this->makeAdmin();
        MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_CATALOG,
            'status' => MsanSyncRun::STATUS_COMPLETED,
            'requested_by' => $admin->id,
            'progress' => 100,
            'total_count' => 10,
            'processed_count' => 10,
            'succeeded_count' => 10,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_IMPORT,
            'status' => MsanSyncRun::STATUS_FAILED,
            'requested_by' => $admin->id,
            'progress' => 50,
            'total_count' => 4,
            'processed_count' => 2,
            'failed_count' => 1,
            'error_message' => 'Kontrolirana testna pogreška',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(RunHistoryManager::class)
            ->assertSee('Kontrolirana testna pogreška')
            ->set('kind', MsanSyncRun::KIND_CATALOG)
            ->set('status', MsanSyncRun::STATUS_COMPLETED)
            ->assertSee('Katalog')
            ->assertDontSee('Kontrolirana testna pogreška')
            ->assertDontSee('Ponovi');
    }

    public function test_specification_mapping_searches_by_prefix_and_saves_admin_overrides(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();
        $width = MsanSpecificationDefinition::query()->create([
            'source_key' => str_repeat('a', 64),
            'group_name' => 'Dimenzije',
            'item_name' => 'Širina',
            'measure' => 'mm',
            'source_for_filter' => true,
            'import_enabled' => true,
            'use_as_filter' => false,
            'data_role' => MsanSpecificationDefinition::ROLE_SPECIFICATION,
            'sample_values' => ['595', '600'],
            'product_count' => 27,
            'last_seen_at' => now(),
            'is_stale' => false,
        ]);
        MsanSpecificationDefinition::query()->create([
            'source_key' => str_repeat('b', 64),
            'group_name' => 'Osnovno',
            'item_name' => 'Premium širina',
            'measure' => 'mm',
            'sample_values' => ['700'],
            'product_count' => 1,
            'last_seen_at' => now(),
            'is_stale' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(SpecificationMappingManager::class)
            ->assertSee('595')
            ->assertSee('27')
            ->set('searchInput', 'Š')
            ->call('applySearch')
            ->assertHasErrors('searchInput')
            ->set('searchInput', 'Šir')
            ->assertSee('Premium širina')
            ->call('applySearch')
            ->assertHasNoErrors('searchInput')
            ->assertSee('Širina')
            ->assertDontSee('Premium širina')
            ->call('openEditor', $width->id)
            ->set('importEnabled', false)
            ->set('useAsFilter', true)
            ->set('dataRole', MsanSpecificationDefinition::ROLE_ENERGY_CLASS)
            ->set('displayGroupName', 'Mjere uređaja')
            ->set('displayItemName', 'Nazivna širina')
            ->set('displayMeasure', 'cm')
            ->call('saveDefinition')
            ->assertHasNoErrors()
            ->assertDispatched('notify');

        $this->assertDatabaseHas('msan_specification_definitions', [
            'id' => $width->id,
            'import_enabled' => false,
            'use_as_filter' => true,
            'data_role' => MsanSpecificationDefinition::ROLE_ENERGY_CLASS,
            'display_group_name' => 'Mjere uređaja',
            'display_item_name' => 'Nazivna širina',
            'display_measure' => 'cm',
            'updated_by' => $admin->id,
        ]);
        Queue::assertPushed(
            RepublishMsanSpecificationDefinitionJob::class,
            fn (RepublishMsanSpecificationDefinitionJob $job): bool => $job->queue === 'integrations',
        );
    }

    public function test_specification_filters_but_not_editor_drafts_survive_component_remounts(): void
    {
        $admin = $this->makeAdmin();
        $definition = MsanSpecificationDefinition::query()->create([
            'source_key' => str_repeat('c', 64),
            'group_name' => 'Energetski podaci',
            'item_name' => 'Energetski razred',
            'source_for_filter' => true,
            'import_enabled' => true,
            'use_as_filter' => false,
            'data_role' => MsanSpecificationDefinition::ROLE_SPECIFICATION,
            'sample_values' => ['A'],
            'product_count' => 3,
            'last_seen_at' => now(),
            'is_stale' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(SpecificationMappingManager::class)
            ->set('searchInput', 'Energetski')
            ->call('applySearch')
            ->set('importState', 'disabled')
            ->set('staleState', 'all')
            ->set('role', MsanSpecificationDefinition::ROLE_ENERGY_CLASS)
            ->call('openEditor', $definition->id)
            ->set('importEnabled', false)
            ->set('useAsFilter', true)
            ->set('dataRole', MsanSpecificationDefinition::ROLE_ENERGY_CLASS)
            ->set('displayGroupName', 'Nacrt grupe')
            ->set('displayItemName', 'Nacrt stavke')
            ->set('displayMeasure', 'nacrt')
            ->set('searchInput', 'Neprimijenjeni nacrt pretrage');

        Livewire::actingAs($admin)
            ->test(SpecificationMappingManager::class)
            ->assertSet('search', 'Energetski')
            ->assertSet('searchInput', 'Energetski')
            ->assertSet('importState', 'disabled')
            ->assertSet('staleState', 'all')
            ->assertSet('role', MsanSpecificationDefinition::ROLE_ENERGY_CLASS)
            ->assertSet('editingDefinitionId', null)
            ->assertSet('editingDefinitionLabel', '')
            ->assertSet('importEnabled', true)
            ->assertSet('useAsFilter', false)
            ->assertSet('dataRole', MsanSpecificationDefinition::ROLE_SPECIFICATION)
            ->assertSet('displayGroupName', '')
            ->assertSet('displayItemName', '')
            ->assertSet('displayMeasure', '');
    }

    public function test_run_history_translates_specification_kind_and_summary(): void
    {
        $admin = $this->makeAdmin();
        MsanSyncRun::query()->create([
            'kind' => 'specifications',
            'status' => MsanSyncRun::STATUS_COMPLETED,
            'progress' => 100,
            'summary' => [
                'source' => 'standard',
                'published_specifications' => 42,
            ],
            'completed_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(RunHistoryManager::class)
            ->assertSee('Tehničke specifikacije')
            ->assertSee('Izvor specifikacija')
            ->assertSee('Standardni M SAN skup')
            ->assertSee('Objavljene specifikacije')
            ->assertSee('42');
    }

    public function test_dashboard_exposes_and_queues_each_manual_background_refresh(): void
    {
        $admin = $this->makeAdmin();
        $coordinator = Mockery::mock(MsanCatalogSyncCoordinator::class);
        $coordinator->shouldReceive('queuePricesAndStock')->once()->with($admin->id)->andReturn(new MsanSyncRun);
        $coordinator->shouldReceive('queueSpecifications')->once()->with($admin->id)->andReturn(new MsanSyncRun);
        $coordinator->shouldReceive('queueEprelEnergy')->once()->with($admin->id)->andReturn(new MsanSyncRun);
        $this->app->instance(MsanCatalogSyncCoordinator::class, $coordinator);

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSee('Osvježi cijene i količine')
            ->assertSee('Dohvati specifikacije')
            ->assertSee('Osvježi EPREL')
            ->call('syncPricesAndStock')
            ->assertDispatched('notify', type: 'success')
            ->call('syncSpecifications')
            ->assertDispatched('notify', type: 'success')
            ->call('syncEprel')
            ->assertDispatched('notify', type: 'success');
    }

    public function test_product_selection_and_category_mapping_invalidate_dashboard_counts_cache(): void
    {
        $admin = $this->makeAdmin();
        $localCategory = $this->createLocalCategory('cache', 'Cache kategorija');
        $selectableCategory = $this->createMsanCategory('MSAN-CACHE-SELECT', 'Odabir za cache');
        $categoryToMap = $this->createMsanCategory('MSAN-CACHE-MAP', 'Mapiranje za cache');
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $selectableCategory->id,
            'local_category_id' => $localCategory->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'updated_by' => $admin->id,
        ]);
        $product = $this->createMsanProduct('CACHE-100', 'Cache artikl', 'Test');
        $product->categories()->attach($selectableCategory->id);

        Cache::put(Dashboard::COUNTS_CACHE_KEY, ['stale' => true], now()->addMinute());

        Livewire::actingAs($admin)
            ->test(ProductSelectionManager::class)
            ->call('toggleSelection', $product->id);

        $this->assertFalse(Cache::has(Dashboard::COUNTS_CACHE_KEY));

        Cache::put(Dashboard::COUNTS_CACHE_KEY, ['stale' => true], now()->addMinute());

        Livewire::actingAs($admin)
            ->test(CategoryMappingManager::class)
            ->call('openEditor', $categoryToMap->id)
            ->set('localCategoryId', (string) $localCategory->id)
            ->call('saveMapping')
            ->assertHasNoErrors();

        $this->assertFalse(Cache::has(Dashboard::COUNTS_CACHE_KEY));
    }

    public function test_settings_explain_default_local_sellable_limits_for_each_availability_level(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(SettingsForm::class)
            ->assertSee('Maksimalna prodajna količina prema M SAN dostupnosti')
            ->assertSee('To je prodajni limit, ne potvrđena zaliha dobavljača.')
            ->assertSee('Automatsko osvježavanje cijena i količina')
            ->assertSee('Osvježi cijene i količine odmah')
            ->assertSee('Europe/Zagreb')
            ->assertSee('Niska dostupnost')
            ->assertSee('Srednja dostupnost')
            ->assertSee('Vrlo visoka dostupnost')
            ->assertSee('Tehničke specifikacije')
            ->assertSee('EPREL energetski podaci')
            ->assertSet('form.msan_import_specifications', true)
            ->assertSet('form.msan_specifications_selected_only', true)
            ->assertSet('form.msan_specifications_source', 'standard')
            ->assertSet('form.msan_specifications_timeout', 1800)
            ->assertSet('form.msan_eprel_enabled', false)
            ->assertSet('form.msan_eprel_connect_timeout', 10)
            ->assertSet('form.msan_eprel_timeout', 30)
            ->assertSet('form.msan_price_stock_sync_enabled', true)
            ->assertSet('form.msan_price_stock_sync_cron', '*/15 * * * *')
            ->assertSet('form.msan_stock_level_0', 0)
            ->assertSet('form.msan_stock_level_1', 1)
            ->assertSet('form.msan_stock_level_2', 3)
            ->assertSet('form.msan_stock_level_3', 5)
            ->assertSet('form.msan_stock_level_4', 10);
    }

    public function test_settings_reject_a_price_and_stock_cron_that_is_invalid_or_too_frequent(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(SettingsForm::class)
            ->set('form.msan_price_stock_sync_cron', '*/5 * * * *')
            ->call('save')
            ->assertHasErrors('form.msan_price_stock_sync_cron');
    }

    public function test_settings_manual_price_and_stock_button_queues_the_refresh(): void
    {
        $admin = $this->makeAdmin();
        $coordinator = Mockery::mock(MsanCatalogSyncCoordinator::class);
        $coordinator->shouldReceive('queuePricesAndStock')->once()->with($admin->id)->andReturn(new MsanSyncRun);
        $this->app->instance(MsanCatalogSyncCoordinator::class, $coordinator);

        Livewire::actingAs($admin)
            ->test(SettingsForm::class)
            ->call('syncPricesAndStock')
            ->assertDispatched('notify', type: 'success');
    }

    public function test_eprel_cannot_be_enabled_without_a_stored_or_new_api_key(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(SettingsForm::class)
            ->set('form.msan_eprel_enabled', true)
            ->set('form.msan_eprel_api_key', '')
            ->call('save')
            ->assertHasErrors('form.msan_eprel_api_key');
    }

    public function test_eprel_api_key_configured_flag_cannot_be_tampered_to_bypass_server_state(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(SettingsForm::class)
            ->set('form.msan_eprel_enabled', true)
            ->set('form.msan_eprel_api_key', '')
            ->set('form.msan_eprel_api_key_configured', true)
            ->call('save')
            ->assertHasErrors('form.msan_eprel_api_key');

        $this->assertFalse(app(\App\Services\Integrations\Msan\MsanSettingsService::class)->eprelEnabled());
    }

    public function test_product_list_distinguishes_supplier_availability_from_local_sellable_limit(): void
    {
        $admin = $this->makeAdmin();
        $this->createMsanProduct('LEVEL-2', 'Artikl srednje dostupnosti', 'Test Brand');

        Livewire::actingAs($admin)
            ->test(ProductSelectionManager::class)
            ->assertSee('Srednja dostupnost')
            ->assertSee('razina 2')
            ->assertSee('Lokalni prodajni limit: 3 kom.')
            ->assertSee('Nije stvarna M SAN zaliha')
            ->assertDontSee('Razina 2');
    }

    public function test_product_list_lazy_loads_internal_image_previews_without_exposing_the_supplier_url(): void
    {
        $admin = $this->makeAdmin();
        $sourceUrl = 'https://b2b.msan.hr/private/catalog/product.jpg?token=sensitive';
        $product = $this->createMsanProduct('IMAGE-100', 'Artikl sa slikom', 'Test Brand');
        $product->update(['image_url' => $sourceUrl]);
        $previewUrl = route('admin.integrations.msan.products.image', $product);

        $component = Livewire::actingAs($admin)->test(ProductSelectionManager::class);
        preg_match_all('/<img\b[^>]*>/i', $component->html(), $matches);
        $previewTags = array_values(array_filter(
            $matches[0],
            static fn (string $tag): bool => str_contains($tag, e($previewUrl)),
        ));

        $this->assertNotEmpty($previewTags, 'The product list does not render an internal M SAN image preview.');
        foreach ($previewTags as $previewTag) {
            $this->assertStringContainsString('loading="lazy"', $previewTag);
            $this->assertStringContainsString('decoding="async"', $previewTag);
            $this->assertStringContainsString('fetchpriority="low"', $previewTag);
        }
        $this->assertStringNotContainsString($sourceUrl, $component->html());
        $this->assertStringNotContainsString('b2b.msan.hr', $component->html());
    }

    public function test_invalid_ftp_form_does_not_replace_certificate_before_full_validation(): void
    {
        $admin = $this->makeAdmin();
        Storage::fake('msan-livewire-temp');
        config(['livewire.temporary_file_upload.disk' => 'msan-livewire-temp']);
        $certificates = Mockery::mock(MsanCertificateService::class);
        $certificates->shouldReceive('hasCertificate')->once()->andReturn(false);
        $certificates->shouldNotReceive('replaceFromPath');
        $this->app->instance(MsanCertificateService::class, $certificates);

        Livewire::actingAs($admin)
            ->test(SettingsForm::class)
            ->set('certificate', UploadedFile::fake()->create('client.p12', 10, 'application/x-pkcs12'))
            ->set('form.msan_enabled', true)
            ->set('form.msan_p12_pin', 'synthetic-pin')
            ->set('form.msan_ftp_enabled', true)
            ->set('form.msan_ftp_username', '')
            ->set('form.msan_ftp_password', '')
            ->call('save')
            ->assertHasErrors('form.msan_ftp_username');

        $this->assertSame([], Storage::disk('msan-livewire-temp')->allFiles('livewire-tmp'));
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function createLocalCategory(string $code, string $name): Category
    {
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => $code,
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 0,
        ]);
        $category->translations()->create([
            'locale' => 'hr',
            'name' => $name,
            'slug' => $code,
        ]);

        return $category;
    }

    private function createMsanCategory(string $externalId, string $name): MsanCategory
    {
        return MsanCategory::query()->create([
            'external_id' => $externalId,
            'name' => $name,
            'path' => $name,
            'product_count' => 0,
            'last_seen_at' => now(),
            'is_stale' => false,
        ]);
    }

    private function createMsanProduct(string $externalCode, string $name, string $brand): MsanProduct
    {
        return MsanProduct::query()->create([
            'external_code' => $externalCode,
            'name' => $name,
            'brand' => $brand,
            'currency_code' => 'EUR',
            'availability_level' => 2,
            'selected' => false,
            'is_stale' => false,
            'match_status' => MsanProduct::MATCH_UNMATCHED,
            'import_status' => MsanProduct::IMPORT_PENDING,
            'last_seen_at' => now(),
        ]);
    }
}
