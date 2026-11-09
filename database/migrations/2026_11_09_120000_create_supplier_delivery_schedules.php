<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_delivery_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('supplier', 80)->unique();
            $table->string('name');
            $table->json('aliases')->nullable();
            $table->json('monthly_days')->nullable();
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->date('next_order_date')->nullable();
            $table->unsignedSmallInteger('lead_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        foreach (config('supplier-delivery.schedules', []) as $key => $schedule) {
            DB::table('supplier_delivery_schedules')->insert([
                'supplier' => $key, 'name' => $schedule[0], 'monthly_days' => json_encode($schedule[1]),
                'lead_days' => $schedule[2], 'aliases' => json_encode($schedule[3]), 'weekday' => $schedule[4] ?? null,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_delivery_schedules');
    }
};
