<?php

namespace App\Livewire\Admin\Integrations\Eprel;

use App\Services\Integrations\Eprel\EprelSettingsService;
use Illuminate\Support\Arr;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;

class SettingsForm extends Component
{
    /** @var array<string, bool|int|string> */
    public array $form = [];

    private const FORM_KEYS = [
        EprelSettingsService::KEY_ENABLED,
        EprelSettingsService::KEY_API_KEY,
        EprelSettingsService::KEY_CONNECT_TIMEOUT,
        EprelSettingsService::KEY_TIMEOUT,
    ];

    public function mount(EprelSettingsService $settings): void
    {
        $this->authorizeSettings();
        $this->loadForm($settings);
    }

    public function save(EprelSettingsService $settings): void
    {
        $this->authorizeSettings();
        $validated = $this->validate([
            'form.eprel_enabled' => ['required', 'boolean'],
            'form.eprel_api_key' => ['nullable', 'string', 'max:2048'],
            'form.eprel_connect_timeout' => ['required', 'integer', 'min:2', 'max:30'],
            'form.eprel_timeout' => ['required', 'integer', 'min:5', 'max:120'],
        ]);
        $values = Arr::only($validated['form'], self::FORM_KEYS);
        if ((bool) $values[EprelSettingsService::KEY_ENABLED]
            && ! $settings->hasApiKey()
            && trim((string) ($values[EprelSettingsService::KEY_API_KEY] ?? '')) === '') {
            $this->addError('form.eprel_api_key', __('Za uključivanje EPREL dohvata unesite API ključ.'));

            return;
        }

        // Samostalne EPREL postavke ne čitaju ni mijenjaju postavke dobavljača.
        $settings->saveAdminValues($values);
        $this->loadForm($settings);
        $this->dispatch('notify', type: 'success', message: __('EPREL postavke su spremljene.'));
    }

    public function render()
    {
        $this->authorizeSettings();

        return view('livewire.admin.integrations.eprel.settings-form', [
            'apiKeyConfigured' => app(EprelSettingsService::class)->hasApiKey(),
        ]);
    }

    private function loadForm(EprelSettingsService $settings): void
    {
        $this->form = $settings->formValues();
    }

    private function authorizeSettings(): void
    {
        $user = auth()->user();
        abort_unless($user && (Bouncer::is($user)->an('superadmin')
            || ($user->can('admin.access') && $user->can('integrations.eprel.settings.manage'))), 403);
    }
}
