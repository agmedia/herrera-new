<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_attribute_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('type', 40)->default('select')->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('catalog_attribute_group_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_group_id')->constrained('catalog_attribute_groups')->cascadeOnDelete();
            $table->string('locale', 12)->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['attribute_group_id', 'locale'],
                'catalog_attribute_group_locale_unique'
            );
        });

        Schema::table('catalog_attributes', function (Blueprint $table): void {
            $table->foreignId('attribute_group_id')
                ->nullable()
                ->after('id')
                ->constrained('catalog_attribute_groups')
                ->nullOnDelete();
        });

        $now = now();
        $groupCodes = DB::table('catalog_attributes')
            ->whereNotNull('group_code')
            ->where('group_code', '<>', '')
            ->distinct()
            ->orderBy('group_code')
            ->pluck('group_code');

        foreach ($groupCodes as $groupCode) {
            $firstAttribute = DB::table('catalog_attributes')
                ->where('group_code', $groupCode)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if (! $firstAttribute) {
                continue;
            }

            $groupId = DB::table('catalog_attribute_groups')->insertGetId([
                'code' => (string) $groupCode,
                'type' => (string) ($firstAttribute->type ?: 'select'),
                'sort_order' => (int) $firstAttribute->sort_order,
                'payload' => null,
                'created_by' => $firstAttribute->created_by,
                'updated_by' => $firstAttribute->updated_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('catalog_attributes')
                ->where('group_code', $groupCode)
                ->update([
                    'attribute_group_id' => $groupId,
                    'type' => (string) ($firstAttribute->type ?: 'select'),
                ]);

            $translations = DB::table('catalog_attribute_translations as translations')
                ->join('catalog_attributes as attributes', 'attributes.id', '=', 'translations.attribute_id')
                ->where('attributes.group_code', $groupCode)
                ->orderBy('attributes.sort_order')
                ->orderBy('attributes.id')
                ->orderBy('translations.id')
                ->get([
                    'translations.locale',
                    'translations.group_name',
                ])
                ->unique('locale');

            foreach ($translations as $translation) {
                DB::table('catalog_attribute_group_translations')->insert([
                    'attribute_group_id' => $groupId,
                    'locale' => (string) $translation->locale,
                    'name' => (string) ($translation->group_name ?: $groupCode),
                    'description' => null,
                    'payload' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('catalog_attributes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('attribute_group_id');
        });

        Schema::dropIfExists('catalog_attribute_group_translations');
        Schema::dropIfExists('catalog_attribute_groups');
    }
};
