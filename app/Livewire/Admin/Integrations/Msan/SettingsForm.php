<?php

namespace App\Livewire\Admin\Integrations\Msan;

use App\Services\Integrations\Msan\MsanCatalogSyncCoordinator;
use App\Services\Integrations\Msan\MsanCertificateService;
use App\Services\Integrations\Msan\MsanSettingsService;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

class SettingsForm extends Component
{
    use WithFileUploads;

    /** @var array<string,mixed> */
    public array $form = [];

    public mixed $certificate = null;

    public bool $certificateConfigured = false;

    public bool $certificateValid = false;

    public ?string $certificateError = null;

    /** @var array<string,string>|null */
    public ?array $certificateMetadata = null;

    public function mount(MsanSettingsService $settings, MsanCertificateService $certificates): void
    {
        $this->authorizeSettings();
        $this->form = $settings->adminValues();
        $this->loadCertificateState($certificates);
    }

    public function save(MsanSettingsService $settings, MsanCertificateService $certificates): void
    {
        $this->authorizeSettings();
        $temporaryCertificate = $this->certificate instanceof TemporaryUploadedFile
            ? $this->certificate
            : null;
        try {
            $validated = $this->validate($this->rules());
            $form = $validated['form'];
            $pin = (string) ($form['msan_p12_pin'] ?? '');

            $pinConfigured = (bool) ($this->form['msan_p12_pin_configured'] ?? false) || $pin !== '';
            $certificateWillBeConfigured = (bool) $this->certificate || $certificates->hasCertificate();
            if ((bool) ($form['msan_enabled'] ?? false) && (! $certificateWillBeConfigured || ! $pinConfigured)) {
                $this->addError('form.msan_enabled', __('Za uključivanje integracije spremite valjan P12 certifikat i PIN.'));

                return;
            }

            $ftpPasswordConfigured = (bool) ($this->form['msan_ftp_password_configured'] ?? false)
                || trim((string) ($form['msan_ftp_password'] ?? '')) !== '';
            if ((bool) ($form['msan_ftp_enabled'] ?? false)
                && (trim((string) ($form['msan_ftp_username'] ?? '')) === '' || ! $ftpPasswordConfigured)
            ) {
                $this->addError('form.msan_ftp_username', __('Za FTP slike unesite korisničko ime i lozinku.'));

                return;
            }

            $eprelApiKeyConfigured = $settings->hasEprelApiKey()
                || trim((string) ($form['msan_eprel_api_key'] ?? '')) !== '';
            if ((bool) ($form['msan_eprel_enabled'] ?? false) && ! $eprelApiKeyConfigured) {
                $this->addError('form.msan_eprel_api_key', __('Za uključivanje EPREL dohvata unesite API ključ.'));

                return;
            }

            try {
                if ($this->certificate) {
                    if ($pin === '') {
                        $this->addError('form.msan_p12_pin', __('PIN je obavezan pri spremanju certifikata.'));

                        return;
                    }
                    $certificates->replaceFromPath((string) $this->certificate->getRealPath(), $pin);
                } elseif ($pin !== '') {
                    if (! $certificates->hasCertificate()) {
                        $this->addError('certificate', __('Najprije odaberite P12 certifikat.'));

                        return;
                    }

                    // Re-validates a changed PIN against the already stored P12 before saving it.
                    $certificates->replaceFromPath($certificates->absolutePath(), $pin);
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->addError('certificate', $exception->getMessage());

                return;
            }

            if ((bool) ($form['msan_enabled'] ?? false)) {
                try {
                    if ($certificates->currentMetadata() === null || $certificates->caAbsolutePath() === null) {
                        throw new \RuntimeException('M SAN certifikat ili CA lanac nisu postavljeni.');
                    }
                } catch (Throwable) {
                    $this->addError('form.msan_enabled', __('Integraciju nije moguće uključiti dok spremljeni certifikat, PIN i CA lanac nisu valjani.'));

                    return;
                }
            }

            $settings->saveAdminValues($form);
            $this->form = $settings->adminValues();
            $this->loadCertificateState($certificates);
            $this->dispatch('notify', type: 'success', message: __('M SAN postavke su spremljene.'));
        } finally {
            if ($temporaryCertificate) {
                try {
                    $temporaryCertificate->delete();
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            $this->certificate = null;
        }
    }

    public function testConnection(MsanCatalogSyncCoordinator $coordinator): void
    {
        $this->authorizeSettings();

        try {
            $coordinator->queueConnectionTest(auth()->id() ? (int) auth()->id() : null);
            $this->dispatch('notify', type: 'success', message: __('Provjera M SAN veze stavljena je u red. Rezultat će biti u Izvršavanjima.'));
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('notify', type: 'error', message: __('Provjeru veze nije moguće pokrenuti. Spremite postavke i pokušajte ponovno.'));
        }
    }

    public function testFtpConnection(MsanCatalogSyncCoordinator $coordinator): void
    {
        $this->authorizeSettings();

        try {
            $coordinator->queueFtpConnectionTest(auth()->id() ? (int) auth()->id() : null);
            $this->dispatch('notify', type: 'success', message: __('Provjera M SAN FTP veze stavljena je u red. Rezultat će biti u Izvršavanjima.'));
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('notify', type: 'error', message: __('M SAN FTP veza nije uspjela. Provjerite spremljene podatke.'));
        }
    }

    public function syncPricesAndStock(MsanCatalogSyncCoordinator $coordinator): void
    {
        $this->authorizeSync();

        try {
            $coordinator->queuePricesAndStock(auth()->id() ? (int) auth()->id() : null);
            $this->dispatch('notify', type: 'success', message: __('Osvježavanje M SAN cijena i količina stavljeno je u red. Rezultat će biti u Izvršavanjima.'));
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('notify', type: 'warning', message: __('Osvježavanje cijena i količina nije moguće pokrenuti. Provjerite spremljene postavke i trenutačna izvršavanja.'));
        }
    }

    public function render()
    {
        $this->authorizeSettings();

        return view('livewire.admin.integrations.msan.settings-form', [
            'productEndpoint' => 'https://b2b.msan.hr/B2BService/B2BProductService.asmx',
            'ftpHost' => 'b2b.msan.hr',
            'availabilityLevelLabels' => MsanSettingsService::AVAILABILITY_LEVEL_LABELS,
            'priceStockSyncTimezone' => MsanSettingsService::PRICE_STOCK_SYNC_TIMEZONE,
            'canSync' => $this->canSync(),
        ]);
    }

    /** @return array<string,mixed> */
    private function rules(): array
    {
        return [
            'certificate' => ['nullable', 'file', 'max:1024', 'extensions:p12,pfx'],
            'form.msan_enabled' => ['required', 'boolean'],
            'form.msan_p12_pin' => ['nullable', 'string', 'max:255'],
            'form.msan_connect_timeout' => ['required', 'integer', 'min:2', 'max:60'],
            'form.msan_request_timeout' => ['required', 'integer', 'min:15', 'max:300'],
            'form.msan_price_stock_sync_enabled' => ['required', 'boolean'],
            'form.msan_price_stock_sync_cron' => [
                'required',
                'string',
                'max:100',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! MsanSettingsService::isValidPriceStockSyncCron((string) $value)) {
                        $fail(__('Unesite valjan cron izraz s pet polja i razmakom od najmanje 10 minuta.'));
                    }
                },
            ],
            'form.msan_ftp_enabled' => ['required', 'boolean'],
            'form.msan_ftp_username' => ['nullable', 'string', 'max:191'],
            'form.msan_ftp_password' => ['nullable', 'string', 'max:255'],
            'form.msan_ftp_connect_timeout' => ['required', 'integer', 'min:2', 'max:60'],
            'form.msan_ftp_timeout' => ['required', 'integer', 'min:15', 'max:120'],
            'form.msan_import_images' => ['required', 'boolean'],
            'form.msan_import_products_active' => ['required', 'boolean'],
            'form.msan_import_specifications' => ['required', 'boolean'],
            'form.msan_specifications_selected_only' => ['required', 'boolean'],
            'form.msan_specifications_source' => [
                'required',
                'string',
                Rule::in([
                    MsanSettingsService::SPECIFICATIONS_SOURCE_STANDARD,
                    MsanSettingsService::SPECIFICATIONS_SOURCE_ICECAT,
                ]),
            ],
            'form.msan_specifications_timeout' => ['required', 'integer', 'min:300', 'max:7200'],
            'form.msan_eprel_enabled' => ['required', 'boolean'],
            'form.msan_eprel_api_key' => ['nullable', 'string', 'max:2048'],
            'form.msan_eprel_connect_timeout' => ['required', 'integer', 'min:2', 'max:30'],
            'form.msan_eprel_timeout' => ['required', 'integer', 'min:5', 'max:120'],
            'form.msan_stock_level_0' => ['required', 'integer', 'min:0', 'max:999999'],
            'form.msan_stock_level_1' => ['required', 'integer', 'min:0', 'max:999999'],
            'form.msan_stock_level_2' => ['required', 'integer', 'min:0', 'max:999999'],
            'form.msan_stock_level_3' => ['required', 'integer', 'min:0', 'max:999999'],
            'form.msan_stock_level_4' => ['required', 'integer', 'min:0', 'max:999999'],
        ];
    }

    private function loadCertificateState(MsanCertificateService $certificates): void
    {
        $this->certificateConfigured = $certificates->hasCertificate();
        $this->certificateValid = false;
        $this->certificateError = null;
        $this->certificateMetadata = null;

        if (! $this->certificateConfigured) {
            return;
        }

        try {
            $this->certificateMetadata = $certificates->currentMetadata();
            $this->certificateValid = $this->certificateMetadata !== null
                && $certificates->caAbsolutePath() !== null;
        } catch (Throwable) {
            $this->certificateError = __('Spremljeni certifikat je istekao, oštećen je ili spremljeni PIN nije valjan. Zamijenite certifikat i PIN.');
        }
    }

    private function authorizeSettings(): void
    {
        $user = auth()->user();
        abort_unless($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.msan.settings.manage')), 403);
    }

    private function authorizeSync(): void
    {
        abort_unless($this->canSync(), 403);
    }

    private function canSync(): bool
    {
        $user = auth()->user();

        return (bool) ($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.msan.sync.run')));
    }
}
