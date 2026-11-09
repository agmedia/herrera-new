<?php

namespace App\Services\Sales;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\ContractWithdrawal;
use App\Models\Sales\ContractWithdrawalItem;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderItem;
use App\Models\User;
use App\Support\OrderStatusClassification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractWithdrawalWorkflow
{
    public function __construct(private readonly ReturnWorkflowSettings $settings) {}

    /** @return array<int, int> Availability is checked again under locks when saving. */
    public function availableQuantities(Order $order): array
    {
        $items = $order->items;
        $reserved = ContractWithdrawalItem::query()
            ->join('contract_withdrawals', 'contract_withdrawals.id', '=', 'contract_withdrawal_items.withdrawal_id')
            ->whereIn('order_item_id', $items->modelKeys())
            ->select('order_item_id')
            ->selectRaw('SUM(CASE WHEN contract_withdrawals.status = ? THEN received_quantity ELSE quantity END) AS reserved', [ContractWithdrawal::STATUS_DECLINED])
            ->groupBy('order_item_id')->pluck('reserved', 'order_item_id');

        return $items->mapWithKeys(fn ($item): array => [$item->id => max(0, $item->quantity - (int) ($reserved[$item->id] ?? 0))])->all();
    }

    /**
     * Reserve the requested quantities, not stock or sold statistics. Serializing
     * on the original order also coordinates competing requests for its items.
     *
     * @param  array<int|string, int|string>  $quantities  Original order-item ID => quantity
     */
    public function attachItems(ContractWithdrawal $withdrawal, array $quantities): void
    {
        DB::transaction(function () use ($withdrawal, $quantities): void {
            $order = $this->lockOrder($withdrawal);
            $withdrawal = ContractWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if ($withdrawal->approved_at || $withdrawal->lineItems()->lockForUpdate()->first(['id']) !== null
                || in_array($withdrawal->status, [ContractWithdrawal::STATUS_COMPLETED, ContractWithdrawal::STATUS_DECLINED], true)) {
                $this->invalid('Stavke već obrađenog zahtjeva nije moguće promijeniti.');
            }
            $selected = array_filter($quantities, static fn ($quantity): bool => (int) $quantity > 0);
            if ($selected === []) {
                $this->invalid('Odaberite barem jednu stavku i količinu za povrat ili reklamaciju.', 'line_quantities');
            }
            $items = $order->items()->whereIn('id', array_keys($selected))->lockForUpdate()->get()->keyBy('id');
            if ($items->count() !== count($selected)) {
                $this->invalid('Odabrane stavke ne pripadaju povezanoj narudžbi.', 'line_quantities');
            }
            foreach ($selected as $id => $quantity) {
                $item = $items->get((int) $id);
                $quantity = (int) $quantity;
                if (data_get($item->payload, 'inventory.restored_at')) {
                    $this->invalid('Zaliha ove stavke već je vraćena kroz otkazivanje narudžbe.');
                }
                // The enclosing request may already have a REPEATABLE READ
                // snapshot. A locking read must see requests committed while
                // this transaction waited for the original order lock.
                $reserved = ContractWithdrawalItem::query()
                    ->join('contract_withdrawals', 'contract_withdrawals.id', '=', 'contract_withdrawal_items.withdrawal_id')
                    ->where('order_item_id', $item->id)
                    ->lockForUpdate()
                    ->get(['contract_withdrawals.status as withdrawal_status', 'contract_withdrawal_items.quantity', 'contract_withdrawal_items.received_quantity'])
                    ->sum(static fn ($line): int => $line->withdrawal_status === ContractWithdrawal::STATUS_DECLINED
                        ? $line->received_quantity : $line->quantity);
                if ($quantity > max(0, (int) $item->quantity - (int) $reserved)) {
                    $this->invalid('Za artikl '.$item->name.' zatraženo je više komada nego što je preostalo za povrat.', 'line_quantities.'.$id);
                }
                $withdrawal->lineItems()->create([
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_option_value_id' => $item->product_option_value_id,
                    'name' => $item->name, 'sku' => $item->sku, 'code' => $item->code,
                    'quantity' => $quantity,
                ]);
            }
        }, 3);
    }

    public function approve(ContractWithdrawal $withdrawal, User $actor, ?string $note = null): void
    {
        abort_unless($this->settings->canApprove($actor), 403, 'Zahtjev odobrava ovlaštena osoba navedena u postavkama povrata.');
        DB::transaction(function () use ($withdrawal, $actor, $note): void {
            $this->lockOrder($withdrawal);
            $withdrawal = ContractWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if ($withdrawal->approved_at) {
                return;
            }
            if ($withdrawal->lineItems()->lockForUpdate()->first(['id']) === null || $withdrawal->status === ContractWithdrawal::STATUS_DECLINED) {
                $this->invalid('Prije odobrenja povežite stavke narudžbe. Odbijeni zahtjev nije moguće odobriti.');
            }
            // Claims record credit approval here. Financial settlement is handled separately.
            $withdrawal->forceFill([
                'approved_by' => $actor->id, 'approved_at' => now(),
                'status' => ContractWithdrawal::STATUS_APPROVED,
                'handled_by' => $actor->id, 'handled_at' => now(),
                'internal_note' => $note ?? $withdrawal->internal_note,
            ])->save();
        }, 3);
    }

    public function updateTracking(ContractWithdrawal $withdrawal, User $actor, string $status, ?string $note): void
    {
        DB::transaction(function () use ($withdrawal, $actor, $status, $note): void {
            if ($withdrawal->order_id) {
                Order::query()->whereKey($withdrawal->order_id)->lockForUpdate()->firstOrFail();
            }
            $withdrawal = ContractWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if ($status === ContractWithdrawal::STATUS_DECLINED) {
                abort_unless($this->settings->canApprove($actor), 403);
            }
            if ($withdrawal->lineItems()->lockForUpdate()->first(['id']) === null) {
                if (in_array($withdrawal->status, [ContractWithdrawal::STATUS_COMPLETED, ContractWithdrawal::STATUS_DECLINED], true)
                    && $status !== $withdrawal->status) {
                    $this->invalid('Dovršeni ili odbijeni zahtjev ostaje zatvoren u povijesti.');
                }
                if (! in_array($status, [ContractWithdrawal::STATUS_RECEIVED, ContractWithdrawal::STATUS_PROCESSING, ContractWithdrawal::STATUS_COMPLETED, ContractWithdrawal::STATUS_DECLINED], true)) {
                    $this->invalid('Za odobrenje i primitak robe najprije povežite stavke narudžbe.');
                }
                if ($status === ContractWithdrawal::STATUS_COMPLETED) {
                    abort_unless($this->settings->canApprove($actor), 403);
                }
                $closed = in_array($status, [ContractWithdrawal::STATUS_COMPLETED, ContractWithdrawal::STATUS_DECLINED], true);
                $withdrawal->forceFill([
                    'status' => $status, 'internal_note' => $note, 'handled_by' => $actor->id,
                    'handled_at' => now(), 'completed_at' => $closed ? ($withdrawal->completed_at ?? now()) : null,
                ])->save();

                return;
            }
            $allowed = $withdrawal->approved_at
                ? [$withdrawal->status]
                : [ContractWithdrawal::STATUS_RECEIVED, ContractWithdrawal::STATUS_PROCESSING, ContractWithdrawal::STATUS_DECLINED];
            if (! in_array($status, $allowed, true) || $withdrawal->status === ContractWithdrawal::STATUS_DECLINED && $status !== $withdrawal->status) {
                $this->invalid('Odobrenje i primitak robe evidentirajte odgovarajućim radnjama. Odbijeni zahtjev ostaje u povijesti.');
            }
            $withdrawal->forceFill([
                'status' => $status, 'internal_note' => $note, 'handled_by' => $actor->id,
                'handled_at' => now(), 'completed_at' => $status === ContractWithdrawal::STATUS_DECLINED ? ($withdrawal->completed_at ?? now()) : $withdrawal->completed_at,
            ])->save();
        }, 3);
    }

    /**
     * Cumulative targets make a repeated form submission a no-op. The receipt
     * ledger records only increases; neither this service nor approval changes money.
     *
     * @param  array<int|string, array{received:int|string,restocked:int|string}>  $targets
     */
    public function receive(ContractWithdrawal $withdrawal, User $actor, array $targets, bool $inspected, ?string $note = null): void
    {
        DB::transaction(function () use ($withdrawal, $actor, $targets, $inspected, $note): void {
            $this->lockOrder($withdrawal);
            $withdrawal = ContractWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if (! $withdrawal->approved_at || $withdrawal->status === ContractWithdrawal::STATUS_DECLINED) {
                $this->invalid('Prije zaprimanja robe zahtjev mora biti odobren.');
            }
            $lines = $withdrawal->lineItems()->orderBy('order_item_id')->lockForUpdate()->get();
            if ($lines->isEmpty() || array_diff(array_map('intval', array_keys($targets)), $lines->modelKeys()) !== []) {
                $this->invalid('Odabrane stavke ne pripadaju ovom zahtjevu.');
            }
            $changed = false;
            foreach ($lines as $line) {
                if (! isset($targets[$line->id])) {
                    continue;
                }
                $received = (int) $targets[$line->id]['received'];
                $restocked = (int) $targets[$line->id]['restocked'];
                if ($received < $line->received_quantity || $received > $line->quantity
                    || $restocked < $line->restocked_quantity || $restocked > $received) {
                    $this->invalid('Kumulativne količine ne smiju se smanjivati niti prelaziti odobrenu/zaprimljenu količinu.', 'receipts.'.$line->id);
                }
                $delta = $received - $line->received_quantity;
                $restockDelta = $restocked - $line->restocked_quantity;
                if ($delta === 0 && $restockDelta === 0) {
                    continue;
                }
                $item = OrderItem::query()->lockForUpdate()->findOrFail($line->order_item_id);
                if (data_get($item->payload, 'inventory.restored_at')) {
                    $this->invalid('Zaliha ove stavke već je vraćena kroz otkazivanje narudžbe.');
                }
                $otherReceived = ContractWithdrawalItem::query()->where('order_item_id', $item->id)->whereKeyNot($line->id)
                    ->lockForUpdate()->get(['received_quantity'])->sum('received_quantity');
                if ($received + (int) $otherReceived > (int) $item->quantity) {
                    $this->invalid('Ukupno zaprimljeni povrati ne smiju biti veći od prodane količine.');
                }
                if ($restockDelta > 0) {
                    if (! $inspected) {
                        $this->invalid('Potvrdite pregled i ispravnost robe prije dodavanja na zalihu.', 'inspected_sellable');
                    }
                    $this->restock($line, $restockDelta);
                }
                $line->forceFill(['received_quantity' => $received, 'restocked_quantity' => $restocked])->save();
                $line->receipts()->create([
                    'quantity' => $delta, 'restocked_quantity' => $restockDelta,
                    'received_by' => $actor->id, 'received_at' => now(), 'note' => $note,
                ]);
                $changed = true;
            }
            if (! $changed) {
                return;
            }
            $completed = $lines->every(static fn ($line): bool => $line->received_quantity === $line->quantity);
            $withdrawal->forceFill([
                'status' => $completed ? ContractWithdrawal::STATUS_COMPLETED : ContractWithdrawal::STATUS_PARTIALLY_RECEIVED,
                'goods_received_at' => $withdrawal->goods_received_at ?? now(),
                'completed_at' => $completed ? ($withdrawal->completed_at ?? now()) : null,
                'handled_by' => $actor->id, 'handled_at' => now(),
            ])->save();
        }, 3);
    }

    private function restock(ContractWithdrawalItem $line, int $quantity): void
    {
        // Physical returns go to our warehouse, never back to a supplier stock pool.
        $product = $line->product_id ? Product::query()->lockForUpdate()->find($line->product_id) : null;
        if (! $product) {
            $this->invalid('Proizvod više ne postoji u katalogu. Zaprimite bez dodavanja na zalihu i provjerite artikl.');
        }
        if ($line->product_option_value_id) {
            $stock = ProductOptionValue::query()->where('product_id', $product->id)->lockForUpdate()->find($line->product_option_value_id);
            if (! $stock) {
                $this->invalid('Izvorna varijanta više ne postoji. Robu nije moguće automatski dodati na zalihu.');
            }
        } else {
            $stock = $product;
        }
        $stock->increment('stock_qty', $quantity);
    }

    private function lockOrder(ContractWithdrawal $withdrawal): Order
    {
        $order = $withdrawal->order_id ? Order::query()->lockForUpdate()->find($withdrawal->order_id) : null;
        if (! $order) {
            $this->invalid('Zahtjev nije povezan s izvornom narudžbom.');
        }
        if ($order->status()->where(fn ($query) => OrderStatusClassification::applyCancelledQuery($query))->exists()) {
            $this->invalid('Otkazana narudžba ne može se ponovno zaprimiti kroz povrat.');
        }

        return $order;
    }

    private function invalid(string $message, string $field = 'workflow'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
