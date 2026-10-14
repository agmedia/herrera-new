<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Media\VayoxDocumentClient;
use App\Services\Integrations\Media\VayoxDocumentImportService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class VayoxDocumentImportTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = 'https://feed.example.test/private?token=do-not-log';

    private const PDF = 'https://docs.example.test/kod-pilot%C3%B3w.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('public');
        config(['supplier-documents.vayox.document_hosts' => ['docs.example.test']]);
        $this->mock(StockSyncSettingsService::class)->shouldReceive('connection')->with('vayox')->andReturn(['url' => self::FEED]);
    }

    public function test_it_reads_real_description_anchors_normalizes_unicode_and_deduplicates_exact_ean_links(): void
    {
        $this->fakeFeed([
            ['ean' => '5905490800879', 'html' => '<a href="https://docs.example.test/kod-pilotów.pdf">Lista kodów</a><a href="'.self::PDF.'">Lista kodów</a>'],
            ['ean' => '5905490800886', 'html' => '<a href="'.self::PDF.'">Kody</a>'],
            ['ean' => '0012345678901', 'html' => '<a href="https://evil.example.test/other.pdf">Bad</a><a href="https://docs.example.test/doc.pdf?token=secret">Query</a><a href="http://docs.example.test/doc.pdf">HTTP</a>'],
            ['ean' => 'not-an-ean', 'html' => '<a href="'.self::PDF.'">Wrong identity</a>'],
        ]);
        $links = app(VayoxDocumentClient::class)->links();
        $this->assertCount(2, $links);
        $this->assertSame(['5905490800879', '5905490800886'], array_column($links, 'ean'));
        $this->assertSame([self::PDF, self::PDF], array_column($links, 'url'));
        $this->assertSame('Lista kodów', $links[0]['label']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->url() === self::PDF);
    }

    public function test_url_validator_is_pure_and_rejects_insecure_private_credential_and_unapproved_urls(): void
    {
        $this->assertSame(self::PDF, VayoxDocumentClient::safeDocumentUrl('https://docs.example.test/kod-pilotów.pdf'));
        foreach (['http://docs.example.test/doc.pdf', 'https://evil.example.test/doc.pdf',
            'https://user:password@docs.example.test/doc.pdf', 'https://docs.example.test/doc.pdf?secret=token',
            'https://docs.example.test/doc.pdf#fragment', 'https://docs.example.test:8443/doc.pdf',
            'javascript:alert(1)', "https://docs.example.test/doc.pdf\n", 'https://docs.example.test/photo.jpg'] as $url) {
            $this->assertNull(VayoxDocumentClient::safeDocumentUrl($url));
        }
        config(['supplier-documents.vayox.document_hosts' => ['127.0.0.1']]);
        $this->assertNull(VayoxDocumentClient::safeDocumentUrl('https://127.0.0.1/private.pdf'));
        Http::assertNothingSent();
    }

    public function test_entity_bearing_xml_and_oversized_or_redirected_feeds_fail_without_exposing_connection_details(): void
    {
        foreach ([
            ['body' => '<!DOCTYPE products [<!ENTITY secret SYSTEM "file:///etc/passwd">]><products/>', 'status' => 200],
            ['body' => '<products/>', 'status' => 200, 'headers' => ['Content-Length' => 99999999]],
            ['body' => '', 'status' => 302, 'headers' => ['Location' => 'https://evil.example.test/feed']],
        ] as $response) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([self::FEED => fn () => Http::response($response['body'], $response['status'], $response['headers'] ?? [])]);
            try {
                app(VayoxDocumentClient::class)->links();
                $this->fail('Unsafe feed must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString('token', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.test'));
    }

    public function test_sync_only_writes_external_links_preserves_manual_uploads_and_existing_payload_and_is_idempotent(): void
    {
        $first = $this->product('5905490800879', 'VA0141');
        $second = $this->product('5905490800886', 'VA0142');
        $first->update(['payload' => ['opencart' => ['sku' => 'VA0141'], 'supplier_documents' => [
            ['source' => 'manual', 'url' => 'https://other.example.test/manual.pdf', 'name' => 'Manual link'],
        ]]]);
        $manual = $first->addMediaFromString("%PDF-1.4\nmanual\n%%EOF\n")->usingFileName('manual.pdf')->toMediaCollection('product_documents');
        $this->fakeFeed([['ean' => $first->barcode], ['ean' => $second->barcode], ['ean' => '0000000000000']]);
        $service = app(VayoxDocumentImportService::class);
        $report = $service->sync();
        $this->assertSame(2, $report['added']);
        $this->assertSame(2, $report['matched_products']);
        $this->assertSame(1, $report['unmatched']);
        $this->assertSame(0, $report['failed']);
        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseHas('media', ['id' => $manual->id]);
        $payload = $first->fresh()->payload;
        $this->assertSame('VA0141', $payload['opencart']['sku']);
        $this->assertCount(2, $payload['supplier_documents']);
        $this->assertSame('manual', $payload['supplier_documents'][0]['source']);
        $this->assertSame(self::PDF, $payload['supplier_documents'][1]['url']);
        $this->assertSame('vayox', $payload['supplier_documents'][1]['source']);
        // Native MySQL JSON reorders keys even though SQLite preserves them.
        $payload['supplier_documents'][1] = array_reverse($payload['supplier_documents'][1], true);
        $first->update(['payload' => $payload]);
        $this->assertSame(2, $service->sync()['unchanged']);
        $this->assertSame($payload, $first->fresh()->payload);
        $this->assertCount(1, $second->fresh()->payload['supplier_documents']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->url() === self::PDF);
        $this->assertCount(1, $first->fresh()->getMedia('product_documents'));
    }

    public function test_changed_link_caption_updates_only_matching_supplier_metadata_without_downloading_files(): void
    {
        $product = $this->product('5905490800879', 'VA0141');
        $this->fakeFeed([['ean' => $product->barcode]]);
        $service = app(VayoxDocumentImportService::class);
        $this->assertSame(1, $service->sync()['added']);
        $this->fakeFeed([['ean' => $product->barcode, 'html' => '<a href="'.self::PDF.'">Updated supplier label</a>']]);
        $this->assertSame(1, $service->sync()['updated']);
        $documents = $product->fresh()->payload['supplier_documents'];
        $this->assertCount(1, $documents);
        $this->assertSame('Updated supplier label', $documents[0]['caption']);
        $this->assertDatabaseCount('media', 0);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->url() === self::PDF);
    }

    public function test_dry_run_selection_and_limit_keep_unselected_products_untouched(): void
    {
        $first = $this->product('5905490800879', 'VA0141');
        $second = $this->product('5905490800886', 'VA0142');
        $this->fakeFeed([['ean' => $first->barcode], ['ean' => $second->barcode]]);
        $service = app(VayoxDocumentImportService::class);
        $this->assertSame(2, $service->sync(true)['eligible_documents']);
        $this->assertNull($first->fresh()->payload);
        $this->assertSame(1, $service->sync(false, [(string) $second->id])['added']);
        $this->assertNull($first->fresh()->payload);
        $this->assertCount(1, $second->fresh()->payload['supplier_documents']);
        $limited = $service->sync(false, [], 1);
        $this->assertSame(1, $limited['deferred']);
        $this->assertSame(1, $limited['added']);
        $this->assertDatabaseCount('media', 0);
        Http::assertNotSent(fn ($request) => $request->url() === self::PDF);
    }

    public function test_command_synchronizes_only_metadata_and_scheduling_is_disabled_by_default(): void
    {
        $product = $this->product('5905490800879', 'VA0141');
        $this->fakeFeed([['ean' => $product->barcode]]);
        $this->artisan('herrera:sync-vayox-documents')->assertExitCode(0);
        $this->assertDatabaseCount('media', 0);
        $this->assertCount(1, $product->fresh()->payload['supplier_documents']);
        Http::assertSentCount(1);
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'herrera:sync-vayox-documents'));
        $this->assertNotNull($event);
        $this->assertFalse($event->filtersPass(app()));
        config(['supplier-documents.vayox.scheduled' => true]);
        $this->assertTrue($event->filtersPass(app()));
    }

    private function fakeFeed(array $rows): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::FEED => fn () => Http::response('<products>'.implode('', array_map(fn ($row) => '<product><ean>'.$row['ean'].'</ean><description><![CDATA['
            .($row['html'] ?? '<a href="'.self::PDF.'">Upute dobavljača</a>').']]></description></product>', $rows)).'</products>')]);
    }

    private function product(string $ean, string $sku): Product
    {
        return Product::query()->create(['code' => $sku, 'sku' => $sku, 'barcode' => $ean, 'base_price' => '10', 'is_active' => true]);
    }
}
