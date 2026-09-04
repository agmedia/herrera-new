<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Attribute\Form as AttributeForm;
use App\Livewire\Admin\Catalog\Attribute\GroupForm as AttributeGroupForm;
use App\Livewire\Admin\Catalog\Attribute\GroupManager as AttributeGroupManager;
use App\Livewire\Admin\Catalog\Attribute\Manager as AttributeManager;
use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Attribute\AttributeGroup;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CatalogAttributesFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_attributes_routes_are_blocked_when_feature_disabled(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->get('/admin/attributes');

        $response
            ->assertRedirect(route('admin.settings.system.catalog-features'))
            ->assertSessionHas('notify.type', 'warning');
    }

    public function test_attribute_group_pages_render_through_hierarchical_routes(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $attribute = $this->createAttribute(
            $user,
            code: 'route-black',
            groupCode: 'route-color',
            groupName: 'Route Color',
            name: 'Black',
        );
        $group = $attribute->group()->firstOrFail();

        $this->actingAs($user)
            ->get(route('admin.attributes'))
            ->assertOk()
            ->assertSeeLivewire(AttributeGroupManager::class);

        $this->actingAs($user)
            ->get(route('admin.attributes.groups.show', $group))
            ->assertOk()
            ->assertSeeLivewire(AttributeManager::class);

        $this->actingAs($user)
            ->get(route('admin.attributes.groups.attributes.create', $group))
            ->assertOk()
            ->assertSeeLivewire(AttributeForm::class);
    }

    public function test_product_form_saves_attribute_assignments_when_feature_enabled(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $this->createDefaultTaxRate();

        $user = $this->makeAdminUser();

        $materialBamboo = Attribute::query()->create([
            'code' => 'material-bamboo',
            'group_code' => 'material',
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 10,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $materialBamboo->translations()->create([
            'locale' => 'en',
            'group_name' => 'Material',
            'name' => 'Bamboo',
            'slug' => 'material-bamboo',
            'description' => null,
            'payload' => null,
        ]);

        $originJapan = Attribute::query()->create([
            'code' => 'origin-japan',
            'group_code' => 'origin',
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 20,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $originJapan->translations()->create([
            'locale' => 'en',
            'group_name' => 'Origin',
            'name' => 'Japan',
            'slug' => 'origin-japan',
            'description' => null,
            'payload' => null,
        ]);

        $component = Livewire::actingAs($user)
            ->test(ProductForm::class)
            ->call('setTab', 'attributes')
            ->assertSet('activeTab', 'attributes')
            ->assertSet('addedAttributeGroupCodes', [])
            ->assertSee('Artikl još nema dodijeljenih atributa.')
            ->assertDontSeeHtml('wire:model="attributeSelections.material"')
            ->set('attributeGroupToAdd', 'material')
            ->call('addAttributeGroup')
            ->assertSet('addedAttributeGroupCodes', ['material'])
            ->assertSeeHtml('wire:model="attributeSelections.material"')
            ->set('attributeGroupToAdd', 'origin')
            ->call('addAttributeGroup')
            ->assertSet('addedAttributeGroupCodes', ['material', 'origin'])
            ->set('form.code', 'p-attribute-1')
            ->set('form.sku', 'SKU-ATTR-1')
            ->set('form.is_active', true)
            ->set('form.base_price', 19.99)
            ->set('form.stock_qty', 5)
            ->set('form.locale', 'en')
            ->set('form.name', 'Attribute Product')
            ->set('form.slug', 'attribute-product')
            ->set('attributeSelections.material', (string) $materialBamboo->id)
            ->set('attributeSelections.origin', (string) $originJapan->id)
            ->call('save');

        $product = Product::query()->where('code', 'p-attribute-1')->first();

        $this->assertNotNull($product);
        $component->assertRedirect(route('admin.products.edit', ['product' => $product->id, 'locale' => 'en']));
        $this->assertSame(
            [$materialBamboo->id, $originJapan->id],
            $product->attributes()->orderBy('catalog_attribute_product.sort_order')->pluck('catalog_attributes.id')->all()
        );
    }

    public function test_product_form_rejects_multiple_values_for_single_type_group(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $this->createDefaultTaxRate();

        $user = $this->makeAdminUser();

        $genderM = Attribute::query()->create([
            'code' => 'gender-m',
            'group_code' => 'gender',
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 10,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $genderM->translations()->create([
            'locale' => 'en',
            'group_name' => 'Gender',
            'name' => 'M',
            'slug' => 'gender-m',
            'description' => null,
            'payload' => null,
        ]);

        $genderF = Attribute::query()->create([
            'code' => 'gender-f',
            'group_code' => 'gender',
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 20,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $genderF->translations()->create([
            'locale' => 'en',
            'group_name' => 'Gender',
            'name' => 'F',
            'slug' => 'gender-f',
            'description' => null,
            'payload' => null,
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class)
            ->set('form.code', 'p-attribute-invalid')
            ->set('form.sku', 'SKU-ATTR-INV')
            ->set('form.is_active', true)
            ->set('form.base_price', 10)
            ->set('form.stock_qty', 2)
            ->set('form.locale', 'en')
            ->set('form.name', 'Invalid Attribute Product')
            ->set('form.slug', 'invalid-attribute-product')
            ->set('attributeSelections.gender', [$genderM->id, $genderF->id])
            ->call('save')
            ->assertHasErrors(['attributeSelections.gender']);

        $this->assertDatabaseMissing('products', ['code' => 'p-attribute-invalid']);
    }

    public function test_product_attribute_tab_keeps_msan_groups_read_only_and_only_removes_manual_groups(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);

        $user = $this->makeAdminUser();
        $manual = $this->createAttribute(
            $user,
            code: 'material-bamboo',
            groupCode: 'material',
            groupName: 'Material',
            name: 'Bamboo',
        );
        $msan = $this->createAttribute(
            $user,
            code: 'msan-spec-power-1200',
            groupCode: 'msan-power',
            groupName: 'Power',
            name: '1200 W',
            payload: ['source' => 'msan_specification', 'source_key' => 'power'],
        );
        $product = $this->createProduct($user, 'product-msan-read-only');
        $product->attributes()->attach([
            $manual->id => ['sort_order' => 0],
            $msan->id => ['sort_order' => 20],
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'attributes')
            ->assertSet('addedAttributeGroupCodes', ['material', 'msan-power'])
            ->assertSet('attributeSelections.material', $manual->id)
            ->assertSet('msanAttributeIds', [$msan->id])
            ->assertSee('Automatski · M SAN')
            ->assertSee('Vrijednost održava M SAN integracija i ovdje je samo za čitanje.')
            ->assertDontSeeHtml('wire:model="attributeSelections.msan-power"')
            ->call('removeAttributeGroup', 'msan-power')
            ->assertSet('addedAttributeGroupCodes', ['material', 'msan-power'])
            ->assertDispatched(
                'notify',
                type: 'warning',
                message: __('M SAN atributi održavaju se automatski kroz integraciju.'),
            )
            ->call('removeAttributeGroup', 'material')
            ->assertSet('addedAttributeGroupCodes', ['msan-power'])
            ->assertSet('attributeSelections', []);
    }

    public function test_product_save_preserves_only_freshly_attached_msan_attributes_from_a_stale_form(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $this->createDefaultTaxRate();

        $user = $this->makeAdminUser();
        $manual = $this->createAttribute(
            $user,
            code: 'origin-japan',
            groupCode: 'origin',
            groupName: 'Origin',
            name: 'Japan',
        );
        $oldMsan = $this->createAttribute(
            $user,
            code: 'msan-spec-old-value',
            groupCode: 'msan-old',
            groupName: 'Old M SAN',
            name: 'Old',
            payload: ['source' => 'msan_specification', 'source_key' => 'old'],
        );
        $freshMsan = $this->createAttribute(
            $user,
            code: 'msan-spec-fresh-value',
            groupCode: 'msan-fresh',
            groupName: 'Fresh M SAN',
            name: 'Fresh',
            payload: ['source' => 'msan_specification', 'source_key' => 'fresh'],
        );
        $product = $this->createProduct($user, 'product-stale-attribute-form');
        $product->attributes()->attach([
            $manual->id => ['sort_order' => 0],
            $oldMsan->id => ['sort_order' => 10],
        ]);

        $component = Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->assertSet('msanAttributeIds', [$oldMsan->id]);

        $product->attributes()->detach($oldMsan->id);
        $product->attributes()->attach($freshMsan->id, ['sort_order' => 70]);

        $component
            ->call('save')
            ->assertRedirect(route('admin.products.edit', ['product' => $product->id, 'locale' => 'en']));

        $this->assertSame(
            [$manual->id, $freshMsan->id],
            $product->fresh()
                ->attributes()
                ->orderBy('catalog_attribute_product.sort_order')
                ->pluck('catalog_attributes.id')
                ->all(),
        );
        $this->assertDatabaseMissing('catalog_attribute_product', [
            'product_id' => $product->id,
            'attribute_id' => $oldMsan->id,
        ]);
        $this->assertDatabaseHas('catalog_attribute_product', [
            'product_id' => $product->id,
            'attribute_id' => $freshMsan->id,
            'sort_order' => 70,
        ]);
    }

    public function test_attribute_manager_deletes_attribute_from_list_and_detaches_products(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);

        $user = $this->makeAdminUser();

        $attribute = Attribute::query()->create([
            'code' => 'material-linen',
            'group_code' => 'material',
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 10,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $attribute->translations()->create([
            'locale' => 'en',
            'group_name' => 'Material',
            'name' => 'Linen',
            'slug' => 'material-linen',
            'description' => null,
            'payload' => null,
        ]);

        $product = Product::query()->create([
            'code' => 'product-with-attribute',
            'sku' => 'PRODUCT-WITH-ATTRIBUTE',
            'is_active' => true,
            'manufacturer_id' => null,
            'tax_rate_id' => null,
            'base_price' => 29.99,
            'stock_qty' => 4,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $product->attributes()->attach($attribute->id, [
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(AttributeManager::class)
            ->call('delete', $attribute->id)
            ->assertDispatched('notify', type: 'success', message: __('Attribute deleted.'));

        $this->assertDatabaseMissing('catalog_attributes', ['id' => $attribute->id]);
        $this->assertDatabaseMissing('catalog_attribute_translations', ['attribute_id' => $attribute->id]);
        $this->assertDatabaseMissing('catalog_attribute_product', [
            'attribute_id' => $attribute->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_attribute_group_is_created_for_legacy_attribute_writes_and_receives_translations(): void
    {
        $user = $this->makeAdminUser();

        $attribute = $this->createAttribute(
            $user,
            code: 'legacy-black',
            groupCode: 'legacy-color',
            groupName: 'Legacy Color',
            name: 'Black',
        );

        $group = AttributeGroup::query()->where('code', 'legacy-color')->firstOrFail();

        $this->assertSame($group->id, $attribute->fresh()->attribute_group_id);
        $this->assertSame(Attribute::TYPE_SELECT, $group->type);
        $this->assertDatabaseHas('catalog_attribute_group_translations', [
            'attribute_group_id' => $group->id,
            'locale' => 'en',
            'name' => 'Legacy Color',
        ]);
    }

    public function test_admin_creates_group_first_and_then_adds_an_attribute_inside_it(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();

        $groupComponent = Livewire::actingAs($user)
            ->test(AttributeGroupForm::class)
            ->set('form.code', 'finish')
            ->set('form.type', Attribute::TYPE_MULTI)
            ->set('form.sort_order', 30)
            ->set('form.locale', 'en')
            ->set('form.name', 'Finish')
            ->call('save');

        $group = AttributeGroup::query()->where('code', 'finish')->firstOrFail();
        $groupComponent->assertRedirect(route('admin.attributes.groups.show', [
            'attributeGroup' => $group->id,
            'locale' => 'en',
        ]));

        Livewire::actingAs($user)
            ->test(AttributeForm::class, ['groupId' => $group->id])
            ->set('form.locale', 'en')
            ->set('form.code', 'finish-matte')
            ->set('form.name', 'Matte')
            ->set('form.slug', 'finish-matte')
            ->set('form.sort_order', 10)
            ->call('save')
            ->assertRedirect(route('admin.attributes.groups.show', [
                'attributeGroup' => $group->id,
                'locale' => 'en',
            ]));

        $this->assertDatabaseHas('catalog_attributes', [
            'attribute_group_id' => $group->id,
            'code' => 'finish-matte',
            'group_code' => 'finish',
            'type' => Attribute::TYPE_MULTI,
        ]);
        $this->assertDatabaseHas('catalog_attribute_translations', [
            'group_name' => 'Finish',
            'name' => 'Matte',
            'slug' => 'finish-matte',
        ]);
    }

    public function test_create_group_form_cannot_be_tampered_into_updating_an_existing_group(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $group = AttributeGroup::query()->create([
            'code' => 'protected-group',
            'type' => Attribute::TYPE_SELECT,
            'sort_order' => 10,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $component = Livewire::actingAs($user)->test(AttributeGroupForm::class);

        try {
            $component->set('groupId', $group->id);
            $this->fail('The locked group identifier was updated from the client.');
        } catch (CannotUpdateLockedPropertyException $exception) {
            $this->assertSame('groupId', $exception->property);
        }

        $this->assertSame(10, $group->fresh()->sort_order);
    }

    public function test_legacy_group_with_non_slug_code_can_still_be_edited(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $group = AttributeGroup::query()->create([
            'code' => 'COLOR.Main',
            'type' => Attribute::TYPE_SELECT,
            'sort_order' => 10,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $group->translations()->create([
            'locale' => 'en',
            'name' => 'Legacy Color',
        ]);

        Livewire::actingAs($user)
            ->test(AttributeGroupForm::class, ['groupId' => $group->id])
            ->assertSet('form.code', 'COLOR.Main')
            ->set('form.sort_order', 35)
            ->set('form.name', 'Legacy Main Color')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.attributes.groups.show', [
                'attributeGroup' => $group->id,
                'locale' => 'en',
            ]));

        $group->refresh();
        $this->assertSame('COLOR.Main', $group->code);
        $this->assertSame(35, $group->sort_order);
        $this->assertSame('Legacy Main Color', $group->translations()->where('locale', 'en')->value('name'));
    }

    public function test_attribute_group_cannot_change_to_single_value_while_a_product_has_multiple_values(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $matte = $this->createAttribute(
            $user,
            code: 'finish-matte-guard',
            groupCode: 'finish-guard',
            groupName: 'Finish Guard',
            name: 'Matte',
            type: Attribute::TYPE_MULTI,
        );
        $gloss = $this->createAttribute(
            $user,
            code: 'finish-gloss-guard',
            groupCode: 'finish-guard',
            groupName: 'Finish Guard',
            name: 'Gloss',
            type: Attribute::TYPE_MULTI,
        );
        $group = $matte->group()->firstOrFail();
        $product = $this->createProduct($user, 'product-multiple-finish-values');
        $product->attributes()->attach([$matte->id, $gloss->id]);
        $message = __('This group cannot use a single selection while an article has multiple values assigned.');

        Livewire::actingAs($user)
            ->test(AttributeGroupForm::class, ['groupId' => $group->id])
            ->set('form.type', Attribute::TYPE_SELECT)
            ->call('save')
            ->assertHasErrors(['form.type'])
            ->assertDispatched('notify', type: 'warning', message: $message);

        $this->assertSame(Attribute::TYPE_MULTI, $group->fresh()->type);
        $this->assertSame(
            [Attribute::TYPE_MULTI],
            Attribute::query()
                ->where('attribute_group_id', $group->id)
                ->distinct()
                ->pluck('type')
                ->all(),
        );
    }

    public function test_attribute_group_can_change_to_single_value_when_each_product_has_only_one_value(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $matte = $this->createAttribute(
            $user,
            code: 'finish-matte-single',
            groupCode: 'finish-single',
            groupName: 'Finish Single',
            name: 'Matte',
            type: Attribute::TYPE_MULTI,
        );
        $gloss = $this->createAttribute(
            $user,
            code: 'finish-gloss-single',
            groupCode: 'finish-single',
            groupName: 'Finish Single',
            name: 'Gloss',
            type: Attribute::TYPE_MULTI,
        );
        $group = $matte->group()->firstOrFail();
        $this->createProduct($user, 'product-matte-only')->attributes()->attach($matte->id);
        $this->createProduct($user, 'product-gloss-only')->attributes()->attach($gloss->id);

        Livewire::actingAs($user)
            ->test(AttributeGroupForm::class, ['groupId' => $group->id])
            ->set('form.type', Attribute::TYPE_SELECT)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.attributes.groups.show', [
                'attributeGroup' => $group->id,
                'locale' => 'en',
            ]));

        $this->assertSame(Attribute::TYPE_SELECT, $group->fresh()->type);
        $this->assertSame(
            [Attribute::TYPE_SELECT],
            Attribute::query()
                ->where('attribute_group_id', $group->id)
                ->distinct()
                ->pluck('type')
                ->all(),
        );
    }

    public function test_attribute_group_manager_lists_groups_and_only_deletes_empty_groups(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $attribute = $this->createAttribute(
            $user,
            code: 'material-linen-source',
            groupCode: 'material-source',
            groupName: 'Material Source',
            name: 'Linen',
            payload: ['source' => 'termol.hr description'],
        );
        $usedGroup = $attribute->group()->firstOrFail();
        $emptyGroup = AttributeGroup::query()->create([
            'code' => 'empty-group',
            'type' => Attribute::TYPE_SELECT,
            'sort_order' => 99,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $emptyGroup->translations()->create([
            'locale' => 'en',
            'name' => 'Empty Group',
        ]);

        Livewire::actingAs($user)
            ->test(AttributeGroupManager::class)
            ->assertSee('Material Source')
            ->assertSee('Termol')
            ->assertSee('Empty Group')
            ->call('delete', $usedGroup->id)
            ->assertDispatched(
                'notify',
                type: 'warning',
                message: __('Delete the attributes in this group first.'),
            )
            ->call('delete', $emptyGroup->id)
            ->assertDispatched(
                'notify',
                type: 'success',
                message: __('Attribute group deleted.'),
            );

        $this->assertDatabaseHas('catalog_attribute_groups', ['id' => $usedGroup->id]);
        $this->assertDatabaseMissing('catalog_attribute_groups', ['id' => $emptyGroup->id]);
    }

    public function test_structured_kozo_source_is_normalized_across_attribute_screens(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $attribute = $this->createAttribute(
            $user,
            code: 'kozo-material-steel',
            groupCode: 'kozo-material',
            groupName: 'Kozo Material',
            name: 'Steel',
            payload: [
                'source' => [
                    'system' => 'kozo_proizvodi',
                    'source_id' => 123,
                ],
            ],
        );
        $group = $attribute->group()->firstOrFail();

        $this->assertSame(Attribute::SOURCE_KOZO_PRODUCTS, $attribute->sourceCode());

        Livewire::actingAs($user)
            ->test(AttributeGroupManager::class)
            ->assertSee('Kozo Material')
            ->assertSee('Kozo');

        Livewire::actingAs($user)
            ->test(AttributeManager::class, ['groupId' => $group->id])
            ->set('locale', 'en')
            ->assertSee('Steel')
            ->assertSee('Kozo');

        Livewire::actingAs($user)
            ->test(AttributeForm::class, ['attributeId' => $attribute->id])
            ->assertSee('Kozo');

        Livewire::actingAs($user)
            ->test(ProductForm::class)
            ->set('attributeGroupToAdd', 'kozo-material')
            ->call('addAttributeGroup')
            ->assertSet('addedAttributeGroupCodes', ['kozo-material']);
    }

    public function test_group_attribute_manager_cannot_delete_an_attribute_from_another_group(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $visibleAttribute = $this->createAttribute(
            $user,
            code: 'scope-visible',
            groupCode: 'scope-visible-group',
            groupName: 'Visible Group',
            name: 'Visible',
        );
        $otherAttribute = $this->createAttribute(
            $user,
            code: 'scope-other',
            groupCode: 'scope-other-group',
            groupName: 'Other Group',
            name: 'Other',
        );

        Livewire::actingAs($user)
            ->test(AttributeManager::class, ['groupId' => $visibleAttribute->attribute_group_id])
            ->call('delete', $otherAttribute->id)
            ->assertDispatched('notify', type: 'warning', message: __('Attribute not found.'));

        $this->assertDatabaseHas('catalog_attributes', ['id' => $otherAttribute->id]);
    }

    public function test_attribute_edit_cannot_move_a_value_by_tampering_with_the_group_id(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $attribute = $this->createAttribute(
            $user,
            code: 'fixed-group-value',
            groupCode: 'fixed-group',
            groupName: 'Fixed Group',
            name: 'Fixed Value',
        );
        $otherAttribute = $this->createAttribute(
            $user,
            code: 'other-group-value',
            groupCode: 'other-group',
            groupName: 'Other Group',
            name: 'Other Value',
        );
        $originalGroupId = (int) $attribute->attribute_group_id;

        $component = Livewire::actingAs($user)
            ->test(AttributeForm::class, ['attributeId' => $attribute->id]);

        try {
            $component->set('groupId', (int) $otherAttribute->attribute_group_id);
            $this->fail('The locked group identifier was updated from the client.');
        } catch (CannotUpdateLockedPropertyException $exception) {
            $this->assertSame('groupId', $exception->property);
        }

        $attribute->refresh();
        $this->assertSame($originalGroupId, (int) $attribute->attribute_group_id);
        $this->assertSame('fixed-group', $attribute->group_code);
    }

    public function test_create_attribute_form_cannot_be_tampered_into_updating_an_existing_value(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $attribute = $this->createAttribute(
            $user,
            code: 'locked-existing-value',
            groupCode: 'locked-existing-group',
            groupName: 'Locked Existing Group',
            name: 'Existing',
        );

        $component = Livewire::actingAs($user)
            ->test(AttributeForm::class, ['groupId' => $attribute->attribute_group_id]);

        try {
            $component->set('attributeId', $attribute->id);
            $this->fail('The locked attribute identifier was updated from the client.');
        } catch (CannotUpdateLockedPropertyException $exception) {
            $this->assertSame('attributeId', $exception->property);
        }

        $this->assertSame('locked-existing-value', $attribute->fresh()->code);
    }

    public function test_msan_group_blocks_manual_value_creation_and_preserves_managed_payload_on_edit(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $attribute = $this->createAttribute(
            $user,
            code: 'msan-spec-managed-value',
            groupCode: 'msan-managed-group',
            groupName: 'M SAN Managed Group',
            name: 'Managed',
            payload: [
                'source' => Attribute::SOURCE_MSAN_SPECIFICATION,
                'source_key' => 'managed-source-key',
            ],
        );
        $group = $attribute->group()->firstOrFail();

        $this->assertTrue($group->isMsanManaged());
        Livewire::actingAs($user)
            ->test(AttributeManager::class, ['groupId' => $group->id])
            ->assertDontSee(__('Add new attribute'));
        $this->actingAs($user)
            ->get(route('admin.attributes.groups.attributes.create', $group))
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(AttributeForm::class, ['attributeId' => $attribute->id])
            ->set('form.payload_text', '{"custom_note":"kept"}')
            ->call('save')
            ->assertHasNoErrors();

        $attribute->refresh();
        $this->assertSame(Attribute::SOURCE_MSAN_SPECIFICATION, data_get($attribute->payload, 'source'));
        $this->assertSame('managed-source-key', data_get($attribute->payload, 'source_key'));
        $this->assertSame('kept', data_get($attribute->payload, 'custom_note'));
    }

    public function test_legacy_attribute_type_change_updates_its_group_and_sibling_values(): void
    {
        $user = $this->makeAdminUser();
        $first = $this->createAttribute(
            $user,
            code: 'legacy-type-first',
            groupCode: 'legacy-type-group',
            groupName: 'Legacy Type Group',
            name: 'First',
        );
        $second = $this->createAttribute(
            $user,
            code: 'legacy-type-second',
            groupCode: 'legacy-type-group',
            groupName: 'Legacy Type Group',
            name: 'Second',
        );

        $first->forceFill(['type' => Attribute::TYPE_MULTI])->save();

        $this->assertSame(Attribute::TYPE_MULTI, $first->group()->firstOrFail()->type);
        $this->assertSame(Attribute::TYPE_MULTI, $first->fresh()->type);
        $this->assertSame(Attribute::TYPE_MULTI, $second->fresh()->type);
    }

    public function test_manual_group_name_override_survives_later_import_translation_updates(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = $this->makeAdminUser();
        $attribute = $this->createAttribute(
            $user,
            code: 'termol-name-override',
            groupCode: 'termol-name-group',
            groupName: 'Imported Group Name',
            name: 'Imported Value',
            payload: ['source' => Attribute::SOURCE_TERMOL_DESCRIPTION],
        );
        $group = $attribute->group()->firstOrFail();

        Livewire::actingAs($user)
            ->test(AttributeGroupForm::class, ['groupId' => $group->id])
            ->set('form.name', 'My Group Name')
            ->call('save')
            ->assertHasNoErrors();

        $attribute->translations()->where('locale', 'en')->firstOrFail()->update([
            'group_name' => 'Imported Group Name Again',
            'name' => 'Imported Value Again',
        ]);

        $groupTranslation = $group->translations()->where('locale', 'en')->firstOrFail();
        $this->assertSame('My Group Name', $groupTranslation->name);
        $this->assertTrue((bool) data_get($groupTranslation->payload, 'manual_override'));
    }

    private function createAttribute(
        User $user,
        string $code,
        string $groupCode,
        string $groupName,
        string $name,
        ?array $payload = null,
        string $type = Attribute::TYPE_SELECT,
    ): Attribute {
        $attribute = Attribute::query()->create([
            'code' => $code,
            'group_code' => $groupCode,
            'type' => $type,
            'is_active' => true,
            'sort_order' => 10,
            'payload' => $payload,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $attribute->translations()->create([
            'locale' => 'en',
            'group_name' => $groupName,
            'name' => $name,
            'slug' => $code,
            'description' => null,
            'payload' => null,
        ]);

        return $attribute;
    }

    private function createProduct(User $user, string $code): Product
    {
        $product = Product::query()->create([
            'code' => $code,
            'sku' => strtoupper($code),
            'unit_of_measure' => 'pcs',
            'minimum_order_quantity' => 1,
            'order_quantity_step' => 1,
            'is_active' => true,
            'manufacturer_id' => null,
            'tax_rate_id' => null,
            'base_price' => 29.99,
            'stock_qty' => 4,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $product->translations()->create([
            'locale' => 'en',
            'name' => str($code)->headline()->toString(),
            'slug' => $code,
        ]);

        return $product;
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function createDefaultTaxRate(): TaxRate
    {
        return TaxRate::query()->create([
            'code' => 'pdv25',
            'name' => 'PDV 25%',
            'geo_zone_id' => null,
            'rate_type' => 'percent',
            'rate' => 25,
            'priority' => 1,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
            'settings' => null,
        ]);
    }
}
