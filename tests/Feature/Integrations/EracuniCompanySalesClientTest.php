<?php

namespace Tests\Feature\Integrations;

use App\Services\Integrations\Eracuni\EracuniCompanySalesClient;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EracuniCompanySalesClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(12, 0));
    }

    public function test_all_company_sources_and_date_passes_use_existing_auth_without_scope_filters(): void
    {
        $this->feed(fn () => Http::response(['response' => ['result' => [$this->row()]]], 200, ['Content-Encoding' => '8-bit']));

        $result = $this->client()->fetch('2026-08-01', '2026-11-09');

        $this->assertTrue($result['complete']);
        $this->assertSame(['00123'], $result['recent_product_codes']);
        $this->assertSame(3, $result['document_count']);
        $this->assertSame(8, $result['request_count']);
        $this->assertSame(EracuniCompanySalesClient::SOURCES, $result['sources']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['fingerprint']);
        $this->assertStringNotContainsString('private-buyer', json_encode($result));
        Http::assertSentCount(8);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://erp.example.test/api/SalesInvoiceList'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('erp-user:private-token_private-password'))
            && $request->hasHeader('Accept-Encoding', 'identity')
            && $request->data() === ['dateFrom' => '2026-08-01', 'dateTo' => '2026-11-09']);
        foreach (Http::recorded() as [$request]) {
            $this->assertEmpty(array_intersect(array_keys($request->data()), ['warehouse', 'costPosition', 'buyer', 'status']));
            if (str_ends_with($request->url(), '/DeliveryNoteList')) {
                $this->assertArrayNotHasKey('issuedTimestampFrom', $request->data());
            }
        }
        Http::assertSent(fn (Request $request): bool => ($request->data()['issuedTimestampFrom'] ?? null) === '2026-08-01 00:00:00'
            && $request['issuedTimestampTo'] === '2026-11-09 23:59:59');
    }

    public function test_pos_and_unbilled_delivery_codes_block_absence_and_duplicates_keep_leading_zeroes(): void
    {
        $this->feed(function (Request $request) {
            $code = str_ends_with($request->url(), '/SalesInvoiceList') ? '00123'
                : (str_ends_with($request->url(), '/POSSalesInvoiceList') ? 'POS' : 'UNBILLED');

            return Http::response(['response' => ['result' => [$this->row(['Items' => [
                ['productCode' => $code, 'quantity' => 1], ['productCode' => $code, 'quantity' => 2],
                ['productCode' => 'RETURN-ONLY', 'quantity' => -1], ['quantity' => 0],
            ]])]]]);
        });

        $result = $this->client()->fetch('2026-11-06', '2026-11-06');

        $this->assertSame(['00123', 'POS', 'UNBILLED'], $result['recent_product_codes']);
    }

    public function test_empty_successful_responses_prove_empty_activity_only_after_all_eight_passes(): void
    {
        $this->feed(fn () => Http::response(['response' => ['result' => []]]));

        $result = $this->client()->fetch('2026-11-06', '2026-11-06');

        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['recent_product_codes']);
        $this->assertSame(0, $result['document_count']);
        Http::assertSentCount(8);
    }

    public function test_only_the_explicit_matching_legacy_no_documents_error_is_an_empty_selection(): void
    {
        $this->feed(fn (Request $request) => Http::response(['response' => [
            'status' => 'error', 'apiMethod' => basename($request->url()),
            'description' => "Error nr: 1 #noDocumentsFound - No documents were found matching search criteria!; \r\n",
        ]], 500));

        $result = $this->client()->fetch('2026-11-06', '2026-11-06');

        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['recent_product_codes']);
        Http::assertSentCount(8);

        foreach ([
            ['apiMethod' => 'AnotherMethod'], ['status' => 'success'],
            ['description' => 'Permission denied #noDocumentsFound'],
            ['description' => 'Error nr: 2 #noDocumentsFound - No documents were found matching search criteria!;'],
            ['hasMore' => true], ['complete' => 0],
        ] as $invalid) {
            $this->feed(fn () => Http::response(['response' => array_replace([
                'status' => 'error', 'apiMethod' => 'SalesInvoiceList',
                'description' => 'Error nr: 1 #noDocumentsFound - No documents were found matching search criteria!;',
            ], $invalid)], 500));
            $this->assertFails('Dohvat', fn () => $this->client()->fetch('2026-11-06', '2026-11-06'));
        }
    }

    public function test_a_500_document_window_keeps_known_activity_and_bisects_to_prove_remaining_scope(): void
    {
        $this->feed(function (Request $request) {
            if (str_ends_with($request->url(), '/SalesInvoiceList') && isset($request['dateFrom'])) {
                if ($request['dateFrom'] !== $request['dateTo']) {
                    return Http::response(['response' => ['result' => array_fill(0, 500, $this->row())]]);
                }

                return Http::response(['response' => ['result' => [$this->row([
                    'documentID' => $request['dateFrom'], 'date' => $request['dateFrom'],
                    'Items' => [['productCode' => $request['dateFrom'], 'quantity' => 1]],
                ])]]]);
            }

            return Http::response(['response' => ['result' => []]]);
        });

        $result = $this->client()->fetch('2026-11-05', '2026-11-06');

        $this->assertSame(['00123', '2026-11-05', '2026-11-06'], $result['recent_product_codes']);
        $this->assertSame(3, $result['document_count']);
        $this->assertSame(10, $result['request_count']);
    }

    public function test_single_day_limit_or_explicit_partial_page_never_proves_absence(): void
    {
        foreach ([
            ['result' => array_fill(0, 500, $this->row())],
            ['result' => [], 'hasMore' => true],
            ['result' => [], 'complete' => false],
            ['result' => [], 'totalCount' => 1],
        ] as $state) {
            $this->feed(fn () => Http::response(['response' => $state]));
            $this->assertFails('ograničenje', fn () => $this->client()->fetch('2026-11-06', '2026-11-06'));
        }
    }

    public function test_a_later_source_failure_cannot_return_partial_sales_evidence(): void
    {
        $this->feed(fn (Request $request) => str_ends_with($request->url(), '/DeliveryNoteList')
            ? Http::response(['response' => ['error' => 'private-error', 'result' => []]])
            : Http::response(['response' => ['result' => [$this->row()]]]));

        $this->assertFails('uspješan', fn () => $this->client()->fetch('2026-11-06', '2026-11-06'));
        Http::assertSentCount(7);
    }

    public function test_invalid_document_dates_items_codes_and_quantities_fail_closed(): void
    {
        foreach ([
            ['documentID' => ''], ['date' => '2026-02-30'], ['Items' => null],
            ['Items' => [['productCode' => 'SKU', 'quantity' => true]]],
            ['Items' => [['productCode' => 'SKU', 'quantity' => 'NaN']]],
            ['Items' => [['productCode' => "SKU\nBAD", 'quantity' => 1]]],
            ['Items' => [['productCode' => '', 'productName' => 'Linked product', 'quantity' => 1]]],
            ['Items' => [['productBarCode' => '123', 'quantity' => 1]]],
            ['Items' => [['productCatalogueCode' => '123', 'quantity' => 1]]],
        ] as $invalid) {
            $this->feed(fn () => Http::response(['response' => ['result' => [$this->row($invalid)]]]));
            $this->assertFails(null, fn () => $this->client()->fetch('2026-11-06', '2026-11-06'));
        }
    }

    public function test_ignored_form_date_filters_and_invalid_issued_timestamps_are_rejected(): void
    {
        $this->feed(fn () => Http::response(['response' => ['result' => [$this->row(['date' => '2026-11-01'])]]]));
        $this->assertFails('razdoblje', fn () => $this->client()->fetch('2026-11-06', '2026-11-06'));

        $this->feed(fn () => Http::response(['response' => ['result' => [$this->row(['issuedTimestamp' => '2026-11-06 25:00:00'])]]]));
        $this->assertFails('vrijeme', fn () => $this->client()->fetch('2026-11-06', '2026-11-06'));
    }

    public function test_supply_periods_may_overlap_query_bounds_but_outside_or_reversed_periods_fail(): void
    {
        $feed = function (string $from, string $until): void {
            $this->feed(fn (Request $request) => Http::response(['response' => ['result' => str_ends_with($request->url(), '/SalesInvoiceList')
                ? [$this->row(['dateOfSupply' => null, 'dateOfSupplyFrom' => $from, 'dateOfSupplyUntil' => $until])]
                : []]]));
        };
        $feed('2026-11-01', '2026-11-09');

        $result = $this->client()->fetch('2026-11-06', '2026-11-06');

        $this->assertTrue($result['complete']);
        $this->assertSame(['00123'], $result['recent_product_codes']);
        foreach ([['2026-11-01', '2026-11-05'], ['2026-11-07', '2026-11-09'], ['2026-11-08', '2026-11-05']] as [$from, $until]) {
            $feed($from, $until);
            $this->assertFails('razdoblje', fn () => $this->client()->fetch('2026-11-06', '2026-11-06'));
        }
    }

    public function test_unlinked_free_text_lines_are_counted_once_per_document_without_guessing_descriptions(): void
    {
        $this->feed(fn () => Http::response(['response' => ['result' => [$this->row(['Items' => [
            ['productCode' => '00123', 'productName' => 'Linked', 'quantity' => 1],
            ['description' => 'Any free text, no inference', 'quantity' => 1],
            ['productCode' => '', 'productName' => '', 'productBarCode' => '', 'productCatalogueCode' => '', 'quantity' => 2],
        ]])]]]));

        $result = $this->client()->fetch('2026-11-06', '2026-11-06');

        $this->assertSame(['00123'], $result['recent_product_codes']);
        $this->assertSame(6, $result['unlinked_line_count']);
        $this->assertSame(3, $result['document_count']);
        $this->assertStringNotContainsString('free text', json_encode($result));
    }

    public function test_transport_and_error_bodies_never_escape_in_exceptions(): void
    {
        $this->feed(fn () => throw new RuntimeException('https://erp.example.test/private-token private-buyer'));
        $this->assertFails('Dohvat', fn () => $this->client()->fetch('2026-11-06', '2026-11-06'));

        foreach ([
            null, ['response' => null], ['response' => ['result' => null]],
            ['response' => ['result' => [], 'totalCount' => 'broken']],
            ['response' => ['result' => [], 'status' => ['error']]],
            ['response' => ['result' => [], 'success' => 'false']],
            ['response' => ['result' => [], 'faultCode' => 2]],
        ] as $invalid) {
            $this->feed(fn () => Http::response(json_encode($invalid)));
            $this->assertFails(null, fn () => $this->client()->fetch('2026-11-06', '2026-11-06'));
        }
    }

    public function test_invalid_or_future_input_periods_make_no_http_requests(): void
    {
        foreach ([['2026-11-07', '2026-11-06'], ['2026-02-30', '2026-11-06'], ['2026-11-06', '2026-11-10'], ['2024-01-01', '2026-11-06']] as [$from, $until]) {
            $this->assertFails(null, fn () => $this->client()->fetch($from, $until));
        }
        Http::assertNothingSent();
    }

    private function feed(callable $callback): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function client(): EracuniCompanySalesClient
    {
        $settings = Mockery::mock(StockSyncSettingsService::class);
        $settings->shouldReceive('configured')->with('eracuni')->andReturn(true);
        $settings->shouldReceive('connection')->with('eracuni')->andReturn([
            'url' => 'https://erp.example.test/api/WarehouseGetArticleStockQuantity',
            'username' => 'erp-user', 'token' => 'private-token', 'password' => 'private-password',
        ]);

        return new EracuniCompanySalesClient($settings);
    }

    private function row(array $replace = []): array
    {
        return array_replace([
            'documentID' => 'INV-1', 'date' => '2026-11-06', 'dateOfSupply' => '2026-11-06',
            'issuedTimestamp' => '2026-11-06 10:30:00', 'status' => 'IssuedInvoice',
            'buyerName' => 'private-buyer', 'Items' => [['productCode' => '00123', 'quantity' => 1]],
        ], $replace);
    }

    private function assertFails(?string $part, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Partial or invalid company evidence was accepted.');
        } catch (RuntimeException $exception) {
            if ($part !== null) {
                $this->assertStringContainsString($part, $exception->getMessage());
            }
            $this->assertStringNotContainsString('private-', $exception->getMessage());
            $this->assertStringNotContainsString('https://', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }
}
