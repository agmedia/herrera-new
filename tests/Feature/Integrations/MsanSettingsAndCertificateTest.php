<?php

namespace Tests\Feature\Integrations;

use App\Models\Settings\System\SystemSetting;
use App\Services\Integrations\Msan\MsanCertificateService;
use App\Services\Integrations\Msan\MsanSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MsanSettingsAndCertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_values_never_return_secrets_and_blank_values_preserve_encrypted_secrets(): void
    {
        $service = app(MsanSettingsService::class);
        $service->saveAdminValues([
            'msan_enabled' => true,
            'msan_p12_pin' => 'synthetic-pin-one',
            'msan_connect_timeout' => 20,
            'msan_request_timeout' => 240,
            'msan_import_images' => false,
            'msan_import_products_active' => true,
            'msan_stock_level_0' => -10,
            'msan_stock_level_1' => 12,
            'msan_stock_level_2' => 1000000,
            'msan_ftp_enabled' => true,
            'msan_ftp_username' => 'termol-fixture',
            'msan_ftp_password' => 'synthetic-ftp-password',
            'msan_ftp_connect_timeout' => 25,
            'msan_ftp_timeout' => 300,
        ]);

        $encryptedPin = app(\App\Services\Settings\SystemSettingsService::class)
            ->get(MsanSettingsService::KEY_P12_PIN_ENCRYPTED);
        $encryptedFtpPassword = app(\App\Services\Settings\SystemSettingsService::class)
            ->get(MsanSettingsService::KEY_FTP_PASSWORD_ENCRYPTED);

        $this->assertIsString($encryptedPin);
        $this->assertIsString($encryptedFtpPassword);
        $this->assertNotSame('synthetic-pin-one', $encryptedPin);
        $this->assertNotSame('synthetic-ftp-password', $encryptedFtpPassword);
        $this->assertSame('synthetic-pin-one', Crypt::decryptString($encryptedPin));
        $this->assertSame('synthetic-ftp-password', Crypt::decryptString($encryptedFtpPassword));

        $admin = $service->adminValues();
        $this->assertSame('', $admin['msan_p12_pin']);
        $this->assertTrue($admin['msan_p12_pin_configured']);
        $this->assertSame('', $admin['msan_ftp_password']);
        $this->assertTrue($admin['msan_ftp_password_configured']);
        $this->assertTrue($admin['msan_enabled']);
        $this->assertSame(20, $admin['msan_connect_timeout']);
        $this->assertSame(240, $admin['msan_request_timeout']);
        $this->assertTrue($admin['msan_price_stock_sync_enabled']);
        $this->assertSame('*/15 * * * *', $admin['msan_price_stock_sync_cron']);
        $this->assertFalse($admin['msan_import_images']);
        $this->assertTrue($admin['msan_import_products_active']);
        $this->assertSame(0, $admin['msan_stock_level_0']);
        $this->assertSame(12, $admin['msan_stock_level_1']);
        $this->assertSame(999999, $admin['msan_stock_level_2']);
        $this->assertSame(5, $admin['msan_stock_level_3']);
        $this->assertSame(10, $admin['msan_stock_level_4']);

        $service->saveAdminValues([
            'msan_p12_pin' => '   ',
            'msan_ftp_password' => '',
            'msan_p12_pin_encrypted' => 'attacker-controlled',
            'msan_ftp_password_encrypted' => 'attacker-controlled',
            'unrelated_setting' => true,
        ]);

        $this->assertSame(
            $encryptedPin,
            app(\App\Services\Settings\SystemSettingsService::class)
                ->get(MsanSettingsService::KEY_P12_PIN_ENCRYPTED),
        );
        $this->assertSame(
            $encryptedFtpPassword,
            app(\App\Services\Settings\SystemSettingsService::class)
                ->get(MsanSettingsService::KEY_FTP_PASSWORD_ENCRYPTED),
        );
        $this->assertFalse(SystemSetting::query()->where('key', 'unrelated_setting')->exists());
    }

    public function test_default_availability_mapping_uses_clear_semantic_levels_and_local_sellable_limits(): void
    {
        $service = app(MsanSettingsService::class);

        $this->assertSame([0, 1, 3, 5, 10], array_values($service->stockLevelQuantities()));
        $this->assertSame('Nije dostupno', MsanSettingsService::availabilityLevelLabel(0));
        $this->assertSame('Niska dostupnost', MsanSettingsService::availabilityLevelLabel(1));
        $this->assertSame('Srednja dostupnost', MsanSettingsService::availabilityLevelLabel(2));
        $this->assertSame('Visoka dostupnost', MsanSettingsService::availabilityLevelLabel(3));
        $this->assertSame('Vrlo visoka dostupnost', MsanSettingsService::availabilityLevelLabel(4));
        $this->assertSame('Nepoznata dostupnost', MsanSettingsService::availabilityLevelLabel(null));
    }

    public function test_default_limit_migration_updates_only_the_complete_untouched_legacy_profile(): void
    {
        $migration = require database_path('migrations/2026_08_30_088000_update_msan_stock_level_defaults.php');

        $this->storeStockLevelProfile([0, 1, 1, 1, 1]);
        $migration->up();
        $this->assertSame([0, 1, 3, 5, 10], $this->storedStockLevelProfile());

        $this->storeStockLevelProfile([0, 1, 1, 7, 20]);
        $migration->up();
        $this->assertSame([0, 1, 1, 7, 20], $this->storedStockLevelProfile());
    }

    public function test_certificate_is_validated_stored_privately_and_failed_replacement_keeps_existing_file(): void
    {
        Storage::fake('local');
        $pin = 'synthetic-p12-pin';
        $sourcePath = $this->syntheticPkcs12($pin, 'Termol M SAN Fixture');
        $service = app(MsanCertificateService::class);

        try {
            $metadata = $service->replaceFromPath($sourcePath, $pin);
        } finally {
            @unlink($sourcePath);
        }

        Storage::disk('local')->assertExists(MsanCertificateService::STORAGE_PATH);
        Storage::disk('local')->assertExists(MsanCertificateService::CA_STORAGE_PATH);
        $this->assertStringContainsString('CN=Termol M SAN Fixture', $metadata['subject']);
        $this->assertStringContainsString('CN=Termol M SAN Fixture', $metadata['issuer']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $metadata['fingerprint']);
        $this->assertMatchesRegularExpression('/Z$/', $metadata['valid_until']);
        $this->assertStringNotContainsString($pin, json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->assertSame($metadata, $service->currentMetadata());
        $this->assertSame(
            Storage::disk('local')->path(MsanCertificateService::CA_STORAGE_PATH),
            $service->caAbsolutePath(),
        );

        $storedBefore = Storage::disk('local')->get(MsanCertificateService::STORAGE_PATH);
        $invalidSource = $this->syntheticPkcs12('different-synthetic-pin', 'Replacement Fixture');
        try {
            $service->replaceFromPath($invalidSource, 'wrong-synthetic-pin');
            $this->fail('Invalid certificate PIN should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('wrong-synthetic-pin', $exception->getMessage());
        } finally {
            @unlink($invalidSource);
        }

        $this->assertSame($storedBefore, Storage::disk('local')->get(MsanCertificateService::STORAGE_PATH));
    }

    private function syntheticPkcs12(string $pin, string $commonName): string
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($privateKey);

        $csr = openssl_csr_new([
            'commonName' => $commonName,
            'organizationName' => 'Termol Test',
            'countryName' => 'HR',
        ], $privateKey, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 30, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_pkcs12_export(
            $certificate,
            $pkcs12,
            $privateKey,
            $pin,
            [
                'friendly_name' => 'Synthetic M SAN fixture',
                'extracerts' => [$certificate],
            ],
        ));

        $path = tempnam(sys_get_temp_dir(), 'msan-p12-');
        $this->assertIsString($path);
        $this->assertNotFalse(file_put_contents($path, $pkcs12));

        return $path;
    }

    /** @param array<int, int> $profile */
    private function storeStockLevelProfile(array $profile): void
    {
        foreach ($profile as $level => $quantity) {
            SystemSetting::query()->updateOrCreate(
                ['key' => MsanSettingsService::KEY_STOCK_LEVEL_PREFIX.$level],
                ['value' => json_encode($quantity, JSON_THROW_ON_ERROR)],
            );
        }
    }

    /** @return array<int, int> */
    private function storedStockLevelProfile(): array
    {
        return collect(range(0, 4))
            ->map(fn (int $level): int => (int) json_decode((string) SystemSetting::query()
                ->where('key', MsanSettingsService::KEY_STOCK_LEVEL_PREFIX.$level)
                ->value('value'), true, 512, JSON_THROW_ON_ERROR))
            ->all();
    }
}
