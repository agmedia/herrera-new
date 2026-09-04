<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_block_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_block_id')
                ->constrained('content_blocks')
                ->cascadeOnDelete();
            $table->string('item_type', 40)->index();
            $table->unsignedBigInteger('item_id')->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();

            $table->unique(
                ['content_block_id', 'item_type', 'item_id'],
                'content_block_items_unique'
            );
            $table->index(
                ['content_block_id', 'item_type', 'sort_order'],
                'content_block_items_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_block_items');
    }
};

