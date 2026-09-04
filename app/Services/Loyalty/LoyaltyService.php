<?php

namespace App\Services\Loyalty;

use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderTotal;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Models\User\LoyaltyTransaction;
use App\Services\Settings\SystemSettingsService;

class LoyaltyService
{
    public function __construct(
        private readonly SystemSettingsService $settings
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(
            'user_loyalty_enabled',
            (bool) config('user_features.flags.user_loyalty_enabled', false)
        );
    }

    public function availableForUser(User|int|null $user): bool
    {
        return $this->enabled() && $this->userIsEligible($user);
    }

    public function userIsEligible(User|int|null $user): bool
    {
        $userId = $user instanceof User ? (int) $user->getKey() : (int) $user;
        if ($userId <= 0) {
            return false;
        }

        $eligibleGroupIds = $this->eligibleCustomerGroupIds();
        if ($eligibleGroupIds === []) {
            return true;
        }

        if ($user instanceof User && $user->relationLoaded('customerGroups')) {
            if ($user->customerGroups->isEmpty()) {
                return false;
            }

            $hasActiveState = $user->customerGroups->every(
                fn ($group): bool => array_key_exists('is_active', $group->getAttributes())
            );

            if ($hasActiveState) {
                return $user->customerGroups->contains(
                    fn ($group): bool => in_array((int) $group->id, $eligibleGroupIds, true)
                        && (bool) $group->is_active
                );
            }
        }

        return User::query()
            ->whereKey($userId)
            ->whereHas('customerGroups', function ($query) use ($eligibleGroupIds): void {
                $query->whereIn('customer_groups.id', $eligibleGroupIds)
                    ->where('customer_groups.is_active', true);
            })
            ->exists();
    }

