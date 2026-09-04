<?php

namespace Tests\Feature\Import;

use App\Data\Import\CatalogAttributeData;
use App\Data\Import\CatalogAttributeTranslationData;
use App\Data\Import\CatalogCategoryData;
use App\Data\Import\CatalogImportAction;
use App\Data\Import\CatalogImportBatch;
use App\Data\Import\CatalogLifecycleStatus;
use App\Data\Import\CatalogProductData;
use App\Data\Import\CatalogTranslationData;
use App\Exceptions\Import\CatalogImportConflictException;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductPriceHistory;
use App\Models\Import\CatalogImportRun;
use App\Models\Import\CatalogSourceMapping;
use App\Services\Import\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class CatalogImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_the_batch_without_writing_any_rows(): void
    {
        $plan = $this->service()->plan($this->batch());

        $this->assertFalse($plan->hasConflicts());
        $this->assertSame(3, $plan->summary()['create']);
        $this->assertSame(1, $plan->summary()['categories']);
        $this->assertSame(1, $plan->summary()['attributes']);
        $this->assertSame(1, $plan->summary()['products']);
        $this->assertDatabaseCount('catalog_import_runs', 0);
        $this->assertDatabaseCount('catalog_source_mappings', 0);
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('catalog_attributes', 0);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_apply_is_idempotent_and_keeps_source_payloads_and_relationships_stable(): void
    {
        $service = $this->service();
        $batch = $this->batch();

        $firstRun = $service->apply($batch);
        $secondPlan = $service->plan($batch);
        $secondRun = $service->apply($batch);

        $this->assertSame(CatalogImportRun::STATUS_COMPLETED, $firstRun->status);
        $this->assertSame(CatalogImportRun::STATUS_COMPLETED, $secondRun->status);
        $this->assertSame(3, $secondPlan->summary()['noop']);
        $this->assertSame(0, $secondPlan->summary()['update']);
        $this->assertDatabaseCount('catalog_import_runs', 2);
        $this->assertDatabaseCount('catalog_source_mappings', 3);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('category_translations', 1);
        $this->assertDatabaseCount('catalog_attributes', 1);
        $this->assertDatabaseCount('catalog_attribute_translations', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_translations', 1);
        $this->assertDatabaseCount('category_product', 1);
        $this->assertDatabaseCount('catalog_attribute_product', 1);

        $category = Category::query()->firstOrFail();
        $attribute = Attribute::query()->firstOrFail();
        $product = Product::query()->with(['translations', 'categories', 'attributes'])->firstOrFail();

        $this->assertContains('quote_shipping', $category->shippingLabels());
        $this->assertSame(['source-note' => 'category'], data_get($category->payload, 'import_sources.konto'));
        $this->assertSame(['source-note' => 'product'], data_get($product->payload, 'import_sources.konto'));
        $this->assertSame(
            ['search-label' => 'source product label'],
            data_get($product->translations->first()->payload, 'import_sources.konto'),
        );
        $this->assertSame([$category->id], $product->categories->pluck('id')->all());
        $this->assertSame([$attribute->id], $product->attributes->pluck('id')->all());
        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $product->id)->count());
        if (Schema::hasColumn('products', 'erp_gross_list_price')) {
            $this->assertSame('120.1234', $product->erp_gross_list_price);
            $this->assertSame('16.7690', $product->erp_cash_discount_percent);
            $this->assertSame('100.0000', $product->erp_cash_selling_price);
        }
    }

    public function test_product_updates_deactivates_tombstones_and_reactivates_without_hard_deletion(): void
    {
        $service = $this->service();
        $service->apply($this->batch());
        $product = Product::query()->firstOrFail();
        $originalId = $product->id;

        $updated = $this->batch(productPrice: '125.5000', productName: 'Updated heat pump', stockQty: 4);
        $updatePlan = $service->plan($updated);
        $this->assertSame(CatalogImportAction::Update, $this->operation($updatePlan->operations, 'product-1')->action);
        $service->apply($updated);

        $product->refresh();
        $this->assertSame('125.50', $product->base_price);
        $this->assertSame(4, $product->stock_qty);
        $this->assertSame('Updated heat pump', $product->translations()->firstOrFail()->name);
        $this->assertSame(2, ProductPriceHistory::query()->where('product_id', $product->id)->count());

        $inactive = $this->batch(
            productStatus: CatalogLifecycleStatus::Inactive,
            productPrice: '125.5000',
            productName: 'Updated heat pump',
            stockQty: 4,
        );
        $inactivePlan = $service->plan($inactive);
        $this->assertSame(CatalogImportAction::Deactivate, $this->operation($inactivePlan->operations, 'product-1')->action);
        $service->apply($inactive);
        $this->assertFalse((bool) $product->refresh()->is_active);

        $tombstone = new CatalogImportBatch(
            source: 'konto',
            products: [new CatalogProductData(
                sourceId: 'product-1',
                status: CatalogLifecycleStatus::Deleted,
            )],
        );
        $this->assertSame(
            CatalogImportAction::Tombstone,
            $this->operation($service->plan($tombstone)->operations, 'product-1')->action,
        );
        $service->apply($tombstone);

        $mapping = CatalogSourceMapping::query()
            ->where('source', 'konto')
            ->where('entity_type', CatalogSourceMapping::ENTITY_PRODUCT)
            ->where('source_id', 'product-1')
            ->firstOrFail();
        $this->assertSame(CatalogLifecycleStatus::Deleted->value, $mapping->lifecycle_status);
        $this->assertNotNull($mapping->tombstoned_at);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame($originalId, Product::query()->firstOrFail()->id);

        $service->apply($updated);
        $mapping->refresh();
        $this->assertTrue((bool) $product->refresh()->is_active);
        $this->assertSame($originalId, $product->id);
        $this->assertSame(CatalogLifecycleStatus::Web->value, $mapping->lifecycle_status);
        $this->assertNull($mapping->tombstoned_at);
        $this->assertSame(2, ProductPriceHistory::query()->where('product_id', $product->id)->count());
    }

    public function test_complete_snapshot_clears_erp_pricing_values_when_the_rebate_is_removed(): void
    {
        $service = $this->service();
        $service->apply($this->batch());

        $withoutRebate = CatalogImportBatch::fromArrays(
            source: 'konto',
            categories: $this->batch()->categories,
            attributes: $this->batch()->attributes,
            products: [[
                'source_id' => 'product-1',
                'status' => 'w',
                'code' => 'P-001',
                'sku' => 'SKU-001',
                'translations' => [[
                    'locale' => 'hr',
                    'name' => 'Heat pump',
                    'slug' => 'heat-pump',
                    'description' => '<p>Efficient heat pump.</p>',
                    'payload' => ['search-label' => 'source product label'],
                ]],
                'base_price' => '120.12',
                'stock_qty' => 2,
                'category_source_ids' => ['category-1'],
                'attribute_source_ids' => ['attribute-red'],
                'barcode' => '385000000001',
                'unit_of_measure' => 'pcs',
                'payload' => ['source-note' => 'product'],
            ]],
        );

        $service->apply($withoutRebate);

        $product = Product::query()->where('code', 'P-001')->firstOrFail();
        $this->assertNull($product->erp_gross_list_price);
        $this->assertNull($product->erp_cash_discount_percent);
        $this->assertNull($product->erp_cash_selling_price);
        $this->assertSame('120.12', $product->base_price);
        $this->assertSame(3, $service->plan($withoutRebate)->summary()['noop']);
    }

    public function test_imported_descriptions_are_sanitized_before_raw_storefront_rendering(): void
    {
        $batch = $this->batch(productName: 'Unsafe description');
        $product = $batch->products[0];
        $unsafeBatch = new CatalogImportBatch(
            source: $batch->source,
            categories: $batch->categories,
            attributes: $batch->attributes,
            products: [new CatalogProductData(
                sourceId: $product->sourceId,
                status: $product->status,
                code: $product->code,
                sku: $product->sku,
                translations: [new CatalogTranslationData(
                    locale: 'hr',
                    name: 'Unsafe description',
                    slug: 'heat-pump',
                    description: '<p onclick="alert(1)">Safe</p><img src="javascript:alert(2)" onerror="alert(3)"><script>alert(4)</script>',
                )],
                basePrice: $product->basePrice,
            )],
        );

        $service = $this->service();
        $service->apply($unsafeBatch);

        $description = Product::query()->firstOrFail()->translations()->firstOrFail()->description;
        $this->assertSame('<p>Safe</p><img>', $description);
        $this->assertSame(3, $service->plan($unsafeBatch)->summary()['noop']);
    }

    public function test_category_and_attribute_n_and_b_statuses_are_non_destructive(): void
    {
        $service = $this->service();
        $service->apply($this->batch());
        $categoryId = Category::query()->firstOrFail()->id;
        $attributeId = Attribute::query()->firstOrFail()->id;

        $service->apply($this->batch(
            categoryStatus: CatalogLifecycleStatus::Inactive,
            attributeStatus: CatalogLifecycleStatus::Inactive,
        ));

        $this->assertFalse((bool) Category::query()->findOrFail($categoryId)->is_active);
        $this->assertFalse((bool) Attribute::query()->findOrFail($attributeId)->is_active);
        $this->assertDatabaseHas('catalog_source_mappings', [
            'entity_type' => CatalogSourceMapping::ENTITY_CATEGORY,
            'source_id' => 'category-1',
            'lifecycle_status' => CatalogLifecycleStatus::Inactive->value,
        ]);
        $this->assertDatabaseHas('catalog_source_mappings', [
            'entity_type' => CatalogSourceMapping::ENTITY_ATTRIBUTE,
            'source_id' => 'attribute-red',
            'lifecycle_status' => CatalogLifecycleStatus::Inactive->value,
        ]);

        $service->apply(new CatalogImportBatch(
            source: 'konto',
            categories: [new CatalogCategoryData(
                sourceId: 'category-1',
                status: CatalogLifecycleStatus::Deleted,
            )],
            attributes: [new CatalogAttributeData(
                sourceId: 'attribute-red',
                status: CatalogLifecycleStatus::Deleted,
            )],
        ));

        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('catalog_attributes', 1);
        $this->assertDatabaseHas('catalog_source_mappings', [
            'entity_type' => CatalogSourceMapping::ENTITY_CATEGORY,
            'source_id' => 'category-1',
            'local_id' => $categoryId,
            'lifecycle_status' => CatalogLifecycleStatus::Deleted->value,
        ]);
        $this->assertDatabaseHas('catalog_source_mappings', [
            'entity_type' => CatalogSourceMapping::ENTITY_ATTRIBUTE,
            'source_id' => 'attribute-red',
            'local_id' => $attributeId,
            'lifecycle_status' => CatalogLifecycleStatus::Deleted->value,
        ]);
        $this->assertNotNull(CatalogSourceMapping::query()->where('entity_type', 'category')->firstOrFail()->tombstoned_at);
        $this->assertNotNull(CatalogSourceMapping::query()->where('entity_type', 'attribute')->firstOrFail()->tombstoned_at);
    }

    public function test_a_second_source_cannot_claim_or_change_records_managed_by_another_source(): void
    {
        $service = $this->service();
        $service->apply($this->batch());

        $otherSourceBatch = new CatalogImportBatch(
            source: 'firebird-other',
            categories: [new CatalogCategoryData(
                sourceId: 'other-category',
                code: '020203',
                translations: [new CatalogTranslationData('hr', 'Other category', 'heat-pumps')],
            )],
            attributes: [new CatalogAttributeData(
                sourceId: 'other-attribute',
                code: 'color-red',
                groupCode: 'color',
                translations: [new CatalogAttributeTranslationData('hr', 'Color', 'Other red', 'color-red')],
            )],
            products: [new CatalogProductData(
                sourceId: 'other-product',
                code: 'P-001',
                sku: 'SKU-001',
                translations: [new CatalogTranslationData('hr', 'Other product', 'heat-pump')],
                basePrice: 999,
                categorySourceIds: ['other-category'],
                attributeSourceIds: ['other-attribute'],
            )],
        );

        $plan = $service->plan($otherSourceBatch);
        $this->assertTrue($plan->hasConflicts());
        $this->assertSame(3, $plan->summary()['conflict']);

        try {
            $service->apply($otherSourceBatch);
            $this->fail('A conflicting source import should have been rejected.');
        } catch (CatalogImportConflictException $exception) {
            $this->assertSame('firebird-other', $exception->plan->source);
        }

        $this->assertDatabaseMissing('catalog_source_mappings', ['source' => 'firebird-other']);
        $this->assertSame('100.00', Product::query()->firstOrFail()->base_price);
        $this->assertSame('Heat pump', Product::query()->firstOrFail()->translations()->firstOrFail()->name);
    }

    public function test_an_import_source_cannot_claim_an_unmanaged_local_product(): void
    {
        $manual = Product::query()->create([
            'code' => 'MANUAL-001',
            'sku' => 'MANUAL-SKU-001',
            'is_active' => true,
            'base_price' => 50,
            'stock_qty' => 1,
        ]);

        $batch = new CatalogImportBatch(
            source: 'konto',
            products: [new CatalogProductData(
                sourceId: 'source-product',
                code: 'MANUAL-001',
                sku: 'SOURCE-SKU',
                translations: [new CatalogTranslationData('hr', 'Source product', 'source-product')],
                basePrice: 10,
            )],
        );

        $plan = $this->service()->plan($batch);

        $this->assertTrue($plan->hasConflicts());
        $this->assertStringContainsString('unmanaged local record', $plan->conflicts()[0]->messages[0]);
        $this->expectException(CatalogImportConflictException::class);

        try {
            $this->service()->apply($batch);
        } finally {
            $this->assertSame('50.00', $manual->refresh()->base_price);
            $this->assertDatabaseCount('catalog_source_mappings', 0);
        }
    }

    public function test_non_alphabetical_category_and_attribute_order_is_idempotent_and_preserves_pivot_order(): void
    {
        $batch = new CatalogImportBatch(
            source: 'konto',
            categories: [
                new CatalogCategoryData(
                    sourceId: 'category-b',
                    code: 'category-code-b',
                    translations: [new CatalogTranslationData('hr', 'Category B', 'category-b')],
                ),
                new CatalogCategoryData(
                    sourceId: 'category-a',
                    code: 'category-code-a',
                    translations: [new CatalogTranslationData('hr', 'Category A', 'category-a')],
                ),
            ],
            attributes: [
                new CatalogAttributeData(
                    sourceId: 'attribute-b',
                    code: 'size-large',
                    groupCode: 'size',
                    translations: [new CatalogAttributeTranslationData('hr', 'Size', 'Large', 'size-large')],
                ),
                new CatalogAttributeData(
                    sourceId: 'attribute-a',
                    code: 'color-red',
                    groupCode: 'color',
                    translations: [new CatalogAttributeTranslationData('hr', 'Color', 'Red', 'color-red')],
                ),
            ],
            products: [new CatalogProductData(
                sourceId: 'product-order',
                code: 'P-ORDER',
                translations: [new CatalogTranslationData('hr', 'Ordered product', 'ordered-product')],
                basePrice: 10,
                categorySourceIds: ['category-b', 'category-a'],
                attributeSourceIds: ['attribute-b', 'attribute-a'],
            )],
        );

        $service = $this->service();
        $service->apply($batch);
        $secondPlan = $service->plan($batch);
        $service->apply($batch);

        $this->assertSame(5, $secondPlan->summary()['noop']);
        $product = Product::query()->where('code', 'P-ORDER')->firstOrFail();
        $this->assertSame(
            ['category-code-b', 'category-code-a'],
            $product->categories()->orderBy('category_product.sort_order')->pluck('code')->all(),
        );
        $this->assertSame(
            ['size-large', 'color-red'],
            $product->attributes()->orderBy('catalog_attribute_product.sort_order')->pluck('code')->all(),
        );
        $this->assertSame(
            [true, false],
            $product->categories()
                ->orderBy('category_product.sort_order')
                ->get()
                ->map(static fn (Category $category): bool => (bool) $category->pivot->is_primary)
                ->all(),
        );
        $this->assertDatabaseCount('category_product', 2);
        $this->assertDatabaseCount('catalog_attribute_product', 2);
    }

    public function test_normalized_records_reject_natural_keys_that_exceed_database_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CatalogProductData(
            sourceId: 'invalid-product',
            code: str_repeat('x', 121),
            translations: [new CatalogTranslationData('hr', 'Invalid', 'invalid')],
            basePrice: 10,
        );
    }

    public function test_normalized_product_rejects_an_erp_cash_discount_above_one_hundred_percent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CatalogProductData(
            sourceId: 'invalid-discount-product',
            code: 'INVALID-DISCOUNT',
            translations: [new CatalogTranslationData('hr', 'Invalid discount', 'invalid-discount')],
            basePrice: 10,
            erpCashDiscountPercent: '100.0001',
        );
    }

    public function test_child_categories_are_applied_after_their_source_parent_even_when_input_is_reversed(): void
    {
        $batch = new CatalogImportBatch(
            source: 'konto',
            categories: [
                new CatalogCategoryData(
                    sourceId: 'child',
                    code: 'child-code',
                    translations: [new CatalogTranslationData('hr', 'Child', 'child')],
                    parentSourceId: 'parent',
                ),
                new CatalogCategoryData(
                    sourceId: 'parent',
                    code: 'parent-code',
                    translations: [new CatalogTranslationData('hr', 'Parent', 'parent')],
                ),
            ],
        );

        $service = $this->service();
        $service->apply($batch);

        $parent = Category::query()->where('code', 'parent-code')->firstOrFail();
        $child = Category::query()->where('code', 'child-code')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame(2, $service->plan($batch)->summary()['noop']);
    }

    public function test_batch_rejects_duplicate_translation_slugs_before_dry_run(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CatalogImportBatch(
            source: 'konto',
            categories: [
                new CatalogCategoryData(
                    sourceId: 'category-1',
                    code: 'category-1',
                    translations: [new CatalogTranslationData('hr', 'One', 'duplicate')],
                ),
                new CatalogCategoryData(
                    sourceId: 'category-2',
                    code: 'category-2',
                    translations: [new CatalogTranslationData('hr', 'Two', 'duplicate')],
                ),
            ],
        );
    }

    private function service(): CatalogImportService
    {
        return app(CatalogImportService::class);
    }

    private function batch(
        CatalogLifecycleStatus $categoryStatus = CatalogLifecycleStatus::Web,
        CatalogLifecycleStatus $attributeStatus = CatalogLifecycleStatus::Web,
        CatalogLifecycleStatus $productStatus = CatalogLifecycleStatus::Web,
        string $productPrice = '100.0000',
        string $productName = 'Heat pump',
        int $stockQty = 2,
    ): CatalogImportBatch {
        return new CatalogImportBatch(
            source: 'konto',
            categories: [new CatalogCategoryData(
                sourceId: 'category-1',
                status: $categoryStatus,
                code: '020203',
                translations: [new CatalogTranslationData(
                    locale: 'hr',
                    name: 'Heat pumps',
                    slug: 'heat-pumps',
                    excerpt: 'Source-only category excerpt',
                    payload: ['search-label' => 'source category label'],
                )],
                sortOrder: 10,
                payload: ['source-note' => 'category'],
            )],
            attributes: [new CatalogAttributeData(
                sourceId: 'attribute-red',
                status: $attributeStatus,
                code: 'color-red',
                groupCode: 'color',
                type: CatalogAttributeData::TYPE_SELECT,
                translations: [new CatalogAttributeTranslationData(
                    locale: 'hr',
                    groupName: 'Color',
                    name: 'Red',
                    slug: 'color-red',
                    payload: ['swatch' => '#ff0000'],
                )],
                sortOrder: 10,
                payload: ['source-note' => 'attribute'],
            )],
            products: [new CatalogProductData(
                sourceId: 'product-1',
                status: $productStatus,
                code: 'P-001',
                sku: 'SKU-001',
                translations: [new CatalogTranslationData(
                    locale: 'hr',
                    name: $productName,
                    slug: 'heat-pump',
                    description: '<p>Efficient heat pump.</p>',
                    payload: ['search-label' => 'source product label'],
                )],
                basePrice: $productPrice,
                stockQty: $stockQty,
                categorySourceIds: ['category-1'],
                attributeSourceIds: ['attribute-red'],
                barcode: '385000000001',
                unitOfMeasure: 'pcs',
                erpGrossListPrice: '120.1234',
                erpCashDiscountPercent: '16.7690',
                erpCashSellingPrice: '100.0000',
                payload: ['source-note' => 'product'],
            )],
        );
    }

    /** @param list<mixed> $operations */
    private function operation(array $operations, string $sourceId): mixed
    {
        foreach ($operations as $operation) {
            if ($operation->sourceId === $sourceId) {
                return $operation;
            }
        }

        $this->fail("Missing planned operation for [{$sourceId}].");
    }
}
