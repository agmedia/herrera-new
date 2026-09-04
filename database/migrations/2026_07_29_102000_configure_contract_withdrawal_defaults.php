<?php

use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $settings = app(SystemSettingsService::class);
        $settings->putMany([
            'store_withdrawal_admin_email' => $settings->get(
                'store_withdrawal_admin_email',
                $settings->get('store_email_orders_to', 'webshop@termol.hr'),
            ),
            'store_withdrawal_return_address' => $settings->get(
                'store_withdrawal_return_address',
                'TERMOL d.o.o., Lapovačka 11A, 32100 Vinkovci, Hrvatska',
            ),
            'store_withdrawal_instructions' => $settings->get(
                'store_withdrawal_instructions',
                '',
            ),
        ]);

        foreach ([
            'store_footer_col_1_custom_links',
            'store_footer_col_2_custom_links',
            'store_footer_col_3_custom_links',
        ] as $key) {
            $value = $settings->get($key);
            if (! is_string($value)) {
                continue;
            }

            $updated = str_replace([
                'Obrazac za povrat|/forma-za-povrat-i-reklamacije',
                'Povrat i reklamacije|/forma-za-povrat-i-reklamacije',
            ], 'Raskid ugovora|/forma-za-povrat-i-reklamacije', $value);

            if ($updated !== $value) {
                $settings->put($key, $updated);
            }

            $translationsKey = $key.'_translations';
            $translations = $settings->get($translationsKey, []);
            if (! is_array($translations)) {
                continue;
            }

            foreach ($translations as $locale => $translated) {
                if (! is_string($translated)) {
                    continue;
                }

                $translations[$locale] = str_replace([
                    'Obrazac za povrat|/forma-za-povrat-i-reklamacije',
                    'Returns and claims form|/returns-and-claims',
                    'Rücksendungen und Reklamationen|/rucksendungen-und-reklamationen',
                ], [
                    'Raskid ugovora|/forma-za-povrat-i-reklamacije',
                    'Withdraw from contract|/returns-and-claims',
                    'Vertrag widerrufen|/rucksendungen-und-reklamationen',
                ], $translated);
            }

            $settings->put($translationsKey, $translations);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        DB::table('system_settings')
            ->whereIn('key', [
                'store_withdrawal_admin_email',
                'store_withdrawal_return_address',
                'store_withdrawal_instructions',
            ])
            ->delete();

        app(SystemSettingsService::class)->flush();
    }
};
