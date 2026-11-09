<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_type', 3)->nullable()->index();
            $table->string('cost_centre', 120)->nullable()->index();
        });

        // Keep the classification recorded at checkout, including customers
        // whose account has since changed from retail to business.
        DB::table('orders')->select('id', 'user_id', 'billing_company', 'payload')
            ->orderBy('id')->chunkById(500, function ($orders): void {
                $businessUsers = DB::table('b2b_accounts')->whereIn('user_id', $orders->pluck('user_id')->filter())
                    ->pluck('user_id')
                    ->merge(DB::table('user_profiles')->whereIn('user_id', $orders->pluck('user_id')->filter())->whereRaw("TRIM(COALESCE(company, '')) <> ''")->pluck('user_id'))
                    ->merge(DB::table('user_addresses')->whereIn('user_id', $orders->pluck('user_id')->filter())->where('type', 'billing')->whereRaw("TRIM(COALESCE(company, '')) <> ''")->pluck('user_id'))
                    ->flip();
                foreach ($orders as $order) {
                    $payload = json_decode((string) $order->payload, true) ?: [];
                    $type = $payload['customer_type'] ?? null;
                    if (! in_array($type, ['B2B', 'B2C'], true)) {
                        $type = trim((string) $order->billing_company) !== '' || $businessUsers->has($order->user_id) ? 'B2B' : 'B2C';
                    }
                    DB::table('orders')->where('id', $order->id)->update([
                        'customer_type' => $type,
                        'cost_centre' => $type === 'B2C' ? 'Web prodaja' : null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['customer_type']);
            $table->dropIndex(['cost_centre']);
            $table->dropColumn(['customer_type', 'cost_centre']);
        });
    }
};
