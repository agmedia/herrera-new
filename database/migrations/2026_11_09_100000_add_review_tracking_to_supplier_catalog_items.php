<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table): void {
            $table->decimal('manual_source_price', 16, 4)->nullable();
            $table->string('manual_currency', 3)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_action', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['manual_source_price', 'manual_currency', 'reviewed_at', 'review_action']);
        });
    }
};
