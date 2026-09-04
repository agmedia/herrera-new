<?php

namespace App\Livewire\Admin\Settings\Api;

use App\Services\Integrations\Gls\GlsApiService;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;

class GlsManager extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    public bool $passwordConfigured = false;

    public function mount(GlsApiService $gls): void
    {
        $this->authorizeAccess();

        $settings = $gls->getSettings();
        $this->passwordConfigured = (bool) ($settings['gls_api_password_configured'] ?? false);
        unset($settings['gls_api_password_configured']);
        $this->form = $settings;
    }

    public function save(GlsApiService $gls): void
    {
        $this->authorizeAccess();

        $validated = $this->validate($this->rules());
        $payload = $this->normalizePayload($validated['form']);

        if ((bool) $payload['gls_api_enabled'] && ! $this->passwordConfigured && $payload['gls_api_password'] === '') {
            $this->addError('form.gls_api_password', __('GLS lozinka je obavezna kada je integracija uključena.'));

            return;
        }

        $gls->saveSettings($payload);
        if ($payload['gls_api_password'] !== '') {
            $this->passwordConfigured = true;
            $this->form['gls_api_password'] = '';
        }

        $this->dispatch('notify', type: 'success', message: __('GLS API postavke su spremljene.'));
    }

    public function render()
    {
        return view('livewire.admin.settings.api.gls-manager', [
            'endpoint' => app(GlsApiService::class)->endpointForMode((string) ($this->form['gls_api_mode'] ?? 'test')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.gls_api_enabled' => ['required', 'boolean'],
            'form.gls_api_mode' => ['required', 'in:test,live'],
            'form.gls_api_username' => ['nullable', 'string', 'max:191'],
            'form.gls_api_password' => ['nullable', 'string', 'max:255'],
            'form.gls_api_client_number' => ['nullable', 'string', 'max:30'],
            'form.gls_api_pickup_name' => ['nullable', 'string', 'max:191'],
            'form.gls_api_pickup_contact_name' => ['nullable', 'string', 'max:191'],
            'form.gls_api_pickup_contact_phone' => ['nullable', 'string', 'max:40'],
            'form.gls_api_pickup_contact_email' => ['nullable', 'email', 'max:191'],
            'form.gls_api_pickup_street' => ['nullable', 'string', 'max:191'],
            'form.gls_api_pickup_address_line_2' => ['nullable', 'string', 'max:191'],
            'form.gls_api_pickup_city' => ['nullable', 'string', 'max:120'],
            'form.gls_api_pickup_postal_code' => ['nullable', 'string', 'max:32'],
            'form.gls_api_pickup_country_code' => ['required', 'string', 'size:2'],
            'form.gls_api_printer_type' => ['required', 'string', 'max:40'],
            'form.gls_api_print_position' => ['required', 'integer', 'min:1', 'max:4'],
            'form.gls_api_show_print_dialog' => ['required', 'boolean'],
            'form.gls_api_verify_tls' => ['required', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizePayload(array $raw): array
    {
        return [
            'gls_api_enabled' => (bool) ($raw['gls_api_enabled'] ?? false),
            'gls_api_mode' => strtolower(trim((string) ($raw['gls_api_mode'] ?? 'test'))) === 'live' ? 'live' : 'test',
            'gls_api_username' => trim((string) ($raw['gls_api_username'] ?? '')),
            'gls_api_password' => trim((string) ($raw['gls_api_password'] ?? '')),
            'gls_api_client_number' => trim((string) ($raw['gls_api_client_number'] ?? '')),
            'gls_api_pickup_name' => trim((string) ($raw['gls_api_pickup_name'] ?? '')),
            'gls_api_pickup_contact_name' => trim((string) ($raw['gls_api_pickup_contact_name'] ?? '')),
            'gls_api_pickup_contact_phone' => trim((string) ($raw['gls_api_pickup_contact_phone'] ?? '')),
            'gls_api_pickup_contact_email' => trim((string) ($raw['gls_api_pickup_contact_email'] ?? '')),
            'gls_api_pickup_street' => trim((string) ($raw['gls_api_pickup_street'] ?? '')),
            'gls_api_pickup_address_line_2' => trim((string) ($raw['gls_api_pickup_address_line_2'] ?? '')),
            'gls_api_pickup_city' => trim((string) ($raw['gls_api_pickup_city'] ?? '')),
            'gls_api_pickup_postal_code' => trim((string) ($raw['gls_api_pickup_postal_code'] ?? '')),
            'gls_api_pickup_country_code' => strtoupper(trim((string) ($raw['gls_api_pickup_country_code'] ?? 'HR'))),
            'gls_api_printer_type' => trim((string) ($raw['gls_api_printer_type'] ?? 'A4_2x2')),
            'gls_api_print_position' => max(1, min(4, (int) ($raw['gls_api_print_position'] ?? 1))),
            'gls_api_show_print_dialog' => (bool) ($raw['gls_api_show_print_dialog'] ?? false),
            'gls_api_verify_tls' => (bool) ($raw['gls_api_verify_tls'] ?? true),
        ];
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();

        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('settings.api.manage')),
            403
        );
    }
}
