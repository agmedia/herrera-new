<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Catalog\Product\ProductPackage;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ProductFormWorkflowFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_create_and_edit_product_pages(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct();

        $this->actingAs($admin)->get(route('admin.products.create'))
            ->assertOk()->assertSeeLivewire(ProductForm::class);
        $this->get(route('admin.products.edit', $product))
            ->assertOk()->assertSeeLivewire(ProductForm::class);
        $this->get(route('admin.products.edit', 999999))->assertNotFound();
    }

    public function test_product_editor_requires_the_correct_permissions(): void
    {
        $product = $this->makeProduct();
        $this->get(route('admin.products.create'))->assertRedirect(route('login'));

        $customer = User::factory()->create();
        Bouncer::assign('customer')->to($customer);
        $this->actingAs($customer)->get(route('admin.products.create'))->assertForbidden();
        $this->get(route('admin.products.edit', $product))->assertForbidden();

        $viewer = User::factory()->create();
        Bouncer::allow($viewer)->to(['admin.access', 'catalog.products.view']);
        $this->actingAs($viewer)->get(route('admin.products'))->assertOk();
        $this->get(route('admin.products.create'))->assertForbidden();
        $this->get(route('admin.products.edit', $product))->assertForbidden();

        Bouncer::allow($viewer)->to('catalog.products.create');
        Bouncer::refreshFor($viewer);
        $this->get(route('admin.products.create'))->assertOk();
        $this->get(route('admin.products.edit', $product))->assertForbidden();
    }

    public function test_product_identity_cannot_be_changed_by_a_livewire_request(): void
    {
        $component = Livewire::actingAs($this->makeAdmin())->test(ProductForm::class);
        $product = $this->makeProduct();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('productId', $product->id);
    }

    public function test_required_errors_use_the_visible_localized_field_labels(): void
    {
        app()->setLocale('hr');

        $component = Livewire::actingAs($this->makeAdmin())->test(ProductForm::class)
            ->call('save')->assertHasErrors([
                'form.code' => 'required', 'form.name' => 'required', 'form.slug' => 'required',
            ]);

        foreach (['form.code' => 'Code', 'form.name' => 'Name', 'form.slug' => 'Slug'] as $field => $label) {
            $component->assertSee(__('validation.required', ['attribute' => __($label)]))
                ->assertDontSee(__('validation.required', ['attribute' => $field]));
        }
    }

    public function test_create_saves_translation_ordered_categories_tax_and_audit_fields(): void
    {
        $admin = $this->makeAdmin();
        $tax = TaxRate::query()->create([
            'code' => 'workflow-tax', 'name' => 'PDV 25%', 'rate_type' => 'percent',
            'rate' => 25, 'is_default' => true, 'is_active' => true,
        ]);
        $first = $this->makeCategory('first');
        $second = $this->makeCategory('second');

        $component = Livewire::withQueryParams(['locale' => 'hr'])->actingAs($admin)
            ->test(ProductForm::class)->assertSet('form.tax_rate_id', $tax->id)
            ->set('form.code', '  NEW-ARTICLE  ')
            ->set('form.sku', '  NEW-SKU  ')
            ->set('form.barcode', '  3851234567890  ')
            ->set('form.name', 'Novi artikl')
            ->call('generateSlug')->assertSet('form.slug', 'novi-artikl')
            ->set('form.excerpt', 'Kratki opis')
            ->set('form.description', '<p>Opis artikla</p>')
            ->set('form.meta_title', 'Novi artikl | Herrera')
            ->set('form.meta_description', 'Opis za tražilice')
            ->set('form.base_price', 125.5)->set('form.stock_qty', 7)
            ->set('form.category_ids', [$second->id, $first->id])
            ->set('form.payload_text', '{"workflow":"created"}')
            ->set('form.translation_payload_text', '{"source":"manual"}')
            ->call('save')->assertHasNoErrors()->assertSessionHas('notify.type', 'success');

        $product = Product::query()->where('code', 'NEW-ARTICLE')->firstOrFail();
        $component->assertRedirect(route('admin.products.edit', ['product' => $product->id, 'locale' => 'hr']));
        $this->assertSame('NEW-SKU', $product->sku);
        $this->assertSame('3851234567890', $product->barcode);
        $this->assertSame($tax->id, $product->tax_rate_id);
        $this->assertSame($admin->id, $product->created_by);
        $this->assertSame($admin->id, $product->updated_by);
        $this->assertSame(['workflow' => 'created'], $product->payload);
        $this->assertDatabaseHas('product_translations', [
            'product_id' => $product->id, 'locale' => 'hr', 'name' => 'Novi artikl',
            'slug' => 'novi-artikl', 'meta_title' => 'Novi artikl | Herrera',
        ]);
        $this->assertSame(['source' => 'manual'], $product->translations()->firstOrFail()->payload);
        $this->assertDatabaseHas('category_product', [
            'product_id' => $product->id, 'category_id' => $second->id, 'sort_order' => 0, 'is_primary' => true,
        ]);
        $this->assertDatabaseHas('category_product', [
            'product_id' => $product->id, 'category_id' => $first->id, 'sort_order' => 1, 'is_primary' => false,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'catalog_products', 'subject_id' => $product->id,
            'causer_id' => $admin->id, 'event' => 'created',
        ]);
    }

    public function test_edit_keeps_other_translations_and_supplier_stock(): void
    {
        $product = $this->makeProduct(['supplier_stock_qty' => 19]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Stari naziv', 'slug' => 'stari-naziv']);
        $category = $this->makeCategory('removed');
        $product->categories()->attach($category->id, ['is_primary' => true]);

        Livewire::withQueryParams(['locale' => 'hr'])->actingAs($this->makeAdmin())
            ->test(ProductForm::class, ['productId' => $product->id])
            ->assertSet('form.name', 'Stari naziv')
            ->set('form.name', 'Novi naziv')->set('form.slug', 'novi-naziv')
            ->set('form.base_price', 87.25)->set('form.stock_qty', 4)
            ->set('form.category_ids', [])
            ->call('save')->assertHasNoErrors()
            ->assertRedirect(route('admin.products.edit', ['product' => $product->id, 'locale' => 'hr']));

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_translations', 2);
        $this->assertDatabaseHas('product_translations', ['product_id' => $product->id, 'locale' => 'en', 'name' => 'Original article']);
        $this->assertDatabaseHas('product_translations', ['product_id' => $product->id, 'locale' => 'hr', 'name' => 'Novi naziv']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 4, 'supplier_stock_qty' => 19, 'base_price' => 87.25]);
        $this->assertDatabaseMissing('category_product', ['product_id' => $product->id]);
    }

    #[DataProvider('invalidCoreFields')]
    public function test_invalid_product_data_does_not_save_partial_records(string $field, mixed $value, ?string $errorField = null): void
    {
        $this->newProductComponent()->set($field, $value)->call('save')->assertHasErrors([$errorField ?? $field]);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_translations', 0);
        $this->assertDatabaseCount('catalog_product_price_history', 0);
    }

    public static function invalidCoreFields(): array
    {
        return [
            'required code' => ['form.code', '   '],
            'required name' => ['form.name', ''],
            'invalid slug' => ['form.slug', 'Invalid slug!'],
            'negative price' => ['form.base_price', -1],
            'fractional stock' => ['form.stock_qty', 1.5],
            'zero minimum quantity' => ['form.minimum_order_quantity', 0],
            'invalid tax' => ['form.tax_rate_id', 999999],
            'invalid category' => ['form.category_ids', [999999], 'form.category_ids.0'],
        ];
    }

    #[DataProvider('duplicateIdentifiers')]
    public function test_duplicate_identifiers_with_whitespace_produce_validation_errors(string $field, string $value): void
    {
        $this->makeProduct(['code' => 'EXISTING', 'sku' => 'EXISTING-SKU', 'barcode' => '3851111111111']);

        $this->newProductComponent()->set($field, '  '.$value.'  ')
            ->call('save')->assertHasErrors([$field => 'unique']);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_translations', 1);
    }

    public static function duplicateIdentifiers(): array
    {
        return [
            'code' => ['form.code', 'EXISTING'],
            'sku' => ['form.sku', 'EXISTING-SKU'],
            'barcode' => ['form.barcode', '3851111111111'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_json_does_not_save_a_product(string $field, string $value): void
    {
        $this->newProductComponent()->set($field, $value)->call('save')->assertHasErrors([$field]);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_translations', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            'broken product JSON' => ['form.payload_text', '{"broken":'],
            'scalar product JSON' => ['form.payload_text', 'true'],
            'broken translation JSON' => ['form.translation_payload_text', '{'],
            'scalar translation JSON' => ['form.translation_payload_text', '123'],
        ];
    }

    public function test_slug_must_be_unique_within_the_selected_locale(): void
    {
        $product = $this->makeProduct();
        $this->newProductComponent()->set('form.slug', 'original-article')
            ->call('save')->assertHasErrors(['form.slug' => 'unique']);

        $this->newProductComponent()->set('form.locale', 'hr')
            ->set('form.name', 'Hrvatski artikl')->set('form.slug', 'original-article')
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('product_translations', ['product_id' => $product->id, 'locale' => 'en', 'slug' => 'original-article']);
    }

    public function test_foreign_package_ids_are_rejected_before_a_barcode_conflict(): void
    {
        $other = $this->makeProduct();
        $package = ProductPackage::query()->create([
            'product_id' => $other->id, 'code' => 'OTHER-BOX', 'name' => 'Other box',
            'barcode' => '3852222222222', 'quantity' => 10,
        ]);

        $this->newProductComponent()->set('packages', [$this->packageRow([
            'id' => $package->id, 'barcode' => $package->barcode,
        ])])->call('save')->assertHasErrors(['packages.0.id']);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('catalog_product_packages', 1);
        $this->assertSame($other->id, $package->fresh()->product_id);
    }

    public function test_duplicate_package_ids_do_not_overwrite_the_same_package_twice(): void
    {
        $product = $this->makeProduct();
        $package = ProductPackage::query()->create([
            'product_id' => $product->id, 'code' => 'BOX', 'name' => 'Box', 'quantity' => 10,
        ]);

        Livewire::actingAs($this->makeAdmin())->test(ProductForm::class, ['productId' => $product->id])
            ->set('packages', [
                $this->packageRow(['id' => $package->id, 'code' => 'FIRST']),
                $this->packageRow(['id' => $package->id, 'code' => 'SECOND']),
            ])->call('save')->assertHasErrors(['packages.1.id']);

        $this->assertSame('BOX', $package->fresh()->code);
    }

    public function test_foreign_price_ids_do_not_create_a_new_price_on_another_product(): void
    {
        $other = $this->makeProduct();
        $group = $this->makeCustomerGroup();
        $price = ProductGroupPrice::query()->create([
            'product_id' => $other->id, 'customer_group_id' => $group->id,
            'price' => 45, 'minimum_quantity' => 1, 'currency_code' => 'EUR',
        ]);

        $this->newProductComponent()->set('groupPrices', [$this->priceRow($group, ['id' => $price->id])])
            ->call('save')->assertHasErrors(['groupPrices.0.id']);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('catalog_product_group_prices', 1);
        $this->assertSame(45.0, (float) $price->fresh()->price);
    }

    public function test_duplicate_price_ids_do_not_silently_overwrite_the_first_row(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeCustomerGroup();
        $price = ProductGroupPrice::query()->create([
            'product_id' => $product->id, 'customer_group_id' => $group->id,
            'price' => 45, 'minimum_quantity' => 1, 'currency_code' => 'EUR',
        ]);

        Livewire::actingAs($this->makeAdmin())->test(ProductForm::class, ['productId' => $product->id])
            ->set('groupPrices', [
                $this->priceRow($group, ['id' => $price->id, 'price' => 30]),
                $this->priceRow($group, ['id' => $price->id, 'price' => 20, 'minimum_quantity' => 10]),
            ])->call('save')->assertHasErrors(['groupPrices.1.id']);

        $this->assertDatabaseCount('catalog_product_group_prices', 1);
        $this->assertSame(45.0, (float) $price->fresh()->price);
    }

    public function test_edit_updates_and_removes_packages_and_prices_without_duplicating_records(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeCustomerGroup();
        $retainedPackage = ProductPackage::query()->create([
            'product_id' => $product->id, 'code' => 'BOX', 'name' => 'Old box',
            'barcode' => '3853333333333', 'quantity' => 10, 'is_default' => true,
        ]);
        $removedPackage = ProductPackage::query()->create([
            'product_id' => $product->id, 'code' => 'PALLET', 'name' => 'Pallet', 'quantity' => 100,
        ]);
        $retainedPrice = ProductGroupPrice::query()->create([
            'product_id' => $product->id, 'customer_group_id' => $group->id,
            'product_package_id' => $retainedPackage->id,
            'price' => 45, 'minimum_quantity' => 1, 'currency_code' => 'EUR',
        ]);
        $removedPrice = ProductGroupPrice::query()->create([
            'product_id' => $product->id, 'customer_group_id' => $group->id,
            'product_package_id' => $removedPackage->id,
            'price' => 35, 'minimum_quantity' => 10, 'currency_code' => 'EUR',
        ]);

        Livewire::actingAs($this->makeAdmin())->test(ProductForm::class, ['productId' => $product->id])
            ->set('packages', [$this->packageRow([
                'id' => $retainedPackage->id, 'name' => 'New box', 'barcode' => $retainedPackage->barcode,
                'quantity' => 12,
            ])])->set('groupPrices', [$this->priceRow($group, [
                'id' => $retainedPrice->id, 'package_code' => 'box', 'price' => 40,
            ])])->call('save')->assertHasNoErrors();

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('catalog_product_packages', 1);
        $this->assertDatabaseCount('catalog_product_group_prices', 1);
        $this->assertDatabaseHas('catalog_product_packages', [
            'id' => $retainedPackage->id, 'product_id' => $product->id, 'name' => 'New box', 'quantity' => 12,
        ]);
        $this->assertDatabaseHas('catalog_product_group_prices', [
            'id' => $retainedPrice->id, 'product_id' => $product->id,
            'product_package_id' => $retainedPackage->id, 'price' => 40,
        ]);
        $this->assertDatabaseMissing('catalog_product_packages', ['id' => $removedPackage->id]);
        $this->assertDatabaseMissing('catalog_product_group_prices', ['id' => $removedPrice->id]);
    }

    public function test_invalid_commerce_rows_report_errors_without_creating_the_product(): void
    {
        $group = $this->makeCustomerGroup();
        $this->newProductComponent()->set('packages', [
            $this->packageRow(['code' => 'BOX']),
            $this->packageRow(['code' => ' box ']),
        ])->set('groupPrices', [$this->priceRow($group, [
            'package_code' => 'MISSING', 'starts_at' => '2026-10-08', 'ends_at' => '2026-10-07',
        ])])->call('save')->assertHasErrors([
            'packages.1.code', 'groupPrices.0.package_code', 'groupPrices.0.ends_at',
        ]);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('catalog_product_packages', 0);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
    }

    private function newProductComponent()
    {
        return Livewire::actingAs($this->makeAdmin())->test(ProductForm::class)
            ->set('form.locale', 'en')->set('form.code', 'NEW-PRODUCT')
            ->set('form.name', 'New product')->set('form.slug', 'new-product')
            ->set('form.base_price', 55.5)->set('form.stock_qty', 3);
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);

        return $admin;
    }

    private function makeProduct(array $attributes = []): Product
    {
        $product = Product::query()->create(array_replace([
            'code' => 'ORIGINAL', 'sku' => 'ORIGINAL-SKU', 'is_active' => true,
            'base_price' => 50, 'stock_qty' => 6,
        ], $attributes));
        $product->translations()->create([
            'locale' => 'en', 'name' => 'Original article', 'slug' => 'original-article',
        ]);

        return $product;
    }

    private function makeCategory(string $code): Category
    {
        $category = new Category(['scope' => Category::SCOPE_CATALOG, 'code' => $code, 'is_active' => true]);
        $category->saveAsRoot();
        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG, 'locale' => 'hr', 'name' => $code, 'slug' => $code,
        ]);

        return $category;
    }

    private function makeCustomerGroup(): CustomerGroup
    {
        return CustomerGroup::query()->create(['code' => 'workflow-b2b', 'name' => 'B2B', 'is_active' => true]);
    }

    private function packageRow(array $overrides = []): array
    {
        return array_replace([
            'id' => null, 'code' => 'BOX', 'name' => 'Box', 'barcode' => '',
            'package_type' => 'box', 'unit_of_measure' => 'pcs', 'quantity' => 10,
            'is_default' => true, 'is_active' => true,
        ], $overrides);
    }

    private function priceRow(CustomerGroup $group, array $overrides = []): array
    {
        return array_replace([
            'id' => null, 'customer_group_id' => $group->id, 'package_code' => '',
            'minimum_quantity' => 1, 'price' => 30, 'currency_code' => 'EUR',
            'starts_at' => '', 'ends_at' => '', 'is_active' => true,
        ], $overrides);
    }
}
