<?php

namespace Tests\Feature\Integrations;

use App\Models\Integrations\Msan\MsanEndpointState;
use App\Services\Integrations\Msan\MsanCertificateService;
use App\Services\Integrations\Msan\MsanClient;
use App\Services\Integrations\Msan\MsanFtpClient;
use App\Services\Integrations\Msan\MsanFtpTransportInterface;
use App\Services\Integrations\Msan\MsanSettingsService;
use App\Services\Integrations\Msan\MsanTransportInterface;
use App\Services\Integrations\Msan\MsanTransportResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class MsanClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_dataset_and_image_downloads_use_fixed_endpoints_mtls_and_atomic_destinations(): void
    {
        Storage::fake('local');
        $settings = app(MsanSettingsService::class);
        $settings->saveAdminValues([
            'msan_enabled' => true,
            'msan_p12_pin' => 'synthetic-client-pin',
        ]);
        Storage::disk('local')->put(MsanCertificateService::STORAGE_PATH, 'synthetic-certificate-file');
        Storage::disk('local')->put(MsanCertificateService::CA_STORAGE_PATH, 'synthetic-ca-bundle');

        $transport = new RecordingMsanTransport;
        $client = new MsanClient($settings, app(MsanCertificateService::class), $transport);

        $catalogPath = Storage::disk('local')->path('integrations/msan/test/catalog.xml');
        $client->downloadDataset('catalog', $catalogPath);
        $this->assertFileExists($catalogPath);
        $this->assertSame('<NewDataSet><Table><ProductCode>TEST-1</ProductCode></Table></NewDataSet>', file_get_contents($catalogPath));
        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertSame(
            'https://b2b.msan.hr/B2BService/HTTP/Product/GetProductsList.aspx',
            $transport->calls[0]['url'],
        );
        $this->assertSame('synthetic-client-pin', $transport->calls[0]['options']['certificate_pin']);
        $this->assertSame(
            Storage::disk('local')->path(MsanCertificateService::CA_STORAGE_PATH),
            $transport->calls[0]['options']['ca_path'],
        );
        $this->assertSame([], $transport->calls[0]['options']['query']);

        $mappingPath = Storage::disk('local')->path('integrations/msan/test/product-categories.xml');
        $client->downloadDataset('product_categories', $mappingPath);
        $this->assertSame('POST', $transport->calls[1]['method']);
        $this->assertSame('https://b2b.msan.hr/B2BService/B2BProductService.asmx', $transport->calls[1]['url']);
        $this->assertSame(
            '"http://www.msan.hr/B2B/GetProductsCategory"',
            $transport->calls[1]['options']['headers']['SOAPAction'],
        );
        $this->assertStringContainsString('<CategoryTypeID>1</CategoryTypeID>', $transport->calls[1]['options']['body']);
        $this->assertStringContainsString('<ProductCode></ProductCode>', $transport->calls[1]['options']['body']);

        $imagePath = Storage::disk('local')->path('integrations/msan/test/product.jpg');
        $transport->responseBody = 'synthetic-image-bytes';
        $transport->contentType = 'image/jpeg';
        $client->downloadProductImage('https://b2b.msan.hr/slike/fixture.jpg', $imagePath);
        $this->assertSame('synthetic-image-bytes', file_get_contents($imagePath));
        $this->assertSame('https://b2b.msan.hr/slike/fixture.jpg', $transport->calls[2]['url']);

        $this->expectException(InvalidArgumentException::class);
        $client->downloadProductImage('https://example.test/fixture.jpg', $imagePath);
    }

    public function test_client_redacts_transport_errors_and_deletes_partial_file(): void
    {
        Storage::fake('local');
        $settings = app(MsanSettingsService::class);
        $settings->saveAdminValues([
            'msan_enabled' => true,
            'msan_p12_pin' => 'synthetic-secret-pin',
        ]);
        Storage::disk('local')->put(MsanCertificateService::STORAGE_PATH, 'synthetic-certificate-file');

        $transport = new RecordingMsanTransport;
        $transport->exceptionMessage = 'failure pin=synthetic-secret-pin';
        $client = new MsanClient($settings, app(MsanCertificateService::class), $transport);
        $destination = Storage::disk('local')->path('integrations/msan/test/error.xml');

        try {
            $client->downloadDataset('prices', $destination);
            $this->fail('Transport failure should bubble up as a safe exception.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('synthetic-secret-pin', $exception->getMessage());
            $this->assertStringNotContainsString('pin=', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($destination);
        $this->assertSame([], glob($destination.'.part-*') ?: []);
    }

    public function test_documented_endpoint_cooldown_is_persisted_and_blocks_repeated_calls(): void
    {
        Storage::fake('local');
        $settings = app(MsanSettingsService::class);
        $settings->saveAdminValues(['msan_enabled' => true, 'msan_p12_pin' => 'synthetic-pin']);
        Storage::disk('local')->put(MsanCertificateService::STORAGE_PATH, 'synthetic-certificate-file');
        $transport = new RecordingMsanTransport;
        $client = new MsanClient($settings, app(MsanCertificateService::class), $transport);

        $client->downloadDataset('catalog', Storage::disk('local')->path('first.xml'));
        $state = MsanEndpointState::query()->where('endpoint', 'catalog')->firstOrFail();
        $this->assertSame(600, data_get($state->metadata, 'cooldown_seconds'));
        $this->assertNotNull($state->last_success_at);
        $this->assertTrue($state->next_allowed_at->isFuture());

        try {
            $client->downloadDataset('catalog', Storage::disk('local')->path('blocked.xml'));
            $this->fail('Repeated catalog request should be blocked by the persistent cooldown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ponovno dostupan', $exception->getMessage());
        }
        $this->assertCount(1, $transport->calls);

        $this->travel(601)->seconds();
        $client->downloadDataset('catalog', Storage::disk('local')->path('after-cooldown.xml'));
        $this->assertCount(2, $transport->calls);
    }

    public function test_html_success_response_is_rejected_as_invalid_xml(): void
    {
        Storage::fake('local');
        $settings = app(MsanSettingsService::class);
        $settings->saveAdminValues(['msan_enabled' => true, 'msan_p12_pin' => 'synthetic-pin']);
        Storage::disk('local')->put(MsanCertificateService::STORAGE_PATH, 'synthetic-certificate-file');
        $transport = new RecordingMsanTransport;
        $transport->contentType = 'text/html; charset=UTF-8';
        $transport->responseBody = '<html><body>Login failed</body></html>';
        $client = new MsanClient($settings, app(MsanCertificateService::class), $transport);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('odgovor nije XML dokument');
        $client->downloadDataset('availability', Storage::disk('local')->path('html.xml'));
    }

    public function test_icecat_specifications_use_the_documented_soap_action_large_stream_limit_and_shared_cooldown(): void
    {
        Storage::fake('local');
        $settings = app(MsanSettingsService::class);
        $settings->saveAdminValues([
            'msan_enabled' => true,
            'msan_p12_pin' => 'synthetic-pin',
            'msan_specifications_timeout' => 1234,
        ]);
        Storage::disk('local')->put(MsanCertificateService::STORAGE_PATH, 'synthetic-certificate-file');
        $transport = new RecordingMsanTransport;
        $client = new MsanClient($settings, app(MsanCertificateService::class), $transport);

        $client->downloadDataset(
            'specifications_icecat',
            Storage::disk('local')->path('integrations/msan/test/specifications.xml'),
        );

        $call = $transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://b2b.msan.hr/B2BService/B2BProductService.asmx', $call['url']);
        $this->assertSame('"http://www.msan.hr/B2B/GetProductsSpecificationIceCat"', $call['options']['headers']['SOAPAction']);
        $this->assertStringContainsString('<ProductCode></ProductCode>', $call['options']['body']);
        $this->assertStringContainsString('<ProductType></ProductType>', $call['options']['body']);
        $this->assertSame(1234, $call['options']['timeout']);
        $this->assertSame(1024 * 1024 * 1024, $call['options']['max_bytes']);
        $state = MsanEndpointState::query()->where('endpoint', 'specifications')->firstOrFail();
        $this->assertSame(3600, data_get($state->metadata, 'cooldown_seconds'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ponovno dostupan');
        $client->downloadDataset('specifications', Storage::disk('local')->path('blocked-specifications.xml'));
    }

    public function test_connection_check_streams_a_dataset_and_returns_only_safe_metadata(): void
    {
        Storage::fake('local');
        $settings = app(MsanSettingsService::class);
        $settings->saveAdminValues([
            'msan_enabled' => true,
            'msan_p12_pin' => 'synthetic-client-pin',
        ]);

        $certificates = new class($settings) extends MsanCertificateService
        {
            public function absolutePath(): string
            {
                return '/private/synthetic-client.p12';
            }

            public function currentMetadata(): ?array
            {
                return [
                    'fingerprint' => str_repeat('a', 64),
                    'subject' => 'CN=Synthetic fixture',
                    'issuer' => 'CN=Synthetic fixture issuer',
                    'valid_until' => '2030-01-01T00:00:00Z',
                ];
            }
        };
        $transport = new RecordingMsanTransport;
        $client = new MsanClient($settings, $certificates, $transport);

        $result = $client->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertSame('b2b.msan.hr', $result['host']);
        $this->assertSame(str_repeat('a', 64), $result['certificate']['fingerprint']);
        $this->assertArrayNotHasKey('pin', $result);
        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertSame(['CategoryTypeID' => 1], $transport->calls[0]['options']['query']);
        $this->assertFileDoesNotExist($transport->calls[0]['destination']);
    }

    public function test_ftp_helper_uses_encrypted_settings_and_only_accepts_image_paths(): void
    {
        Storage::fake('local');
        $settings = app(MsanSettingsService::class);
        $settings->saveAdminValues([
            'msan_enabled' => true,
            'msan_ftp_enabled' => true,
            'msan_ftp_username' => 'synthetic-ftp-user',
            'msan_ftp_password' => 'synthetic-ftp-secret',
        ]);

        $transport = new RecordingMsanFtpTransport;
        $client = new MsanFtpClient($settings, $transport);
        $connection = $client->testConnection();
        $this->assertTrue($connection['ok']);
        $this->assertSame('b2b.msan.hr', $connection['host']);
        $this->assertSame('synthetic-ftp-user', $transport->connectionCalls[0]['username']);
        $this->assertSame('synthetic-ftp-secret', $transport->connectionCalls[0]['password']);

        $destination = Storage::disk('local')->path('integrations/msan/test/ftp-image.jpg');
        $client->downloadImage('/slike/fixture image.jpg', $destination);

        $this->assertSame('synthetic-ftp-image', file_get_contents($destination));
        $this->assertSame('/slike/fixture image.jpg', $transport->calls[0]['remote_path']);
        $this->assertSame('synthetic-ftp-user', $transport->calls[0]['username']);
        $this->assertSame('synthetic-ftp-secret', $transport->calls[0]['password']);

        $this->expectException(InvalidArgumentException::class);
        $client->downloadImage('../private.key', $destination);
    }

    public function test_ftp_connection_error_is_redacted(): void
    {
        Storage::fake('local');
        $settings = app(MsanSettingsService::class);
        $settings->saveAdminValues([
            'msan_enabled' => true,
            'msan_ftp_enabled' => true,
            'msan_ftp_username' => 'synthetic-ftp-user',
            'msan_ftp_password' => 'synthetic-ftp-secret',
        ]);

        $transport = new RecordingMsanFtpTransport;
        $transport->connectionExceptionMessage = 'login failed: synthetic-ftp-secret';

        try {
            (new MsanFtpClient($settings, $transport))->testConnection();
            $this->fail('FTPS transport failure should be redacted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('M SAN FTPS provjera veze nije uspjela.', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-ftp-secret', $exception->getMessage());
        }
    }
}

class RecordingMsanTransport implements MsanTransportInterface
{
    /** @var list<array{method: string, url: string, destination: string, options: array<string, mixed>}> */
    public array $calls = [];

    public string $responseBody = '<NewDataSet><Table><ProductCode>TEST-1</ProductCode></Table></NewDataSet>';

    public string $contentType = 'text/xml';

    public int $status = 200;

    public ?string $exceptionMessage = null;

    public function sendToFile(
        string $method,
        string $url,
        string $destinationPath,
        #[\SensitiveParameter] array $options,
    ): MsanTransportResponse {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'destination' => $destinationPath,
            'options' => $options,
        ];

        if ($this->exceptionMessage !== null) {
            throw new RuntimeException($this->exceptionMessage);
        }

        file_put_contents($destinationPath, $this->responseBody);

        return new MsanTransportResponse($this->status, $this->contentType);
    }
}

class RecordingMsanFtpTransport implements MsanFtpTransportInterface
{
    /** @var list<array{username: string, password: string, connect_timeout: int, timeout: int}> */
    public array $connectionCalls = [];

    /** @var list<array{remote_path: string, destination: string, username: string, password: string}> */
    public array $calls = [];

    public ?string $connectionExceptionMessage = null;

    public function testConnection(
        string $username,
        #[\SensitiveParameter] string $password,
        int $connectTimeout,
        int $timeout,
        ?string $caPath,
        int $maxBytes,
    ): void {
        $this->connectionCalls[] = [
            'username' => $username,
            'password' => $password,
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
        ];

        if ($this->connectionExceptionMessage !== null) {
            throw new RuntimeException($this->connectionExceptionMessage);
        }
    }

    public function downloadToFile(
        string $remotePath,
        string $destinationPath,
        string $username,
        #[\SensitiveParameter] string $password,
        int $connectTimeout,
        int $timeout,
        ?string $caPath,
        int $maxBytes,
    ): void {
        $this->calls[] = [
            'remote_path' => $remotePath,
            'destination' => $destinationPath,
            'username' => $username,
            'password' => $password,
        ];

        file_put_contents($destinationPath, 'synthetic-ftp-image');
    }
}
