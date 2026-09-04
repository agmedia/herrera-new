<?php

namespace App\Livewire\Admin\Sales\Order;

use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Payments\BankTransferUpiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    public int $orderId;

    public string $tagInput = '';

    public int $redeemPoints = 0;

    /**
     * @var array<string, mixed>
     */
    public array $form = [
        'status_id' => null,
        'comment' => '',
    ];

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        $this->loadOrderDefaults();
    }

    public function updateStatus(): void
    {
        $validated = $this->validate([
            'form.status_id' => ['required', 'integer', Rule::exists('order_statuses', 'id')],
            'form.comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $toStatusId = (int) $validated['form']['status_id'];
        $comment = trim((string) ($validated['form']['comment'] ?? ''));
        if (! $this->applyStatusUpdate($toStatusId, $comment, 'admin')) {
            $this->dispatch('notify', type: 'info', message: __('No status change to save.'));

            return;
        }

        $this->dispatch('notify', type: 'success', message: __('Order status history updated.'));
    }

    public function quickStatusByCode(string $code): void
    {
        $status = OrderStatus::query()
            ->where('is_active', true)
            ->where('code', $code)
            ->first();

        if (! $status) {
            $this->dispatch('notify', type: 'warning', message: __('Quick status is not available.'));

            return;
        }

        $noteMap = [
            'paid' => 'Quick action: marked as paid from order detail.',
            'sent' => 'Quick action: marked as sent from order detail.',
            'cancelled' => 'Quick action: marked as cancelled from order detail.',
        ];
        $note = $noteMap[$code] ?? 'Quick action status update from order detail.';

        if (! $this->applyStatusUpdate((int) $status->id, $note, 'quick_action')) {
            $this->dispatch('notify', type: 'info', message: __('Order is already in selected quick status.'));

            return;
        }

        $this->dispatch('notify', type: 'success', message: __('Quick status action saved.'));
    }

    public function addInternalTag(): void
    {
        $this->validate([
            'tagInput' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9_\-\s]+$/'],
        ]);

        $tag = trim($this->tagInput);
        $order = Order::query()->findOrFail($this->orderId);
        $tags = $this->extractInternalTags($order);

        if (! in_array($tag, $tags, true)) {
            $tags[] = $tag;
        }

        $this->persistInternalTags($order, $tags, 'tag_added', 'Internal tag added to order.');
        $this->tagInput = '';
        $this->dispatch('notify', type: 'success', message: __('Tag added.'));
    }

    public function removeInternalTag(string $tag): void
    {
        $tag = trim($tag);
        if ($tag === '') {
            return;
        }

        $order = Order::query()->findOrFail($this->orderId);
        $tags = array_values(array_filter(
            $this->extractInternalTags($order),
            fn (string $item): bool => $item !== $tag
        ));

        $this->persistInternalTags($order, $tags, 'tag_removed', 'Internal tag removed from order.');
        $this->dispatch('notify', type: 'info', message: __('Tag removed.'));
    }

    public function backToList()
    {
        return redirect()->route('admin.orders');
    }

    public function applyLoyaltyRedemption(): void
    {
        $validated = $this->validate([
            'redeemPoints' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $loyaltyService = app(LoyaltyService::class);

        if (! $loyaltyService->enabled()) {
            $this->dispatch('notify', type: 'warning', message: __('Loyalty system is disabled.'));

            return;
        }

        $result = null;

        DB::transaction(function () use ($validated, &$result, $loyaltyService): void {
            $order = Order::query()
                ->lockForUpdate()
                ->with('status:id,code,name,color,is_paid,is_cancelled')
                ->findOrFail($this->orderId);

            if (! $order->user_id) {
                return;
            }

            $result = $loyaltyService->syncOrderRedemption(
                $order,
                (int) $validated['redeemPoints'],
                auth()->id()
            );
        });

        $this->loadOrderDefaults();

        if (! is_array($result)) {
            $this->dispatch('notify', type: 'warning', message: __('Loyalty redemption requires an assigned user.'));

            return;
        }

        $appliedPoints = (int) ($result['applied_points'] ?? 0);
        $appliedAmount = (float) ($result['applied_amount'] ?? 0);

        if ($appliedPoints > 0) {
            $this->dispatch(
                'notify',
                type: 'success',
                message: __('Loyalty redemption applied: ').$appliedPoints.' pts / '.number_format($appliedAmount, 2)
            );
        } else {
            $this->dispatch('notify', type: 'info', message: __('Loyalty redemption cleared.'));
        }
    }

    public function render()
    {
        $order = Order::query()
            ->with([
                'status:id,code,name,color,is_paid,is_cancelled',
                'user:id,name,email',
                'items',
                'totals',
                'history.fromStatus:id,name,color',
                'history.toStatus:id,name,color',
                'history.changedBy:id,name',
                'transactions',
                'loyaltyTransactions',
            ])
            ->findOrFail($this->orderId);

        $statuses = OrderStatus::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'color']);

        $quickStatuses = OrderStatus::query()
            ->where('is_active', true)
            ->whereIn('code', ['paid', 'sent', 'cancelled'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'color']);

        $loyaltyService = app(LoyaltyService::class);
        $loyaltyEnabled = $loyaltyService->availableForUser($order->user_id);
        $bankTransfer = app(BankTransferUpiService::class)->ensureForOrder($order);
        $currencyValuePerPoint = $loyaltyEnabled ? $loyaltyService->currencyValuePerPoint() : 0.0;
        $redemptionPoints = 0;
        if ($loyaltyEnabled) {
            $redemptionPoints = max(
                0,
                -1 * (int) optional($order->loyaltyTransactions->firstWhere('type', 'order_redemption'))->points
            );
        }

        $availableLoyaltyPoints = null;
        $maxRedeemablePoints = 0;
        if ($order->user_id && $loyaltyEnabled && $currencyValuePerPoint > 0) {
            $rawBalance = $loyaltyService->pointsBalanceForUser((int) $order->user_id);
            $availableLoyaltyPoints = max(0, $rawBalance + $redemptionPoints);

            $payload = is_array($order->payload) ? $order->payload : [];
            $baseTotals = is_array($payload['loyalty_base_totals'] ?? null) ? $payload['loyalty_base_totals'] : [];
            $baseGrand = isset($baseTotals['grand_total']) && is_numeric($baseTotals['grand_total'])
                ? (float) $baseTotals['grand_total']
                : ((float) $order->grand_total + ($redemptionPoints * $currencyValuePerPoint));
            $maxByOrder = (int) floor(max(0.0, $baseGrand) / $currencyValuePerPoint);
            $maxRedeemablePoints = min($availableLoyaltyPoints, $maxByOrder);
        }

        return view('livewire.admin.sales.order.show', [
            'order' => $order,
            'statuses' => $statuses,
            'quickStatuses' => $quickStatuses,
            'internalTags' => $this->extractInternalTags($order),
            'availableLoyaltyPoints' => $availableLoyaltyPoints,
            'maxRedeemablePoints' => $maxRedeemablePoints,
            'currencyValuePerPoint' => $currencyValuePerPoint,
            'loyaltyEnabled' => $loyaltyEnabled,
            'bankTransfer' => $bankTransfer,
        ]);
    }

    private function loadOrderDefaults(): void
    {
        $order = Order::query()->findOrFail($this->orderId);
        $this->form['status_id'] = $order->status_id;
        if (! app(LoyaltyService::class)->availableForUser($order->user_id)) {
            $this->redeemPoints = 0;

            return;
        }

        $redemption = $order->loyaltyTransactions()
            ->where('type', 'order_redemption')
            ->latest('id')
            ->first();
        $this->redeemPoints = max(0, -1 * (int) ($redemption?->points ?? 0));
    }

    private function applyStatusUpdate(int $toStatusId, string $comment, string $origin): bool
    {
        $saved = false;

        DB::transaction(function () use ($toStatusId, $comment, $origin, &$saved): void {
            $order = Order::query()->lockForUpdate()->findOrFail($this->orderId);
            $fromStatusId = $order->status_id ? (int) $order->status_id : null;
            $changed = $fromStatusId !== $toStatusId;

            if (! $changed && $comment === '') {
                $saved = false;

                return;
            }

            $targetStatus = OrderStatus::query()->find($toStatusId);

            if ($changed) {
                $order->status_id = $toStatusId;
                $order->updated_by = auth()->id();

                if ($targetStatus?->is_paid && ! $order->paid_at) {
                    $order->paid_at = now();
                }
            }

            if ($comment !== '') {
                $order->admin_note = $comment;
            }

            $order->save();

            $order->history()->create([
                'from_status_id' => $fromStatusId,
                'to_status_id' => $toStatusId,
                'changed_by' => auth()->id(),
                'comment' => $comment !== '' ? $comment : null,
                'payload' => [
                    'origin' => $origin,
                    'status_changed' => $changed,
                ],
            ]);

            activity('orders')
                ->performedOn($order)
                ->causedBy(auth()->user())
                ->event($changed ? 'status_changed' : 'note_added')
                ->withProperties([
                    'origin' => $origin,
                    'from_status_id' => $fromStatusId,
                    'to_status_id' => $toStatusId,
                    'comment' => $comment,
                ])
                ->log($changed ? 'Order status updated from admin.' : 'Order note added from admin.');

            $saved = true;
        });

        $this->form['comment'] = '';
        $this->loadOrderDefaults();

        return $saved;
    }

    /**
     * @return array<int, string>
     */
    private function extractInternalTags(Order $order): array
    {
        $payload = is_array($order->payload) ? $order->payload : [];
        $raw = $payload['internal_tags'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $tags = collect($raw)
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();

        return $tags;
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function persistInternalTags(Order $order, array $tags, string $event, string $message): void
    {
        $payload = is_array($order->payload) ? $order->payload : [];
        $payload['internal_tags'] = array_values($tags);

        $order->payload = $payload;
        $order->updated_by = auth()->id();
        $order->save();

        activity('orders')
            ->performedOn($order)
            ->causedBy(auth()->user())
            ->event($event)
            ->withProperties([
                'internal_tags' => $payload['internal_tags'],
            ])
            ->log($message);
    }
}
