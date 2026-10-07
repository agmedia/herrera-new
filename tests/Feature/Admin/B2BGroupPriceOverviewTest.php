<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Pricing\PriceCatalogManager;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\LegacyGroupDiscountReference;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\CatalogGroupDiscountService;
use App\Services\Pricing\PriceCatalogService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class B2BGroupPriceOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_overview_contains_all_fourteen_groups_including_inactive_and_empty_groups(): void
    {
        [$admin, $catalog, $product, $first, $second] = $this->fixture();
        $second->update(['is_active' => false]);
        $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first);
        $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '16.1234', $first, ['is_active' => false]);
        foreach (range(3, 14) as $number) {
            CustomerGroup::query()->create(['code' => 'group-'.$number, 'name' => 'Grupa bez cijena '.$number, 'is_active' => true]);
        }

        $screen = Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->assertSet('tab', 'groups')
            ->assertViewHas('groups', fn ($groups): bool => $groups->count() === 14)
            ->assertViewHas('groupOverview', fn ($counts): bool => $counts->get($first->id) === [PriceCatalogEntry::GROUP => 1, PriceCatalogEntry::SPECIAL => 1]
                && ($counts->get($second->id) ?? []) === [])
            ->assertSee($first->name)
            ->assertSee($second->name)
            ->assertSee('Grupa bez cijena 14');

        $screen->call('openGroup', $second->id)->assertSet('selectedGroupId', $second->id);
        $this->assertSame(0, $catalog->discountRules()->count());
        $this->assertSame('draft', $catalog->fresh()->status);
    }

    public function test_open_group_shows_only_its_existing_group_and_special_prices_with_original_source_and_dimensions(): void
    {
        [$admin, $catalog, $product, $first, $second] = $this->fixture();
        $otherProduct = $this->product('OTHER-GROUP-ONLY');
        $groupEntry = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first, ['source_key' => 'opencart:product_to_customer_group:101']);
        $special = $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '15.9876', $first, [
            'source_key' => 'opencart:product_special:102',
            'priority' => -8,
            'starts_at' => '2026-10-01 09:15:00',
            'ends_at' => '2026-10-31 23:45:00',
            'is_active' => false,
        ]);
        $other = $this->entry($catalog, $otherProduct, PriceCatalogEntry::GROUP, '7.4321', $second, ['source_key' => 'opencart:other-group:103']);
        $otherCatalog = app(PriceCatalogService::class)->createDraft('Druga radna verzija', $admin);
        $outside = $this->entry($otherCatalog, $otherProduct, PriceCatalogEntry::SPECIAL, '6.1234', $first, ['source_key' => 'opencart:other-catalog:104']);

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('selectCatalog', $catalog->id)
            ->call('openGroup', $first->id)
            ->assertSet('tab', 'groups')
            ->assertSet('selectedGroupId', $first->id)
            ->assertViewHas('groupRows', function (array $rows) use ($product, $groupEntry, $special): bool {
                $row = $rows[$product->id] ?? null;

                return $row && $row['base_price'] === '20.4800'
                    && $row['effective_price'] === '18.4320'
                    && $row['effective_entry']?->id === $groupEntry->id
                    && $row['entries']->where('kind', '!=', PriceCatalogEntry::BASE)->pluck('id')->sort()->values()->all() === [$groupEntry->id, $special->id]
                    && $row['entries']->firstWhere('id', $special->id)?->priority === -8
                    && $row['entries']->firstWhere('id', $special->id)?->starts_at->format('Y-m-d H:i:s') === '2026-10-01 09:15:00'
                    && $row['entries']->firstWhere('id', $special->id)?->ends_at->format('Y-m-d H:i:s') === '2026-10-31 23:45:00';
            })
            ->assertSee($product->sku)
            ->assertSee($groupEntry->source_key)
            ->assertSee($special->source_key)
            ->assertDontSee($otherProduct->sku)
            ->assertDontSee($other->source_key)
            ->assertDontSee($outside->source_key)
            ->call('editGroupEntry', $special->id)
            ->assertSet('groupEntryForm.price', '15.9876')
            ->assertSet('groupEntryForm.priority', -8)
            ->assertSet('groupEntryForm.starts_at', '2026-10-01T09:15:00')
            ->assertSet('groupEntryForm.ends_at', '2026-10-31T23:45:00')
            ->assertSet('groupEntryForm.is_active', false);
    }

    public function test_new_group_rule_preselects_selected_group_without_guessing_percent_from_its_name(): void
    {
        [$admin, $catalog, , , $second] = $this->fixture();

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $second->id)
            ->call('newGroupRule')
            ->assertSet('showRuleForm', true)
            ->assertSet('ruleForm.customer_group_ids', [$second->id])
            ->assertSet('ruleForm.percent', '');

        $this->assertSame(0, $catalog->discountRules()->count());
    }

    public function test_imported_draft_entry_accepts_decimal_comma_and_preserves_product_audience_source_and_payload(): void
    {
        [$admin, $catalog, $product, $first] = $this->fixture();
        $entry = $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '17.9876', $first, [
            'source_key' => 'opencart:product_special:105',
            'payload' => ['source' => ['product_special_id' => 105, 'price' => '17.9876']],
        ]);

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)
            ->call('editGroupEntry', $entry->id)
            ->assertSet('groupEntryId', $entry->id)
            ->assertSet('groupEntryForm.price', '17.9876')
            ->set('groupEntryForm.price', '16,3841')
            ->set('groupEntryForm.priority', -3)
            ->set('groupEntryForm.starts_at', '2026-11-01T08:00')
            ->set('groupEntryForm.ends_at', '2026-11-30T18:00')
            ->set('groupEntryForm.is_active', false)
            ->call('saveGroupEntry')->assertHasNoErrors()
            ->assertSet('groupEntryId', null);

        $entry->refresh();
        $this->assertSame('16.3841', $entry->price);
        $this->assertSame(-3, $entry->priority);
        $this->assertSame('2026-11-01 08:00:00', $entry->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 18:00:00', $entry->ends_at->format('Y-m-d H:i:s'));
        $this->assertFalse($entry->is_active);
        $this->assertSame($product->id, $entry->product_id);
        $this->assertSame($first->id, $entry->customer_group_id);
        $this->assertNull($entry->user_id);
        $this->assertSame(PriceCatalogEntry::SPECIAL, $entry->kind);
        $this->assertSame('opencart:product_special:105', $entry->source_key);
        $this->assertSame(['source' => ['product_special_id' => 105, 'price' => '17.9876']], $entry->payload);
        $this->assertSame('draft', $catalog->fresh()->status);
        $this->assertNull(DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
        $this->assertDatabaseHas('catalog_price_catalog_audits', ['price_catalog_id' => $catalog->id, 'actor_id' => $admin->id, 'event' => 'entry_updated']);
    }

    public function test_extra_mutable_form_keys_cannot_change_product_audience_or_import_identity(): void
    {
        [$admin, $catalog, $product, $first, $second] = $this->fixture();
        $otherProduct = $this->product('UNAUTHORIZED-REPLACEMENT');
        $entry = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first, ['source_key' => 'opencart:fixed-identity:106']);

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->call('editGroupEntry', $entry->id)
            ->set('groupEntryForm.price', '12,3456')
            ->set('groupEntryForm.product_id', $otherProduct->id)
            ->set('groupEntryForm.customer_group_id', $second->id)
            ->set('groupEntryForm.user_id', $admin->id)
            ->set('groupEntryForm.kind', PriceCatalogEntry::CUSTOMER)
            ->set('groupEntryForm.minimum_quantity', 99)
            ->set('groupEntryForm.source_key', 'tampered-source')
            ->call('saveGroupEntry')->assertHasNoErrors();

        $entry->refresh();
        $this->assertSame('12.3456', $entry->price);
        $this->assertSame($product->id, $entry->product_id);
        $this->assertSame($first->id, $entry->customer_group_id);
        $this->assertNull($entry->user_id);
        $this->assertSame(PriceCatalogEntry::GROUP, $entry->kind);
        $this->assertSame(1, $entry->minimum_quantity);
        $this->assertSame('opencart:fixed-identity:106', $entry->source_key);
    }

    public function test_invalid_precision_and_reversed_dates_do_not_change_the_imported_price(): void
    {
        [$admin, $catalog, $product, $first] = $this->fixture();
        $entry = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first);
        $screen = Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->call('editGroupEntry', $entry->id)
            ->set('groupEntryForm.price', '19,87654')
            ->call('saveGroupEntry')->assertHasErrors(['price']);

        $this->assertSame('18.4320', $entry->fresh()->price);
        $screen->set('groupEntryForm.price', '19,8765')
            ->set('groupEntryForm.starts_at', '2026-11-02T08:00')
            ->set('groupEntryForm.ends_at', '2026-11-01T08:00')
            ->call('saveGroupEntry')->assertHasErrors(['ends_at']);
        $this->assertSame('18.4320', $entry->fresh()->price);
    }

    public function test_edit_cannot_target_an_entry_in_another_group(): void
    {
        [$admin, $catalog, $product, $first, $second] = $this->fixture();
        $other = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '8.7654', $second);

        try {
            Livewire::actingAs($admin)->test(PriceCatalogManager::class)
                ->call('openGroup', $first->id)->call('editGroupEntry', $other->id);
            $this->fail('The selected group must not edit another group\'s entry.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(PriceCatalogEntry::class, $exception->getModel());
            $this->assertSame([$other->id], $exception->getIds());
        }

        $this->assertSame('8.7654', $other->fresh()->price);
    }

    public function test_edit_cannot_target_an_entry_in_another_catalog(): void
    {
        [$admin, $catalog, $product, $first] = $this->fixture();
        $otherCatalog = app(PriceCatalogService::class)->createDraft('Neodabrani cjenik', $admin);
        $other = $this->entry($otherCatalog, $product, PriceCatalogEntry::GROUP, '8.7654', $first);

        try {
            Livewire::actingAs($admin)->test(PriceCatalogManager::class)
                ->call('selectCatalog', $catalog->id)->call('openGroup', $first->id)
                ->call('editGroupEntry', $other->id);
            $this->fail('The selected catalog must not edit another catalog\'s entry.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(PriceCatalogEntry::class, $exception->getModel());
            $this->assertSame([$other->id], $exception->getIds());
        }

        $this->assertSame('8.7654', $other->fresh()->price);
    }

    public function test_selected_group_entry_and_context_cannot_be_changed_by_client_updates(): void
    {
        [$admin, $catalog, $product, $first, $second] = $this->fixture();
        $entry = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first);
        $other = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '8.7654', $second);

        foreach (['selectedGroupId' => $second->id, 'groupEntryId' => $other->id, 'groupEntryContext' => ['product_id' => 999, 'source_key' => 'tampered']] as $property => $value) {
            $screen = Livewire::actingAs($admin)->test(PriceCatalogManager::class)
                ->call('openGroup', $first->id)->call('editGroupEntry', $entry->id);
            try {
                $screen->set($property, $value);
                $this->fail('Client updates must not change locked '.$property.'.');
            } catch (CannotUpdateLockedPropertyException $exception) {
                $this->assertStringContainsString($property, $exception->getMessage());
            }
        }
        $this->assertSame('18.4320', $entry->fresh()->price);
        $this->assertSame('8.7654', $other->fresh()->price);
    }

    public function test_published_catalog_cannot_open_edit_or_create_a_group_rule(): void
    {
        [$admin, $catalog, $product, $first] = $this->fixture();
        $entry = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first);
        app(PriceCatalogService::class)->activate($catalog, $admin);

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)
            ->call('editGroupEntry', $entry->id)->assertHasErrors(['catalog'])
            ->assertSet('groupEntryId', null)
            ->call('newGroupRule')->assertHasErrors(['catalog']);

        $this->assertSame('18.4320', $entry->fresh()->price);
        $this->assertSame('active', $catalog->fresh()->status);
        $this->assertSame(0, $catalog->discountRules()->count());
    }

    public function test_entry_opened_before_publication_cannot_be_saved_after_catalog_becomes_active(): void
    {
        [$admin, $catalog, $product, $first] = $this->fixture();
        $entry = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first);
        $screen = Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->call('editGroupEntry', $entry->id)
            ->set('groupEntryForm.price', '0,0001');
        app(PriceCatalogService::class)->activate($catalog, $admin);

        $screen->call('saveGroupEntry')->assertHasErrors(['catalog']);
        $this->assertSame('18.4320', $entry->fresh()->price);
        $this->assertSame($catalog->id, (int) DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
    }

    public function test_read_only_pricing_role_can_view_groups_but_cannot_edit_save_or_create_rules(): void
    {
        [, $catalog, $product, $first] = $this->fixture();
        $entry = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first);
        $viewer = User::factory()->create();
        Bouncer::allow($viewer)->to('admin.access');
        Bouncer::allow($viewer)->to('catalog.b2b_prices.view');

        Livewire::actingAs($viewer)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->assertSee($entry->source_key)
            ->call('editGroupEntry', $entry->id)->assertForbidden();
        Livewire::actingAs($viewer)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->call('newGroupRule')->assertForbidden();
        Livewire::actingAs($viewer)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->set('groupEntryForm.price', '1')
            ->call('saveGroupEntry')->assertForbidden();

        $this->assertSame('18.4320', $entry->fresh()->price);
        $this->assertSame(0, $catalog->discountRules()->count());
    }

    public function test_generated_group_price_edit_opens_its_rule_not_an_individual_price_form(): void
    {
        [$admin, $catalog, , $first] = $this->fixture();
        $rule = app(CatalogGroupDiscountService::class)->saveRule($catalog, [
            'name' => 'Izričito zadan popust 12,5 %',
            'percent' => '12.5',
            'customer_group_ids' => [$first->id],
        ], $admin);
        $entry = $rule->entries()->firstOrFail();

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->call('editGroupEntry', $entry->id)
            ->assertSet('ruleId', $rule->id)
            ->assertSet('showRuleForm', true)
            ->assertSet('groupEntryId', null)
            ->assertSet('ruleForm.percent', '12.5000')
            ->assertSet('ruleForm.customer_group_ids', [$first->id]);

        $this->assertSame('17.9200', $entry->fresh()->price);
    }

    public function test_begin_group_editing_creates_one_draft_only_on_explicit_action_and_never_publishes(): void
    {
        [$admin, $catalog, $product, $first] = $this->fixture();
        $entry = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $screen = Livewire::actingAs($admin)->test(PriceCatalogManager::class)->call('openGroup', $first->id);
        $this->assertSame(1, PriceCatalog::query()->count());

        $screen->call('beginGroupEditing')->assertHasNoErrors()->assertSet('selectedGroupId', $first->id);
        $draft = PriceCatalog::query()->where('status', PriceCatalog::DRAFT)->sole();
        $screen->assertSet('catalogId', $draft->id)->call('beginGroupEditing')->assertHasNoErrors();
        $this->assertSame(2, PriceCatalog::query()->count());
        $this->assertSame($catalog->id, $draft->metadata['cloned_from']);
        $this->assertSame('18.4320', $draft->entries()->where('source_key', $entry->source_key)->value('price'));
        $this->assertSame('18.4320', $entry->fresh()->price);
        $this->assertSame('active', $catalog->fresh()->status);
        $this->assertSame($catalog->id, (int) DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
    }

    public function test_begin_group_editing_reuses_its_source_draft_not_an_unrelated_latest_draft(): void
    {
        [$admin, $catalog, $product, $first] = $this->fixture();
        $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first);
        $service = app(PriceCatalogService::class);
        $service->activate($catalog, $admin);
        $matching = $service->cloneToDraft($catalog, $admin);
        $unrelated = $service->createDraft('Kasniji nepovezani cjenik', $admin);

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->call('beginGroupEditing')
            ->assertSet('catalogId', $matching->id)->assertSet('selectedGroupId', $first->id);

        $this->assertSame(3, PriceCatalog::query()->count());
        $this->assertSame(0, $unrelated->entries()->count());
        $this->assertSame($catalog->id, (int) DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
    }

    public function test_group_rule_button_opens_only_a_rule_assigned_to_the_selected_group(): void
    {
        [$admin, $catalog, , $first, $second] = $this->fixture();
        $service = app(CatalogGroupDiscountService::class);
        $own = $service->saveRule($catalog, ['name' => 'Pravilo prve grupe', 'percent' => '12.5', 'customer_group_ids' => [$first->id]], $admin);
        $other = $service->saveRule($catalog, ['name' => 'Pravilo druge grupe', 'percent' => '20', 'customer_group_ids' => [$second->id]], $admin);
        $screen = Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->call('openGroupRule', $own->id)
            ->assertSet('ruleId', $own->id)->assertSet('tab', 'rules')->assertSet('showRuleForm', true);

        $screen->call('openGroupRule', $other->id)->assertNotFound();
        $this->assertSame('12.5000', $own->fresh()->percent);
        $this->assertSame('20.0000', $other->fresh()->percent);
    }

    public function test_cancelling_or_switching_catalog_clears_the_entry_editor(): void
    {
        [$admin, $catalog, $product, $first] = $this->fixture();
        $entry = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '18.4320', $first);
        $otherCatalog = app(PriceCatalogService::class)->createDraft('Sljedeća verzija', $admin);

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('selectCatalog', $catalog->id)->call('openGroup', $first->id)
            ->call('editGroupEntry', $entry->id)->set('groupEntryForm.price', '1,2345')
            ->call('cancelGroupEntry')->assertSet('groupEntryId', null)
            ->call('editGroupEntry', $entry->id)->assertSet('groupEntryForm.price', '18.4320')
            ->call('selectCatalog', $otherCatalog->id)
            ->assertSet('groupEntryId', null)
            ->assertSet('groupEntryContext', null)
            ->assertSet('groupEntryForm', []);

        $this->assertSame('18.4320', $entry->fresh()->price);
    }

    public function test_prepare_legacy_reference_prefills_all_original_groups_and_conditions_without_creating_native_prices(): void
    {
        [$admin, $catalog, $product, $first, $second] = $this->fixture();
        $brand = Manufacturer::query()->create(['code' => 'reference-brand', 'is_active' => true]);
        $category = Category::query()->create(['scope' => Category::SCOPE_CATALOG, 'code' => 'reference-category', 'is_active' => true]);
        $reference = $this->legacyReference($catalog, [$first->id, $second->id], [
            'manufacturer_ids' => [$brand->id],
            'category_ids' => [$category->id],
            'excluded_product_ids' => [$product->id],
            'include_descendants' => false,
            'starts_at' => '2026-10-01 08:15:00',
            'ends_at' => '2026-12-31 18:45:00',
            'priority' => -7,
            'warnings' => ['Provjerite izvorni opseg prije spremanja.'],
        ]);
        $before = $catalog->entries()->orderBy('id')->get()->toArray();

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->call('prepareLegacyRule', $reference->id)
            ->assertHasNoErrors()->assertSet('tab', 'rules')->assertSet('showRuleForm', true)
            ->assertSet('ruleId', null)
            ->assertSet('ruleForm.name', $reference->name)
            ->assertSet('ruleForm.percent', '40.0000')
            ->assertSet('ruleForm.customer_group_ids', [$first->id, $second->id])
            ->assertSet('ruleForm.manufacturer_ids', [$brand->id])
            ->assertSet('ruleForm.category_ids', [$category->id])
            ->assertSet('ruleForm.excluded_product_ids', [$product->id])
            ->assertSet('ruleForm.include_descendants', false)
            ->assertSet('ruleForm.starts_at', '2026-10-01T08:15')
            ->assertSet('ruleForm.ends_at', '2026-12-31T18:45')
            ->assertSet('ruleForm.priority', -7)
            ->assertSet('ruleForm.is_active', true)
            ->assertSet('legacyRuleWarnings', fn (array $warnings): bool => in_array('Provjerite izvorni opseg prije spremanja.', $warnings, true))
            ->assertSee('Provjerite izvorni opseg prije spremanja.')
            ->assertSee('Priprema izmjene izvornog pravila — nije objavljeno');

        $this->assertSame($before, $catalog->entries()->orderBy('id')->get()->toArray());
        $this->assertSame(0, $catalog->discountRules()->count());
        $this->assertSame('draft', $catalog->fresh()->status);
        $this->assertNull(DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
        $this->assertSame('40.0000', $reference->fresh()->percent);
    }

    public function test_prepare_legacy_reference_rejects_a_selected_group_outside_the_original_scope(): void
    {
        [$admin, $catalog, , $first, $second] = $this->fixture();
        $reference = $this->legacyReference($catalog, [$first->id]);
        $before = $catalog->entries()->count();

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $second->id)->call('prepareLegacyRule', $reference->id)->assertNotFound();

        $this->assertSame($before, $catalog->entries()->count());
        $this->assertSame(0, $catalog->discountRules()->count());
    }

    public function test_prepare_legacy_reference_rejects_another_source_snapshot_system_or_checksum(): void
    {
        [$admin, $catalog, , $first] = $this->fixture();
        $reference = $this->legacyReference($catalog, [$first->id]);
        $before = $catalog->entries()->count();
        foreach (['source_snapshot' => 'different-snapshot', 'source_system' => 'different-source', 'source_checksum' => str_repeat('b', 64)] as $field => $value) {
            $unrelated = $catalog->replicate();
            $unrelated->fill([$field => $value])->save();
            try {
                Livewire::actingAs($admin)->test(PriceCatalogManager::class)
                    ->call('selectCatalog', $unrelated->id)->call('openGroup', $first->id)
                    ->call('prepareLegacyRule', $reference->id);
                $this->fail('A different '.$field.' must not inherit a source reference.');
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(LegacyGroupDiscountReference::class, $exception->getModel());
                $this->assertSame([$reference->id], $exception->getIds());
            }
            $this->assertSame(0, $unrelated->discountRules()->count());
        }
        $this->assertSame($before, $catalog->entries()->count());
        $this->assertSame('40.0000', $reference->fresh()->percent);
    }

    public function test_prepare_unsupported_legacy_reference_returns_a_validation_error_without_materializing_prices(): void
    {
        [$admin, $catalog, , $first] = $this->fixture();
        $reference = $this->legacyReference($catalog, [$first->id], ['is_supported' => false, 'warnings' => ['Nepodržano izvorno zaokruživanje.']]);
        $before = $catalog->entries()->orderBy('id')->get()->toArray();

        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('openGroup', $first->id)->call('prepareLegacyRule', $reference->id)
            ->assertHasErrors(['legacyReference'])
            ->assertSet('tab', 'groups')->assertSet('showRuleForm', false);

        $this->assertSame($before, $catalog->entries()->orderBy('id')->get()->toArray());
        $this->assertSame(0, $catalog->discountRules()->count());
        $this->assertFalse($reference->fresh()->is_supported);
        $this->assertNull(DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
    }

    private function legacyReference(PriceCatalog $catalog, array $groupIds, array $extra = []): LegacyGroupDiscountReference
    {
        $catalog->update(['source_system' => 'herrera-opencart', 'source_snapshot' => 'source-fixture', 'source_checksum' => str_repeat('a', 64)]);

        return LegacyGroupDiscountReference::query()->create($extra + [
            'source_system' => $catalog->source_system,
            'source_snapshot' => $catalog->source_snapshot,
            'source_catalog_checksum' => $catalog->source_checksum,
            'source_key' => 'mega_sale:362',
            'source_type' => 'mega_sale',
            'source_sale_id' => 362,
            'definition_checksum' => str_repeat('c', 64),
            'name' => 'Izvorna akcija za obje grupe',
            'percent' => '40.0000',
            'customer_group_ids' => $groupIds,
            'manufacturer_ids' => [],
            'category_ids' => [],
            'excluded_product_ids' => [],
            'include_descendants' => true,
            'priority' => 0,
            'is_supported' => true,
            'warnings' => [],
            'source_flags' => [],
            'definition' => ['sale' => ['id' => 362, 'discount_value' => 40]],
        ]);
    }

    private function fixture(): array
    {
        config(['commerce.b2b_only' => true]);
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        $catalog = app(PriceCatalogService::class)->createDraft('Uvezene grupne cijene', $admin);
        $product = $this->product('GROUP-OVERVIEW-SKU');
        $first = CustomerGroup::query()->create(['code' => 'b2b10', 'name' => 'B2B10', 'is_active' => true]);
        $second = CustomerGroup::query()->create(['code' => 'b2b20', 'name' => 'B2B20', 'is_active' => true]);
        $this->entry($catalog, $product, PriceCatalogEntry::BASE, '20.4800');

        return [$admin, $catalog, $product, $first, $second];
    }

    private function product(string $sku): Product
    {
        return Product::query()->create(['code' => $sku, 'sku' => $sku, 'base_price' => '20.4800', 'is_active' => true]);
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price, ?CustomerGroup $group = null, array $extra = []): PriceCatalogEntry
    {
        return PriceCatalogEntry::query()->create($extra + ['price_catalog_id' => $catalog->id, 'source_key' => 'fixture:'.$kind.':'.$product->id.':'.($group?->id ?? 'base'), 'product_id' => $product->id, 'kind' => $kind, 'customer_group_id' => $group?->id, 'user_id' => null, 'minimum_quantity' => 1, 'price' => $price, 'priority' => 0, 'is_active' => true]);
    }
}
