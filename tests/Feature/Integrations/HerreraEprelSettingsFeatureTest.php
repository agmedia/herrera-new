<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Eprel\SettingsForm;
use App\Models\Settings\System\SystemSetting;
use App\Models\User;
use App\Services\Integrations\Eprel\EprelSettingsService;
use App\Services\Integrations\Msan\MsanSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class HerreraEprelSettingsFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_authorized_admin_has_standalone_eprel_page_and_navigation_without_msan_setup_requirements(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.integrations.eprel.settings'))
            ->assertOk()->assertSee('EPREL postavke')
            ->assertSee('Omogući EPREL API')
            ->assertSee('EPREL ima vlastite postavke')
            ->assertSee(route('admin.integrations.eprel.settings'), false)
            ->assertDontSee('P12/PFX certifikat')->assertDontSee('msan_price_stock_sync_cron');
        $this->assertFalse(app(MsanSettingsService::class)->enabled());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_new_key_is_encrypted_and_cleared_without_enabling_supplier_or_automatic_jobs(): void
    {
        $this->actingAs($this->admin());
        app(MsanSettingsService::class)->saveAdminValues([
            'msan_enabled' => false, 'msan_price_stock_sync_enabled' => false,
            'msan_p12_pin' => 'fixture-pin', 'msan_ftp_enabled' => false,
            'msan_ftp_username' => 'fixture-supplier-user', 'msan_ftp_password' => 'fixture-password',
        ]);
        $before = app(SystemSettingsService::class)->all();

        $component = Livewire::test(SettingsForm::class)
            ->set('form.eprel_enabled', true)
            ->set('form.eprel_api_key', ' fixture-eprel-key ')
            ->set('form.eprel_connect_timeout', 12)
            ->set('form.eprel_timeout', 45)
            ->call('save')->assertHasNoErrors()->assertSet('form.eprel_api_key', '')
            ->assertSee('API ključ je spremljen.')
            ->assertDontSee('fixture-eprel-key');

        $settings = app(SystemSettingsService::class);
        $encrypted = $settings->get(EprelSettingsService::KEY_API_KEY_ENCRYPTED);
        $this->assertNotSame('fixture-eprel-key', $encrypted);
        $this->assertSame('fixture-eprel-key', Crypt::decryptString($encrypted));
        $this->assertTrue($settings->get(EprelSettingsService::KEY_ENABLED));
        $this->assertSame(12, $settings->get(EprelSettingsService::KEY_CONNECT_TIMEOUT));
        $this->assertSame(45, $settings->get(EprelSettingsService::KEY_TIMEOUT));
        foreach ($before as $key => $value) {
            if (str_starts_with($key, 'eprel_')) {
                continue;
            }
            $this->assertSame($value, $settings->get($key), $key.' must remain unchanged.');
        }
        $this->assertSame(['eprel_enabled', 'eprel_api_key', 'eprel_connect_timeout', 'eprel_timeout'], array_keys($component->get('form')));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_stored_key_is_never_hydrated_and_blank_input_preserves_it_while_extra_keys_are_ignored(): void
    {
        $this->actingAs($this->admin());
        app(MsanSettingsService::class)->saveAdminValues([
            'msan_enabled' => false, 'msan_price_stock_sync_enabled' => false,
            'msan_eprel_enabled' => true, 'msan_eprel_api_key' => 'stored-eprel-secret',
            'msan_ftp_password' => 'stored-supplier-secret', 'msan_p12_pin' => 'stored-pin',
        ]);
        $settings = app(SystemSettingsService::class);
        $before = $settings->all();
        $component = Livewire::test(SettingsForm::class)->assertSet('form.eprel_api_key', '')
            ->assertDontSee('stored-eprel-secret')->assertDontSee('stored-supplier-secret')
            ->set('form.eprel_api_key', '  ')
            ->set('form.msan_enabled', true)
            ->set('form.msan_price_stock_sync_enabled', true)
            ->set('form.msan_p12_pin', 'attacker-pin')
            ->set('form.msan_ftp_password', 'attacker-password')
            ->set('form.eprel_api_key_encrypted', 'attacker-ciphertext')
            ->set('form.unrelated_setting', true)
            ->set('form.eprel_timeout', 60)
            ->call('save')->assertHasNoErrors()->assertSet('form.eprel_api_key', '');

        foreach ($before as $key => $value) {
            if ($key === EprelSettingsService::KEY_TIMEOUT) {
                continue;
            }
            $this->assertSame($value, $settings->get($key), $key.' must be preserved.');
        }
        $this->assertSame(60, $settings->get(EprelSettingsService::KEY_TIMEOUT));
        $this->assertArrayNotHasKey('msan_enabled', $component->get('form'));
        $this->assertFalse(SystemSetting::query()->where('key', 'unrelated_setting')->exists());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_enabled_eprel_requires_a_real_stored_or_new_key_and_client_configured_flag_is_not_trusted(): void
    {
        $this->actingAs($this->admin());
        $before = SystemSetting::query()->count();
        Livewire::test(SettingsForm::class)->set('form.eprel_enabled', true)
            ->set('form.eprel_api_key_configured', true)
            ->call('save')->assertHasErrors('form.eprel_api_key');
        $this->assertSame($before, SystemSetting::query()->count());
        $this->assertFalse(app(EprelSettingsService::class)->enabled());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_timeouts_and_keys_are_rejected_without_writes(string $field, mixed $value): void
    {
        $this->actingAs($this->admin());
        $before = SystemSetting::query()->count();
        Livewire::test(SettingsForm::class)->set('form.'.$field, $value)->call('save')->assertHasErrors('form.'.$field);
        $this->assertSame($before, SystemSetting::query()->count());
    }

    public static function invalidFields(): array
    {
        return [
            ['eprel_connect_timeout', 1], ['eprel_connect_timeout', 31],
            ['eprel_timeout', 4], ['eprel_timeout', 121],
            ['eprel_api_key', str_repeat('x', 2049)],
        ];
    }

    public function test_integration_viewer_cannot_open_or_mount_settings_and_has_no_eprel_menu_link(): void
    {
        $viewer = $this->admin(manage: false);
        Bouncer::allow($viewer)->to('integrations.msan.view');
        $this->actingAs($viewer)->get(route('admin.integrations.eprel.settings'))->assertForbidden();
        Livewire::test(SettingsForm::class)->assertForbidden();
        $this->get(route('admin.help.index'))->assertOk()
            ->assertDontSee(route('admin.integrations.eprel.settings'), false);
    }

    public function test_superadmin_has_standalone_access_and_guest_cannot_open_it(): void
    {
        $this->get(route('admin.integrations.eprel.settings'))->assertRedirect();
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'superadmin']);
        Bouncer::assign('superadmin')->to($user);
        $this->actingAs($user)->get(route('admin.integrations.eprel.settings'))->assertOk();
    }

    public function test_save_rechecks_authority_after_the_form_was_opened(): void
    {
        $this->actingAs($this->admin());
        $component = Livewire::test(SettingsForm::class)
            ->set('form.eprel_api_key', 'unsaved-fixture-key')
            ->set('form.eprel_enabled', true);
        $before = SystemSetting::query()->count();
        $this->actingAs($this->admin(manage: false));

        $component->call('save')->assertForbidden();
        $this->assertSame($before, SystemSetting::query()->count());
        $this->assertFalse(app(EprelSettingsService::class)->enabled());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    private function admin(bool $manage = true): User
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'eprel-fixture-admin']);
        Bouncer::assign('eprel-fixture-admin')->to($user);
        Bouncer::allow($user)->to('admin.access');
        if ($manage) {
            Bouncer::allow($user)->to('integrations.eprel.settings.manage');
        }

        return $user;
    }
}
