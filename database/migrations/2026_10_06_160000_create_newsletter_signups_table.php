<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('newsletter_signups')) {
            return;
        }

        Schema::create('newsletter_signups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 191)->unique();
            $table->string('source', 50)->default('footer');
            $table->string('locale', 12)->default('hr');
            $table->string('provider', 20)->default('none')->index();
            $table->string('sync_status', 20)->default('skipped')->index();
            $table->boolean('consent_accepted')->default(false);
            $table->string('provider_reference')->nullable();
            $table->text('provider_error')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('subscribed_at')->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_signups');
    }
};
