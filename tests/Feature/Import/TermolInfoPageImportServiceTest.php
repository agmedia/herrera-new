<?php

namespace Tests\Feature\Import;

use App\Models\Content\Page\InfoPage;
use App\Services\Import\TermolInfoPageImportService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TermolInfoPageImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_editable_pages_and_configures_the_footer_from_admin_settings(): void
    {
        $sourceClasses = [
            '/o-nama.aspx' => ['HR_VK_TERMOL_bnm869_ABOUTUS', 'O nama'],
            '/nacini-placanja.aspx' => ['HR_VK_TERMOL_bnm869_PAYMENT_METHODS', 'Načini plaćanja'],
            '/nacini-dostave.aspx' => ['HR_VK_TERMOL_bnm869_SHIPPING_METHODS', 'Načini dostave'],
            '/uvjeti-koristenja.aspx' => ['HR_VK_TERMOL_bnm869_TERMS_CONDITIONS', 'Uvjeti korištenja'],
            '/privatnost-podataka.aspx' => ['HR_VK_TERMOL_bnm869_PRIVACY_INFORMATION', 'Privatnost podataka'],
            '/WebContent.aspx' => ['HR_VK_TERMOL_bnm869_POVRATI_REKLAMACIJE', 'Povrati i reklamacije'],
        ];

        Http::fake(function (Request $request) use ($sourceClasses) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            [$sourceClass, $title] = $sourceClasses[$path];

            return Http::response(
                '<html><body><div class="'.$sourceClass.'">'
                .'<h2 style="color:red">'.$title.'</h2>'
                .'<table style="width:100%"><tbody><tr><td>'
                .'<p style="font-size:16px">Preneseni sadržaj za '.$title.'.</p>'
                .'<div><span><strong>Važna informacija</strong></span></div>'
                .'<img src="placeholder.jpg"><script>alert(1)</script>'
                .'</td></tr></tbody></table>'
                .'</div></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        });

        $stats = app(TermolInfoPageImportService::class)->import();

        $this->assertSame(6, $stats['pages_imported']);
        $this->assertSame(6, InfoPage::query()->where('show_in_footer', true)->count());

        $paymentPage = InfoPage::query()
            ->where('code', 'payment-methods')
            ->with('translations')
            ->firstOrFail();
        $body = (string) $paymentPage->translations->firstWhere('locale', 'hr')?->body_html;

        $this->assertStringContainsString('Preneseni sadržaj za Načini plaćanja.', $body);
        $this->assertStringContainsString('<strong>Važna informacija</strong>', $body);
        $this->assertStringNotContainsString('<table', $body);
        $this->assertStringNotContainsString('<span', $body);
        $this->assertStringNotContainsString('<img', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('style=', $body);

        $settings = app(SystemSettingsService::class);
        $this->assertSame('+385 91 600 1958', $settings->get('store_footer_phone'));
        $this->assertSame('webshop@termol.hr', $settings->get('store_footer_email_sales'));
        $this->assertSame('info@termol.hr', $settings->get('store_footer_email_support'));
        $this->assertSame([$paymentPage->id], [
            $settings->get('store_footer_col_2_page_ids')[0],
        ]);
        $this->assertSame('Kontakt i podrška', $settings->get('store_footer_contact_title'));
        $this->assertSame('Lapovačka 11A', $settings->get('store_schema_address_street'));

        Http::assertSentCount(6);
        Http::assertSent(static fn (Request $request): bool => str_contains(
            (string) $request->header('User-Agent')[0],
            'Googlebot'
        ));
    }
}
