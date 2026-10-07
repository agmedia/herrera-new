<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Ability;
use Silber\Bouncer\Database\Role;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('supplier', 32);
            $table->string('trigger', 16);
            $table->string('status', 20)->default('running');
            $table->unsignedBigInteger('actor_id')->nullable();
            foreach (['fetched_count', 'matched_count', 'updated_count', 'unchanged_count', 'unmatched_count', 'invalid_count'] as $field) {
                $table->unsignedInteger($field)->default(0);
            }
            $table->text('error_message')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['supplier', 'trigger', 'status', 'id'], 'stock_sync_last_run');
        });
        Schema::create('stock_sync_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('stock_sync_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('identifier', 160);
            $table->string('status', 20);
            $table->integer('old_quantity')->nullable();
            $table->integer('new_quantity')->nullable();
            $table->string('message')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'status']);
        });
        $ability = Ability::query()->firstOrCreate(['name' => 'integrations.stock.manage', 'entity_id' => null, 'entity_type' => null], [
            'title' => 'Upravljanje zalihama i cronovima', 'options' => ['group' => 'integrations.stock'],
        ]);
        $role = Role::query()->firstOrCreate(['name' => 'admin'], ['title' => 'Administrator']);
        Bouncer::allow($role)->to($ability);
        Bouncer::refresh($role);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_sync_items');
        Schema::dropIfExists('stock_sync_runs');
    }
};
