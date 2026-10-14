<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Services\Import\HerreraOpenCartImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HerreraFinalSyncOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'herrera-ownership-source-');
        config(['database.connections.herrera_ownership_source' => ['driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('herrera_ownership_source');
        $this->source('language', [['language_id' => 3, 'code' => 'hr-hr']]);
        $this->source('country', [['country_id' => 1, 'iso_code_2' => 'HR']]);
        $this->source('manufacturer', [['manufacturer_id' => 1, 'name' => 'Source brand', 'sort_order' => 0]]);
        $this->source('category', [
            ['category_id' => 10, 'parent_id' => 0, 'status' => 1, 'top' => 1, 'sort_order' => 0, 'image' => 'catalog/root.jpg'],
            ['category_id' => 11, 'parent_id' => 10, 'status' => 1, 'top' => 0, 'sort_order' => 1, 'image' => 'catalog/child.jpg'],
        ]);
        $this->source('category_description', [
            ['category_id' => 10, 'language_id' => 3, 'name' => 'Source root', 'description' => '', 'meta_title' => '', 'meta_description' => ''],
            ['category_id' => 11, 'language_id' => 3, 'name' => 'Source child', 'description' => '', 'meta_title' => '', 'meta_description' => ''],
        ]);
        $this->source('product', [$this->sourceProduct(30), $this->sourceProduct(31)]);
        $this->source('product_description', [
            ['product_id' => 30, 'language_id' => 3, 'name' => 'Source lamp', 'description' => 'Snaga: 20 W', 'meta_title' => '', 'meta_description' => ''],
            ['product_id' => 31, 'language_id' => 3, 'name' => 'Related lamp', 'description' => '', 'meta_title' => '', 'meta_description' => ''],
        ]);
        $this->source('product_to_category', [['product_id' => 30, 'category_id' => 11]]);
        $this->source('product_image', [['product_id' => 30, 'image' => 'catalog/gallery.jpg', 'sort_order' => 1]]);
        $this->source('product_related', [['product_id' => 30, 'related_id' => 31]]);
        $this->source('attribute_group_description', [['attribute_group_id' => 1, 'language_id' => 3, 'name' => 'Električne karakteristike']]);
        $this->source('attribute_description', [['attribute_id' => 1, 'language_id' => 3, 'name' => 'Snaga']]);
        $this->source('attribute', [['attribute_id' => 1, 'attribute_group_id' => 1, 'sort_order' => 0]]);
        $this->source('product_attribute', [['product_id' => 30, 'attribute_id' => 1, 'language_id' => 3, 'text' => '20 W']]);
        $this->source('option', [['option_id' => 1, 'type' => 'select', 'sort_order' => 0]]);
        $this->source('option_description', [['option_id' => 1, 'language_id' => 3, 'name' => 'Boja svjetla']]);
        $this->source('option_value', [['option_value_id' => 1, 'option_id' => 1, 'sort_order' => 0]]);
        $this->source('option_value_description', [['option_value_id' => 1, 'language_id' => 3, 'name' => '4000 K']]);
        $this->source('product_option', [['product_option_id' => 1, 'product_id' => 30, 'option_id' => 1, 'required' => 1]]);
        $this->source('product_option_value', [['product_option_value_id' => 1, 'product_option_id' => 1, 'product_id' => 30, 'option_id' => 1, 'option_value_id' => 1, 'quantity' => 7, 'price' => '2.1234', 'price_prefix' => '+']]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('herrera_ownership_source');
        unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_changed_source_fields_preserve_native_payload_settings_option_customization_and_uploaded_documents(): void
    {
        Storage::fake('public');
        $this->import();
        $product = $this->product();
        $category = Category::query()->where('code', 'herrera-oc-category-11')->firstOrFail();
        $option = Option::query()->where('code', 'herrera-oc-option-1')->firstOrFail();
        $value = OptionValue::query()->where('code', 'herrera-oc-value-1')->firstOrFail();
        $variant = ProductOptionValue::query()->where('product_id', $product->id)->firstOrFail();
        $variant->update(['sku' => 'CURATED-VARIANT-SKU']);
        $product->update(['payload' => array_replace($product->payload, ['native_feature' => ['curated' => true]]), 'eprel_registration_number' => 'NATIVE-EPREL']);
        $category->update(['payload' => array_replace($category->payload, ['show_filters' => false, 'native_design' => 'curated'])]);
        $option->update(['payload' => array_replace($option->payload, [Option::PAYLOAD_SHOW_ON_PRODUCT_PAGE => false])]);
        $value->update(['payload' => array_replace($value->payload, ['swatch_image_path' => 'native/swatch.jpg'])]);
        $document = $product->addMedia(UploadedFile::fake()->createWithContent('native-document.pdf', "%PDF-1.4\nNative document\n%%EOF"))
            ->toMediaCollection('product_documents');
        $beforeFile = file_get_contents($document->getPath());
        $this->sourceQuery('product')->where('product_id', 30)->update(['quantity' => 19, 'suplierqty' => 23, 'price' => '101.2345']);
        $this->sourceQuery('category')->where('category_id', 11)->update(['sort_order' => 8]);
        $this->sourceQuery('option')->update(['sort_order' => 9]);
        $this->sourceQuery('option_value')->update(['sort_order' => 10]);

        $this->import(finalSync: true);

        $this->assertSame(19, $product->fresh()->stock_qty);
        $this->assertSame(23, $product->fresh()->supplier_stock_qty);
        $this->assertTrue(data_get($product->fresh()->payload, 'native_feature.curated'));
        $this->assertSame('NATIVE-EPREL', $product->fresh()->eprel_registration_number);
        $this->assertFalse(data_get($category->fresh()->payload, 'show_filters'));
        $this->assertSame('curated', data_get($category->fresh()->payload, 'native_design'));
        $this->assertFalse(data_get($option->fresh()->payload, Option::PAYLOAD_SHOW_ON_PRODUCT_PAGE));
        $this->assertSame('native/swatch.jpg', data_get($value->fresh()->payload, 'swatch_image_path'));
        // The option's source row is unchanged, but its absolute price depends on the newly imported base.
        $this->assertSame(103.3579, (float) $variant->fresh()->price_override);
        $this->assertSame('CURATED-VARIANT-SKU', $variant->fresh()->sku);
        $this->assertDatabaseHas('media', ['id' => $document->id, 'collection_name' => 'product_documents']);
        $this->assertSame($beforeFile, file_get_contents($document->getPath()));
    }

    public function test_final_sync_replaces_source_attribute_values_and_removes_source_edges_without_detaching_native_assignments(): void
    {
        $this->import();
        $product = $this->product();
        $oldAttributeId = $product->attributes()->sole()->id;
        $sourceCategoryId = Category::query()->where('code', 'herrera-oc-category-11')->value('id');
        $nativeCategory = Category::query()->create(['code' => 'native-category', 'scope' => 'catalog', 'is_active' => true]);
        $nativeAttribute = Attribute::query()->create(['code' => 'native-material', 'group_code' => 'material', 'type' => 'select', 'is_active' => true]);
        $nativeOption = Option::query()->create(['code' => 'native-dimensions', 'type' => 'select', 'is_active' => true]);
        $nativeValue = OptionValue::query()->create(['option_id' => $nativeOption->id, 'code' => '120mm', 'is_active' => true]);
        $product->categories()->attach($nativeCategory, ['is_primary' => true, 'sort_order' => 15]);
        $product->attributes()->attach($nativeAttribute, ['sort_order' => 16]);
        $product->options()->attach($nativeOption, ['is_required' => false, 'sort_order' => 17]);
        $nativeRow = $product->optionValues()->create(['option_value_id' => $nativeValue->id, 'mode' => 'single', 'sku' => 'NATIVE-120MM', 'stock_qty' => 3, 'price_override' => 9.75, 'is_active' => true, 'combination_hash' => hash('sha256', 'native:'.$nativeValue->id), 'payload' => ['native_variant' => true]]);
        $sourceRow = ProductOptionValue::query()->where('id', DB::table('herrera_import_maps')->where('entity', 'product_option_value')->value('target_id'))->firstOrFail();
        $this->sourceQuery('product_attribute')->update(['text' => '30 W']);
        $this->sourceQuery('product_to_category')->delete();
        $this->sourceQuery('product_option')->delete();
        $this->sourceQuery('product_option_value')->delete();

        $this->import(finalSync: true);

        $this->assertFalse($product->categories()->whereKey($sourceCategoryId)->exists());
        $this->assertDatabaseHas('category_product', ['product_id' => $product->id, 'category_id' => $nativeCategory->id, 'is_primary' => true, 'sort_order' => 15]);
        $this->assertFalse($product->attributes()->whereKey($oldAttributeId)->exists());
        $this->assertDatabaseHas('catalog_attribute_product', ['product_id' => $product->id, 'attribute_id' => $nativeAttribute->id, 'sort_order' => 16]);
        $this->assertDatabaseHas('catalog_option_product', ['product_id' => $product->id, 'option_id' => $nativeOption->id, 'is_required' => false, 'sort_order' => 17]);
        $this->assertDatabaseHas('catalog_product_option_values', ['id' => $sourceRow->id, 'is_active' => false]);
        $this->assertDatabaseHas('catalog_product_option_values', ['id' => $nativeRow->id, 'sku' => 'NATIVE-120MM', 'stock_qty' => 3, 'is_active' => true]);
        $sourceSpecs = $product->technicalSpecificationRows()->where('source', 'herrera-opencart')->get();
        $this->assertSame([['30 W']], $sourceSpecs->pluck('values')->all());
        $this->assertSame(2, $product->attributes()->count());

        $this->import(finalSync: true);
        $this->assertSame(2, $product->attributes()->count());
        $this->assertDatabaseHas('catalog_product_option_values', ['id' => $nativeRow->id, 'is_active' => true]);
    }

    public function test_empty_source_gallery_and_related_lists_are_cleared_while_native_media_and_payload_remain(): void
    {
        Storage::fake('public');
        $this->import();
        $product = $this->product();
        $this->assertNotEmpty(data_get($product->payload, 'opencart.gallery_images'));
        $this->assertNotEmpty(data_get($product->payload, 'related_product_ids'));
        $product->update(['payload' => array_replace($product->payload, ['native_gallery_note' => 'Keep curated media'])]);
        $image = $product->addMedia(UploadedFile::fake()->image('curated.jpg', 20, 20))->toMediaCollection('product_main');
        $this->sourceQuery('product_image')->delete();
        $this->sourceQuery('product_related')->delete();

        $this->import(finalSync: true);

        $this->assertSame([], data_get($product->fresh()->payload, 'opencart.gallery_images'));
        $this->assertSame([], data_get($product->fresh()->payload, 'related_product_ids'));
        $this->assertSame('Keep curated media', data_get($product->fresh()->payload, 'native_gallery_note'));
        $this->assertDatabaseHas('media', ['id' => $image->id, 'collection_name' => 'product_main']);
        $this->assertFileExists($image->getPath());
        $this->import(finalSync: true);
        $this->assertSame([], data_get($product->fresh()->payload, 'opencart.gallery_images'));
        $this->assertSame([], data_get($product->fresh()->payload, 'related_product_ids'));
    }

    public function test_final_sync_preserves_a_native_attribute_group_translation_when_source_name_is_unchanged(): void
    {
        $this->import();
        DB::table('catalog_attribute_group_translations')->where('locale', 'hr')->update(['name' => 'Ručno uređene značajke']);

        $this->import(finalSync: true);

        $this->assertDatabaseHas('catalog_attribute_group_translations', ['locale' => 'hr', 'name' => 'Ručno uređene značajke']);
    }

    public function test_removing_a_source_specification_with_native_payload_metadata_requires_review(): void
    {
        $this->import();
        $specification = $this->product()->technicalSpecificationRows()->where('source', 'herrera-opencart')->sole();
        $specification->update(['payload' => array_replace($specification->payload, ['native_note' => 'Reviewed by staff'])]);
        $this->sourceQuery('product_attribute')->delete();

        $result = $this->import(finalSync: true);

        $this->assertGreaterThan(0, $result['final_sync_conflicts']);
        $this->assertDatabaseHas('catalog_product_specifications', ['id' => $specification->id]);
        $this->assertSame('Reviewed by staff', data_get($specification->fresh()->payload, 'native_note'));
    }

    private function import(bool $finalSync = false): array
    {
        return app(HerreraOpenCartImportService::class)->import('herrera_ownership_source', 'oc_', false, ['catalog', 'relations'], finalSync: $finalSync);
    }

    private function product(): Product
    {
        return Product::query()->where('code', 'herrera-oc-product-30')->firstOrFail();
    }

    private function sourceQuery(string $table)
    {
        return DB::connection('herrera_ownership_source')->table('oc_'.$table);
    }

    private function source(string $table, array $rows): void
    {
        Schema::connection('herrera_ownership_source')->create('oc_'.$table, function (Blueprint $schema) use ($rows): void {
            foreach (array_keys($rows[0]) as $column) {
                $schema->text($column)->nullable();
            }
        });
        $this->sourceQuery($table)->insert($rows);
    }

    private function sourceProduct(int $id): array
    {
        return ['product_id' => $id, 'model' => 'SKU-'.$id, 'sku' => 'SKU-'.$id, 'ean' => '', 'upc' => '', 'manufacturer_id' => 1, 'tax_class_id' => 0, 'status' => 1, 'price' => '100.1234', 'quantity' => 12, 'suplierqty' => 13, 'minimum' => 1, 'weight' => '1', 'length' => '10', 'width' => '5', 'height' => '3', 'image' => 'catalog/main.jpg'];
    }
}
