<?php

namespace App\Livewire\Admin\Integrations\Delivery;

use App\Models\Integrations\SupplierDeliverySchedule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;

class Dashboard extends Component
{
    public array $forms = [];

    public string $newSupplier = '';

    public string $newName = '';

    public function mount(): void
    {
        $this->authorizeManage();
        $this->loadForms();
    }

    public function save(int $id): void
    {
        $this->authorizeManage();
        $schedule = SupplierDeliverySchedule::query()->findOrFail($id);
        $data = $this->validate([
            "forms.$id.days" => ['nullable', 'string', 'max:100'],
            "forms.$id.aliases" => ['nullable', 'string', 'max:1000'],
            "forms.$id.weekday" => ['nullable', 'integer', 'between:1,7'],
            "forms.$id.next_order_date" => ['nullable', 'date_format:Y-m-d'],
            "forms.$id.lead_days" => ['required', 'integer', 'between:0,365'],
            "forms.$id.is_active" => ['boolean'],
        ])['forms'][$id];
        $days = preg_split('/[\s,;]+/', trim($data['days'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($days as $day) {
            if (! ctype_digit($day) || (int) $day < 1 || (int) $day > 31) {
                throw ValidationException::withMessages(["forms.$id.days" => 'Unesite dane u mjesecu od 1 do 31, odvojene zarezom.']);
            }
        }
        $schedule->update([
            'monthly_days' => array_values(array_unique(array_map('intval', $days))),
            'aliases' => array_values(array_filter(array_map('trim', explode(',', $data['aliases'] ?? '')))),
            'weekday' => ($data['weekday'] ?? '') !== '' ? $data['weekday'] : null,
            'next_order_date' => ($data['next_order_date'] ?? '') !== '' ? $data['next_order_date'] : null,
            'lead_days' => $data['lead_days'], 'is_active' => $data['is_active'],
        ]);
        $this->dispatch('notify', type: 'success', message: 'Raspored je spremljen.');
    }

    public function create(): void
    {
        $this->authorizeManage();
        $validated = $this->validate([
            'newSupplier' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:80', 'unique:supplier_delivery_schedules,supplier'],
            'newName' => ['required', 'string', 'max:255'],
        ]);
        SupplierDeliverySchedule::query()->create(['supplier' => $validated['newSupplier'], 'name' => $validated['newName'], 'lead_days' => 14, 'monthly_days' => [], 'aliases' => [], 'is_active' => true]);
        $this->reset('newSupplier', 'newName');
        $this->loadForms();
    }

    public function render()
    {
        $this->authorizeManage();

        return view('livewire.admin.integrations.delivery.dashboard', ['schedules' => SupplierDeliverySchedule::query()->orderBy('name')->get()]);
    }

    private function loadForms(): void
    {
        foreach (SupplierDeliverySchedule::query()->get() as $schedule) {
            $this->forms[$schedule->id] = ['days' => implode(', ', $schedule->monthly_days ?? []), 'aliases' => implode(', ', $schedule->aliases ?? []), 'weekday' => $schedule->weekday ?? '', 'next_order_date' => $schedule->next_order_date?->toDateString() ?? '', 'lead_days' => $schedule->lead_days, 'is_active' => $schedule->is_active];
        }
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->check() && (auth()->user()->isA('superadmin') || Bouncer::can('integrations.stock.manage')), 403);
    }
}
