<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\Media\ImportLegacyProductMediaJob;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Media\LegacyProductImageClient;
use App\Services\Integrations\Media\MediaImportService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MediaImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'media-library.queue_conversions_by_default' => true]);
        config(['media_profiles.models.'.Product::class.'.collections.product_main.conversions' => [], 'media_profiles.models.'.Product::class.'.collections.product_gallery.conversions' => []]);
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        Http::preventStrayRequests();
        app(StockSyncSettingsService::class)->saveConnection('eracuni', [
            'url' => 'https://erp.example.test/api', 'username' => 'sample-user', 'password' => 'private-password',
            'token' => 'private-token', 'url_image_suffix' => 'ProductImageGet',
        ]);
        app(StockSyncSettingsService::class)->saveConnection('braytron', ['url' => 'https://feed.example.test/xml?private-feed=secret']);
    }

    public function test_candidate_preview_is_local_preserves_existing_main_and_uses_original_sku(): void
    {
        $missing = $this->product('ADJUSTED', ['opencart' => ['sku' => '00003', 'image' => 'catalog/products/no-image.jpg']]);
        $manual = $this->product('MANUAL');
        $this->attach($manual, $this->png(10), 'product_main');
        $this->product('LEGACY-PHOTO', ['opencart' => ['image' => 'catalog/products/manual.jpg']]);
        $this->product('DUP-A', ['opencart' => ['sku' => 'DUP']]);
        $this->product('DUP-B', ['opencart' => ['sku' => 'DUP']]);

        $preview = app(MediaImportService::class)->candidates('images');

        $this->assertSame(1, $preview['count']);
        $this->assertSame($missing->id, $preview['products'][0]['id']);
        $this->assertSame('00003', $preview['products'][0]['identifier']);
        Http::assertNothingSent();
    }

    public function test_start_enqueues_and_erp_attachment_envelope_adds_only_missing_main(): void
    {
        $product = $this->product('ADJUSTED', ['opencart' => ['sku' => '00003', 'image' => 'catalog/products/no-image.jpg']]);
        $before = $product->fresh()->only(['payload', 'base_price', 'stock_qty', 'supplier_stock_qty']);
        Http::fake(['https://erp.example.test/api/ProductImageGet' => Http::response([
            'response' => ['result' => ['FileAttachment' => ['fileName' => '../../private-name.php', 'contents' => base64_encode($this->png(20))]]],
        ], 200, ['Content-Encoding' => '8-bit'])]);
        $service = app(MediaImportService::class);

        $run = $service->start('images', [$product->id]);

        $this->assertSame('queued', $run->status);
        $this->assertCount(0, $product->fresh()->getMedia('product_main'));
        Http::assertNothingSent();
        Queue::assertPushed(ImportLegacyProductMediaJob::class, fn ($job) => $job->runId === $run->id);
        $finished = $service->process($run->id);

        $this->assertSame('completed', $finished->status);
        $this->assertSame(1, $finished->updated_count);
        $this->assertSame(1, $finished->summary['images_added']);
        $media = $product->fresh()->getFirstMedia('product_main');
        $this->assertSame('eracuni', $media->getCustomProperty('supplier_source'));
        $this->assertStringEndsWith('.png', $media->file_name);
        $this->assertStringNotContainsString('private', $media->file_name);
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
        Http::assertSent(fn (Request $request) => $request['productCode'] === '"00003"'
            && $request->hasHeader('Accept-Encoding', 'identity')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sample-user:private-token_private-password')));
        $this->assertSame('imported', $finished->items()->sole()->status);
        $this->assertFalse(Cache::has('herrera-eracuni-api'));
        $again = $service->process($run->id);
        $this->assertSame(1, $again->summary['images_added']);
        $this->assertCount(1, $product->fresh()->getMedia('product_main'));
        Http::assertSentCount(1);
    }

    public function test_photo_added_during_erp_fetch_is_preserved(): void
    {
        $product = $this->product('RACE');
        Http::fake(function () use ($product) {
            $this->attach($product, $this->png(30), 'product_main');

            return Http::response(['response' => ['result' => ['contents' => base64_encode($this->png(40))]]]);
        });
        $service = app(MediaImportService::class);
        $run = $service->start('images', [$product->id]);

        $finished = $service->process($run->id);

        $this->assertSame('completed', $finished->status);
        $this->assertSame(1, $finished->skipped_count);
        $media = $product->fresh()->getFirstMedia('product_main');
        $this->assertSame(hash('sha256', $this->png(30)), hash('sha256', Storage::disk('public')->get($media->getPathRelativeToRoot())));
    }

    public function test_erp_empty_photo_result_is_reported_without_writes(): void
    {
        $product = $this->product('EMPTY');
        Http::fake(['*' => Http::response(['response' => ['result' => null]])]);
        $service = app(MediaImportService::class);
        $run = $service->start('images', [$product->id]);

        $finished = $service->process($run->id);

        $this->assertSame('completed', $finished->status);
        $this->assertSame(1, $finished->skipped_count);
        $this->assertCount(0, $product->fresh()->getMedia('product_main'));
    }

    public function test_invalid_attachment_is_failed_without_exposing_source_or_secret(): void
    {
        $product = $this->product('INVALID');
        Http::fake(['*' => Http::response(['response' => ['result' => ['Attachment' => ['contents' => base64_encode('<?php secret malicious image')]]]])]);
        $service = app(MediaImportService::class);
        $run = $service->start('images', [$product->id]);

        $finished = $service->process($run->id);

        $this->assertSame('failed', $finished->status);
        $this->assertSame(1, $finished->summary['failed_count']);
        $this->assertCount(0, $product->fresh()->getMedia('product_main'));
        $json = json_encode($finished->load('items')->toArray());
        foreach (['private-password', 'private-token', 'erp.example.test', 'malicious'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_braytron_shared_feed_imports_image_two_through_ten_and_hash_deduplicates_manual_media(): void
    {
        $product = $this->product('ADJUSTED', ['opencart' => ['sku' => '00123', 'image' => 'catalog/products/manual.jpg']]);
        $main = $this->attach($product, $this->png(1), 'product_main');
        $manual = $this->attach($product, $this->png(2), 'product_gallery');
        $body = '<Stoklar><Stok><ProductCode>00123</ProductCode><Quantity>4</Quantity><Image1>https://images.example.test/ignored.png</Image1><Image2>https://images.example.test/manual.png</Image2><Image3>https://images.example.test/new.png</Image3><Image4>https://images.example.test/same-bytes.png</Image4><Image10>https://images.example.test/ten.png</Image10></Stok></Stoklar>';
        Cache::put('herrera-braytron-feed:'.hash('sha256', 'https://feed.example.test/xml?private-feed=secret'), $body, 10800);
        Http::fake([
            'https://images.example.test/manual.png' => Http::response($this->png(2)),
            'https://images.example.test/new.png' => Http::response($this->png(3)),
            'https://images.example.test/same-bytes.png' => Http::response($this->png(3)),
            'https://images.example.test/ten.png' => Http::response($this->png(10)),
        ]);
        $service = app(MediaImportService::class);
        $run = $service->start('braytron_images', [$product->id]);

        $finished = $service->process($run->id);

        $this->assertSame('completed', $finished->status);
        $this->assertSame(2, $finished->summary['images_added']);
        $this->assertSame(2, $finished->summary['duplicate_count']);
        $this->assertSame($main->id, $product->fresh()->getFirstMedia('product_main')->id);
        $this->assertContains($manual->id, $product->fresh()->getMedia('product_gallery')->pluck('id'));
        $this->assertCount(3, $product->fresh()->getMedia('product_gallery'));
        Http::assertSentCount(4);
        $second = $service->start('braytron_images', [$product->id]);
        $second = $service->process($second->id);
        $this->assertSame(0, $second->summary['images_added']);
        $this->assertSame(4, $second->summary['duplicate_count']);
        $this->assertSame(1, $second->unchanged_count);
        $this->assertCount(3, $product->fresh()->getMedia('product_gallery'));
        $this->assertStringNotContainsString('https://', json_encode($finished->load('items')->toArray()));
    }

    public function test_private_supplier_image_url_is_rejected_and_good_gallery_image_survives_partial_failure(): void
    {
        $product = $this->product('PARTIAL');
        $body = '<Stoklar><Stok><ProductCode>PARTIAL</ProductCode><Quantity>4</Quantity><Image2>http://127.0.0.1/private-token</Image2><Image3>https://images.example.test/good.png</Image3></Stok></Stoklar>';
        Cache::put('herrera-braytron-feed:'.hash('sha256', 'https://feed.example.test/xml?private-feed=secret'), $body, 10800);
        Http::fake(['https://images.example.test/good.png' => Http::response($this->png(50))]);
        $service = app(MediaImportService::class);
        $run = $service->start('braytron_images', [$product->id]);

        $finished = $service->process($run->id);

        $this->assertSame('failed', $finished->status);
        $this->assertSame(1, $finished->summary['images_added']);
        $this->assertSame(1, $finished->summary['failed_images']);
        $this->assertCount(1, $product->fresh()->getMedia('product_gallery'));
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('private-token', json_encode($finished->load('items')->toArray()));
    }

    public function test_worker_processes_one_product_and_reschedules_the_remaining_bounded_batch(): void
    {
        $first = $this->product('FIRST');
        $second = $this->product('SECOND');
        Http::fake(['*' => Http::response(['response' => ['result' => null]])]);
        $service = app(MediaImportService::class);
        $run = $service->start('images', [$first->id, $second->id]);

        (new ImportLegacyProductMediaJob($run->id))->handle($service);

        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame(1, $run->items()->where('status', 'queued')->count());
        Http::assertSentCount(1);
        Queue::assertPushed(ImportLegacyProductMediaJob::class, 2);
        (new ImportLegacyProductMediaJob($run->id))->handle($service);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->skipped_count);
    }

    public function test_new_main_or_changed_identity_before_worker_prevents_external_fetch(): void
    {
        $product = $this->product('BEFORE');
        $service = app(MediaImportService::class);
        $run = $service->start('images', [$product->id]);
        $product->forceFill(['sku' => 'AFTER'])->save();

        $finished = $service->process($run->id);

        $this->assertSame('completed', $finished->status);
        $this->assertSame(1, $finished->skipped_count);
        Http::assertNothingSent();
    }

    public function test_braytron_candidates_use_brand_and_exclude_legacy_completed_imports(): void
    {
        $manufacturer = Manufacturer::query()->create(['code' => 'braytron', 'is_active' => true]);
        $wanted = $this->product('BRAYTRON');
        $wanted->update(['manufacturer_id' => $manufacturer->id]);
        $done = $this->product('OLD-DONE', ['opencart' => ['mpn' => '1']]);
        $done->update(['manufacturer_id' => $manufacturer->id]);
        $this->product('OTHER');

        $preview = app(MediaImportService::class)->candidates('braytron_images');

        $this->assertSame(1, $preview['count']);
        $this->assertSame($wanted->id, $preview['products'][0]['id']);
        Http::assertNothingSent();
    }

    public function test_over_limit_and_conflicting_identity_are_rejected_before_queue_or_media_writes(): void
    {
        $service = app(MediaImportService::class);
        try {
            $service->start('images', range(1, 101));
            $this->fail('Over-limit batch accepted.');
        } catch (RuntimeException) {
            Queue::assertNothingPushed();
        }
        $first = $this->product('DUP-A', ['opencart' => ['sku' => 'SAME']]);
        $this->product('DUP-B', ['opencart' => ['sku' => 'SAME']]);
        $this->expectException(RuntimeException::class);
        $service->start('images', [$first->id]);
    }

    public function test_in_progress_source_blocks_second_run_and_worker_failure_closes_report_safely(): void
    {
        $product = $this->product('BUSY');
        $service = app(MediaImportService::class);
        $run = $service->start('images', [$product->id]);
        try {
            $service->start('images', [$product->id]);
            $this->fail('Concurrent run accepted.');
        } catch (RuntimeException) {
            $this->assertSame('queued', $run->fresh()->status);
        }

        (new ImportLegacyProductMediaJob($run->id))->failed(new RuntimeException('private-password https://private.invalid'));

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('failed', $run->items()->sole()->status);
        $this->assertStringNotContainsString('private-password', json_encode($run->fresh()->load('items')->toArray()));
        Http::assertNothingSent();
    }

    public function test_failed_queue_dispatch_closes_report_and_does_not_block_a_new_run(): void
    {
        $product = $this->product('QUEUE-FAIL');
        $service = \Mockery::mock(MediaImportService::class, [app(StockSyncSettingsService::class), app(LegacyProductImageClient::class)])->makePartial();
        $service->shouldReceive('enqueue')->once()->andThrow(new RuntimeException('private queue URL private-password'));
        try {
            $service->start('images', [$product->id]);
            $this->fail('Queue failure was hidden.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private', $exception->getMessage());
        }
        $failed = \App\Models\Integrations\Eracuni\EracuniCatalogRun::query()->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('failed', $failed->items()->sole()->status);
        $this->assertNotNull($failed->completed_at);
        $retry = app(MediaImportService::class)->start('images', [$product->id]);
        $this->assertSame('queued', $retry->status);
        Http::assertNothingSent();
    }

    public function test_synchronous_default_queue_is_overridden_and_uploaded_placeholder_can_be_filled(): void
    {
        config(['queue.default' => 'sync']);
        $product = $this->product('PLACEHOLDER');
        $file = Storage::disk('local')->path('placeholder.png');
        file_put_contents($file, $this->png(5));
        $placeholder = $product->addMedia($file)->usingFileName('no-image.jpg')->toMediaCollection('product_main');
        Http::fake(['*' => Http::response(['response' => ['status' => 'ok', 'result' => [['Attachment' => ['fileName' => 'sku.jpg', 'contents' => base64_encode($this->png(6))]]]]])]);
        $service = app(MediaImportService::class);
        $this->assertSame(1, $service->candidates('images')['count']);

        $run = $service->start('images', [$product->id]);

        Queue::assertPushed(ImportLegacyProductMediaJob::class, fn ($job) => $job->connection === 'database');
        Http::assertNothingSent();
        $finished = $service->process($run->id);
        $this->assertSame('completed', $finished->status);
        $this->assertNotSame($placeholder->id, $product->fresh()->getFirstMedia('product_main')->id);
        $this->assertCount(1, $product->fresh()->getMedia('product_main'));
    }

    public function test_private_ipv6_and_authenticated_supplier_urls_are_rejected_without_requests(): void
    {
        $client = app(LegacyProductImageClient::class);
        foreach (['http://10.0.0.1/private', 'http://[::1]/private', 'http://[::ffff:127.0.0.1]/private', 'https://user:password@images.example.test/private', 'file:///etc/passwd'] as $url) {
            try {
                $client->download($url);
                $this->fail('Non-public URL accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString($url, $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_oversized_supplier_image_is_rejected_before_media_writes(): void
    {
        Http::fake(['https://images.example.test/huge.png' => Http::response($this->png(1).str_repeat('x', LegacyProductImageClient::MAX_IMAGE_BYTES))]);
        $this->expectException(RuntimeException::class);
        app(LegacyProductImageClient::class)->download('https://images.example.test/huge.png');
    }

    public function test_manual_main_insert_in_final_save_window_survives_non_destructive_promotion(): void
    {
        $product = $this->product('SAVE-RACE');
        Http::fake(['*' => Http::response(['response' => ['result' => ['contents' => base64_encode($this->png(70))]]])]);
        $service = app(MediaImportService::class);
        $run = $service->start('images', [$product->id]);
        $original = \Spatie\MediaLibrary\MediaCollections\Models\Media::getEventDispatcher();
        \Spatie\MediaLibrary\MediaCollections\Models\Media::setEventDispatcher(clone $original);
        $manual = null;
        try {
            \Spatie\MediaLibrary\MediaCollections\Models\Media::creating(function ($media) use ($product, &$manual): void {
                if ($media->collection_name === 'product_erp_import_staging' && $manual === null) {
                    $manual = $this->attach($product, $this->png(71), 'product_main');
                }
            });
            $finished = $service->process($run->id);
        } finally {
            \Spatie\MediaLibrary\MediaCollections\Models\Media::setEventDispatcher($original);
        }

        $this->assertSame('completed', $finished->status);
        $this->assertSame(1, $finished->skipped_count);
        $this->assertSame($manual->id, $product->fresh()->getFirstMedia('product_main')->id);
        Storage::disk('public')->assertExists($manual->getPathRelativeToRoot());
        $this->assertCount(0, $product->fresh()->getMedia('product_erp_import_staging'));
    }

    public function test_completed_missing_photo_batch_advances_beyond_first_hundred_and_can_be_explicitly_retried(): void
    {
        $ids = [];
        for ($index = 1; $index <= 101; $index++) {
            $ids[] = $this->product('BATCH-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT))->id;
        }
        Http::fake(['*' => Http::response(['response' => ['result' => null]])]);
        $service = app(MediaImportService::class);
        $run = $service->start('images');
        $this->assertSame(100, $run->eligible_count);
        for ($index = 0; $index < 100; $index++) {
            $run = $service->process($run->id);
        }
        $this->assertSame('completed', $run->status);
        $preview = $service->candidates('images');
        $this->assertSame(1, $preview['count']);
        $this->assertSame($ids[100], $preview['products'][0]['id']);
        $next = $service->start('images');
        $this->assertSame($ids[100], $next->items()->sole()->product_id);
        $service->process($next->id);
        $retry = $service->retry($run->id);
        $this->assertSame(100, $retry->eligible_count);
        $this->assertSame('queued', $retry->status);
        Http::assertSentCount(101);
    }

    private function product(string $sku, array $payload = []): Product
    {
        return Product::query()->create(['code' => $sku, 'sku' => $sku, 'base_price' => 15, 'stock_qty' => 5, 'supplier_stock_qty' => 7, 'payload' => $payload]);
    }

    private function png(int $red): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, 0, 0));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function attach(Product $product, string $bytes, string $collection)
    {
        $file = Storage::disk('local')->path('test-'.bin2hex(random_bytes(8)).'.png');
        file_put_contents($file, $bytes);

        return $product->addMedia($file)->toMediaCollection($collection);
    }
}
