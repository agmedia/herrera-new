<?php

namespace Tests\Feature\Import;

use App\Data\Import\CatalogAdoptionAction;
use App\Data\Import\CatalogAdoptionOperation;
use App\Data\Import\CatalogAttributeData;
use App\Data\Import\CatalogAttributeTranslationData;
use App\Data\Import\CatalogCategoryData;
use App\Data\Import\CatalogImportBatch;
use App\Data\Import\CatalogLifecycleStatus;
use App\Data\Import\CatalogProductData;
use App\Data\Import\CatalogTranslationData;
use App\Exceptions\Import\CatalogAdoptionConflictException;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Import\CatalogSourceMapping;
use App\Services\Import\CatalogSourceAdoptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSourceAdoptionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_matches_only_exact_catalog_identifiers_and_never_writes(): void
    {
        $category = $this->category('CAT-1');
        $this->category('BLOG-ONLY', Category::SCOPE_BLOG);
        $attribute = Attribute::query()->create([
            'code' => 'ATTR-1',
            'group_code' => 'group',
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'code' => 'P-1',
            'sku' => 'SKU-1',
            'barcode' => 'BAR-1',
            'base_price' => 25,
            'stock_qty' => 3,
        ]);

        $plan = $this->service()->plan(new CatalogImportBatch(
            source: 'konto',
            categories: [
                $this->categoryRecord('category-1', 'CAT-1'),
                $this->categoryRecord('category-blog-code', 'BLOG-ONLY'),
            ],
            attributes: [$this->attributeRecord('attribute-1', 'ATTR-1')],
            products: [
                $this->productRecord('product-1', 'P-1', 'SKU-1', 'BAR-1'),
                new CatalogProductData('deleted-product', CatalogLifecycleStatus::Deleted),
            ],
        ));

        $this->assertFalse($plan->hasConflicts());
        $this->assertSame(3, $plan->summary()['adopt']);
        $this->assertSame(1, $plan->summary()['unmatched']);
        $this->assertSame(1, $plan->summary()['skip_tombstone']);
        $this->assertSame($category->id, $this->operation($plan->operations, 'category-1')->localId);
        $this->assertSame($attribute->id, $this->operation($plan->operations, 'attribute-1')->localId);
        $this->assertSame($product->id, $this->operation($plan->operations, 'product-1')->localId);
        $this->assertSame(
            CatalogAdoptionAction::Unmatched,
            $this->operation($plan->operations, 'category-blog-code')->action,
        );
        $this->assertDatabaseCount('catalog_source_mappings', 0);
        $this->assertDatabaseCount('catalog_import_runs', 0);
        $this->assertDatabaseCount('categories', 2);
        $this->assertDatabaseCount('catalog_attributes', 1);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_apply_adopts_an_exact_sku_with_a_legacy_synthetic_code_without_mutating_the_product(): void
    {
        $product = Product::query()->create([
            'code' => 'termol-SKU-164',
            'sku' => 'SKU-164',
            'barcode' => null,
            'base_price' => 12.34,
            'stock_qty' => 7,
            'payload' => [
                'source' => 'termol.hr',
                'source_url' => 'https://www.termol.hr/legacy-product.aspx',
            ],
        ]);
        $batch = new CatalogImportBatch(
            source: 'konto',
            products: [$this->productRecord('erp-product-164', 'ERP-164', 'SKU-164', null, '99.99')],
        );

        $plan = $this->service()->plan($batch);
        $operation = $this->operation($plan->operations, 'erp-product-164');

        $this->assertSame(CatalogAdoptionAction::Adopt, $operation->action);
        $this->assertSame([
            'sku' => 'SKU-164',
            'legacy_synthetic_code' => 'termol-SKU-164',
            'legacy_source' => 'termol.hr',
            'legacy_source_url' => 'https://www.termol.hr/legacy-product.aspx',
        ], $operation->identifiers);
        $this->assertStringContainsString('legacy synthetic code', $operation->messages[0]);

        $applied = $this->service()->apply($batch);
        $mapping = CatalogSourceMapping::query()->firstOrFail();

        $this->assertSame(1, $applied->summary()['adopt']);
        $this->assertSame($product->id, $mapping->local_id);
        $this->assertSame(CatalogLifecycleStatus::Web->value, $mapping->lifecycle_status);
        $this->assertNotNull($mapping->source_checksum);
        $this->assertNotNull($mapping->last_seen_at);
        $this->assertNull($mapping->last_import_run_id);
        $this->assertSame(1, data_get($mapping->metadata, 'adoption.rule_version'));
        $this->assertSame(['sku'], data_get($mapping->metadata, 'adoption.match_basis'));
        $this->assertSame('SKU-164', data_get($mapping->metadata, 'adoption.matched_identifiers.sku'));
        $this->assertSame(
            'termol-SKU-164',
            data_get($mapping->metadata, 'adoption.matched_identifiers.legacy_synthetic_code'),
        );
        $this->assertSame(
            'https://www.termol.hr/legacy-product.aspx',
            data_get($mapping->metadata, 'adoption.matched_identifiers.legacy_source_url'),
        );
        $this->assertSame(
            ['code' => 'termol-SKU-164', 'sku' => 'SKU-164', 'barcode' => null],
            data_get($mapping->metadata, 'adoption.local_identity_before'),
        );
        $this->assertSame('termol-SKU-164', $product->refresh()->code);
        $this->assertSame('12.34', $product->base_price);
        $this->assertSame(7, $product->stock_qty);
        $this->assertDatabaseCount('catalog_import_runs', 0);

        $secondPlan = $this->service()->plan($batch);
        $this->assertSame(CatalogAdoptionAction::AlreadyMapped, $secondPlan->operations[0]->action);
        $this->service()->apply($batch);
        $this->assertDatabaseCount('catalog_source_mappings', 1);
    }

    public function test_product_identifiers_that_resolve_to_different_rows_are_a_conflict(): void
    {
        Product::query()->create([
            'code' => 'LOCAL-A',
            'sku' => 'SKU-A',
            'base_price' => 10,
        ]);
        Product::query()->create([
            'code' => 'SOURCE-CODE',
            'sku' => 'SKU-B',
            'base_price' => 20,
        ]);
        $batch = new CatalogImportBatch(
            source: 'konto',
            products: [$this->productRecord('conflicted', 'SOURCE-CODE', 'SKU-A')],
        );

        $plan = $this->service()->plan($batch);

        $this->assertTrue($plan->hasConflicts());
        $this->assertSame(CatalogAdoptionAction::Conflict, $plan->operations[0]->action);
        $this->assertStringContainsString('different local records', $plan->operations[0]->messages[0]);

        try {
            $this->service()->apply($batch);
            $this->fail('A conflicted adoption should be rejected.');
        } catch (CatalogAdoptionConflictException $exception) {
            $this->assertSame($plan->summary(), $exception->plan->summary());
        }

        $this->assertDatabaseCount('catalog_source_mappings', 0);
        $this->assertDatabaseCount('catalog_import_runs', 0);
    }

    public function test_non_null_product_identity_disagreement_is_a_conflict_and_legacy_exception_requires_exact_sku(): void
    {
        Product::query()->create([
            'code' => 'P-1',
            'sku' => 'LOCAL-SKU',
            'base_price' => 10,
        ]);
        Product::query()->create([
            'code' => 'termol-LEGACY-SKU',
            'sku' => 'LEGACY-SKU',
            'barcode' => 'LEGACY-BARCODE',
            'base_price' => 20,
        ]);
        $batch = new CatalogImportBatch(
            source: 'konto',
            products: [
                $this->productRecord('sku-disagreement', 'P-1', 'SOURCE-SKU'),
                $this->productRecord('barcode-only-legacy', 'ERP-2', 'OTHER-SKU', 'LEGACY-BARCODE'),
            ],
        );

        $plan = $this->service()->plan($batch);

        $this->assertSame(2, $plan->summary()['conflict']);
        $this->assertStringContainsString('Product sku differs', $plan->operations[0]->messages[0]);
        $this->assertStringContainsString('Product code differs', $plan->operations[1]->messages[0]);
        $this->assertArrayNotHasKey('legacy_synthetic_code', $plan->operations[1]->identifiers);
    }

    public function test_legacy_synthetic_code_exception_requires_konto_and_verified_termol_provenance(): void
    {
        Product::query()->create([
            'code' => 'termol-SKU-NO-PROVENANCE',
            'sku' => 'SKU-NO-PROVENANCE',
            'base_price' => 10,
            'payload' => [
                'source' => 'termol.hr',
                'source_url' => 'http://www.termol.hr/not-https.aspx',
            ],
        ]);
        $kontoPlan = $this->service()->plan(new CatalogImportBatch(
            source: 'konto',
            products: [$this->productRecord('no-provenance', 'ERP-NO-PROVENANCE', 'SKU-NO-PROVENANCE')],
        ));

        $this->assertSame(CatalogAdoptionAction::Conflict, $kontoPlan->operations[0]->action);
        $this->assertStringContainsString('Product code differs', $kontoPlan->operations[0]->messages[0]);
        $this->assertArrayNotHasKey('legacy_synthetic_code', $kontoPlan->operations[0]->identifiers);

        $product = Product::query()->create([
            'code' => 'termol-SKU-WRONG-SOURCE',
            'sku' => 'SKU-WRONG-SOURCE',
            'base_price' => 10,
            'payload' => [
                'source' => 'termol.hr',
                'source_url' => 'https://www.termol.hr/verified.aspx',
            ],
        ]);
        $otherSourcePlan = $this->service()->plan(new CatalogImportBatch(
            source: 'other-source',
            products: [$this->productRecord('wrong-source', 'ERP-WRONG-SOURCE', 'SKU-WRONG-SOURCE')],
        ));

        $this->assertSame($product->id, $otherSourcePlan->operations[0]->localId);
        $this->assertSame(CatalogAdoptionAction::Conflict, $otherSourcePlan->operations[0]->action);
        $this->assertArrayNotHasKey('legacy_synthetic_code', $otherSourcePlan->operations[0]->identifiers);
    }

    public function test_missing_source_identity_values_cannot_clear_non_null_local_sku_or_barcode(): void
    {
        Product::query()->create([
            'code' => 'P-MISSING',
            'sku' => 'LOCAL-SKU',
            'barcode' => 'LOCAL-BARCODE',
            'base_price' => 10,
        ]);
        $plan = $this->service()->plan(new CatalogImportBatch(
            source: 'konto',
            products: [$this->productRecord('missing-identities', 'P-MISSING', null, null)],
        ));

        $this->assertSame(CatalogAdoptionAction::Conflict, $plan->operations[0]->action);
        $this->assertCount(2, $plan->operations[0]->messages);
        $this->assertStringContainsString('sku is null', $plan->operations[0]->messages[0]);
        $this->assertStringContainsString('barcode is null', $plan->operations[0]->messages[1]);
    }

    public function test_every_batch_record_targeting_the_same_local_product_is_marked_as_a_conflict(): void
    {
        Product::query()->create([
            'code' => 'termol-DUPLICATE-SKU',
            'sku' => 'DUPLICATE-SKU',
            'base_price' => 10,
            'payload' => [
                'source' => 'termol.hr',
                'source_url' => 'https://www.termol.hr/duplicate.aspx',
            ],
        ]);
        $plan = $this->service()->plan(new CatalogImportBatch(
            source: 'konto',
            products: [
                $this->productRecord('first-alias', 'ERP-DUPLICATE', 'DUPLICATE-SKU'),
                $this->productRecord('second-alias', 'termol-DUPLICATE-SKU', null),
            ],
        ));

        $this->assertSame(2, $plan->summary()['conflict']);
        foreach ($plan->operations as $operation) {
            $this->assertSame(CatalogAdoptionAction::Conflict, $operation->action);
            $this->assertStringContainsString(
                'Multiple source records target the same local record',
                implode(' ', $operation->messages),
            );
        }
    }

    public function test_adoption_refuses_another_sources_owner_and_stale_source_mappings(): void
    {
        $product = Product::query()->create([
            'code' => 'OWNED',
            'sku' => 'OWNED-SKU',
            'base_price' => 10,
        ]);
        CatalogSourceMapping::query()->create([
            'source' => 'other-source',
            'entity_type' => CatalogSourceMapping::ENTITY_PRODUCT,
            'source_id' => 'other-id',
            'local_id' => $product->id,
            'lifecycle_status' => CatalogLifecycleStatus::Web->value,
        ]);
        CatalogSourceMapping::query()->create([
            'source' => 'konto',
            'entity_type' => CatalogSourceMapping::ENTITY_PRODUCT,
            'source_id' => 'stale-id',
            'local_id' => null,
            'lifecycle_status' => CatalogLifecycleStatus::Web->value,
        ]);
        $batch = new CatalogImportBatch(
            source: 'konto',
            products: [
                $this->productRecord('owned-id', 'OWNED', 'OWNED-SKU'),
                $this->productRecord('stale-id', 'NEW', 'NEW-SKU'),
            ],
        );

        $plan = $this->service()->plan($batch);

        $this->assertSame(2, $plan->summary()['conflict']);
        $this->assertStringContainsString('already owned', $plan->operations[0]->messages[0]);
        $this->assertStringContainsString('no local record ID', $plan->operations[1]->messages[0]);
    }

    public function test_apply_is_all_or_nothing_when_one_record_conflicts(): void
    {
        $this->category('SAFE-CATEGORY');
        $product = Product::query()->create([
            'code' => 'OWNED',
            'sku' => 'OWNED-SKU',
            'base_price' => 10,
        ]);
        CatalogSourceMapping::query()->create([
            'source' => 'other-source',
            'entity_type' => CatalogSourceMapping::ENTITY_PRODUCT,
            'source_id' => 'other-id',
            'local_id' => $product->id,
            'lifecycle_status' => CatalogLifecycleStatus::Web->value,
        ]);
        $batch = new CatalogImportBatch(
            source: 'konto',
            categories: [$this->categoryRecord('safe-category', 'SAFE-CATEGORY')],
            products: [$this->productRecord('owned-id', 'OWNED', 'OWNED-SKU')],
        );

        try {
            $this->service()->apply($batch);
            $this->fail('A conflicted adoption should be rejected atomically.');
        } catch (CatalogAdoptionConflictException) {
            // Expected.
        }

        $this->assertDatabaseMissing('catalog_source_mappings', [
            'source' => 'konto',
            'source_id' => 'safe-category',
        ]);
        $this->assertDatabaseCount('catalog_import_runs', 0);
    }

    private function service(): CatalogSourceAdoptionService
    {
        return app(CatalogSourceAdoptionService::class);
    }

    private function category(string $code, string $scope = Category::SCOPE_CATALOG): Category
    {
        $category = new Category([
            'scope' => $scope,
            'code' => $code,
            'is_active' => true,
            'show_in_menu' => true,
        ]);
        $category->saveAsRoot();

        return $category;
    }

    private function categoryRecord(string $sourceId, string $code): CatalogCategoryData
    {
        return new CatalogCategoryData(
            sourceId: $sourceId,
            code: $code,
            translations: [new CatalogTranslationData('hr', $sourceId, $sourceId)],
        );
    }

    private function attributeRecord(string $sourceId, string $code): CatalogAttributeData
    {
        return new CatalogAttributeData(
            sourceId: $sourceId,
            code: $code,
            groupCode: 'group',
            translations: [new CatalogAttributeTranslationData('hr', 'Group', $sourceId, $sourceId)],
        );
    }

    private function productRecord(
        string $sourceId,
        string $code,
        ?string $sku,
        ?string $barcode = null,
        string $price = '100.00',
    ): CatalogProductData {
        return new CatalogProductData(
            sourceId: $sourceId,
            code: $code,
            sku: $sku,
            translations: [new CatalogTranslationData('hr', $sourceId, $sourceId)],
            basePrice: $price,
            barcode: $barcode,
        );
    }

    /** @param list<CatalogAdoptionOperation> $operations */
    private function operation(array $operations, string $sourceId): CatalogAdoptionOperation
    {
        foreach ($operations as $operation) {
            if ($operation->sourceId === $sourceId) {
                return $operation;
            }
        }

        $this->fail("Missing planned adoption operation for [{$sourceId}].");
    }
}
