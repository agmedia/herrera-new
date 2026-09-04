<?php

namespace App\Livewire\Admin\Settings\System;

use App\Services\Catalog\CatalogFeatureService;
use App\Services\Settings\SystemSettingsService;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;

class CatalogFeatures extends Component
{
    public array $form = [
        'catalog_use_api' => false,
        'catalog_use_blog' => false,
        'catalog_use_attributes' => false,
        'catalog_use_options' => false,
        'catalog_use_manufacturers' => false,
        'catalog_use_actions' => false,
        'catalog_hide_out_of_stock_products' => false,
    ];

    public function mount(): void
    {
        $this->authorizeAccess();

        $this->form = array_merge(
            config('catalog_features.flags', []),
            app(CatalogFeatureService::class)->all()
        );
    }

    public function toggle(string $flag): void
    {
        $this->authorizeAccess();

        if (!in_array($flag, $this->flagKeys(), true)) {
            return;
        }

        $this->form[$flag] = !((bool) ($this->form[$flag] ?? false));
    }

    public function save(): void
    {
        $this->authorizeAccess();

        $this->validate($this->rules());

        $payload = [];
        foreach ($this->flagKeys() as $flag) {
            $payload[$flag] = (bool) ($this->form[$flag] ?? false);
        }

        app(SystemSettingsService::class)->putMany($payload);

        $this->dispatch('notify', type: 'success', message: __('Catalog feature flags saved.'));
    }

    public function resetToDefaults(): void
    {
        $this->authorizeAccess();

        /** @var array<string, bool> $defaults */
        $defaults = config('catalog_features.flags', []);

        foreach ($this->form as $key => $value) {
            $this->form[$key] = (bool) ($defaults[$key] ?? false);
        }

        $this->dispatch('notify', type: 'info', message: __('Default feature values loaded in form (save to persist).'));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $rules = [];

        foreach ($this->flagKeys() as $flag) {
            $rules['form.'.$flag] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    private function flagKeys(): array
    {
        return array_keys(config('catalog_features.flags', []));
    }

    public function render()
    {
        return view('livewire.admin.settings.system.catalog-features');
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('settings.system.catalog_features.manage')),
            403
        );
    }
}
