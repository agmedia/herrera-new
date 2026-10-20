<?php

namespace Tests\Feature\Integrations;

use App\Models\User;
use App\Services\Integrations\Eracuni\CustomerInvoices;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerInvoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoices_are_matched_by_tax_number_and_currency_and_keep_signed_cancellations(): void
    {
        $buyer = User::factory()->create();
        $buyer->profile()->create(['oib' => '12345678901']);
        $settings = $this->mock(StockSyncSettingsService::class);
        $settings->shouldReceive('configured')->with('eracuni')->andReturn(true);
        $settings->shouldReceive('connection')->with('eracuni')->andReturn(['url' => 'https://erp.example.test/api/ProductList', 'username' => 'test', 'password' => 'secret', 'token' => 'token']);
        $base = ['buyerTaxNumber' => 'HR12345678901', 'documentCurrency' => 'EUR', 'status' => 'IssuedInvoice', 'date' => '2026-01-01'];
        Http::fake(['*' => Http::response(['response' => ['result' => [
            $base + ['documentID' => '1', 'documentAmount' => 125],
            $base + ['documentID' => '2', 'documentAmount' => -25, 'cancelledDocumentRef' => '1'],
            array_merge($base, ['documentID' => '3', 'documentAmount' => 999, 'buyerTaxNumber' => '99999999999']),
            array_merge($base, ['documentID' => '4', 'documentAmount' => 999, 'status' => 'Draft']),
            array_merge($base, ['documentID' => '5', 'documentAmount' => 999, 'documentCurrency' => 'USD']),
        ]]])]);
        $invoices = app(CustomerInvoices::class)->forCustomer($buyer, ['dateFrom' => '2026-01-01', 'dateTo' => '2026-01-31', 'currency' => 'EUR']);
        $this->assertCount(2, $invoices);
        $this->assertEquals(100, $invoices->sum('documentAmount'));
        Http::assertSent(fn ($request) => $request['dateFrom'] === '2026-01-01' && $request->url() === 'https://erp.example.test/api/SalesInvoiceList');
    }

    public function test_customer_without_tax_number_does_not_trigger_a_request(): void
    {
        Http::fake();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OIB');
        try {
            app(CustomerInvoices::class)->forCustomer(User::factory()->create(), []);
        } finally {
            Http::assertNothingSent();
        }
    }
}
