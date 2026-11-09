<?php

namespace Tests\Feature\Admin;

use App\Mail\ContractWithdrawalAdminMail;
use App\Mail\ContractWithdrawalReceiptMail;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\ContractWithdrawal;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderItem;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Services\Analytics\CustomerPurchaseStatistics;
use App\Services\Front\OrderStockAllocationService;
use App\Services\Sales\ContractWithdrawalWorkflow;
use App\Services\Sales\ReceivedReturnQuantities;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class StructuredReturnsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_select_original_order_items_and_partial_quantities_and_all_contacts_are_notified(): void
    {
        Mail::fake();
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'store_withdrawal_admin_email' => 'returns@example.test',
        ]);
        [$order, $item] = $this->orderItem();
        $review = $this->post('/forma-za-povrat-i-reklamacije', $this->submission($order, ['request_type' => 'claim']))
            ->assertOk()->assertSee($item->name)->assertSee('line_quantities['.$item->id.']', false);
        preg_match('/name="draft_token" value="([^"]+)"/', $review->getContent(), $matches);
        $this->post('/forma-za-povrat-i-reklamacije/confirm', [
            'draft_token' => $matches[1], 'structured_items' => 1, 'line_quantities' => [$item->id => 2],
        ])->assertRedirect('/forma-za-povrat-i-reklamacije');
        $withdrawal = ContractWithdrawal::query()->firstOrFail();
        $this->assertSame('claim', $withdrawal->request_type);
        $this->assertSame($order->id, $withdrawal->order_id);
        $this->assertSame(2, $withdrawal->lineItems->sole()->quantity);
        $this->assertSame(0, $withdrawal->lineItems->sole()->received_quantity);
        $this->assertSame($item->id, $withdrawal->request_snapshot['data']['line_items'][0]['order_item_id']);
        $this->assertStringContainsString('reklamaciju', $withdrawal->declaration);
        Mail::assertSent(ContractWithdrawalReceiptMail::class, fn ($mail): bool => $mail->hasTo('buyer@example.test'));
        foreach (['returns@example.test', 'klara@herrera.hr', 'warehouse@herrera.hr', 'judita@herrera.hr'] as $email) {
            Mail::assertSent(ContractWithdrawalAdminMail::class, fn ($mail): bool => $mail->hasTo($email));
        }
    }

    public function test_customer_cannot_select_another_orders_items_or_view_them_with_wrong_email(): void
    {
        Mail::fake();
        [$order, $item] = $this->orderItem();
        [, $otherItem] = $this->orderItem();
        $wrong = $this->post('/forma-za-povrat-i-reklamacije', $this->submission($order, ['email' => 'different@example.test', 'items' => 'Opis zahtjeva']))->assertOk();
        $wrong->assertDontSee('line_quantities['.$item->id.']', false);
        $review = $this->post('/forma-za-povrat-i-reklamacije', $this->submission($order))->assertOk();
        preg_match('/name="draft_token" value="([^"]+)"/', $review->getContent(), $matches);
        $this->post('/forma-za-povrat-i-reklamacije/confirm', [
            'draft_token' => $matches[1], 'structured_items' => 1, 'line_quantities' => [$otherItem->id => 1],
        ])->assertUnprocessable()->assertSee('Odabrane stavke ne pripadaju narudžbi.')->assertSee($item->name);
        $this->assertDatabaseCount('contract_withdrawals', 0);
    }

    public function test_approval_is_assigned_and_does_not_change_sold_quantities_then_receipt_is_idempotent(): void
    {
        [$order, $item, $product] = $this->orderItem();
        $withdrawal = $this->withdrawal($order);
        $workflow = app(ContractWithdrawalWorkflow::class);
        $workflow->attachItems($withdrawal, [$item->id => 3]);
        $admin = $this->admin('operator@example.test');
        $klara = $this->admin('klara@herrera.hr');
        app(SystemSettingsService::class)->put('store_withdrawal_approver_email', 'klara@herrera.hr');
        $this->actingAs($admin)->patch('/admin/withdrawals/'.$withdrawal->id, ['action' => 'approve'])->assertForbidden();
        $this->actingAs($admin)->patch('/admin/withdrawals/'.$withdrawal->id, ['status' => 'completed'])->assertSessionHasErrors('workflow');
        $this->actingAs($klara)->patch('/admin/withdrawals/'.$withdrawal->id, ['action' => 'approve'])->assertRedirect();
        $stats = app(CustomerPurchaseStatistics::class);
        $this->assertSame(5, $stats->summary([])['items_sold']);
        $this->assertSame(5, (int) $stats->products([])->first()->quantity);
        $line = $withdrawal->lineItems()->sole();
        $receipt = [
            'action' => 'receive', 'physical_receipt_confirmed' => 1, 'inspected_sellable' => 1,
            'receipts' => [$line->id => ['received' => 2, 'restocked' => 1]],
            'internal_note' => 'Dva komada fizički primljena, jedan ispravan.',
        ];
        $this->actingAs($admin)->patch('/admin/withdrawals/'.$withdrawal->id, $receipt)->assertRedirect();
        $this->actingAs($admin)->patch('/admin/withdrawals/'.$withdrawal->id, $receipt)->assertRedirect();
        $this->assertSame(3, $stats->summary([])['items_sold']);
        $this->assertSame(3, (int) $stats->products([])->first()->quantity);
        $this->assertSame(3, (int) $stats->customers([])->first()->items_sold);
        $this->assertSame(100.0, $stats->summary([])['order_value']);
        $this->assertSame('100.00', $order->fresh()->grand_total);
        $this->assertSame(5, $item->fresh()->quantity);
        $this->assertSame(11, $product->fresh()->stock_qty);
        $this->assertSame(20, $product->fresh()->supplier_stock_qty);
        $this->assertDatabaseCount('contract_withdrawal_receipts', 1);
        $this->assertSame(2, (int) app(ReceivedReturnQuantities::class)->query()->first()->returned_quantity);
        $this->assertSame('partially_received', $withdrawal->fresh()->status);
        $this->actingAs($admin)->get('/admin/withdrawals/'.$withdrawal->id)->assertOk()->assertSee('Povijest primitaka')->assertSee('Dva komada fizički primljena');
    }

    public function test_claim_receipt_reduces_sold_quantity_without_automatic_restock_or_external_credit(): void
    {
        [$order, $item, $product] = $this->orderItem();
        $withdrawal = $this->withdrawal($order, ['request_type' => 'claim']);
        $workflow = app(ContractWithdrawalWorkflow::class);
        $workflow->attachItems($withdrawal, [$item->id => 2]);
        $actor = $this->admin('klara@herrera.hr');
        app(SystemSettingsService::class)->put('store_withdrawal_approver_email', $actor->email);
        $workflow->approve($withdrawal, $actor);
        $line = $withdrawal->lineItems()->sole();
        $workflow->receive($withdrawal, $actor, [$line->id => ['received' => 2, 'restocked' => 0]], false);
        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock_qty);
        $this->assertSame('100.00', $order->fresh()->grand_total);
        $this->assertDatabaseCount('order_transactions', 0);
        $this->assertSame(3, app(CustomerPurchaseStatistics::class)->summary([])['items_sold']);
    }

    public function test_textual_request_decisions_require_the_assigned_approver_without_changing_inventory_or_sales(): void
    {
        [$order, $item, $product] = $this->orderItem();
        $operator = $this->admin('warehouse@example.test');
        $klara = $this->admin('klara@herrera.hr');
        $superadmin = User::factory()->create();
        Bouncer::assign('superadmin')->to($superadmin);
        app(SystemSettingsService::class)->put('store_withdrawal_approver_email', $klara->email);

        foreach ([ContractWithdrawal::STATUS_DECLINED, ContractWithdrawal::STATUS_COMPLETED] as $decision) {
            $withdrawal = $this->withdrawal($order);
            $this->actingAs($operator)->patch('/admin/withdrawals/'.$withdrawal->id, ['status' => $decision])->assertForbidden();
            $this->assertSame(ContractWithdrawal::STATUS_RECEIVED, $withdrawal->fresh()->status);
            $this->assertNull($withdrawal->fresh()->completed_at);
            $this->actingAs($operator)->patch('/admin/withdrawals/'.$withdrawal->id, ['status' => ContractWithdrawal::STATUS_PROCESSING])->assertRedirect();
            $this->assertSame(ContractWithdrawal::STATUS_PROCESSING, $withdrawal->fresh()->status);
            $this->actingAs($klara)->patch('/admin/withdrawals/'.$withdrawal->id, ['status' => $decision])->assertRedirect();
            $this->assertSame($decision, $withdrawal->fresh()->status);
            $this->assertSame($klara->id, $withdrawal->fresh()->handled_by);
            $this->actingAs($operator)->patchJson('/admin/withdrawals/'.$withdrawal->id, ['status' => ContractWithdrawal::STATUS_PROCESSING])
                ->assertUnprocessable()->assertJsonValidationErrors('workflow');
            $this->assertSame($decision, $withdrawal->fresh()->status);

            $override = $this->withdrawal($order);
            $this->actingAs($superadmin)->patch('/admin/withdrawals/'.$override->id, ['status' => $decision])->assertRedirect();
            $this->assertSame($decision, $override->fresh()->status);
        }

        $structured = $this->withdrawal($order);
        app(ContractWithdrawalWorkflow::class)->attachItems($structured, [$item->id => 1]);
        $this->actingAs($operator)->patch('/admin/withdrawals/'.$structured->id, ['status' => ContractWithdrawal::STATUS_DECLINED])->assertForbidden();
        $this->actingAs($klara)->patch('/admin/withdrawals/'.$structured->id, ['status' => ContractWithdrawal::STATUS_DECLINED])->assertRedirect();
        $this->assertSame(10, $product->fresh()->stock_qty);
        $this->assertSame(20, $product->fresh()->supplier_stock_qty);
        $this->assertSame(5, $item->fresh()->quantity);
        $summary = app(CustomerPurchaseStatistics::class)->summary([]);
        $this->assertSame(5, $summary['items_sold']);
        $this->assertSame(100.0, $summary['order_value']);
        $this->assertDatabaseCount('contract_withdrawal_receipts', 0);
    }

    public function test_explicit_inspection_is_required_before_restock_and_decreasing_receipts_is_rejected(): void
    {
        [$order, $item, $product] = $this->orderItem();
        $withdrawal = $this->withdrawal($order);
        $workflow = app(ContractWithdrawalWorkflow::class);
        $workflow->attachItems($withdrawal, [$item->id => 2]);
        $actor = $this->admin('klara@herrera.hr');
        app(SystemSettingsService::class)->put('store_withdrawal_approver_email', $actor->email);
        $workflow->approve($withdrawal, $actor);
        $line = $withdrawal->lineItems()->sole();
        $this->actingAs($actor)->patch('/admin/withdrawals/'.$withdrawal->id, [
            'action' => 'receive', 'physical_receipt_confirmed' => 1,
            'receipts' => [$line->id => ['received' => 1, 'restocked' => 1]],
        ])->assertSessionHasErrors('inspected_sellable');
        $this->assertSame(0, $line->fresh()->received_quantity);
        $this->assertSame(10, $product->fresh()->stock_qty);
        $workflow->receive($withdrawal, $actor, [$line->id => ['received' => 1, 'restocked' => 0]], false);
        $this->actingAs($actor)->patch('/admin/withdrawals/'.$withdrawal->id, [
            'action' => 'receive', 'physical_receipt_confirmed' => 1,
            'receipts' => [$line->id => ['received' => 0, 'restocked' => 0]],
        ])->assertSessionHasErrors('receipts.'.$line->id);
        $this->assertSame(1, $line->fresh()->received_quantity);
        $this->assertDatabaseCount('contract_withdrawal_receipts', 1);
    }

    public function test_receipt_requires_approval_and_later_inspection_can_restock_without_counting_a_second_return(): void
    {
        [$order, $item, $product] = $this->orderItem();
        $withdrawal = $this->withdrawal($order);
        $workflow = app(ContractWithdrawalWorkflow::class);
        $workflow->attachItems($withdrawal, [$item->id => 2]);
        $actor = $this->admin('klara@herrera.hr');
        app(SystemSettingsService::class)->put('store_withdrawal_approver_email', $actor->email);
        $line = $withdrawal->lineItems()->sole();
        $targets = [$line->id => ['received' => 2, 'restocked' => 0]];
        $this->actingAs($actor)->patch('/admin/withdrawals/'.$withdrawal->id, [
            'action' => 'receive', 'physical_receipt_confirmed' => 1, 'receipts' => $targets,
        ])->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('contract_withdrawal_receipts', 0);
        $workflow->approve($withdrawal, $actor);
        $workflow->receive($withdrawal, $actor, $targets, false);
        $targets[$line->id]['restocked'] = 2;
        $workflow->receive($withdrawal, $actor, $targets, true, 'Naknadno pregledano i ispravno.');
        $workflow->receive($withdrawal, $actor, $targets, true);
        $this->assertSame(2, $line->fresh()->received_quantity);
        $this->assertSame(2, $line->fresh()->restocked_quantity);
        $this->assertSame(12, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('contract_withdrawal_receipts', 2);
        $this->assertSame(2, (int) $line->receipts()->sum('quantity'));
        $this->assertSame(3, app(CustomerPurchaseStatistics::class)->summary([])['items_sold']);
    }

    public function test_requests_cannot_reserve_more_than_the_original_sale(): void
    {
        [$order, $item] = $this->orderItem();
        $workflow = app(ContractWithdrawalWorkflow::class);
        $first = $this->withdrawal($order);
        $workflow->attachItems($first, [$item->id => 3]);
        $second = $this->withdrawal($order);
        try {
            $workflow->attachItems($second, [$item->id => 3]);
            $this->fail('The second request must not reserve more than remains.');
        } catch (ValidationException) {
            $this->assertSame(0, $second->lineItems()->count());
        }
        $workflow->attachItems($second, [$item->id => 2]);
        $this->assertSame(5, $first->lineItems()->sum('quantity') + $second->lineItems()->sum('quantity'));
        $actor = $this->admin('klara@herrera.hr');
        app(SystemSettingsService::class)->put('store_withdrawal_approver_email', $actor->email);
        $workflow->updateTracking($first, $actor, 'declined', 'Zahtjev je odbijen.');
        $third = $this->withdrawal($order);
        $workflow->attachItems($third, [$item->id => 3]);
        $this->assertSame(0, $workflow->availableQuantities($order)[$item->id]);
        $this->assertSame('Izvorni opis kupca', $first->fresh()->items);
    }

    public function test_received_return_blocks_order_cancellation_and_duplicate_cancellation_stock_restoration(): void
    {
        [$order, $item, $product] = $this->orderItem();
        $cancelled = OrderStatus::query()->create(['code' => 'cancelled-return-test', 'name' => 'Otkazano', 'is_cancelled' => true]);
        $withdrawal = $this->withdrawal($order);
        $workflow = app(ContractWithdrawalWorkflow::class);
        $workflow->attachItems($withdrawal, [$item->id => 2]);
        $actor = $this->admin('klara@herrera.hr');
        app(SystemSettingsService::class)->put('store_withdrawal_approver_email', $actor->email);
        $workflow->approve($withdrawal, $actor);
        $line = $withdrawal->lineItems()->sole();
        $workflow->receive($withdrawal, $actor, [$line->id => ['received' => 2, 'restocked' => 2]], true);
        foreach (['status', 'stock'] as $attempt) {
            try {
                DB::transaction(function () use ($attempt, $order, $cancelled, $item, $product): void {
                    // Ensure all preceding writes roll back with the rejected cancellation.
                    $product->increment('stock_qty', 99);
                    if ($attempt === 'status') {
                        $order->forceFill(['status_id' => $cancelled->id])->save();
                    } else {
                        app(OrderStockAllocationService::class)->restore($item);
                    }
                });
                $this->fail('A received return must prevent cancellation/restoration.');
            } catch (ValidationException) {
                $this->assertNull($order->fresh()->status_id);
                $this->assertSame(12, $product->fresh()->stock_qty);
                $this->assertSame(20, $product->fresh()->supplier_stock_qty);
                $this->assertNull(data_get($item->fresh()->payload, 'inventory.restored_at'));
            }
        }
        $this->assertSame(2, $line->fresh()->received_quantity);
        $this->assertDatabaseCount('contract_withdrawal_receipts', 1);
    }

    /** @return array{Order, OrderItem, Product} */
    private function orderItem(): array
    {
        $sku = 'RETURN-'.Str::random(8);
        $product = Product::query()->create(['sku' => $sku, 'code' => $sku, 'base_price' => 20, 'stock_qty' => 10, 'supplier_stock_qty' => 20, 'is_active' => true]);
        $order = Order::query()->create([
            'order_number' => 'RET-'.Str::random(8), 'customer_name' => 'Ana Horvat',
            'customer_email' => 'buyer@example.test', 'currency_code' => 'EUR',
            'item_qty' => 5, 'subtotal' => 80, 'tax_total' => 20, 'grand_total' => 100,
            'placed_at' => now(),
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id, 'sku' => $product->sku, 'name' => 'Povratni artikl '.$product->id,
            'quantity' => 5, 'unit_price' => 16, 'line_total' => 80,
        ]);

        return [$order, $item, $product];
    }

    private function withdrawal(Order $order, array $attributes = []): ContractWithdrawal
    {
        return ContractWithdrawal::query()->create(array_merge([
            'reference' => 'JR-'.Str::random(16), 'submission_key' => hash('sha256', Str::random(40)),
            'order_id' => $order->id, 'order_number' => $order->order_number,
            'full_name' => 'Ana Horvat', 'email' => 'buyer@example.test',
            'address_line' => 'Ilica 1', 'postal_code' => '10000', 'city' => 'Zagreb', 'country_code' => 'HR',
            'items' => 'Izvorni opis kupca', 'declaration' => 'Izjava kupca',
            'request_snapshot' => ['original' => true], 'snapshot_hash' => hash('sha256', 'original'),
            'status' => 'received', 'locale' => 'hr', 'submitted_at' => now(),
        ], $attributes));
    }

    private function admin(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function submission(Order $order, array $attributes = []): array
    {
        return array_merge([
            'full_name' => 'Ana Horvat', 'email' => 'buyer@example.test', 'address_line' => 'Ilica 1',
            'postal_code' => '10000', 'city' => 'Zagreb', 'country_code' => 'HR',
            'order_number' => $order->order_number, 'items' => '',
        ], $attributes);
    }
}
