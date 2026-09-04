<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('scope', 32)->default('catalog')->after('id')->index();
        });

        Schema::table('category_translations', function (Blueprint $table): void {
            $table->string('scope', 32)->default('catalog')->after('category_id')->index();
        });

        $this->syncTranslationScopes();

        $this->dropUniqueIfExists('category_translations', 'category_locale_slug_unique');
        $this->dropUniqueIfExists('categories', 'categories_code_unique');

        Schema::table('categories', function (Blueprint $table): void {
            $table->unique(['scope', 'code'], 'categories_scope_code_unique');
        });

        Schema::table('category_translations', function (Blueprint $table): void {
            $table->unique(['scope', 'locale', 'slug'], 'category_scope_locale_slug_unique');
        });
    }

    public function down(): void
    {
        $this->dropUniqueIfExists('category_translations', 'category_scope_locale_slug_unique');
        $this->dropUniqueIfExists('categories', 'categories_scope_code_unique');

        Schema::table('categories', function (Blueprint $table): void {
            $table->unique('code', 'categories_code_unique');
        });

        Schema::table('category_translations', function (Blueprint $table): void {
            $table->unique(['locale', 'slug'], 'category_locale_slug_unique');
        });

        $this->dropIndexIfExists('category_translations', 'category_translations_scope_index');
        $this->dropIndexIfExists('categories', 'categories_scope_index');

        Schema::table('category_translations', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }

    private function syncTranslationScopes(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('UPDATE category_translations ct INNER JOIN categories c ON c.id = ct.category_id SET ct.scope = c.scope');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('UPDATE category_translations ct SET scope = c.scope FROM categories c WHERE c.id = ct.category_id');
            return;
        }

        DB::statement('UPDATE category_translations SET scope = (SELECT scope FROM categories WHERE categories.id = category_translations.category_id)');
    }

    private function dropUniqueIfExists(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $table) use ($indexName): void {
                $table->dropUnique($indexName);
            });
        } catch (QueryException) {
            // noop
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        } catch (QueryException) {
            // noop
        }
    }
};
