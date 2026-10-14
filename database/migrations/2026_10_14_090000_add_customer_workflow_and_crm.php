<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('county', 120)->nullable()->index();
            $table->string('customer_type', 120)->nullable()->index();
        });
        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->foreignId('assigned_sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contacted_at')->nullable();
            $table->text('sales_comment')->nullable();
            $table->json('proposed_terms')->nullable();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proposed_at')->nullable();
        });
        Schema::create('customer_sales_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->index();
            $table->index(['customer_user_id', 'started_at'], 'customer_sales_session_customer_date');
            $table->index(['sales_rep_id', 'started_at'], 'customer_sales_session_rep_date');
            $table->timestamp('ended_at')->nullable();
            $table->string('outcome', 20)->default('open')->index();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('customer_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('visited_at')->index();
            $table->index(['customer_user_id', 'visited_at'], 'customer_visit_customer_date');
            $table->index(['sales_rep_id', 'visited_at'], 'customer_visit_rep_date');
            $table->string('contact_type', 20)->default('visit');
            $table->string('outcome', 20)->index();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('quote_reference', 191)->nullable();
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_visits');
        Schema::dropIfExists('customer_sales_sessions');
        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_sales_rep_id');
            $table->dropConstrainedForeignId('proposed_by');
            $table->dropColumn(['contacted_at', 'sales_comment', 'proposed_terms', 'proposed_at']);
        });
        Schema::table('user_profiles', fn (Blueprint $table) => $table->dropColumn(['county', 'customer_type']));
    }
};
