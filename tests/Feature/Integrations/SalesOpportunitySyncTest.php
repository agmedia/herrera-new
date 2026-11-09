<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Product\Product;
use App\Services\Analytics\SalesOpportunitySync;
use App\Services\Integrations\Eracuni\EracuniCompanySalesClient;
use App\Services\Integrations\Eracuni\EracuniCompanySalesException;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesOpportunitySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(12, 0));
        $settings = $this->mock(StockSyncSettingsService::class);
        $settings->shouldReceive('configured')->with('eracuni')->andReturn(true);
        $settings->shouldReceive('connection')->with('eracuni')->andReturn(['url' => 'https://erp.example.test/api/ProductList', 'username' => 'test', 'password' => 'private', 'token' => 'token']);
    }

    public function test_financial_sales_are_deduplicated_and_net_of_cancellations_and_both_credit_sign_representations(): void
    {
        $product = Product::query()->create(['code' => 'ERP-ONE', 'base_price' => 10, 'stock_qty' => 10, 'is_active' => true]);
        $sale = $this->document('sale', 10);
        $invoiceCancellation = $this->document('cancel', -2, ['cancelledDocumentRef' => 'old-invoice']);
        $old = $this->document('old', 5, ['date' => '2026-01-01']);
        $draft = $this->document('draft', 1000, ['status' => 'Draft']);
        Http::fake(function (Request $request) use ($sale, $invoiceCancellation, $old, $draft) {
            $rows = match (basename($request->url())) {
                'SalesInvoiceList' => [$sale, $invoiceCancellation, $old, $draft],
                'POSSalesInvoiceList' => [$sale, $this->document('anonymous', 3, ['buyerTaxNumber' => '', 'buyerDocumentID' => ''])],
                'CreditNoteList' => [
                    $this->document('credit-positive', 2),
                    $this->document('credit-negative', -1),
                    $this->document('cancel-credit', -1, ['cancelledDocumentRef' => 'credit-negative']),
                ],
            };

            return Http::response(['response' => ['result' => $rows]]);
        });
        $result = app(SalesOpportunitySync::class)->run();
        $this->assertSame('completed', $result['status']);
        $this->assertSame(8, $result['document_count']);
        $this->assertSame(3, $result['request_count']);
        $this->assertSame(1, $result['anonymous_lines']);
        $rows = DB::table('sales_opportunity_purchases')->where('snapshot_id', $result['id'])->get();
        $this->assertCount(2, $rows);
        $known = $rows->firstWhere('known_buyer', 1);
        $this->assertSame($product->id, $known->product_id);
        $this->assertEquals(6, $known->quantity_90);
        $this->assertEquals(11, $known->quantity_365);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $known->tax_key);
        $this->assertStringNotContainsString('12345678901', json_encode($rows));
        $this->assertStringNotContainsString('Private Buyer', json_encode($rows));
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'DeliveryNote'));
    }

    public function test_failure_keeps_last_completed_snapshot_and_cannot_publish_partial_rows(): void
    {
        Product::query()->create(['code' => 'ERP-ONE', 'base_price' => 10, 'is_active' => true]);
        $previous = DB::table('sales_opportunity_snapshots')->insertGetId(['status' => 'completed', 'coverage_from' => '2025-11-08', 'as_of' => '2026-11-08', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(fn (Request $request) => basename($request->url()) === 'SalesInvoiceList'
            ? Http::response(['response' => ['result' => [$this->document('sale', 10)]]])
            : Http::response('permission denied', 403));
        try {
            app(SalesOpportunitySync::class)->run();
            $this->fail('Incomplete API must not publish a snapshot.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('private', $exception->getMessage());
        }
        $this->assertSame($previous, DB::table('sales_opportunity_snapshots')->where('status', 'completed')->sole()->id);
        $this->assertDatabaseCount('sales_opportunity_purchases', 0);
        $this->assertSame('failed', DB::table('sales_opportunity_snapshots')->latest('id')->first()->status);
    }

    public function test_truncated_parent_rows_are_not_counted_and_identical_documents_on_multiple_endpoints_count_once(): void
    {
        Product::query()->create(['code' => 'ERP-ONE', 'base_price' => 10, 'is_active' => true]);
        Http::fake(function (Request $request) {
            if (basename($request->url()) !== 'SalesInvoiceList') {
                return Http::response(['response' => ['result' => []]]);
            }
            if ($request['dateFrom'] === '2025-11-09' && $request['dateTo'] === '2026-11-09') {
                return Http::response(['response' => ['result' => array_fill(0, 500, $this->document('truncated-parent', 9999))]]);
            }

            return Http::response(['response' => ['result' => [$this->document('leaf-'.$request['dateFrom'], 2, ['date' => $request['dateFrom']])]]]);
        });
        $result = app(SalesOpportunitySync::class)->run();
        $this->assertSame(2, $result['document_count']);
        $this->assertEquals(4, DB::table('sales_opportunity_purchases')->sum('quantity_365'));
        Http::assertSentCount(5);
    }

    public function test_country_tax_identity_is_stable_but_never_merges_different_countries(): void
    {
        $sync = app(SalesOpportunitySync::class);
        $this->assertSame($sync->buyerIdentity(['buyerTaxNumber' => 'HR12345'])['buyer_key'], $sync->buyerIdentity(['buyerTaxNumber' => '12345', 'buyerCountry' => 'HR'])['buyer_key']);
        $this->assertSame($sync->buyerIdentity(['buyerTaxNumber' => 'SI12345'])['buyer_key'], $sync->buyerIdentity(['buyerTaxNumber' => '12345', 'buyerCountry' => 'SI'])['buyer_key']);
        $this->assertNotSame($sync->buyerIdentity(['buyerTaxNumber' => 'HR12345'])['buyer_key'], $sync->buyerIdentity(['buyerTaxNumber' => 'SI12345'])['buyer_key']);
    }

    public function test_buffer_flushes_merge_repeated_buyer_product_pairs_across_batches(): void
    {
        $products = [];
        for ($index = 0; $index < 251; $index++) {
            Product::query()->create(['code' => 'P-'.$index, 'base_price' => 10, 'is_active' => true]);
            $products[] = ['productCode' => 'P-'.$index, 'quantity' => 1, 'amount' => 10];
        }
        $products[] = ['productCode' => 'P-0', 'quantity' => 4, 'amount' => 40];
        Http::fake(fn (Request $request) => Http::response(['response' => ['result' => basename($request->url()) === 'SalesInvoiceList'
            ? [$this->document('many', 1, ['Items' => $products])] : []]]));
        app(SalesOpportunitySync::class)->run();
        $this->assertDatabaseCount('sales_opportunity_purchases', 251);
        $this->assertEquals(255, DB::table('sales_opportunity_purchases')->sum('quantity_90'));
        $this->assertEquals(5, DB::table('sales_opportunity_purchases')->where('product_id', Product::query()->where('code', 'P-0')->value('id'))->value('quantity_90'));
    }

    public function test_buyer_aliases_bridge_documents_missing_either_tax_or_immutable_erp_id_without_inflating_reach(): void
    {
        Product::query()->create(['code' => 'ERP-ONE', 'base_price' => 10, 'is_active' => true]);
        Http::fake(fn (Request $request) => Http::response(['response' => ['result' => basename($request->url()) === 'SalesInvoiceList' ? [
            $this->document('tax-only', 1, ['buyerDocumentID' => '']),
            $this->document('id-only', 2, ['buyerTaxNumber' => '']),
            $this->document('bridge', 3),
        ] : []]]));
        app(SalesOpportunitySync::class)->run();
        $this->assertDatabaseCount('sales_opportunity_purchases', 1);
        $this->assertDatabaseCount('sales_opportunity_buyer_aliases', 2);
        $this->assertEquals(6, DB::table('sales_opportunity_purchases')->sole()->quantity_90);
        $this->assertSame(1, DB::table('sales_opportunity_buyer_aliases')->distinct()->count('buyer_key'));
    }

    public function test_reused_buyer_id_discovered_on_late_credit_note_keeps_tax_buyers_separate_and_all_signed_quantities(): void
    {
        Product::query()->create(['code' => 'ERP-ONE', 'base_price' => 10, 'is_active' => true]);
        Http::fake(fn (Request $request) => Http::response(['response' => ['result' => match (basename($request->url())) {
            'SalesInvoiceList' => [
                $this->document('tax-only', 1, ['buyerDocumentID' => '']),
                $this->document('id-only', 2, ['buyerTaxNumber' => '', 'buyerDocumentID' => 'shared-buyer']),
                $this->document('bridge-first-tax', 3, ['buyerDocumentID' => 'shared-buyer']),
            ],
            'CreditNoteList' => [$this->document('late-other-tax', 1, ['buyerTaxNumber' => 'HR99999999999', 'buyerDocumentID' => 'shared-buyer'])],
            default => [],
        }]]));
        $sync = app(SalesOpportunitySync::class);
        $result = $sync->run();
        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['ambiguous_buyer_ids']);
        $this->assertSame(1, $result['anonymous_lines']);
        $this->assertStringContainsString('identiteta: 1.', $result['message']);
        $this->assertStringNotContainsString('99999999999', $result['message']);
        $this->assertStringNotContainsString('shared-buyer', $result['message']);
        $rows = DB::table('sales_opportunity_purchases')->get();
        $this->assertCount(3, $rows);
        $this->assertEquals(5, $rows->sum('quantity_90'));
        $firstTax = $sync->buyerIdentity(['buyerTaxNumber' => 'HR12345678901'])['tax_key'];
        $secondTax = $sync->buyerIdentity(['buyerTaxNumber' => 'HR99999999999'])['tax_key'];
        $documentKey = $sync->buyerIdentity(['buyerDocumentID' => 'shared-buyer'])['document_key'];
        $this->assertEquals(4, $rows->firstWhere('tax_key', $firstTax)->quantity_90);
        $this->assertEquals(-1, $rows->firstWhere('tax_key', $secondTax)->quantity_90);
        $unknown = $rows->firstWhere('buyer_key', $documentKey);
        $this->assertFalse((bool) $unknown->known_buyer);
        $this->assertNull($unknown->document_key);
        $this->assertEquals(2, $unknown->quantity_90);
        $this->assertSame(0, $rows->whereNotNull('document_key')->count());
        $this->assertDatabaseCount('sales_opportunity_buyer_aliases', 2);
        $this->assertDatabaseMissing('sales_opportunity_buyer_aliases', ['alias_key' => $documentKey]);
        $this->assertSame($result['message'], DB::table('sales_opportunity_snapshots')->sole()->message);
    }

    public function test_late_ambiguity_after_buffer_flush_is_order_independent_and_progress_contains_no_buyer_data(): void
    {
        $product = Product::query()->create(['code' => 'ERP-ONE', 'base_price' => 10, 'is_active' => true]);
        $filler = [];
        for ($index = 0; $index < 249; $index++) {
            $filler[] = Product::query()->create(['code' => 'FILL-'.$index, 'base_price' => 10, 'is_active' => true]);
        }
        $this->mock(EracuniCompanySalesClient::class)->shouldReceive('eachFinancialDocument')->once()->andReturnUsing(function ($from, $until, $consume) use ($product, $filler): array {
            $consume($this->document('first-tax', 3, ['buyerDocumentID' => 'shared-buyer']), 'SalesInvoiceList');
            $consume($this->document('id-only', 2, ['buyerTaxNumber' => '', 'buyerDocumentID' => 'shared-buyer']), 'SalesInvoiceList');
            foreach ($filler as $index => $row) {
                $consume($this->document('filler-'.$index, 1, ['buyerDocumentID' => '', 'Items' => [['productCode' => $row->code, 'quantity' => 1, 'amount' => 10]]]), 'SalesInvoiceList');
            }
            // The initial pairs have already been written. A later OIB must
            // still be able to invalidate the ID without irreversibly merging.
            $this->assertSame(2, DB::table('sales_opportunity_purchases')->where('product_id', $product->id)->count());
            $consume($this->document('last-other-tax', 4, ['buyerTaxNumber' => 'HR99999999999', 'buyerDocumentID' => 'shared-buyer']), 'POSSalesInvoiceList');

            return ['complete' => true, 'document_count' => 252, 'request_count' => 3];
        });
        $progress = [];
        $result = app(SalesOpportunitySync::class)->run(function ($event) use (&$progress): void {
            $progress[] = $event;
        });
        $rows = DB::table('sales_opportunity_purchases')->where('product_id', $product->id)->get();
        $this->assertSame('completed', $result['status']);
        $this->assertCount(3, $rows);
        $this->assertEquals(9, $rows->sum('quantity_90'));
        $this->assertSame(2, $rows->where('known_buyer', 1)->count());
        $this->assertEquals(2, $rows->where('known_buyer', 0)->sole()->quantity_90);
        $this->assertSame([['method' => 'SalesInvoiceList', 'document_count' => 250]], $progress);
    }

    public function test_invoice_cancellation_with_positive_quantity_and_negative_amount_reverses_the_sale(): void
    {
        Product::query()->create(['code' => 'ERP-ONE', 'base_price' => 10, 'is_active' => true]);
        Http::fake(fn (Request $request) => Http::response(['response' => ['result' => basename($request->url()) === 'SalesInvoiceList' ? [
            $this->document('sale', 5),
            $this->document('cancel', 5, ['cancelledDocumentRef' => 'sale', 'documentAmount' => -50, 'Items' => [['productCode' => 'ERP-ONE', 'quantity' => 5, 'amount' => -50]]]),
        ] : []]]));
        app(SalesOpportunitySync::class)->run();
        $this->assertEquals(0, DB::table('sales_opportunity_purchases')->sum('quantity_90'));
    }

    public function test_zero_placeholder_ids_are_not_known_buyers_and_ambiguous_cancellation_has_safe_line_context(): void
    {
        $this->assertFalse(app(SalesOpportunitySync::class)->buyerIdentity(['buyerTaxNumber' => 'HR00000000000', 'buyerDocumentID' => '0', 'buyerCode' => '0000'])['known_buyer']);
        Product::query()->create(['code' => 'ERP-ONE', 'base_price' => 10, 'is_active' => true]);
        Http::fake(fn () => Http::response(['response' => ['result' => [$this->document('cancel-id', 1, ['cancelledDocumentRef' => 'old', 'documentAmount' => 0, 'Items' => [['productCode' => 'ERP-ONE', 'quantity' => 1, 'amount' => 0]]])]]]));
        try {
            app(SalesOpportunitySync::class)->run();
            $this->fail('Ambiguous cancellation must not publish a report.');
        } catch (EracuniCompanySalesException $exception) {
            $this->assertSame(['method' => 'SalesInvoiceList', 'document_id' => 'cancel-id', 'line_position' => 1], $exception->context);
            $this->assertStringNotContainsString('Private Buyer', $exception->getMessage());
        }
        $this->assertSame('failed', DB::table('sales_opportunity_snapshots')->sole()->status);
    }

    public function test_sparse_buffer_does_not_hydrate_the_cartesian_history_of_products_and_buyers(): void
    {
        $products = [];
        for ($index = 0; $index < 250; $index++) {
            $products[] = Product::query()->create(['code' => 'GRID-'.$index, 'base_price' => 10, 'is_active' => true]);
        }
        $peakDelta = null;
        $this->mock(EracuniCompanySalesClient::class)->shouldReceive('eachFinancialDocument')->once()->andReturnUsing(function ($from, $until, $consume) use ($products, &$peakDelta): array {
            $snapshotId = DB::table('sales_opportunity_snapshots')->where('status', 'running')->value('id');
            foreach ($products as $product) {
                $rows = [];
                for ($index = 0; $index < 250; $index++) {
                    $buyer = app(SalesOpportunitySync::class)->buyerIdentity(['buyerTaxNumber' => 'BUYER-'.$index]);
                    $rows[] = $buyer + ['snapshot_id' => $snapshotId, 'product_id' => $product->id, 'quantity_90' => 1, 'quantity_365' => 1];
                }
                DB::table('sales_opportunity_purchases')->insert($rows);
            }
            unset($rows, $buyer);
            $retainedResponse = str_repeat('x', 16 * 1024 * 1024);
            memory_reset_peak_usage();
            $before = memory_get_usage(true);
            foreach ($products as $index => $product) {
                $consume($this->document('grid-'.$index, 2, ['buyerTaxNumber' => 'BUYER-'.$index, 'buyerDocumentID' => '', 'Items' => [['productCode' => $product->code, 'quantity' => 2, 'amount' => 20]]]), 'SalesInvoiceList');
            }
            $peakDelta = memory_get_peak_usage(true) - $before;
            unset($retainedResponse);

            return ['complete' => true, 'document_count' => 250, 'request_count' => 1];
        });
        app(SalesOpportunitySync::class)->run();
        $this->assertLessThan(12 * 1024 * 1024, $peakDelta, 'Only the 250 exact buffered pairs may be hydrated, not all 62,500 historical combinations.');
        $this->assertDatabaseCount('sales_opportunity_purchases', 62500);
        $this->assertEquals(63000, DB::table('sales_opportunity_purchases')->sum('quantity_90'));
    }

    private function document(string $id, int $quantity, array $changes = []): array
    {
        return array_replace(['documentID' => $id, 'date' => '2026-11-01', 'status' => 'IssuedInvoice', 'buyerTaxNumber' => 'HR12345678901',
            'buyerDocumentID' => 'buyer-1', 'buyerName' => 'Private Buyer', 'documentAmount' => $quantity * 10,
            'Items' => [['productCode' => 'ERP-ONE', 'quantity' => $quantity, 'amount' => $quantity * 10]]], $changes);
    }
}
