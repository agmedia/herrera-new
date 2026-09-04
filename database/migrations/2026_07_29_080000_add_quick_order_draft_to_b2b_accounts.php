<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('b2b_accounts', 'quick_order_draft')) {
            return;
        }

        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->json('quick_order_draft')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('b2b_accounts', 'quick_order_draft')) {
            return;
        }

        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->dropColumn('quick_order_draft');
        });
    }
};
