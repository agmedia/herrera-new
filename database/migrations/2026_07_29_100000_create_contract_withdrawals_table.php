<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contract_withdrawals')) {
            return;
        }

        Schema::create('contract_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->string('submission_key', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('order_number', 80)->index();
            $table->string('full_name', 191);
            $table->string('email', 191)->index();
            $table->string('phone', 80)->nullable();
            $table->string('address_line', 255);
            $table->string('postal_code', 32);
            $table->string('city', 120);
            $table->char('country_code', 2)->default('HR');
            $table->date('contract_date')->nullable();
            $table->date('received_date')->nullable();
            $table->text('items');
            $table->text('note')->nullable();
            $table->text('declaration');
            $table->json('request_snapshot');
            $table->string('snapshot_hash', 64);
            $table->string('status', 32)->default('received')->index();
            $table->text('internal_note')->nullable();
            $table->string('locale', 12)->default('hr');
            $table->timestamp('submitted_at')->index();
            $table->timestamp('consumer_notified_at')->nullable();
            $table->timestamp('admin_notified_at')->nullable();
            $table->text('notification_error')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_withdrawals');
    }
};
