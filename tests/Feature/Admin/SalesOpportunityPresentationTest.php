<?php

namespace Tests\Feature\Admin;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Services\Analytics\SalesOpportunitySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;
use ZipArchive;

class SalesOpportunityPresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $partner;

    private int $snapshotId;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->manager = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('order_manager')->to($this->manager);
        Bouncer::allow($this->manager)->to('admin.access');
        Bouncer::refresh();
        $this->partner = $this->customer('Odabrani partner', '12345678901');
        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $this->manager->id, 'customer_user_id' => $this->partner->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->snapshotId = DB::table('sales_opportunity_snapshots')->insertGetId(['status' => 'completed', 'coverage_from' => today()->subDays(365), 'as_of' => today(), 'document_count' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_page_size_choices_are_bounded_and_pagination_retains_partner_and_category(): void
    {
        $category = $this->category('selected');
        for ($index = 0; $index < 53; $index++) {
            $this->product('ROW-'.$index, $category, 100 - $index);
        }
        $this->actingAs($this->manager);
        foreach ([20, 50, 100, 200] as $size) {
            $this->get(route('admin.users.opportunities', ['user_id' => $this->partner->id, 'category_id' => $category->id, 'per_page' => $size]))
                ->assertOk()->assertViewHas('products', fn ($products) => $products->perPage() === $size && $products->total() === 53)
                ->assertSee('Sve filtrirane prilike, ne samo ova stranica.');
        }
        $this->get(route('admin.users.opportunities', ['user_id' => $this->partner->id, 'category_id' => $category->id, 'per_page' => 20, 'page' => 2]))
            ->assertOk()->assertViewHas('products', fn ($products) => $products->currentPage() === 2 && $products->count() === 20)
            ->assertSee('per_page=20', false)->assertSee('category_id='.$category->id, false)->assertSee('user_id='.$this->partner->id, false);
        foreach ([0, 21, 1000, 'all'] as $invalid) {
            $this->getJson(route('admin.users.opportunities', ['user_id' => $this->partner->id, 'per_page' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        }
    }

    public function test_xlsx_exports_all_filtered_rows_in_rank_order_with_safe_text_and_numeric_columns(): void
    {
        $category = $this->category('selected');
        $outside = $this->category('outside');
        for ($index = 0; $index < 25; $index++) {
            $product = $this->product('EXPORT-'.$index, $category, 100 - $index);
            if ($index === 0) {
                $product->update(['sku' => '=HYPERLINK("https://example.test","text")', 'barcode' => '0000123456789']);
                $product->translations()->update(['name' => '=SUM(1,1)']);
            }
        }
        $bought = $this->product('BOUGHT', $category, 500);
        $this->purchase($bought, '12345678901', 1);
        $this->product('OUTSIDE', $outside, 1000);
        $this->product('INACTIVE', $category, 1000)->update(['is_active' => false]);
        $this->customer('Other private company', '22222222222')->update(['email' => 'secret-other@example.test']);
        $response = $this->actingAs($this->manager)->get(route('admin.users.opportunities.export', [
            'user_id' => $this->partner->id, 'category_id' => $category->id, 'per_page' => 20, 'page' => 2,
        ]))->assertOk()->assertDownload()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame(26, $sheet->getHighestDataRow());
            $this->assertSame('EXPORT-0', (string) $sheet->getCell('B2')->getValue());
            $this->assertSame('EXPORT-24', (string) $sheet->getCell('B26')->getValue());
            $this->assertSame('0000123456789', (string) $sheet->getCell('D2')->getValue());
            $this->assertContains($sheet->getCell('C2')->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE]);
            $this->assertSame('=SUM(1,1)', (string) $sheet->getCell('E2')->getValue());
            $this->assertContains($sheet->getCell('E2')->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE]);
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G2')->getDataType());
            $this->assertEquals(100, $sheet->getCell('H2')->getValue());
            $this->assertSame('selected', (string) $sheet->getCell('F2')->getValue());
            $book->disconnectWorksheets();
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringNotContainsString('BOUGHT', $xml);
            $this->assertStringNotContainsString('OUTSIDE', $xml);
            $this->assertStringNotContainsString('INACTIVE', $xml);
            $this->assertStringNotContainsString('Other private company', $xml);
            $this->assertStringNotContainsString('secret-other@example.test', $xml);
            $zip->close();
        } finally {
            @unlink($path);
        }
        Http::assertNothingSent();
    }

    public function test_export_keeps_report_permissions_partner_assignments_and_completed_snapshot_requirement(): void
    {
        $other = $this->customer('Not assigned', '22222222222');
        $url = route('admin.users.opportunities.export', ['user_id' => $this->partner->id]);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->partner)->get($url)->assertForbidden();
        $this->actingAs($this->manager)->get(route('admin.users.opportunities.export', ['user_id' => $other->id]))->assertForbidden();
        $this->getJson(route('admin.users.opportunities.export'))->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->partner->b2bAccount->update(['status' => B2BAccount::STATUS_PENDING]);
        $this->get($url)->assertUnprocessable();
        $this->partner->b2bAccount->update(['status' => B2BAccount::STATUS_APPROVED]);
        DB::table('sales_opportunity_snapshots')->where('id', $this->snapshotId)->delete();
        $this->get($url)->assertUnprocessable();
    }

    public function test_product_thumbnails_use_existing_safe_sources_and_have_a_missing_image_fallback(): void
    {
        config(['legacy_media.enabled' => true, 'legacy_media.origin' => 'https://images.example.test']);
        $this->product('WITH-IMAGE', null, 20)->update(['payload' => ['opencart' => ['image' => 'catalog/opportunity.jpg']]]);
        $this->product('NO-IMAGE', null, 10);
        $response = $this->actingAs($this->manager)->get(route('admin.users.opportunities', ['user_id' => $this->partner->id]))->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), 'data-opportunity-image-fallback'));
        $this->assertSame(1, substr_count($response->getContent(), 'data-opportunity-product-image'));
        $response->assertSee('https://images.example.test/image/catalog/opportunity.jpg', false)->assertSee('loading="lazy"', false);
        Http::assertNothingSent();
    }

    public function test_presentations_are_explicit_idempotent_scoped_and_reported(): void
    {
        $product = $this->product('LED-DEMO', null, 8);
        $this->manager->forceFill(['admin_login_enabled' => true])->save();
        $this->actingAs($this->manager);
        $data = ['user_id' => $this->partner->id, 'selection' => [$product->id], 'operation' => 'presented',
            'batch' => (string) \Illuminate\Support\Str::uuid(), 'note' => 'Predstavljen na sastanku',
            'location' => ['latitude' => 45.82, 'longitude' => 15.99, 'accuracy' => 9]];
        $this->post(route('admin.users.opportunities.products'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.users.opportunities.products'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('customer_product_presentations', 1);
        $this->get(route('admin.users.opportunities', ['user_id' => $this->partner->id, 'unpresented' => 1]))
            ->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 0);
        $this->get(route('admin.users.sales-reports', ['tab' => 'products', 'customer' => $this->partner->id]))->assertOk()
            ->assertSee('LED-DEMO')->assertSee('Predstavljen na sastanku')->assertSee('45.82')->assertSee('15.99');
        $other = $this->customer('Nedodijeljeni partner', '22222222222');
        $this->get(route('admin.users.sales-reports', ['tab' => 'products', 'customer' => $other->id]))->assertForbidden();
        $this->post(route('admin.users.opportunities.products'), array_merge($data, ['user_id' => $other->id]))->assertForbidden();
    }

    public function test_selected_opportunity_opens_customer_cart_with_requested_quantity(): void
    {
        $product = $this->product('KUPOVINA-1', null, 9);
        $this->manager->forceFill(['admin_login_enabled' => true])->save();
        Bouncer::allow($this->manager)->to('users.profile.update');
        Bouncer::refresh();
        $this->actingAs($this->manager);
        $this->post(route('admin.users.opportunities.products'), ['user_id' => $this->partner->id, 'selection' => [$product->id],
            'operation' => 'cart', 'quantities' => [$product->id => 4], 'batch' => (string) \Illuminate\Support\Str::uuid()])
            ->assertRedirect(route('cart.index'))->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->partner);
        $this->assertSame(4, app(\App\Services\Front\CartService::class)->lines()->sole()['quantity']);
        $this->assertDatabaseCount('customer_product_presentations', 0);
    }

    private function customer(string $name, string $tax): User
    {
        $user = User::factory()->create(['name' => $name, 'account_type' => 'customer']);
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => $name, 'status' => B2BAccount::STATUS_APPROVED, 'oib' => $tax]);

        return $user;
    }

    private function category(string $code): Category
    {
        $category = Category::query()->create(['scope' => 'catalog', 'code' => $code, 'is_active' => true]);
        $category->translations()->create(['scope' => 'catalog', 'locale' => 'hr', 'name' => $code, 'slug' => $code]);

        return $category;
    }

    private function product(string $code, ?Category $category, int $quantity): Product
    {
        $product = Product::query()->create(['code' => $code, 'sku' => $code, 'base_price' => 10, 'stock_qty' => 10, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => strtolower($code)]);
        if ($category) {
            $product->categories()->attach($category);
        }
        $this->purchase($product, '11111111111', $quantity);

        return $product;
    }

    private function purchase(Product $product, string $tax, int $quantity): void
    {
        DB::table('sales_opportunity_purchases')->insert(app(SalesOpportunitySync::class)->buyerIdentity(['buyerTaxNumber' => $tax])
            + ['snapshot_id' => $this->snapshotId, 'product_id' => $product->id, 'quantity_90' => $quantity, 'quantity_365' => $quantity]);
    }
}
