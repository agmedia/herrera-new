<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('languages')->updateOrInsert(
            ['code' => 'de'],
            [
                'locale' => 'de_DE',
                'name' => 'German',
                'native_name' => 'Deutsch',
                'direction' => 'ltr',
                'is_default' => false,
                'is_active' => false,
                'sort_order' => 3,
                'settings' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('languages')
            ->where('code', 'de')
            ->where('is_default', false)
            ->where('is_active', false)
            ->delete();
    }
};
