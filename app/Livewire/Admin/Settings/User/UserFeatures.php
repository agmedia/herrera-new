<?php

namespace App\Livewire\Admin\Settings\User;

use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Validation\Rule;
use Livewire\Component;

class UserFeatures extends Component
{
    public array $form = [
        'user_tracking_enabled' => true,
        'user_loyalty_enabled' => false,
        'loyalty_points_per_currency' => 1.0,
        'loyalty_currency_value_per_point' => 0.01,
        'loyalty_customer_group_ids' => [],
        'loyalty_min_order_total' => 0.0,
        'loyalty_reversal_mode' => 'zero_out',
    ];

    public function mount(): void
    {
        /** @var array<string, mixed> $defaults */
        $defaults = array_merge(
            config('user_features.flags', []),
            [
                'loyalty_points_per_currency' => (float) config('user_features.loyalty.points_per_currency', 1.0),
                'loyalty_currency_value_per_point' => (float) config('user_features.loyalty.currency_value_per_point', 0.01),
                'loyalty_customer_group_ids' => (array) config('user_features.loyalty.eligible_customer_group_ids', []),
                'loyalty_min_order_total' => (float) config('user_features.loyalty.min_order_total', 0.0),
                'loyalty_reversal_mode' => (string) config('user_features.loyalty.reversal_mode', 'zero_out'),
            ]
        );

        $settings = app(SystemSettingsService::class);
        foreach ($defaults as $key => $defaultValue) {
            $this->form[$key] = $settings->get($key, $defaultValue);
        }
    }

    public function toggle(string $key): void
    {
        if (! in_array($key, ['user_tracking_enabled', 'user_loyalty_enabled'], true)) {
            return;
        }

        $this->form[$key] = ! (bool) ($this->form[$key] ?? false);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'form.user_tracking_enabled' => ['required', 'boolean'],
            'form.user_loyalty_enabled' => ['required', 'boolean'],
            'form.loyalty_points_per_currency' => ['required', 'numeric', 'min:0', 'max:10000'],
            'form.loyalty_currency_value_per_point' => ['required', 'numeric', 'min:0', 'max:10000'],
            'form.loyalty_customer_group_ids' => ['array'],
            'form.loyalty_customer_group_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('customer_groups', 'id')->where('is_active', true),
            ],
            'form.loyalty_min_order_total' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'form.loyalty_reversal_mode' => ['required', 'string', 'in:zero_out,separate_entry'],
        ]);

        $payload = [
            'user_tracking_enabled' => (bool) $validated['form']['user_tracking_enabled'],
            'user_loyalty_enabled' => (bool) $validated['form']['user_loyalty_enabled'],
            'loyalty_points_per_currency' => (float) $validated['form']['loyalty_points_per_currency'],
            'loyalty_currency_value_per_point' => (float) $validated['form']['loyalty_currency_value_per_point'],
            'loyalty_customer_group_ids' => collect($validated['form']['loyalty_customer_group_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all(),
            'loyalty_min_order_total' => (float) $validated['form']['loyalty_min_order_total'],
            'loyalty_reversal_mode' => (string) $validated['form']['loyalty_reversal_mode'],
        ];

        app(SystemSettingsService::class)->putMany($payload);

        $this->dispatch('notify', type: 'success', message: __('User settings saved.'));
    }

    public function resetToDefaults(): void
    {
        $this->form['user_tracking_enabled'] = (bool) config('user_features.flags.user_tracking_enabled', true);
        $this->form['user_loyalty_enabled'] = (bool) config('user_features.flags.user_loyalty_enabled', false);
        $this->form['loyalty_points_per_currency'] = (float) config('user_features.loyalty.points_per_currency', 1.0);
        $this->form['loyalty_currency_value_per_point'] = (float) config('user_features.loyalty.currency_value_per_point', 0.01);
        $this->form['loyalty_customer_group_ids'] = (array) config('user_features.loyalty.eligible_customer_group_ids', []);
        $this->form['loyalty_min_order_total'] = (float) config('user_features.loyalty.min_order_total', 0.0);
        $this->form['loyalty_reversal_mode'] = (string) config('user_features.loyalty.reversal_mode', 'zero_out');

        $this->dispatch('notify', type: 'info', message: __('Default user settings loaded in form (save to persist).'));
    }

    public function render()
    {
        return view('livewire.admin.settings.user.user-features', [
            'customerGroupOptions' => CustomerGroup::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
