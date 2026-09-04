<?php

namespace Tests\Feature\Admin;

use App\Models\Integrations\Msan\MsanProduct;
use App\Models\User;
use App\Services\Integrations\Msan\Exceptions\MsanProductImageNotFoundException;
use App\Services\Integrations\Msan\Exceptions\MsanProductImagePreviewUnavailableException;
use App\Services\Integrations\Msan\MsanClient;
use App\Services\Integrations\Msan\MsanProductImagePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class MsanProductImagePreviewFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_service_caches_a_small_webp_thumbnail_and_refreshes_it_for_a_new_catalog_revision(): void
    {
        Storage::fake('local');
        $product = $this->createMsanProduct('http://b2b.msan.hr/private/catalog/product.png?token=sensitive');
        $sourcePaths = [];
        $client = Mockery::mock(MsanClient::class);
        $client->shouldReceive('downloadProductImage')
            ->twice()
            ->with(
                'https://b2b.msan.hr/private/catalog/product.png?token=sensitive',
                Mockery::type('string'),
            )
            ->andReturnUsing(function (string $url, string $path) use (&$sourcePaths): void {
                $sourcePaths[] = $path;
                file_put_contents($path, $this->onePixelPng());
            });

        $previews = new MsanProductImagePreviewService($client);
        $firstPath = $previews->cachedPath($product);
        $secondPath = $previews->cachedPath($product);
        $size = getimagesize($firstPath);
        $sentinelPath = dirname($firstPath).'/do-not-delete.txt';
        file_put_contents($sentinelPath, 'sentinel');
        $product->forceFill(['catalog_checksum' => 'new-catalog-revision'])->save();
        $refreshedPath = $previews->cachedPath($product->fresh());

        $this->assertSame($firstPath, $secondPath);
        $this->assertNotSame($firstPath, $refreshedPath);
        $this->assertFileDoesNotExist($firstPath);
        $this->assertFileExists($refreshedPath);
        $this->assertFileExists($sentinelPath);
        $this->assertSame('image/webp', $previews->mimeType($refreshedPath));
        $this->assertIsArray($size);
        $this->assertLessThanOrEqual(192, $size[0]);
        $this->assertLessThanOrEqual(192, $size[1]);
        $this->assertStringNotContainsString('token', basename($firstPath));
        $this->assertCount(2, array_unique($sourcePaths));
        foreach ($sourcePaths as $sourcePath) {
            $this->assertStringContainsString('.source-', basename($sourcePath));
            $this->assertFileDoesNotExist($sourcePath);
        }
    }

    public function test_preview_service_refreshes_an_expired_cached_preview_in_place(): void
    {
        Storage::fake('local');
        $product = $this->createMsanProduct('https://b2b.msan.hr/private/catalog/product.png');
        $client = Mockery::mock(MsanClient::class);
        $client->shouldReceive('downloadProductImage')
            ->twice()
            ->andReturnUsing(function (string $url, string $path): void {
                file_put_contents($path, $this->onePixelPng());
            });

        $previews = new MsanProductImagePreviewService($client);
        $firstPath = $previews->cachedPath($product);
        touch($firstPath, now()->subDays(2)->timestamp);
        clearstatcache(true, $firstPath);
        $refreshedPath = $previews->cachedPath($product);

        $this->assertSame($firstPath, $refreshedPath);
        $this->assertGreaterThan(now()->subMinute()->timestamp, filemtime($refreshedPath));
    }

    #[DataProvider('unsafeImageDimensions')]
    public function test_preview_service_rejects_excessive_dimensions_before_decoding(int $width, int $height): void
    {
        Storage::fake('local');
        $product = $this->createMsanProduct('https://b2b.msan.hr/private/catalog/oversized.png');
        $client = Mockery::mock(MsanClient::class);
        $client->shouldReceive('downloadProductImage')
            ->once()
            ->andReturnUsing(function (string $url, string $path) use ($width, $height): void {
                file_put_contents($path, $this->pngWithDimensions($width, $height));
            });

        $this->expectException(MsanProductImageNotFoundException::class);

        (new MsanProductImagePreviewService($client))->cachedPath($product);
    }

    /** @return array<string, array{int, int}> */
    public static function unsafeImageDimensions(): array
    {
        return [
            'dimension limit' => [8001, 1],
            'pixel limit' => [6000, 5000],
        ];
    }

    public function test_preview_lock_lease_exceeds_the_maximum_supplier_request_timeout(): void
    {
        $leaseSeconds = (new \ReflectionClass(MsanProductImagePreviewService::class))
            ->getConstant('LOCK_LEASE_SECONDS');

        $this->assertIsInt($leaseSeconds);
        $this->assertGreaterThan(300, $leaseSeconds);
    }

    public function test_preview_service_rejects_non_msan_image_hosts_before_downloading(): void
    {
        Storage::fake('local');
        $product = $this->createMsanProduct('https://example.test/product.png');
        $client = Mockery::mock(MsanClient::class);
        $client->shouldNotReceive('downloadProductImage');

        $this->expectException(\RuntimeException::class);

        (new MsanProductImagePreviewService($client))->cachedPath($product);
    }

    public function test_authorized_admin_receives_a_cached_preview_through_an_id_only_internal_url(): void
    {
        $admin = $this->makeAdmin();
        $sourceUrl = 'https://b2b.msan.hr/private/catalog/product.png?token=sensitive';
        $product = $this->createMsanProduct($sourceUrl);
        Storage::fake('local');
        $relativePath = 'testing/msan-product-preview.png';
        Storage::disk('local')->put($relativePath, $this->onePixelPng());
        $cachedPath = Storage::disk('local')->path($relativePath);

        $preview = Mockery::mock(MsanProductImagePreviewService::class);
        $preview->shouldReceive('cachedPath')
            ->once()
            ->with(Mockery::on(
                static fn (MsanProduct $candidate): bool => $candidate->is($product),
            ))
            ->andReturn($cachedPath);
        $preview->shouldReceive('mimeType')
            ->once()
            ->with($cachedPath)
            ->andReturn('image/png');
        $this->app->instance(MsanProductImagePreviewService::class, $preview);

        $url = $this->previewUrl($product);
        $parts = parse_url($url);

        $this->assertStringContainsString('/'.$product->getKey().'/', (string) ($parts['path'] ?? ''));
        $this->assertArrayNotHasKey('query', $parts);
        $this->assertStringNotContainsString('b2b.msan.hr', $url);
        $this->assertStringNotContainsString(rawurlencode($sourceUrl), $url);

        $response = $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $cacheControl = (string) $response->headers->get('cache-control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function test_invalid_supplier_image_returns_not_found_without_exposing_details(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->createMsanProduct('https://b2b.msan.hr/private/catalog/invalid.png');
        $preview = Mockery::mock(MsanProductImagePreviewService::class);
        $preview->shouldReceive('cachedPath')
            ->once()
            ->andThrow(new MsanProductImageNotFoundException('sensitive supplier detail'));
        $preview->shouldNotReceive('mimeType');
        $this->app->instance(MsanProductImagePreviewService::class, $preview);

        $this->actingAs($admin)
            ->get($this->previewUrl($product))
            ->assertNotFound()
            ->assertDontSee('sensitive supplier detail');
    }

    public function test_transient_preview_failure_returns_retryable_service_unavailable_without_details(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->createMsanProduct('https://b2b.msan.hr/private/catalog/product.png');
        $preview = Mockery::mock(MsanProductImagePreviewService::class);
        $preview->shouldReceive('cachedPath')
            ->once()
            ->andThrow(new MsanProductImagePreviewUnavailableException('sensitive runtime detail'));
        $preview->shouldNotReceive('mimeType');
        $this->app->instance(MsanProductImagePreviewService::class, $preview);

        $response = $this->actingAs($admin)
            ->get($this->previewUrl($product))
            ->assertStatus(503)
            ->assertHeader('retry-after', '10')
            ->assertDontSee('sensitive runtime detail');

        $cacheControl = (string) $response->headers->get('cache-control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_product_without_a_source_image_returns_not_found(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->createMsanProduct(null);
        $preview = Mockery::mock(MsanProductImagePreviewService::class);
        $preview->shouldNotReceive('cachedPath');
        $this->app->instance(MsanProductImagePreviewService::class, $preview);

        $this->actingAs($admin)
            ->get($this->previewUrl($product))
            ->assertNotFound();
    }

    public function test_admin_without_msan_view_ability_cannot_request_a_product_preview(): void
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to('admin.access');
        Bouncer::refreshFor($user);
        $product = $this->createMsanProduct('https://b2b.msan.hr/private/catalog/product.png');
        $preview = Mockery::mock(MsanProductImagePreviewService::class);
        $preview->shouldNotReceive('cachedPath');
        $this->app->instance(MsanProductImagePreviewService::class, $preview);

        $this->actingAs($user)
            ->get($this->previewUrl($product))
            ->assertForbidden();
    }

    public function test_product_preview_route_is_rate_limited_for_admin_ui_usage(): void
    {
        $route = Route::getRoutes()->getByName('admin.integrations.msan.products.image');

        $this->assertNotNull($route);
        $this->assertContains('throttle:120,1', $route->gatherMiddleware());
    }

    private function previewUrl(MsanProduct $product): string
    {
        $route = Route::getRoutes()->getByName('admin.integrations.msan.products.image');

        $this->assertNotNull($route, 'The M SAN product image preview route is not registered.');
        $this->assertContains('GET', $route->methods());
        $this->assertCount(1, $route->parameterNames());

        return route(
            'admin.integrations.msan.products.image',
            [$route->parameterNames()[0] => $product->getRouteKey()],
        );
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function createMsanProduct(?string $imageUrl): MsanProduct
    {
        return MsanProduct::query()->create([
            'external_code' => 'IMAGE-'.fake()->unique()->numerify('#####'),
            'name' => 'M SAN artikl sa slikom',
            'brand' => 'Test Brand',
            'currency_code' => 'EUR',
            'image_url' => $imageUrl,
            'availability_level' => 2,
            'selected' => false,
            'is_stale' => false,
            'match_status' => MsanProduct::MATCH_UNMATCHED,
            'import_status' => MsanProduct::IMPORT_PENDING,
            'last_seen_at' => now(),
        ]);
    }

    private function onePixelPng(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
    }

    private function pngWithDimensions(int $width, int $height): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data))
                .$type
                .$data
                .pack('N', crc32($type.$data));
        };

        $header = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', $header)
            .$chunk('IEND', '');
    }
}
