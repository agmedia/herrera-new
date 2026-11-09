<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesOpportunityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_command_reports_progress_without_buyer_data_and_finishes_the_snapshot(): void
    {
        Http::preventStrayRequests();
        $settings = $this->mock(StockSyncSettingsService::class);
        $settings->shouldReceive('configured')->with('eracuni')->andReturn(true);
        $settings->shouldReceive('connection')->with('eracuni')->andReturn([
            'url' => 'https://erp.example.test/api/ProductList', 'username' => 'test', 'password' => 'test', 'token' => 'test',
        ]);
        Product::query()->create(['code' => 'ERP-ONE', 'base_price' => 10, 'is_active' => true]);
        $documents = [];
        for ($index = 0; $index < 250; $index++) {
            $documents[] = ['documentID' => 'sale-'.$index, 'date' => today()->toDateString(), 'status' => 'IssuedInvoice',
                'buyerTaxNumber' => 'HR12345678901', 'buyerName' => 'Private Buyer', 'buyerDocumentID' => 'buyer-1',
                'Items' => [['productCode' => 'ERP-ONE', 'quantity' => 1, 'amount' => 10]]];
        }
        Http::fake(fn (Request $request) => Http::response(['response' => ['result' => basename($request->url()) === 'SalesInvoiceList' ? $documents : []]]));

        $this->assertSame(0, Artisan::call('herrera:sync-sales-opportunities'));

        $output = Artisan::output();
        $this->assertStringContainsString('SalesInvoiceList: provjereno 250 dokumenata.', $output);
        $this->assertStringContainsString('Potpuni ERP pregled prodajnih prilika je spremljen.', $output);
        $this->assertStringContainsString('Vrh memorije:', $output);
        $this->assertStringNotContainsString('Private Buyer', $output);
        $this->assertStringNotContainsString('12345678901', $output);
        $this->assertDatabaseHas('sales_opportunity_snapshots', ['status' => 'completed', 'document_count' => 250, 'request_count' => 3]);
        Http::assertSentCount(3);
    }
}
