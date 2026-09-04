<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->storeEnabledState(false);
        $this->storeSettingIfMissing('loyalty_currency_value_per_point', 0.01);
        $this->storeSettingIfMissing('loyalty_customer_group_ids', []);
        $this->replaceLegacyNewsletterLabel();

        Cache::forget('settings.system.map');
    }

    public function down(): void
    {
        $this->storeEnabledState(true);

        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->whereIn('key', [
                    'loyalty_currency_value_per_point',
                    'loyalty_customer_group_ids',
                ])
                ->delete();
        }

        Cache::forget('settings.system.map');
    }

    private function storeEnabledState(bool $enabled): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $now = now();
        $query = DB::table('system_settings')->where('key', 'user_loyalty_enabled');

        if ($query->exists()) {
            $query->update([
                'value' => json_encode($enabled),
                'updated_at' => $now,
            ]);
        } else {
            DB::table('system_settings')->insert([
                'key' => 'user_loyalty_enabled',
                'value' => json_encode($enabled),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function storeSettingIfMissing(string $key, mixed $value): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        if (DB::table('system_settings')->where('key', $key)->exists()) {
            return;
        }

        $now = now();

        DB::table('system_settings')->insert([
            'key' => $key,
            'value' => json_encode($value),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function replaceLegacyNewsletterLabel(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $query = DB::table('system_settings')->where('key', 'store_newsletter_club_label');
        $raw = $query->value('value');

        if (! is_string($raw) || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        $label = json_last_error() === JSON_ERROR_NONE && is_string($decoded) ? $decoded : $raw;

        if (strcasecmp(trim($label), 'BALI LOYALTY') !== 0) {
            return;
        }

        $query->update([
            'value' => json_encode('BALI NEWSLETTER'),
            'updated_at' => now(),
        ]);
    }
};