    /**
     * @return array<int, int>
     */
    public function eligibleCustomerGroupIds(): array
    {
        $raw = $this->settings->get(
            'loyalty_customer_group_ids',
            (array) config('user_features.loyalty.eligible_customer_group_ids', [])
        );

        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function pointsBalanceForUser(int $userId): int
    {
        return (int) LoyaltyTransaction::query()
            ->where('user_id', $userId)
            ->sum('points');
    }

    public function currencyValuePerPoint(): float
    {
        $raw = $this->settings->get(
            'loyalty_currency_value_per_point',
            (float) config('user_features.loyalty.currency_value_per_point', 0.01)
        );
        $value = is_numeric($raw) ? (float) $raw : 0.01;

        return max(0.0, min(10000.0, $value));
    }

    /**
     * @return array<string, mixed>
     */
    public function syncOrderRedemption(Order $order, int $requestedPoints, ?int $actorUserId = null): array
    {
        $requestedPoints = max(0, $requestedPoints);

        if (! $this->availableForUser($order->user_id)) {
            return $this->redemptionResult($requestedPoints, 0, 0.0, 0, 0, 0.0);
        }

        $currencyPerPoint = $this->currencyValuePerPoint();
        if ($currencyPerPoint <= 0) {
            return $this->redemptionResult($requestedPoints, 0, 0.0, 0, 0, 0.0);
        }

        $eventKey = 'order:'.$order->id.':redemption';
        $existingRedemption = LoyaltyTransaction::query()->where('event_key', $eventKey)->first();
        $existingPoints = max(0, -1 * (int) ($existingRedemption?->points ?? 0));
        $existingDiscountAmount = round($existingPoints * $currencyPerPoint, 2);

        $currentBalance = $this->pointsBalanceForUser((int) $order->user_id);
        $availablePoints = max(0, $currentBalance + $existingPoints);

        $payload = is_array($order->payload) ? $order->payload : [];
        $baseTotals = is_array($payload['loyalty_base_totals'] ?? null) ? $payload['loyalty_base_totals'] : [];
        $baseDiscount = isset($baseTotals['discount_total']) && is_numeric($baseTotals['discount_total'])
            ? (float) $baseTotals['discount_total']
            : (float) $order->discount_total;
        $baseGrand = isset($baseTotals['grand_total']) && is_numeric($baseTotals['grand_total'])
            ? (float) $baseTotals['grand_total']
            : ((float) $order->grand_total + $existingDiscountAmount);

        $maxByOrder = (int) floor(max(0.0, $baseGrand) / $currencyPerPoint);
        $appliedPoints = min($requestedPoints, $availablePoints, $maxByOrder);
        $appliedDiscount = round($appliedPoints * $currencyPerPoint, 2);

        $newDiscountTotal = round(max(0.0, $baseDiscount + $appliedDiscount), 2);
        $newGrandTotal = round(max(0.0, $baseGrand - $appliedDiscount), 2);

        $payload['loyalty_base_totals'] = [
            'discount_total' => $baseDiscount,
            'grand_total' => $baseGrand,
        ];

        if ($appliedPoints > 0) {
            $payload['loyalty_redemption'] = [
                'points' => $appliedPoints,
                'amount' => $appliedDiscount,
                'currency_value_per_point' => $currencyPerPoint,
                'event_key' => $eventKey,
                'updated_at' => now()->toISOString(),
            ];
        } else {
            unset($payload['loyalty_redemption']);
        }

        $order->discount_total = $newDiscountTotal;
        $order->grand_total = $newGrandTotal;
        $order->payload = $payload;
        $order->updated_by = $actorUserId;
        $order->save();

        OrderTotal::query()
            ->where('order_id', $order->id)
            ->where('code', 'grand_total')
            ->update(['value' => $newGrandTotal]);

        if ($appliedPoints > 0) {
            $redemption = LoyaltyTransaction::query()->updateOrCreate(
                ['event_key' => $eventKey],
                [
                    'user_id' => (int) $order->user_id,
                    'order_id' => $order->id,
                    'type' => 'order_redemption',
                    'points' => -1 * $appliedPoints,
                    'note' => 'Order discount consumed through loyalty redemption.',
                    'payload' => [
                        'requested_points' => $requestedPoints,
                        'applied_points' => $appliedPoints,
                        'applied_amount' => $appliedDiscount,
                        'currency_value_per_point' => $currencyPerPoint,
                    ],
                    'created_by' => $actorUserId,
                ]
            );

            OrderTotal::query()->updateOrCreate(
                ['order_id' => $order->id, 'code' => 'loyalty_redemption'],
                [
                    'title' => 'Loyalty Redemption',
                    'value' => -1 * $appliedDiscount,
                    'sort_order' => 650,
                    'payload' => [
                        'points' => $appliedPoints,
                        'event_key' => $eventKey,
                    ],
                ]
            );

            if ($redemption->wasRecentlyCreated || $existingPoints !== $appliedPoints) {
                $this->logLoyaltyAudit(
                    $order,
                    $actorUserId,
                    'order_redemption_synced',
                    'Order loyalty redemption synced.',
                    [
                        'event_key' => $eventKey,
                        'from_points' => $existingPoints,
                        'to_points' => $appliedPoints,
                        'from_amount' => $existingDiscountAmount,
                        'to_amount' => $appliedDiscount,
                    ]
                );
            }
        } else {
            if ($existingRedemption) {
                $existingRedemption->delete();

                $this->logLoyaltyAudit(
                    $order,
                    $actorUserId,
                    'order_redemption_removed',
                    'Order loyalty redemption removed.',
                    [
                        'event_key' => $eventKey,
                        'from_points' => $existingPoints,
                        'to_points' => 0,
                        'from_amount' => $existingDiscountAmount,
                        'to_amount' => 0,
                    ]
                );
            }

            OrderTotal::query()
                ->where('order_id', $order->id)
                ->where('code', 'loyalty_redemption')
                ->delete();
        }

        return $this->redemptionResult(
            $requestedPoints,
            $appliedPoints,
            $appliedDiscount,
            $availablePoints,
            $maxByOrder,
            $currencyPerPoint
        );
    }

    public function syncOrderSettlement(Order $order, ?OrderStatus $status = null, ?int $actorUserId = null): void
    {
        if (! $this->enabled() || ! $order->user_id) {
            return;
        }

        $status = $status ?: $order->status;
        if (! $status) {
            return;
        }

        $settlementEventKey = 'order:'.$order->id.':settlement';
        $reversalEventKey = 'order:'.$order->id.':reversal';
        $targetPoints = $this->userIsEligible((int) $order->user_id)
            ? $this->resolveSettlementPoints($order, $status)
            : 0;
        $reversalMode = $this->reversalMode();
        $existingSettlement = LoyaltyTransaction::query()
            ->where('event_key', $settlementEventKey)
            ->first();
        $existingSettlementPoints = (int) ($existingSettlement?->points ?? 0);
        $existingReversal = LoyaltyTransaction::query()
            ->where('event_key', $reversalEventKey)
            ->first();

        if ($targetPoints === 0 && ! $existingSettlement && ! $existingReversal) {
            return;
        }

        if ($reversalMode === 'separate_entry') {
            $this->syncSeparateEntryMode(
                $order,
                $status,
                $targetPoints,
                $existingSettlementPoints,
                $existingSettlement?->id,
                $settlementEventKey,
                $reversalEventKey,
                $existingReversal?->id,
                (int) ($existingReversal?->points ?? 0),
                $actorUserId
            );

            return;
        }

        $this->syncZeroOutMode(
            $order,
            $status,
            $targetPoints,
            $existingSettlementPoints,
            $existingSettlement?->id,
            $settlementEventKey,
            $reversalEventKey,
            $existingReversal?->id,
            (int) ($existingReversal?->points ?? 0),
            $actorUserId
        );
    }

    private function syncZeroOutMode(
        Order $order,
        OrderStatus $status,
        int $targetPoints,
        int $existingSettlementPoints,
        ?int $existingSettlementId,
        string $settlementEventKey,
        string $reversalEventKey,
        ?int $existingReversalId,
        int $existingReversalPoints,
        ?int $actorUserId
    ): void {
        if ($targetPoints > 0 || $existingSettlementId) {
            $settlement = LoyaltyTransaction::query()->updateOrCreate(
                ['event_key' => $settlementEventKey],
                $this->buildSettlementPayload($order, $status, $targetPoints, $actorUserId, 'zero_out')
            );

            if ($settlement->wasRecentlyCreated || $existingSettlementPoints !== $targetPoints) {
                $this->logLoyaltyAudit(
                    $order,
                    $actorUserId,
                    'order_settlement_synced',
                    'Order loyalty settlement synced.',
                    [
                        'mode' => 'zero_out',
                        'status_code' => $status->code,
                        'event_key' => $settlementEventKey,
                        'from_points' => $existingSettlementPoints,
                        'to_points' => $targetPoints,
                    ]
                );
            }
        }

        if ($existingReversalId) {
            LoyaltyTransaction::query()
                ->where('event_key', $reversalEventKey)
                ->delete();

            $this->logLoyaltyAudit(
                $order,
                $actorUserId,
                'order_reversal_removed',
                'Order loyalty reversal removed.',
                [
                    'mode' => 'zero_out',
                    'status_code' => $status->code,
                    'event_key' => $reversalEventKey,
                    'from_points' => $existingReversalPoints,
                    'to_points' => 0,
                ]
            );
        }
    }

    private function syncSeparateEntryMode(
        Order $order,
        OrderStatus $status,
        int $targetPoints,
        int $existingSettlementPoints,
        ?int $existingSettlementId,
        string $settlementEventKey,
        string $reversalEventKey,
        ?int $existingReversalId,
        int $existingReversalPoints,
        ?int $actorUserId
    ): void {
        if ($targetPoints > 0) {
            $settlement = LoyaltyTransaction::query()->updateOrCreate(
                ['event_key' => $settlementEventKey],
                $this->buildSettlementPayload($order, $status, $targetPoints, $actorUserId, 'separate_entry')
            );

            if ($settlement->wasRecentlyCreated || $existingSettlementPoints !== $targetPoints) {
                $this->logLoyaltyAudit(
                    $order,
                    $actorUserId,
                    'order_settlement_synced',
                    'Order loyalty settlement synced.',
                    [
                        'mode' => 'separate_entry',
                        'status_code' => $status->code,
                        'event_key' => $settlementEventKey,
                        'from_points' => $existingSettlementPoints,
                        'to_points' => $targetPoints,
                    ]
                );
            }

            if ($existingReversalId) {
                LoyaltyTransaction::query()
                    ->where('event_key', $reversalEventKey)
                    ->delete();

                $this->logLoyaltyAudit(
                    $order,
                    $actorUserId,
                    'order_reversal_removed',
                    'Order loyalty reversal removed.',
                    [
                        'mode' => 'separate_entry',
                        'status_code' => $status->code,
                        'event_key' => $reversalEventKey,
                        'from_points' => $existingReversalPoints,
                        'to_points' => 0,
                    ]
                );
            }

            return;
        }

        if ($existingSettlementPoints > 0) {
            if (! $existingSettlementId) {
                LoyaltyTransaction::query()->create(
                    array_merge(
                        $this->buildSettlementPayload($order, $status, $existingSettlementPoints, $actorUserId, 'separate_entry'),
                        ['event_key' => $settlementEventKey]
                    )
                );
            }

            $reversal = LoyaltyTransaction::query()->updateOrCreate(
                ['event_key' => $reversalEventKey],
                [
                    'user_id' => (int) $order->user_id,
                    'order_id' => $order->id,
                    'type' => 'order_reversal',
                    'points' => -1 * $existingSettlementPoints,
                    'note' => 'Auto reversal from order status sync.',
                    'payload' => [
                        'status_id' => $status->id,
                        'status_code' => $status->code,
                        'reversal_mode' => 'separate_entry',
                        'reversed_points' => $existingSettlementPoints,
                    ],
                    'created_by' => $actorUserId,
                ]
            );

            if ($reversal->wasRecentlyCreated || $existingReversalPoints !== (-1 * $existingSettlementPoints)) {
                $this->logLoyaltyAudit(
                    $order,
                    $actorUserId,
                    'order_reversal_synced',
                    'Order loyalty reversal synced.',
                    [
                        'mode' => 'separate_entry',
                        'status_code' => $status->code,
                        'event_key' => $reversalEventKey,
                        'from_points' => $existingReversalPoints,
                        'to_points' => -1 * $existingSettlementPoints,
                    ]
                );
            }
        } else {
            if ($existingReversalId) {
                LoyaltyTransaction::query()
                    ->where('event_key', $reversalEventKey)
                    ->delete();

                $this->logLoyaltyAudit(
                    $order,
                    $actorUserId,
                    'order_reversal_removed',
                    'Order loyalty reversal removed.',
                    [
                        'mode' => 'separate_entry',
                        'status_code' => $status->code,
                        'event_key' => $reversalEventKey,
                        'from_points' => $existingReversalPoints,
                        'to_points' => 0,
                    ]
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSettlementPayload(
        Order $order,
        OrderStatus $status,
        int $points,
        ?int $actorUserId,
        string $reversalMode
    ): array {
        return [
            'user_id' => (int) $order->user_id,
            'order_id' => $order->id,
            'type' => 'order_settlement',
            'points' => $points,
            'note' => 'Auto settlement from order status sync.',
            'payload' => [
                'status_id' => $status->id,
                'status_code' => $status->code,
                'grand_total' => (float) $order->grand_total,
                'rate' => $this->pointsPerCurrency(),
                'min_order_total' => $this->minOrderTotal(),
                'awarded' => $points > 0,
                'reversal_mode' => $reversalMode,
            ],
            'created_by' => $actorUserId,
        ];
    }

    private function resolveSettlementPoints(Order $order, OrderStatus $status): int
    {
        if (! $status->is_paid || $status->is_cancelled) {
            return 0;
        }

        $grandTotal = (float) $order->grand_total;
        if ($grandTotal < $this->minOrderTotal()) {
            return 0;
        }

        $points = (int) round($grandTotal * $this->pointsPerCurrency());

        return max(0, $points);
    }

    private function pointsPerCurrency(): float
    {
        $raw = $this->settings->get(
            'loyalty_points_per_currency',
            (float) config('user_features.loyalty.points_per_currency', 1.0)
        );

        $value = is_numeric($raw) ? (float) $raw : 1.0;

        return max(0.0, min(10000.0, $value));
    }

    private function minOrderTotal(): float
    {
        $raw = $this->settings->get(
            'loyalty_min_order_total',
            (float) config('user_features.loyalty.min_order_total', 0.0)
        );

        $value = is_numeric($raw) ? (float) $raw : 0.0;

        return max(0.0, min(10000000.0, $value));
    }

    private function reversalMode(): string
    {
        $mode = (string) $this->settings->get(
            'loyalty_reversal_mode',
            (string) config('user_features.loyalty.reversal_mode', 'zero_out')
        );

        return in_array($mode, ['zero_out', 'separate_entry'], true) ? $mode : 'zero_out';
    }

    /**
     * @return array<string, mixed>
     */
    private function redemptionResult(
        int $requestedPoints,
        int $appliedPoints,
        float $appliedAmount,
        int $availablePoints,
        int $maxPointsByOrder,
        float $currencyValuePerPoint
    ): array {
        return [
            'requested_points' => $requestedPoints,
            'applied_points' => $appliedPoints,
            'applied_amount' => $appliedAmount,
            'available_points' => $availablePoints,
            'max_points_by_order' => $maxPointsByOrder,
            'currency_value_per_point' => $currencyValuePerPoint,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logLoyaltyAudit(
        Order $order,
        ?int $actorUserId,
        string $event,
        string $description,
        array $properties
    ): void {
        $logger = activity('loyalty')
            ->performedOn($order)
            ->event($event)
            ->withProperties($properties);

        if ($actorUserId) {
            $actor = User::query()->find($actorUserId);
            if ($actor) {
                $logger->causedBy($actor);
            }
        }

        $logger->log($description);
    }
}
