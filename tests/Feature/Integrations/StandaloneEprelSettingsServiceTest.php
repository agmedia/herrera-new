<?php

namespace Tests\Feature\Integrations;

use App\Models\Settings\System\SystemSetting;
use App\Services\Integrations\Eprel\EprelSettingsService;
use App\Services\Integrations\Msan\MsanSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class StandaloneEprelSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.msan.available' => false]);
        Http::preventStrayRequests();
    }

    public function test_native_settings_encrypt_the_key_without_exposing_it_or_changing_supplier_settings(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->putMany(['msan_enabled' => true, 'msan_ftp_username' => 'unchanged-supplier']);
        $service = app(EprelSettingsService::class);
        $service->saveAdminValues([
            'eprel_enabled' => true, 'eprel_api_key' => ' fixture-standalone-key ',
            'eprel_connect_timeout' => 12, 'eprel_timeout' => 45,
            'msan_enabled' => false, 'msan_ftp_username' => 'attacker-value',
            'eprel_api_key_encrypted' => 'attacker-ciphertext',
            'unrelated_setting' => true,
        ]);

        $encrypted = $settings->get(EprelSettingsService::KEY_API_KEY_ENCRYPTED);
        $this->assertNotSame('fixture-standalone-key', $encrypted);
        $this->assertSame('fixture-standalone-key', Crypt::decryptString($encrypted));
        $this->assertTrue($service->enabled());
        $this->assertTrue($service->hasApiKey());
        $this->assertSame('fixture-standalone-key', $service->apiKey());
        $this->assertSame([
            'eprel_enabled' => true, 'eprel_api_key' => '', 'eprel_connect_timeout' => 12, 'eprel_timeout' => 45,
        ], $service->formValues());
        $this->assertTrue($settings->get('msan_enabled'));
        $this->assertSame('unchanged-supplier', $settings->get('msan_ftp_username'));
        $this->assertFalse(SystemSetting::query()->where('key', 'unrelated_setting')->exists());
        $this->assertFalse(app(MsanSettingsService::class)->enabled());
        $service->saveAdminValues(['eprel_api_key' => '  ']);
        $this->assertSame($encrypted, $settings->get(EprelSettingsService::KEY_API_KEY_ENCRYPTED));
        Http::assertNothingSent();
    }

    public function test_legacy_values_are_read_only_fallbacks_when_native_keys_do_not_exist(): void
    {
        $settings = app(SystemSettingsService::class);
        DB::table('system_settings')->whereIn('key', $this->nativeKeys())->delete();
        $settings->flush();
        $settings->putMany([
            'msan_eprel_enabled' => true,
            'msan_eprel_api_key_encrypted' => Crypt::encryptString('fixture-legacy-key'),
            'msan_eprel_connect_timeout' => 13, 'msan_eprel_timeout' => 50,
        ]);
        $count = SystemSetting::query()->count();
        $service = app(EprelSettingsService::class);

        $this->assertTrue($service->enabled());
        $this->assertSame('fixture-legacy-key', $service->apiKey());
        $this->assertSame(13, $service->connectTimeout());
        $this->assertSame(50, $service->timeout());
        $this->assertSame($count, SystemSetting::query()->count());
        $this->assertSame(0, SystemSetting::query()->whereIn('key', $this->nativeKeys())->count());
        Http::assertNothingSent();
    }

    public function test_explicit_native_disabled_or_empty_values_never_resurrect_an_old_key(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->putMany([
            'msan_eprel_enabled' => true,
            'msan_eprel_api_key_encrypted' => Crypt::encryptString('fixture-old-key'),
            'eprel_enabled' => false, 'eprel_api_key_encrypted' => '',
        ]);
        $service = app(EprelSettingsService::class);
        $this->assertFalse($service->enabled());
        $this->assertFalse($service->hasApiKey());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EPREL API ključ nije postavljen.');
        $service->apiKey();
    }

    public function test_migration_copies_ciphertext_byte_for_byte_and_preserves_original_rows(): void
    {
        $settings = app(SystemSettingsService::class);
        DB::table('system_settings')->whereIn('key', $this->nativeKeys())->delete();
        $settings->flush();
        $settings->putMany([
            'msan_eprel_enabled' => true,
            'msan_eprel_api_key_encrypted' => Crypt::encryptString('fixture-migration-key'),
            'msan_eprel_connect_timeout' => 14, 'msan_eprel_timeout' => 55,
            'msan_enabled' => false, 'msan_ftp_username' => 'supplier-preserved',
        ]);
        $before = DB::table('system_settings')->orderBy('key')->get()->keyBy('key');
        $migration = require database_path('migrations/2026_10_06_200000_move_eprel_to_standalone_settings.php');
        $migration->up();
        foreach ($before as $key => $row) {
            $this->assertEquals($row, DB::table('system_settings')->where('key', $key)->first());
        }
        $this->assertSame($before['msan_eprel_api_key_encrypted']->value, DB::table('system_settings')->where('key', 'eprel_api_key_encrypted')->value('value'));
        $service = app(EprelSettingsService::class);
        $this->assertTrue($service->enabled());
        $this->assertSame('fixture-migration-key', $service->apiKey());
        $this->assertSame(14, $service->connectTimeout());
        $this->assertSame(55, $service->timeout());
        $after = DB::table('system_settings')->orderBy('key')->get();
        $migration->up();
        $migration->down();
        $this->assertEquals($after, DB::table('system_settings')->orderBy('key')->get());
        Http::assertNothingSent();
    }

    public function test_existing_native_key_and_disabled_state_win_over_migration_defaults(): void
    {
        $settings = app(SystemSettingsService::class);
        $nativeCipher = Crypt::encryptString('fixture-new-key');
        $settings->putMany([
            'msan_eprel_enabled' => true, 'msan_eprel_api_key_encrypted' => Crypt::encryptString('fixture-old-key'),
            'eprel_enabled' => false, 'eprel_api_key_encrypted' => $nativeCipher,
            'eprel_connect_timeout' => 18, 'eprel_timeout' => 80,
        ]);
        $migration = require database_path('migrations/2026_10_06_200000_move_eprel_to_standalone_settings.php');
        $migration->up();
        $service = app(EprelSettingsService::class);
        $this->assertFalse($service->enabled());
        $this->assertSame($nativeCipher, $settings->get('eprel_api_key_encrypted'));
        $this->assertSame('fixture-new-key', $service->apiKey());
        $this->assertSame(18, $service->connectTimeout());
        $this->assertSame(80, $service->timeout());
    }

    public function test_legacy_settings_api_delegates_to_native_storage_without_rewriting_old_ciphertext(): void
    {
        $settings = app(SystemSettingsService::class);
        $legacyCipher = Crypt::encryptString('fixture-original-key');
        $settings->putMany(['msan_eprel_api_key_encrypted' => $legacyCipher, 'msan_eprel_enabled' => false]);
        $legacy = app(MsanSettingsService::class);
        $legacy->saveAdminValues([
            'msan_eprel_enabled' => true, 'msan_eprel_api_key' => 'fixture-delegated-key',
            'msan_eprel_connect_timeout' => 11, 'msan_eprel_timeout' => 44,
        ]);
        $this->assertSame($legacyCipher, $settings->get('msan_eprel_api_key_encrypted'));
        $this->assertFalse($settings->get('msan_eprel_enabled'));
        $this->assertTrue($legacy->eprelEnabled());
        $this->assertTrue($legacy->hasEprelApiKey());
        $this->assertSame('fixture-delegated-key', $legacy->eprelApiKey());
        $this->assertSame(11, $legacy->eprelConnectTimeout());
        $this->assertSame(44, $legacy->eprelTimeout());
        $this->assertSame(EprelSettingsService::KEY_API_KEY_ENCRYPTED, MsanSettingsService::KEY_EPREL_API_KEY_ENCRYPTED);
        $this->assertSame('', $legacy->adminValues()['msan_eprel_api_key']);
        Http::assertNothingSent();
    }

    public function test_invalid_encrypted_key_fails_with_a_generic_message(): void
    {
        app(SystemSettingsService::class)->put('eprel_api_key_encrypted', 'fixture-invalid-ciphertext');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EPREL API ključ nije moguće dešifrirati. Spremite ga ponovno.');
        app(EprelSettingsService::class)->apiKey();
    }

    public function test_overlong_plaintext_rejects_all_native_setting_changes_and_timeout_values_are_bounded(): void
    {
        $service = app(EprelSettingsService::class);
        $before = app(SystemSettingsService::class)->all();
        try {
            $service->saveAdminValues(['eprel_enabled' => true, 'eprel_timeout' => 80, 'eprel_api_key' => str_repeat('x', 2049)]);
            $this->fail('Overlong key must be rejected before any setting is saved.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, app(SystemSettingsService::class)->all());
        }
        $service->saveAdminValues(['eprel_connect_timeout' => -1, 'eprel_timeout' => 1000]);
        $this->assertSame(2, $service->connectTimeout());
        $this->assertSame(120, $service->timeout());
        Http::assertNothingSent();
    }

    private function nativeKeys(): array
    {
        return ['eprel_enabled', 'eprel_api_key_encrypted', 'eprel_connect_timeout', 'eprel_timeout'];
    }
}
