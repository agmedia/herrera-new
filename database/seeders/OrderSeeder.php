<?php

namespace Database\Seeders;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $defaultStatus = OrderStatus::query()
            ->where('is_default', true)
            ->first() ?? OrderStatus::query()->orderBy('sort_order')->first();

        if (! $defaultStatus) {
            return;
        }

        $paidStatus = OrderStatus::query()
            ->where('is_paid', true)
            ->orderBy('sort_order')
            ->first() ?? $defaultStatus;

        $sentStatus = OrderStatus::query()
            ->where('code', 'sent')
            ->orWhere('code', 'shipped')
            ->orWhere('code', 'completed')
            ->orderBy('sort_order')
            ->first() ?? $paidStatus;

        $user = User::query()->orderBy('id')->first();
        $products = Product::query()->with('translations')->orderBy('id')->take(4)->get();

        $this->seedOrder(
            orderNumber: 'AG-2026-0001',
            status: $defaultStatus,
            user: $user,
            products: $products->take(2)->all(),
            placedAt: now()->subHours(4),
            shippingTotal: 4.50,
            paymentFeeTotal: 0.00,
            discountTotal: 0.00,
            taxTotal: 12.50,
            comment: 'Order created from demo seed.'
        );

        $this->seedOrder(
            orderNumber: 'AG-2026-0002',
            status: $paidStatus,
            user: $user,
            products: $products->slice(1, 2)->all(),
            placedAt: now()->subDays(1),
            shippingTotal: 0.00,
            paymentFeeTotal: 0.00,
            discountTotal: 2.00,
            taxTotal: 8.20,
            comment: 'Paid via manual transfer.',
            withTransaction: true
        );

        $this->seedOrder(
            orderNumber: 'AG-2026-0003',
            status: $sentStatus,
            user: $user,
            products: $products->take(1)->all(),
            placedAt: now()->subDays(3),
            shippingTotal: 5.00,
            paymentFeeTotal: 0.00,
            discountTotal: 0.00,
            taxTotal: 4.10,
            comment: 'Packed and sent to courier.',
            withTransaction: true
        );
    }

    /**
     * @param  array<int, Product>  $products
     */
    private function seedOrder(
        string $orderNumber,
        OrderStatus $status,
        ?User $user,
        array $products,
        \DateTimeInterface $placedAt,
        float $shippingTotal,
        float $paymentFeeTotal,
        float $discountTotal,
        float $taxTotal,
        string $comment,
        bool $withTransaction = false,
    ): void {
        if (Order::query()->where('order_number', $orderNumber)->exists()) {
            return;
        }

        $itemRows = collect($products)
            ->values()
            ->map(function (Product $product, int $index): array {
                $tr = $product->translations->first();
                $qty = $index === 0 ? 2 : 1;
                $unit = (float) $product->base_price;

                return [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'code' => $product->code,
                    'name' => $tr?->name ?? $product->code,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $unit * $qty,
                    'sort_order' => $index,
                ];
            })
            ->all();

        if ($itemRows === []) {
            $itemRows[] = [
                'product_id' => null,
                'sku' => 'DEMO-SKU',
                'code' => 'demo-product',
                'name' => 'Demo Product',
                'quantity' => 1,
                'unit_price' => 19.99,
                'line_total' => 19.99,
                'sort_order' => 0,
            ];
        }

        $subtotal = (float) collect($itemRows)->sum(fn (array $row): float => (float) $row['line_total']);
        $grandTotal = $subtotal + $shippingTotal + $paymentFeeTotal + $taxTotal - $discountTotal;

        $order = Order::query()->create([
            'order_number' => $orderNumber,
            'status_id' => $status->id,
            'user_id' => $user?->id,
            'source' => 'web',
            'locale' => 'hr',
            'currency_code' => 'EUR',
            'currency_rate' => 1,
            'customer_name' => $user?->name ?? 'Demo Customer',
            'customer_email' => $user?->email ?? 'demo.customer@example.test',
            'customer_phone' => '+38591000111',
            'billing_first_name' => 'Demo',
            'billing_last_name' => 'Customer',
            'billing_address_line_1' => 'Billing Street 1',
            'billing_postal_code' => '10000',
            'billing_city' => 'Zagreb',
            'billing_country_code' => 'HR',
            'shipping_first_name' => 'Demo',
            'shipping_last_name' => 'Customer',
            'shipping_address_line_1' => 'Shipping Street 1',
            'shipping_postal_code' => '10000',
            'shipping_city' => 'Zagreb',
            'shipping_country_code' => 'HR',
            'payment_method_code' => 'bank',
            'payment_method_name' => 'Bank Transfer',
            'shipping_method_code' => 'standard',
            'shipping_method_name' => 'Standard Shipping',
            'item_qty' => (int) collect($itemRows)->sum(fn (array $row): int => (int) $row['quantity']),
            'subtotal' => $subtotal,
            'shipping_total' => $shippingTotal,
            'payment_fee_total' => $paymentFeeTotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
            'customer_note' => null,
            'admin_note' => $comment,
            'payload' => ['seeded' => true],
            'placed_at' => $placedAt,
            'paid_at' => $status->is_paid ? now()->subHours(2) : null,
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);

        foreach ($itemRows as $row) {
            $order->items()->create([
                'product_id' => $row['product_id'],
                'sku' => $row['sku'],
                'code' => $row['code'],
                'name' => $row['name'],
                'unit_price' => $row['unit_price'],
                'discount_amount' => 0,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'quantity' => $row['quantity'],
                'line_total' => $row['line_total'],
                'sort_order' => $row['sort_order'],
                'payload' => null,
            ]);
        }

        $order->totals()->createMany([
            ['code' => 'subtotal', 'title' => 'Subtotal', 'value' => $subtotal, 'sort_order' => 1],
            ['code' => 'shipping', 'title' => 'Shipping', 'value' => $shippingTotal, 'sort_order' => 2],
            ['code' => 'payment_fee', 'title' => 'Payment Fee', 'value' => $paymentFeeTotal, 'sort_order' => 3],
            ['code' => 'discount', 'title' => 'Discount', 'value' => -abs($discountTotal), 'sort_order' => 4],
            ['code' => 'tax', 'title' => 'Tax', 'value' => $taxTotal, 'sort_order' => 5],
            ['code' => 'total', 'title' => 'Total', 'value' => $grandTotal, 'sort_order' => 6],
        ]);

        $order->history()->create([
            'from_status_id' => null,
            'to_status_id' => $status->id,
            'changed_by' => $user?->id,
            'comment' => $comment,
            'payload' => ['seeded' => true],
        ]);

        if ($withTransaction) {
            $order->transactions()->create([
                'provider' => 'manual',
                'transaction_ref' => 'TX-'.$order->order_number,
                'status' => 'confirmed',
                'amount' => $grandTotal,
                'currency_code' => 'EUR',
                'processed_at' => now()->subHours(1),
                'payload' => ['seeded' => true],
                'created_by' => $user?->id,
            ]);
        }
    }
}
