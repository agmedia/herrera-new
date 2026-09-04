<?php

namespace App\Livewire\Admin\Sales\Order;

use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'adminOrdersPage';

    public string $search = '';
    public string $status = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $sortBy = 'placed_at';
    public string $sortDir = 'desc';

    public function mount(): void
    {
        $this->search = (string) request()->query('search', '');

        $status = (string) request()->query('status', '');
        if ($status !== '' && ctype_digit($status) && (int) $status > 0) {
            $this->status = $status;
        }

        $dateFrom = (string) request()->query('dateFrom', '');
        if ($this->looksLikeDate($dateFrom)) {
            $this->dateFrom = $dateFrom;
        }

        $dateTo = (string) request()->query('dateTo', '');
        if ($this->looksLikeDate($dateTo)) {
            $this->dateTo = $dateTo;
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedStatus(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedDateTo(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function delete(int $orderId): void
    {
        $order = Order::query()->find($orderId);
        if (! $order) {
            $this->dispatch('notify', type: 'warning', message: __('Order not found.'));

            return;
        }

        $properties = [
            'order_id' => $orderId,
            'order_number' => $order->order_number,
            'item_qty' => (int) $order->item_qty,
            'grand_total' => (float) $order->grand_total,
        ];

        DB::transaction(function () use ($order): void {
            $order->delete();
        });

        activity('orders')
            ->performedOn($order)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties($properties)
            ->log('Order deleted from admin.');

        $this->dispatch('notify', type: 'success', message: __('Order deleted.'));
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function sort(string $field): void
    {
        $allowed = ['id', 'order_number', 'customer_name', 'customer_email', 'grand_total', 'placed_at', 'created_at'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDir = in_array($field, ['customer_name', 'customer_email', 'order_number'], true) ? 'asc' : 'desc';
        }

        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $rows = Order::query()
            ->with(['status:id,code,name,color', 'user:id,name,email'])
            ->withCount('items')
            ->when($this->status !== '', function (Builder $query): void {
                $statusId = (int) $this->status;
                if ($statusId > 0) {
                    $query->where('status_id', $statusId);
                }
            })
            ->when($this->search !== '', function (Builder $query): void {
                $needle = '%'.$this->search.'%';
                $query->where(function (Builder $q) use ($needle): void {
                    $q->where('order_number', 'like', $needle)
                        ->orWhere('customer_name', 'like', $needle)
                        ->orWhere('customer_email', 'like', $needle)
                        ->orWhere('customer_phone', 'like', $needle)
                        ->orWhere('id', 'like', $needle);
                });
            })
            ->when($this->dateFrom !== '', function (Builder $query): void {
                $query->whereDate('placed_at', '>=', $this->dateFrom);
            })
            ->when($this->dateTo !== '', function (Builder $query): void {
                $query->whereDate('placed_at', '<=', $this->dateTo);
            })
            ->orderBy($this->sortBy, $this->sortDir)
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], self::PAGE_NAME);

        $statuses = OrderStatus::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'color']);

        return view('livewire.admin.sales.order.manager', [
            'rows' => $rows,
            'statuses' => $statuses,
            'perPage' => $perPage,
        ]);
    }

    private function looksLikeDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
