<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->decimal('default_discount_percent', 7, 4)->nullable();
        });
        // A percentage in a group name is not an agreement. Only an explicit,
        // unambiguous imported description can establish the initial default.
        DB::table('customer_groups')->orderBy('id')->chunkById(100, function ($groups): void {
            foreach ($groups as $group) {
                $payload = json_decode($group->payload ?? '{}', true);
                $descriptions = array_values(array_unique(array_filter(array_map(
                    fn (array $translation): string => trim(html_entity_decode(strip_tags((string) ($translation['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    (array) ($payload['translations'] ?? []),
                ), fn (string $description): bool => $description !== '')));
                $percents = [];
                foreach ($descriptions as $description) {
                    if (! preg_match('/^Kupci\s+sa\s+(\d{1,3}(?:[.,]\d{1,4})?)\s*%\s+rabata$/iu', $description, $match)
                        || (float) str_replace(',', '.', $match[1]) > 100) {
                        continue 2;
                    }
                    $percents[] = number_format((float) str_replace(',', '.', $match[1]), 4, '.', '');
                }
                $percents = array_unique($percents);
                if (count($percents) === 1) {
                    DB::table('customer_groups')->where('id', $group->id)->update(['default_discount_percent' => reset($percents)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->dropColumn('default_discount_percent');
        });
    }
};
