<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_customer_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['admin_user_id', 'customer_user_id'], 'admin_customer_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_customer_assignments');
    }
};
