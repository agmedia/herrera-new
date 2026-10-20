<?php

namespace Tests\Feature\Import;

use App\Livewire\Admin\User\CustomerPriceManager;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Pricing\CustomerDiscountRule;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Import\HerreraCustomerCategoryDiscountImportService;
use App\Services\Pricing\CustomerDiscountService;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class HerreraCustomerCategoryDiscountImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        app()->setLocale('hr');
    }

    public function test_preview_is_read_only_and_import_creates_one_rule_for_the_original_mapped_category(): void
    {
        [$admin, $customer, $group, $catalog, $category, $otherCategory] = $this->fixture();
        // An identically discounted product elsewhere is not evidence for a category rule.
        $this->product($catalog, $group, $otherCategory, 'LOOKALIKE');
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $this->source(601, ['amount' => '60.0000']);
        $entries = $catalog->entries()->get()->toArray();
        $sourceRows = DB::table('herrera_source_records')->get()->all();
        $service = app(HerreraCustomerCategoryDiscountImportService::class);

        $preview = $service->preview();
        $this->assertSame(1, $preview['planned']);
        $this->assertDatabaseCount('customer_discount_rules', 0);
        $result = $service->import($admin);

        $this->assertSame(1, $result['created']);
        $rule = CustomerDiscountRule::query()->sole();
        $this->assertSame($customer->id, $rule->user_id);
        $this->assertSame([$category->id], $rule->category_ids);
        $this->assertSame([], $rule->manufacturer_ids);
        $this->assertFalse($rule->include_descendants);
        $this->assertSame('60.0000', $rule->percent);
        $this->assertStringContainsString('Cerade', $rule->name);
        $this->assertStringContainsString('60', $rule->name);
        $this->assertSame('opencart', $rule->source_system);
        $this->assertSame('opencart:customer-category:77:901', $rule->source_key);
        $this->assertSame(77, data_get($rule->payload, 'opencart.customer_id'));
        $this->assertSame(901, data_get($rule->payload, 'opencart.category_id'));
        $this->assertSame(601, data_get($rule->payload, 'opencart.transaction_id'));
        $this->assertSame($group->id, $customer->fresh()->b2bAccount->customer_group_id);
        $this->assertSame($entries, $catalog->entries()->get()->toArray());
        $this->assertEquals($sourceRows, DB::table('herrera_source_records')->get()->all());
    }

    public function test_reruns_do_not_duplicate_category_rules_or_generated_product_prices(): void
    {
        [$admin, $customer, $group, $catalog, $category] = $this->fixture();
        $this->product($catalog, $group, $category, 'CERADE-01');
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $this->source(601);
        $service = app(HerreraCustomerCategoryDiscountImportService::class);

        $this->assertSame(1, $service->import($admin)['created']);
        $rule = CustomerDiscountRule::query()->sole();
        $original = $rule->getAttributes();
        $preview = $service->preview();
        $this->assertSame(0, $preview['planned']);
        $this->assertSame(1, $preview['existing']);
        $again = $service->import($admin);

        $this->assertSame(0, $again['created']);
        $this->assertSame(1, $again['existing']);
        $this->assertDatabaseCount('customer_discount_rules', 1);
        $this->assertSame($original, $rule->fresh()->getAttributes());
        $this->assertSame([$category->id], $rule->fresh()->category_ids);
        $this->assertDatabaseMissing('catalog_price_entries', ['user_id' => $customer->id]);
    }

    public function test_category_rule_automatically_follows_new_and_reclassified_products_and_only_the_mapped_buyer(): void
    {
        [$admin, $customer, $group, $catalog, $category, $otherCategory] = $this->fixture();
        $existing = $this->product($catalog, $group, $otherCategory, 'MOVE-IN');
        $childCategory = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG, 'code' => 'child', 'parent_id' => $category->id, 'is_active' => true,
        ]);
        $childProduct = $this->product($catalog, $group, $childCategory, 'CHILD-ONLY');
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $this->source(601);
        app(HerreraCustomerCategoryDiscountImportService::class)->import($admin);

        $new = Product::query()->create(['code' => 'NEW-CERADE', 'sku' => 'NEW-CERADE', 'base_price' => '100', 'is_active' => true]);
        $new->categories()->attach($category);
        $otherBuyer = $this->buyer($group);
        $this->assertSame(40.0, $this->price($new, $customer));
        $this->assertSame(100.0, $this->price($new, $otherBuyer));
        $this->assertSame(50.0, $this->price($childProduct, $customer));
        $this->assertSame(50.0, $this->price($existing, $customer));

        $existing->categories()->attach($category);
        $this->assertSame(40.0, $this->price($existing, $customer));
        $existing->categories()->detach($category);
        $this->assertSame(50.0, $this->price($existing, $customer));
        $new->categories()->sync([$otherCategory->id]);
        $this->assertSame(100.0, $this->price($new, $customer));
        $this->assertDatabaseCount('customer_discount_rules', 1);
        $this->assertDatabaseMissing('catalog_price_entries', ['user_id' => $customer->id]);
    }

    public function test_repeated_original_category_definitions_become_one_rule_using_the_latest_transaction(): void
    {
        [$admin, $customer, , , $category] = $this->fixture();
        $this->source(602, ['amount' => '65']);
        $this->source(601, ['amount' => '60']);

        $result = app(HerreraCustomerCategoryDiscountImportService::class)->import($admin);

        $this->assertSame(1, $result['created']);
        $rule = CustomerDiscountRule::query()->sole();
        $this->assertSame($customer->id, $rule->user_id);
        $this->assertSame([$category->id], $rule->category_ids);
        $this->assertSame('65.0000', $rule->percent);
        $this->assertSame(602, data_get($rule->payload, 'opencart.transaction_id'));
    }

    public function test_new_customer_category_agreement_takes_precedence_over_imported_original_rule(): void
    {
        [$admin, $customer, $group, $catalog, $category] = $this->fixture();
        $product = $this->product($catalog, $group, $category, 'CERADE-01');
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $this->source(601);
        app(HerreraCustomerCategoryDiscountImportService::class)->import($admin);
        $native = app(CustomerDiscountService::class)->saveRule($customer, [
            'name' => 'Cerade novi ugovor 70 %', 'percent' => '70', 'category_ids' => [$category->id],
        ], $admin);

        $this->assertSame(30.0, $this->price($product, $customer));
        $this->assertSame($native->id, app(PriceCatalogResolver::class)->resolve($product->fresh(), $customer->fresh())?->rule_id);
        $this->assertSame(0, app(HerreraCustomerCategoryDiscountImportService::class)->import($admin)['created']);
        $this->assertSame(30.0, $this->price($product, $customer));
    }

    public function test_customer_edited_and_deleted_imported_rules_are_preserved_on_rerun(): void
    {
        [$admin, $customer, $group, $catalog, $category, $otherCategory] = $this->fixture();
        $this->product($catalog, $group, $category, 'CERADE-01');
        $this->map('category', 902, $otherCategory->id);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $this->source(601);
        $this->source(602, ['description' => '902', 'amount' => '55']);
        $importer = app(HerreraCustomerCategoryDiscountImportService::class);
        $this->assertSame(2, $importer->import($admin)['created']);
        $edited = CustomerDiscountRule::query()->where('source_key', 'opencart:customer-category:77:901')->sole();
        $deleted = CustomerDiscountRule::query()->where('source_key', 'opencart:customer-category:77:902')->sole();
        $data = $edited->toArray();
        $data['name'] = 'Cerade ugovoreno 65 %';
        $data['percent'] = '65';
        app(CustomerDiscountService::class)->saveRule($customer, $data, $admin, $edited->id);
        app(CustomerDiscountService::class)->deleteRule($customer, $deleted->id, $admin);

        $rerun = $importer->import($admin);

        $this->assertSame(0, $rerun['created']);
        $this->assertGreaterThanOrEqual(2, $rerun['protected']);
        $this->assertSame(1, CustomerDiscountRule::query()->count());
        $this->assertSame(2, CustomerDiscountRule::withTrashed()->count());
        $this->assertSame('65.0000', $edited->fresh()->percent);
        $this->assertSame('Cerade ugovoreno 65 %', $edited->fresh()->name);
        $this->assertSoftDeleted($deleted);
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_source_rows_are_reported_without_creating_a_broader_rule(array $changes): void
    {
        [$admin] = $this->fixture();
        $this->source(601, $changes);
        $result = app(HerreraCustomerCategoryDiscountImportService::class)->import($admin);

        $this->assertSame(0, $result['created']);
        $this->assertGreaterThanOrEqual(1, $result['invalid']);
        $this->assertDatabaseCount('customer_discount_rules', 0);
    }

    public static function invalidRows(): array
    {
        return [
            'category name is not an original ID' => [['description' => 'Cerade']],
            'empty category does not mean every category' => [['description' => '']],
            'percentage above one hundred' => [['amount' => '101']],
            'negative percentage' => [['amount' => '-5']],
            'not a percentage' => [['amount' => 'invalid']],
            'order credit is not a category discount' => [['order_id' => 21]],
        ];
    }

    #[DataProvider('missingMappings')]
    public function test_unmapped_categories_and_customers_are_reported_and_never_guessed(string $entity): void
    {
        [$admin] = $this->fixture();
        DB::table('herrera_import_maps')->where('entity', $entity)->delete();
        $this->source(601);
        $result = app(HerreraCustomerCategoryDiscountImportService::class)->import($admin);

        $this->assertSame(0, $result['created']);
        $this->assertGreaterThanOrEqual(1, $result['unmapped']);
        $this->assertDatabaseCount('customer_discount_rules', 0);
    }

    public static function missingMappings(): array
    {
        return [['customer'], ['category']];
    }

    public function test_a_category_mapping_outside_the_catalog_is_not_imported_as_an_unrestricted_rule(): void
    {
        [$admin, , , , $category] = $this->fixture();
        $category->update(['scope' => Category::SCOPE_BLOG]);
        $this->source(601);

        $result = app(HerreraCustomerCategoryDiscountImportService::class)->import($admin);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['unmapped']);
        $this->assertDatabaseCount('customer_discount_rules', 0);
    }

    public function test_imported_category_is_visible_and_editable_in_the_customer_pricing_tab(): void
    {
        [$admin, $customer, $group, $catalog, $category] = $this->fixture();
        $product = $this->product($catalog, $group, $category, 'CERADE-01');
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $this->source(601);
        app(HerreraCustomerCategoryDiscountImportService::class)->import($admin);
        $rule = CustomerDiscountRule::query()->sole();

        Livewire::actingAs($admin)->test(CustomerPriceManager::class, ['userId' => $customer->id])
            ->assertSee('Cerade')->assertSee('60 %')
            ->assertSee('Izvorni popust po kategoriji sa stare stranice')
            ->call('editRule', $rule->id)
            ->assertSet('ruleForm.category_ids', [$category->id])
            ->assertSet('ruleForm.include_descendants', false)
            ->set('ruleForm.percent', '65')->call('saveRule')->assertHasNoErrors();

        $this->assertSame(35.0, $this->price($product, $customer));
        $this->assertSame(0, app(HerreraCustomerCategoryDiscountImportService::class)->import($admin)['created']);
        $this->assertSame('65.0000', $rule->fresh()->percent);
    }

    private function fixture(): array
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        $group = CustomerGroup::query()->create(['code' => 'b2b50', 'name' => 'B2B50', 'is_active' => true]);
        $customer = $this->buyer($group);
        $category = Category::query()->create(['code' => 'tarpaulins', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => 'Cerade', 'slug' => 'cerade']);
        $otherCategory = Category::query()->create(['code' => 'covers', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $catalog = app(PriceCatalogService::class)->createDraft('Izvorni B2B cjenik', $admin);
        $this->map('customer', 77, $customer->id);
        $this->map('category', 901, $category->id);

        return [$admin, $customer, $group, $catalog, $category, $otherCategory];
    }

    private function buyer(CustomerGroup $group): User
    {
        $customer = User::factory()->create();
        B2BAccount::query()->create([
            'user_id' => $customer->id, 'company_name' => 'Test Company '.$customer->id,
            'oib' => str_pad((string) $customer->id, 11, '0', STR_PAD_LEFT),
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id,
        ]);

        return $customer;
    }

    private function product(PriceCatalog $catalog, CustomerGroup $group, Category $category, string $code): Product
    {
        $product = Product::query()->create(['code' => $code, 'sku' => $code, 'base_price' => '100', 'is_active' => true]);
        $product->categories()->attach($category);
        foreach ([PriceCatalogEntry::BASE => 100, PriceCatalogEntry::GROUP => 50] as $kind => $price) {
            $catalog->entries()->create([
                'source_key' => 'fixture:'.$code.':'.$kind, 'kind' => $kind, 'product_id' => $product->id,
                'customer_group_id' => $kind === PriceCatalogEntry::GROUP ? $group->id : null,
                'price' => $price, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true,
            ]);
        }

        return $product;
    }

    private function source(int $id, array $changes = []): void
    {
        $payload = array_replace([
            'customer_transaction_id' => $id, 'customer_id' => 77, 'description' => '901',
            'amount' => '60', 'order_id' => 0, 'date_added' => '2019-01-02 12:00:00',
        ], $changes);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        DB::table('herrera_source_records')->updateOrInsert(['source_table' => 'customer_transaction', 'source_key' => (string) $id], [
            'checksum' => hash('sha256', $json), 'payload' => $json, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function map(string $entity, int $sourceId, int $targetId): void
    {
        DB::table('herrera_import_maps')->insert([
            'source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => (string) $sourceId,
            'target_id' => $targetId, 'checksum' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function price(Product $product, User $customer): ?float
    {
        return app(PriceCatalogResolver::class)->resolve($product->fresh(), $customer->fresh())?->price;
    }
}
