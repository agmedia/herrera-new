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
        Schema::create('sales_opportunity_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20)->index();
            $table->date('coverage_from');
            $table->date('as_of');
            $table->unsignedInteger('document_count')->default(0);
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('unmapped_lines')->default(0);
            $table->unsignedInteger('anonymous_lines')->default(0);
            $table->string('message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sales_opportunity_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('sales_opportunity_snapshots')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('buyer_key', 64);
            $table->string('tax_key', 64)->nullable();
            $table->string('document_key', 64)->nullable();
            $table->string('code_key', 64)->nullable();
            $table->boolean('known_buyer');
            $table->decimal('quantity_90', 20, 4)->default(0);
            $table->decimal('quantity_365', 20, 4)->default(0);
            $table->unique(['snapshot_id', 'product_id', 'buyer_key'], 'opportunity_purchase_identity');
            $table->index(['snapshot_id', 'tax_key'], 'opportunity_buyer_tax');
            $table->index(['snapshot_id', 'document_key'], 'opportunity_buyer_document');
            $table->index(['snapshot_id', 'code_key'], 'opportunity_buyer_code');
        });
        Schema::create('sales_opportunity_buyer_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('sales_opportunity_snapshots')->cascadeOnDelete();
            $table->string('alias_key', 64);
            $table->string('buyer_key', 64);
            $table->unique(['snapshot_id', 'alias_key'], 'opportunity_buyer_alias');
        });
        $ability = Ability::query()->firstOrCreate(['name' => 'sales.opportunities.view', 'entity_id' => null, 'entity_type' => null], ['title' => 'Prodajne prilike za komercijalu', 'options' => ['group' => 'sales.opportunities']]);
        foreach (['admin', 'order_manager'] as $name) {
            $role = Role::query()->firstOrCreate(['name' => $name]);
            Bouncer::allow($role)->to($ability);
            Bouncer::refresh($role);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_opportunity_buyer_aliases');
        Schema::dropIfExists('sales_opportunity_purchases');
        Schema::dropIfExists('sales_opportunity_snapshots');
    }
};
