<?php

namespace Tests\Feature\Integrations;

use App\Services\Integrations\Stock\StockFeedClient;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StockFeedClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    #[DataProvider('supplierSnapshots')]
    public function test_it_reads_the_actual_legacy_formats_and_preserves_identifier_zeroes(string $supplier, string $body, int $quantity): void
    {
        $client = $this->client($supplier);
        Http::fake(['feeds.example.test/*' => Http::response($body)]);

        $result = $client->fetch($supplier);

        $this->assertSame([['identifier' => '001234', 'quantity' => $quantity]], $result['rows']);
        $this->assertSame(0, $result['invalid_count']);
        $this->assertTrue($result['complete']);
    }

    public static function supplierSnapshots(): array
    {
        return [
            'Braytron ProductCode' => ['braytron', '<Stoklar><Stok><ProductCode>001234</ProductCode><Quantity>7</Quantity></Stok></Stoklar>', 7],
            'Master code' => ['master', '<products><product><code>001234</code><stock>8</stock></product></products>', 8],
            'Videx vendorCode' => ['videx', '<yml_catalog><shop><offers><offer><vendorCode>001234</vendorCode><stock_quantity>9</stock_quantity></offer></offers></shop></yml_catalog>', 9],
            'Videx normal external DOCTYPE' => ['videx', '<!DOCTYPE yml_catalog SYSTEM "shops.dtd"><yml_catalog><shop><offers><offer><vendorCode>001234</vendorCode><stock_quantity>9</stock_quantity></offer></offers></shop></yml_catalog>', 9],
            'DPM legacy BOX_QTY' => ['dpm', '<offers><PRODUCT><ean>001234</ean><stock>99</stock><BOX_QTY>10</BOX_QTY></PRODUCT></offers>', 10],
            'DPM empty BOX_QTY' => ['dpm', '<offers><PRODUCT><ean>001234</ean><BOX_QTY/></PRODUCT></offers>', 0],
            'Vayox EAN' => ['vayox', '<products><product><ean>001234</ean><sku>OTHER</sku><quantity>11</quantity></product></products>', 11],
            'Enovalite semicolon CSV' => ['enovalite', "\xEF\xBB\xBFID;\"Stueckzahl\"\r\n\"001234\";\"12\"\r\n", 12],
            'Enovalite comma CSV' => ['enovalite', "ID,Stueckzahl\n001234,12\n", 12],
        ];
    }

    public function test_e_racuni_sends_legacy_post_auth_and_reads_wrapped_stock_rows(): void
    {
        $client = $this->client('eracuni', ['url' => 'https://feeds.example.test/api/v1/', 'username' => 'test-user', 'token' => 'test-token', 'password' => 'test-password']);
        Http::fake(['feeds.example.test/*' => Http::response(['response' => ['result' => [
            ['StockQuantityInfo' => ['productCode' => '001234', 'quantityOnStock' => '13.9']],
        ]]])]);

        $result = $client->fetch('eracuni');

        $this->assertSame([['identifier' => '001234', 'quantity' => 13]], $result['rows']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://feeds.example.test/api/v1/WarehouseGetArticleStockQuantity'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-user:test-token_test-password'))
            && $request->hasHeader('Accept', 'application/json')
            && $request->body() === '');
    }

    public function test_e_racuni_raw_json_8_bit_encoding_is_not_sent_for_curl_decompression(): void
    {
        $client = $this->client('eracuni', ['username' => 'test-user', 'token' => 'test-token', 'password' => 'test-password']);
        Http::fake(function (Request $request, array $options) {
            $this->assertFalse($options['decode_content']);
            $this->assertTrue($request->hasHeader('Accept-Encoding', 'identity'));

            return Http::response('{"response":{"result":[{"StockQuantityInfo":{"productCode":"001234","quantityOnStock":3}}]}}', 200, [
                'Content-Type' => 'application/json', 'Content-Encoding' => '8-bit',
            ]);
        });

        $this->assertSame([['identifier' => '001234', 'quantity' => 3]], $client->fetch('eracuni')['rows']);
    }

    public function test_blank_vayox_eans_are_skipped_but_bad_quantities_are_marked_invalid(): void
    {
        $client = $this->client('vayox');
        Http::fake(['feeds.example.test/*' => Http::response('<products>
            <product><ean>001234</ean><quantity>4</quantity></product>
            <product><ean> </ean><quantity>7</quantity></product>
            <product><ean>INVALID</ean><quantity>unknown</quantity></product>
            <product><ean>NEGATIVE</ean><quantity>-1</quantity></product>
            <product><ean>OVERFLOW</ean><quantity>2147483648</quantity></product>
            <product><ean>NEGATIVE_OVERFLOW</ean><quantity>-2147483648</quantity></product>
            <product><ean>MISSING</ean></product>
        </products>')]);

        $result = $client->fetch('vayox');

        $this->assertSame([['identifier' => '001234', 'quantity' => 4], ['identifier' => 'NEGATIVE', 'quantity' => 0]], $result['rows']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(4, $result['invalid_count']);
        $this->assertSame(1, $result['clamped_count']);
    }

    public function test_blank_erp_article_identifiers_invalidate_the_whole_warehouse_snapshot(): void
    {
        $client = $this->client('eracuni', ['username' => 'test-user', 'token' => 'test-token', 'password' => 'test-password']);
        Http::fake(['feeds.example.test/*' => Http::response(['response' => ['result' => [
            ['StockQuantityInfo' => ['productCode' => '001234', 'quantityOnStock' => 3]],
            ['StockQuantityInfo' => ['productCode' => ' ', 'quantityOnStock' => 7]],
        ]]])]);

        $result = $client->fetch('eracuni');

        $this->assertSame([['identifier' => '001234', 'quantity' => 3]], $result['rows']);
        $this->assertSame(1, $result['invalid_count']);
        $this->assertSame(0, $result['skipped_count']);
    }

    public function test_dpm_keeps_the_first_pack_quantity_for_a_duplicate_ean_like_the_legacy_import(): void
    {
        $client = $this->client('dpm');
        Http::fake(['feeds.example.test/*' => Http::response('<offers>
            <PRODUCT><ean>001234</ean><BOX_QTY>6</BOX_QTY></PRODUCT>
            <PRODUCT><ean>001234</ean><BOX_QTY>12</BOX_QTY></PRODUCT>
        </offers>')]);

        $result = $client->fetch('dpm');

        $this->assertSame([['identifier' => '001234', 'quantity' => 6]], $result['rows']);
        $this->assertSame(1, $result['duplicate_count']);
        $this->assertSame(0, $result['invalid_count']);
    }

    public function test_braytron_stock_and_photo_downloads_share_the_successful_feed_within_supplier_quota(): void
    {
        $client = $this->client('braytron');
        Http::fake(['feeds.example.test/*' => Http::response('<Stoklar><Stok><ProductCode>001234</ProductCode><Quantity>7</Quantity><Image2>https://images.example.test/photo.jpg</Image2></Stok></Stoklar>')]);

        $this->assertSame(7, $client->fetch('braytron')['rows'][0]['quantity']);
        $body = app(\App\Services\Integrations\Stock\BraytronFeedClient::class)->body(['url' => 'https://feeds.example.test/stock']);

        $this->assertStringContainsString('<Image2>', $body);
        Http::assertSentCount(1);
    }

    #[DataProvider('unsafeXmlSnapshots')]
    public function test_malformed_empty_and_entity_xml_cannot_be_a_stock_snapshot(string $body): void
    {
        $client = $this->client('braytron');
        Http::fake(['feeds.example.test/*' => Http::response($body)]);

        $this->expectException(RuntimeException::class);
        $client->fetch('braytron');
    }

    public static function unsafeXmlSnapshots(): array
    {
        return [
            'truncated' => ['<Stoklar><Stok><ProductCode>001234</ProductCode><Quantity>5</Quantity></Stok>'],
            'empty' => ['<Stoklar/>'],
            'wrong schema' => ['<html><body>Feed temporarily unavailable</body></html>'],
            'external entity' => ['<!DOCTYPE Stoklar [<!ENTITY secret SYSTEM "file:///etc/passwd">]><Stoklar><Stok><ProductCode>&secret;</ProductCode><Quantity>4</Quantity></Stok></Stoklar>'],
            'internal subset' => ['<!DOCTYPE Stoklar [<!ELEMENT Stoklar ANY>]><Stoklar><Stok><ProductCode>001234</ProductCode><Quantity>4</Quantity></Stok></Stoklar>'],
            'all invalid' => ['<Stoklar><Stok><ProductCode>001234</ProductCode><Quantity>invalid</Quantity></Stok></Stoklar>'],
        ];
    }

    #[DataProvider('incompleteErpSnapshots')]
    public function test_incomplete_or_unsuccessful_erp_responses_cannot_reset_own_stock(array $body): void
    {
        $client = $this->client('eracuni', ['username' => 'test-user', 'token' => 'test-token', 'password' => 'test-password']);
        Http::fake(['feeds.example.test/*' => Http::response($body)]);

        $this->expectException(RuntimeException::class);
        $client->fetch('eracuni');
    }

    public static function incompleteErpSnapshots(): array
    {
        $valid = ['StockQuantityInfo' => ['productCode' => '001234', 'quantityOnStock' => 3]];

        return [
            'error' => [['response' => ['error' => 'Access denied']]],
            'empty result' => [['response' => ['result' => []]]],
            'partial result' => [['response' => ['result' => [$valid], 'complete' => false]]],
            'more pages' => [['response' => ['result' => [$valid], 'hasMore' => true]]],
            'missing quantity' => [['response' => ['result' => [['StockQuantityInfo' => ['productCode' => '001234']]]]]],
            'unrecognised result object' => [['response' => ['result' => ['StockQuantityInfo' => $valid['StockQuantityInfo']]]]],
        ];
    }

    public function test_http_failure_is_reported_without_secret_url_details(): void
    {
        $client = $this->client('master', ['url' => 'https://feeds.example.test/secret-feed-token.xml']);
        Http::fake(['feeds.example.test/*' => Http::response('upstream secret response', 500)]);

        try {
            $client->fetch('master');
            $this->fail('Expected a failed snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertStringNotContainsString('https://', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_connection_errors_are_sanitized_without_chained_sensitive_exception(): void
    {
        $client = $this->client('master');
        Http::fake(fn () => throw new ConnectionException('Could not reach https://feed.invalid/private-token'));

        try {
            $client->fetch('master');
            $this->fail('Expected a failed snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Dohvat izvora zalihe nije uspio.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_brock_reads_the_recovered_legacy_feed_stock_attribute_and_preserves_ean(): void
    {
        $client = $this->client('brock');
        Http::fake(['feeds.example.test/*' => Http::response('<catalog><products_inner><product_inner><ean_code>001234</ean_code><stock value="4"/></product_inner><product_inner><ean_code>000567</ean_code><stock value="0"/></product_inner></products_inner></catalog>')]);
        $result = $client->fetch('brock');
        $this->assertSame([['identifier' => '001234', 'quantity' => 4], ['identifier' => '000567', 'quantity' => 0]], $result['rows']);
        $this->assertSame(0, $result['invalid_count']);
    }

    private function client(string $supplier, array $connection = []): StockFeedClient
    {
        $this->mock(StockSyncSettingsService::class)->shouldReceive('connection')->once()->with($supplier)
            ->andReturn($connection + ['url' => 'https://feeds.example.test/stock']);

        return app(StockFeedClient::class);
    }
}
