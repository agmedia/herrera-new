<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('herrera_import_runs')) {
            Schema::create('herrera_import_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('source_snapshot');
                $table->string('status', 24)->default('running');
                $table->json('summary')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('herrera_import_maps')) {
            Schema::create('herrera_import_maps', function (Blueprint $table): void {
                $table->id();
                $table->string('source', 80)->default('herrera-opencart');
                $table->string('entity', 60);
                $table->string('source_id', 160);
                $table->unsignedBigInteger('target_id');
                $table->char('checksum', 64);
                $table->timestamps();
                $table->unique(['source', 'entity', 'source_id'], 'herrera_map_source_unique');
                $table->index(['entity', 'target_id']);
            });
        }
        if (! Schema::hasTable('herrera_import_issues')) {
            Schema::create('herrera_import_issues', function (Blueprint $table): void {
                $table->id();
                $table->string('entity', 60);
                $table->string('source_id', 160);
                $table->string('code', 80);
                $table->json('context')->nullable();
                $table->timestamps();
                $table->unique(['entity', 'source_id', 'code'], 'herrera_issue_source_unique');
            });
        }
        if (! Schema::hasTable('herrera_source_records')) {
            Schema::create('herrera_source_records', function (Blueprint $table): void {
                $table->id();
                $table->string('source_table', 80);
                $table->string('source_key', 160);
                $table->char('checksum', 64);
                $table->json('payload');
                $table->timestamps();
                $table->unique(['source_table', 'source_key'], 'herrera_record_source_unique');
            });
        }
        if (! Schema::hasTable('herrera_legacy_urls')) {
            Schema::create('herrera_legacy_urls', function (Blueprint $table): void {
                $table->id();
                $table->string('locale', 12)->default('hr');
                $table->text('path');
                $table->char('path_hash', 64);
                $table->string('source_query');
                $table->string('status', 24)->default('redirect');
                $table->text('destination')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->unique(['locale', 'path_hash'], 'herrera_legacy_path_unique');
                $table->index('source_query');
            });
        }
        // OpenCart accounts can have more than one delivery address. The old
        // two-address uniqueness would silently lose those addresses.
        if (! Schema::hasIndex('user_addresses', 'user_addresses_user_type_index')) {
            Schema::table('user_addresses', fn (Blueprint $table) => $table->index(['user_id', 'type'], 'user_addresses_user_type_index'));
        }
        if (Schema::hasIndex('user_addresses', 'user_addresses_user_type_unique')) {
            Schema::table('user_addresses', fn (Blueprint $table) => $table->dropUnique('user_addresses_user_type_unique'));
        }
    }

    public function down(): void
    {
        // Do not reintroduce destructive two-address uniqueness on rollback.
        Schema::table('user_addresses', fn (Blueprint $table) => $table->dropIndex('user_addresses_user_type_index'));
        foreach (['herrera_legacy_urls', 'herrera_source_records', 'herrera_import_issues', 'herrera_import_maps', 'herrera_import_runs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
