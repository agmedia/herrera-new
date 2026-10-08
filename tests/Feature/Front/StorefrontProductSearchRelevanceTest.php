<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\StorefrontProductSearch;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorefrontProductSearchRelevanceTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'store_search_autocomplete_enabled' => true,
            'store_search_autocomplete_categories_enabled' => false,
            'store_search_autocomplete_manufacturers_enabled' => false,
            'store_search_autocomplete_blog_enabled' => false,
            'catalog_hide_out_of_stock_products' => false,
        ]);
        $this->category = Category::query()->create(['code' => 'search', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $this->category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => 'Search', 'slug' => 'search']);
    }

    public function test_fans_rank_by_name_and_description_only_dehydrators_never_enter_catalog_or_autocomplete(): void
    {
        $exact = $this->product('exact', 'Ventilator', ['sku' => 'ventilator']);
        $fan = $this->product('fan', 'Ventilator stropni bijeli');
        $heater = $this->product('heater', 'Ventilatorska grijalica');
        $dehydrator = $this->product('dehydrator', 'Dehidrator hrane');
        $dehydrator->translations()->update(['excerpt' => 'Ugrađeni ventilator', 'description' => '<p>Ventilator za cirkulaciju zraka</p>']);
        $this->product('inactive', 'Ventilator kupaonski', ['is_active' => false]);
        $expected = [$exact->id, $fan->id, $heater->id];

        foreach ([route('shop.index', ['q' => 'ventilator']), route('categories.show', ['slug' => 'search', 'q' => 'ventilator'])] as $url) {
            $this->get($url)->assertOk()
                ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === $expected && $products->total() === 3)
                ->assertViewHas('filters', fn ($filters) => $filters['sort'] === 'relevance')
                ->assertSee('Najrelevantnije prvo')->assertDontSee('Dehidrator hrane')
                ->assertDontSee('137.49', false)->assertDontSee('data-product-card-form', false);
        }
        $this->getJson(route('search.autocomplete', ['q' => 'ventilator']))->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('items.0.id', $exact->id)
            ->assertJsonPath('items.1.id', $fan->id)->assertJsonPath('items.2.id', $heater->id)
            ->assertJsonPath('items.0.price', null)->assertJsonPath('items.0.old_price', null);
    }

    public function test_natural_multiword_search_accepts_reversed_name_words_and_short_prefixes_stay_at_the_start(): void
    {
        $reverse = $this->product('reverse', 'Ventilator stropni bijeli');
        $phrase = $this->product('phrase', 'Stropni ventilator crni');
        $this->product('single-word', 'Ventilator stolni');
        $this->product('incidental', 'Adapter za cirkulaciju zraka');
        $this->get(route('shop.index', ['q' => '  stropni   ventilator  ']))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$phrase->id, $reverse->id])
            ->assertViewHas('filters', fn ($filters) => $filters['q'] === 'stropni ventilator');

        $this->get(route('shop.index', ['q' => 've']))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->contains($reverse->id)
                && ! $products->pluck('id')->contains($phrase->id));
    }

    #[DataProvider('croatianProductWordForms')]
    public function test_croatian_singular_and_plural_names_match_in_catalog_and_header_search(array $forms, string $unrelatedName): void
    {
        $expected = [];
        foreach ($forms as $index => $form) {
            $expected[] = $this->product('form-'.$index, 'Katalog '.$form)->id;
        }
        sort($expected);
        $this->product('unrelated', $unrelatedName);
        $this->product('hidden-form', 'Katalog '.$forms[0], ['is_active' => false]);

        foreach ($forms as $search) {
            $this->get(route('shop.index', ['q' => $search]))->assertOk()
                ->assertViewHas('products', fn ($products) => $products->pluck('id')->sort()->values()->all() === $expected
                    && $products->total() === count($expected));
            $response = $this->getJson(route('search.autocomplete', ['q' => $search]))->assertOk()
                ->assertJsonPath('query', $search)
                ->assertJsonPath('groups.products.total', count($expected))
                ->assertJsonCount(count($expected), 'items');
            $this->assertSame($expected, collect($response->json('items'))->pluck('id')->sort()->values()->all());
        }
    }

    public static function croatianProductWordForms(): array
    {
        return [
            'clothing' => [['majica', 'majice'], 'Majicinski mjerač'],
            'lighting with Croatian characters' => [['žarulja', 'žarulje'], 'LED žaruljometar'],
            'irregular cable plural' => [['kabel', 'kabeli', 'kablovi'], 'Kabliranje ploče'],
        ];
    }

    public function test_croatian_multiword_forms_require_every_word_and_keep_literal_names_first(): void
    {
        $singular = $this->product('singular', 'Radna majica pamučna');
        $plural = $this->product('plural', 'Radne majice pamučne');
        $this->product('wrong-noun', 'Radne rukavice pamučne');
        $this->product('wrong-use', 'Sportske majice pamučne');
        $this->product('wrong-material', 'Radne majice poliesterske');

        foreach (['radne majice pamučne' => [$plural->id, $singular->id], 'radna majica pamučna' => [$singular->id, $plural->id]] as $search => $ids) {
            foreach ([route('shop.index', ['q' => $search]), route('categories.show', ['slug' => 'search', 'q' => $search])] as $url) {
                $this->get($url)->assertOk()
                    ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === $ids && $products->total() === 2);
            }
            $response = $this->getJson(route('search.autocomplete', ['q' => $search]))->assertOk()->assertJsonCount(2, 'items');
            $this->assertSame($ids, collect($response->json('items'))->pluck('id')->all());
        }
    }

    public function test_croatian_forms_leave_identifiers_literal_and_exact_sku_ahead_of_name_matches(): void
    {
        $identifier = $this->product('identifier-form', 'Rezervni dio', ['sku' => 'MAJICE']);
        $literalName = $this->product('literal-form', 'Majice');
        $relatedName = $this->product('related-form', 'Majica');
        $this->product('other-identifier-form', 'Drugi rezervni dio', ['sku' => 'MAJICA']);
        $ids = [$identifier->id, $literalName->id, $relatedName->id];
        $this->get(route('shop.index', ['q' => 'majice']))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === $ids);
        $response = $this->getJson(route('search.autocomplete', ['q' => 'majice']))->assertOk()->assertJsonCount(3, 'items');
        $this->assertSame($ids, collect($response->json('items'))->pluck('id')->all());

        $exactSku = $this->product('exact-form-sku', 'Treći rezervni dio', ['sku' => 'MAJICE-42']);
        $this->product('different-form-sku', 'Četvrti rezervni dio', ['sku' => 'MAJICA-42']);
        $this->get(route('shop.index', ['q' => 'MAJICE-42']))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$exactSku->id]);
        $this->getJson(route('search.autocomplete', ['q' => 'MAJICE-42']))->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $exactSku->id);
    }

    public function test_croatian_forms_are_disabled_when_searching_only_other_locales(): void
    {
        $product = $this->product('english-only', 'Majica');
        $product->translations()->create(['locale' => 'en', 'name' => 'Majica', 'slug' => 'english-only-en']);
        $query = Product::query()->visibleOnStorefront(false);
        app(StorefrontProductSearch::class)->apply($query, 'en', 'en', 'majice');
        $this->assertSame([], $query->pluck('products.id')->all());
    }

    public function test_croatian_category_forms_match_both_directions_without_changing_visibility_order_or_slugs(): void
    {
        app(SystemSettingsService::class)->put('store_search_autocomplete_categories_enabled', true);
        $singular = $this->searchCategory('category-singular', 'Majica', ['sort_order' => 20]);
        $plural = $this->searchCategory('category-plural', 'Majice', ['sort_order' => 10]);
        $this->searchCategory('unrelated-category', 'Majicinski pribor');
        $this->searchCategory('hidden-category', 'Majice', ['is_active' => false]);
        $this->searchCategory('future-category', 'Majice', ['starts_at' => now()->addDay()]);
        $this->searchCategory('blog-category', 'Majice', ['scope' => Category::SCOPE_BLOG]);
        $this->searchCategory('majice', 'Odjeća');

        foreach (['majica', 'majice'] as $search) {
            $response = $this->getJson(route('search.autocomplete', ['q' => $search]))->assertOk()
                ->assertJsonPath('groups.categories.total', 2)
                ->assertJsonCount(2, 'groups.categories.items')
                ->assertJsonPath('groups.categories.items.0.url', route('categories.show', ['slug' => 'category-plural']))
                ->assertJsonPath('groups.categories.items.1.url', route('categories.show', ['slug' => 'category-singular']));
            $this->assertSame([$plural->id, $singular->id], collect($response->json('groups.categories.items'))->pluck('id')->all());
        }
    }

    public function test_croatian_category_multiword_search_requires_each_term_and_non_croatian_names_remain_literal(): void
    {
        app(SystemSettingsService::class)->put('store_search_autocomplete_categories_enabled', true);
        $singular = $this->searchCategory('working-shirt', 'Radna majica pamučna');
        $plural = $this->searchCategory('working-shirts', 'Radne pamučne majice');
        $this->searchCategory('working-gloves', 'Radne rukavice pamučne');
        $this->searchCategory('sports-shirts', 'Sportske majice pamučne');

        $response = $this->getJson(route('search.autocomplete', ['q' => 'radne majice pamučne']))->assertOk()
            ->assertJsonPath('groups.categories.total', 2)->assertJsonCount(2, 'groups.categories.items');
        $this->assertSame([$singular->id, $plural->id], collect($response->json('groups.categories.items'))->pluck('id')->all());

        $singular->translations()->create(['locale' => 'en', 'scope' => Category::SCOPE_CATALOG, 'name' => 'Majica', 'slug' => 'english-shirt']);
        config(['app.locale' => 'en', 'app.fallback_locale' => 'en']);
        app()->setLocale('en');
        $this->getJson(route('search.autocomplete', ['q' => 'majice']))->assertOk()
            ->assertJsonCount(0, 'groups.categories.items');
    }

    #[DataProvider('identifiers')]
    public function test_exact_identifiers_and_active_variant_skus_rank_ahead_of_exact_product_names(string $field, string $search): void
    {
        $exact = $this->product('identifier', 'Rezervni motor');
        if ($field === 'variant') {
            $this->variant($exact, $search, true);
            $hidden = $this->product('hidden-variant', 'Rezervna osovina');
            $this->variant($hidden, $search.'-HIDDEN', false);
        } else {
            $exact->update([$field => $search]);
        }
        $name = $this->product('name', $search);
        $prefix = $this->product('prefix', $search.' adapter');
        $this->get(route('shop.index', ['q' => strtolower($search)]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$exact->id, $name->id, $prefix->id]);
        $this->getJson(route('search.autocomplete', ['q' => strtolower($search)]))->assertOk()
            ->assertJsonPath('items.0.id', $exact->id)->assertJsonCount(3, 'items');
    }

    public static function identifiers(): array
    {
        return [['sku', 'FIND-42'], ['code', 'CODE-42'], ['barcode', '3851234567890'], ['variant', 'VARIANT-42']];
    }

    public function test_wildcard_characters_are_literal_and_cannot_broaden_sku_name_or_brand_queries(): void
    {
        $literal = $this->product('literal', '100% ventilator', ['sku' => 'FAN_01']);
        $this->product('wildcard', '1000 ventilator', ['sku' => 'FANX01']);
        foreach (['FAN_01', '100% ventilator'] as $search) {
            $this->get(route('shop.index', ['q' => $search]))->assertOk()
                ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$literal->id]);
        }
        $this->get(route('shop.index', ['q' => "' OR 1=1 --"]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->total() === 0);
    }

    public function test_active_brand_search_finds_products_and_name_hits_rank_ahead_of_brand_only_hits(): void
    {
        $name = $this->product('name-hit', 'Braytron adapter');
        $activeBrand = $this->brand('Braytron', true);
        $inactiveBrand = $this->brand('Braytron Legacy', false);
        $brandProduct = $this->product('brand-hit', 'Kupaonski ventilator', ['manufacturer_id' => $activeBrand->id]);
        $this->product('inactive-brand', 'Kupaonski adapter', ['manufacturer_id' => $inactiveBrand->id]);
        $this->get(route('shop.index', ['q' => 'braytron']))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$name->id, $brandProduct->id] && $products->total() === 2);
        $this->getJson(route('search.autocomplete', ['q' => 'braytron']))->assertOk()
            ->assertJsonPath('items.0.id', $name->id)->assertJsonPath('items.1.id', $brandProduct->id);
        $this->get(route('shop.index', ['q' => 'aytr']))->assertOk()
            ->assertViewHas('products', fn ($products) => ! $products->pluck('id')->contains($brandProduct->id));
    }

    public function test_explicit_newest_oldest_and_price_sort_still_control_search_results(): void
    {
        $this->actingAs($this->customer());
        $exact = $this->product('exact', 'Ventilator', ['base_price' => 200]);
        $prefix = $this->product('prefix', 'Ventilator stolni', ['base_price' => 10]);
        foreach ([route('shop.index'), route('categories.show', ['slug' => 'search'])] as $url) {
            foreach (['relevance' => [$exact->id, $prefix->id], 'newest' => [$prefix->id, $exact->id], 'oldest' => [$exact->id, $prefix->id], 'price_low' => [$prefix->id, $exact->id], 'price_high' => [$exact->id, $prefix->id]] as $sort => $ids) {
                $this->get($url.'?'.http_build_query(['q' => 'ventilator', 'sort' => $sort]))->assertOk()
                    ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === $ids);
            }
        }
    }

    public function test_fallback_locale_is_searchable_and_candidate_branches_do_not_duplicate_products(): void
    {
        $product = $this->product('fallback', 'Motor', ['sku' => 'FALLBACK-42']);
        $product->translations()->create(['locale' => 'en', 'name' => 'Ventilator', 'slug' => 'fallback-en']);
        $query = Product::query()->visibleOnStorefront(false);
        app(StorefrontProductSearch::class)->apply($query, 'hr', 'en', 'ventilator');
        $this->assertSame([$product->id], $query->pluck('products.id')->all());
    }

    private function product(string $slug, string $name, array $attributes = []): Product
    {
        $product = Product::query()->create(array_merge(['code' => $slug, 'sku' => strtoupper($slug), 'base_price' => 137.49, 'stock_qty' => 5, 'is_active' => true], $attributes));
        $product->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => $slug]);
        $product->categories()->attach($this->category);

        return $product;
    }

    private function searchCategory(string $slug, string $name, array $attributes = []): Category
    {
        $category = Category::query()->create(array_merge(['code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true], $attributes));
        $category->translations()->create(['locale' => 'hr', 'scope' => $category->scope, 'name' => $name, 'slug' => $slug]);

        return $category;
    }

    private function variant(Product $product, string $sku, bool $active): void
    {
        $option = Option::query()->firstOrCreate(['code' => 'search-size'], ['type' => Option::TYPE_SELECT, 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => $sku, 'is_active' => true]);
        ProductOptionValue::query()->create(['product_id' => $product->id, 'option_value_id' => $value->id, 'sku' => $sku, 'stock_qty' => 0, 'is_active' => $active, 'combination_hash' => hash('sha256', $sku)]);
    }

    private function brand(string $name, bool $active): Manufacturer
    {
        $brand = Manufacturer::query()->create(['code' => $name, 'is_active' => $active]);
        $brand->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => str($name)->slug()]);

        return $brand;
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'approved', 'name' => 'Approved', 'is_active' => true]);
        $user->customerGroups()->attach($group);
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => 'Test', 'oib' => '12345678901', 'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id]);

        return $user;
    }
}
