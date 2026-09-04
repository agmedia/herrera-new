<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->fixLegalPageLinks();
        $this->fixFooterLinks();
        $this->setCookiePolicyUrl();
        Cache::forget('settings.system.map');
    }

    public function down(): void
    {
        // These are one-way corrections for links that already return 404.
    }

    private function fixLegalPageLinks(): void
    {
        $translations = DB::table('content_info_page_translations')
            ->get(['id', 'body_html']);

        foreach ($translations as $translation) {
            $body = (string) ($translation->body_html ?? '');
            if ($body === '') {
                continue;
            }

            $fixed = str_replace(
                [
                    'https://www.balidoo.hr/pravila-zastite-podataka-i-privatnosti',
                    'http://www.balidoo.hr/pravila-zastite-podataka-i-privatnosti',
                ],
                '/page/pravila-zastite-podataka-i-privatnosti',
                $body
            );

            $fixed = str_replace(
                [
                    'https://www.kozo-underwear.hr/image/catalog/PDF/obrazac raskid ugovora novo.pdf',
                    'https://www.kozo-underwear.hr/image/catalog/PDF/obrazac%20raskid%20ugovora%20novo.pdf',
                    'https://www.bali.hr/image/catalog/PDF/obrazac raskid ugovora novo.pdf',
                    'https://www.bali.hr/image/catalog/PDF/obrazac%20raskid%20ugovora%20novo.pdf',
                    'http://www.balidoo.hr/image/catalog/pdf/Raskid ugovora Balidoo.pdf',
                    'https://www.balidoo.hr/image/catalog/pdf/Raskid ugovora Balidoo.pdf',
                ],
                '/documents/obrazac-za-jednostrani-raskid-ugovora.pdf',
                $fixed
            );

            $fixed = str_replace(
                [
                    'http://www.balidoo.hr/image/catalog/pdf/povrat.pdf',
                    'https://www.balidoo.hr/image/catalog/pdf/povrat.pdf',
                ],
                '/forma-za-povrat-i-reklamacije',
                $fixed
            );

            if ($fixed !== $body) {
                DB::table('content_info_page_translations')
                    ->where('id', $translation->id)
                    ->update(['body_html' => $fixed, 'updated_at' => now()]);
            }
        }
    }

    private function fixFooterLinks(): void
    {
        $base = trim((string) $this->setting('store_footer_col_2_custom_links', ''));
        $base = str_replace('|/kontakt', '|/contact', $base);

        if (! str_contains($base, '/forma-za-povrat-i-reklamacije')) {
            $base = trim($base."\nObrazac za povrat|/forma-za-povrat-i-reklamacije");
        }

        $this->putSetting('store_footer_col_2_custom_links', $base);

        $translations = $this->translationSetting('store_footer_col_2_custom_links');
        $translations['hr'] ??= $base;
        $translations['en'] ??= $this->englishFooterLinks($base);
        $this->putSetting('store_footer_col_2_custom_links_translations', $translations);

        foreach ([
            'store_footer_col_1_title' => ['hr' => 'Shop', 'en' => 'Shop'],
            'store_footer_col_2_title' => ['hr' => 'Pomoć', 'en' => 'Help'],
            'store_footer_col_3_title' => ['hr' => 'Informacije', 'en' => 'Information'],
        ] as $key => $defaults) {
            $titleTranslations = $this->translationSetting($key);
            $baseTitle = trim((string) $this->setting($key, $defaults['hr']));
            $titleTranslations['hr'] ??= $baseTitle !== '' ? $baseTitle : $defaults['hr'];
            $titleTranslations['en'] ??= $defaults['en'];
            $this->putSetting($key.'_translations', $titleTranslations);
        }
    }

    private function setCookiePolicyUrl(): void
    {
        $url = '/page/pravila-zastite-podataka-i-privatnosti';
        $current = trim((string) $this->setting('store_cookie_consent_policy_url', ''));

        if ($current === '') {
            $this->putSetting('store_cookie_consent_policy_url', $url);
        }

        $translations = $this->translationSetting('store_cookie_consent_policy_url');
        $translations['hr'] ??= $url;
        $translations['en'] ??= $url;
        $this->putSetting('store_cookie_consent_policy_url_translations', $translations);
    }

    private function englishFooterLinks(string $links): string
    {
        $translated = [];

        foreach (preg_split('/\r\n|\r|\n/', $links) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$label, $url] = array_pad(explode('|', $line, 2), 2, '');
            $label = match (trim($label)) {
                'Kontakt' => 'Contact',
                'Obrazac za povrat' => 'Returns and claims form',
                default => trim($label),
            };

            $url = match (trim($url)) {
                '/forma-za-povrat-i-reklamacije' => '/returns-and-claims',
                '/kontakt' => '/contact',
                default => trim($url),
            };

            $translated[] = $label.'|'.$url;
        }

        return implode("\n", $translated);
    }

    private function translationSetting(string $key): array
    {
        $value = $this->setting($key.'_translations', []);

        return is_array($value) ? $value : [];
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        $raw = DB::table('system_settings')->where('key', $key)->value('value');
        if ($raw === null || $raw === '') {
            return $default;
        }

        $decoded = json_decode((string) $raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    private function putSetting(string $key, mixed $value): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
};
