<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_legacy_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('legacy_hash')->nullable();
            $table->text('legacy_salt')->nullable();
            $table->string('password_fingerprint', 64);
            $table->boolean('enabled')->default(false);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_legacy_credentials');
    }
};
