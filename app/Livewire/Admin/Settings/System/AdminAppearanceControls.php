<?php

namespace App\Livewire\Admin\Settings\System;

use App\Services\Settings\SystemSettingsService;
use Livewire\Component;

class AdminAppearanceControls extends Component
{
    public array $form = [
        'admin_items_per_page' => 20,
        'admin_category_roots_per_page' => 12,
        'front_category_products_per_page_desktop' => 24,
        'front_category_products_per_page_mobile' => 12,
        'front_manufacturer_products_per_page_desktop' => 24,
        'front_manufacturer_products_per_page_mobile' => 12,
    ];

    public function mount(): void
    {
        /** @var array<string, int> $defaults */
        $defaults = config('admin_ui.pagination', []);
        $settings = app(SystemSettingsService::class);

        foreach ($this->form as $key => $fallback) {
            $default = (int) ($defaults[$key] ?? $fallback);
            $this->form[$key] = $settings->getInt($key, $default, 1, 200);
        }
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());

        app(SystemSettingsService::class)->putMany($validated['form']);

        $this->dispatch('notify', type: 'success', message: __('Admin appearance pagination settings saved.'));
    }

    public function resetToDefaults(): void
    {
        /** @var array<string, int> $defaults */
        $defaults = config('admin_ui.pagination', []);

        foreach ($this->form as $key => $fallback) {
            $this->form[$key] = (int) ($defaults[$key] ?? $fallback);
        }

        $this->dispatch('notify', type: 'info', message: __('Default values restored in form (save to persist).'));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.admin_items_per_page' => ['required', 'integer', 'min:5', 'max:200'],
            'form.admin_category_roots_per_page' => ['required', 'integer', 'min:5', 'max:100'],
            'form.front_category_products_per_page_desktop' => ['required', 'integer', 'min:6', 'max:120'],
            'form.front_category_products_per_page_mobile' => ['required', 'integer', 'min:4', 'max:80'],
            'form.front_manufacturer_products_per_page_desktop' => ['required', 'integer', 'min:6', 'max:120'],
            'form.front_manufacturer_products_per_page_mobile' => ['required', 'integer', 'min:4', 'max:80'],
        ];
    }

    public function render()
    {
        return view('livewire.admin.settings.system.admin-appearance-controls');
    }
}
