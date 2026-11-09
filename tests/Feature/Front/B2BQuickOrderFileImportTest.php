<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class B2BQuickOrderFileImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true]);
    }

    public function test_csv_preview_preserves_ean_and_customer_prices_and_merges_pack_quantities_without_ordering(): void
    {
        [$buyer, $group] = $this->buyer();
        $product = $this->product(['barcode' => '0000123456789', 'minimum_order_quantity' => 2, 'order_quantity_step' => 2]);
        ProductGroupPrice::query()->create(['product_id' => $product->id, 'customer_group_id' => $group->id, 'price' => 7.5, 'minimum_quantity' => 1, 'currency_code' => 'EUR', 'is_active' => true]);
        $file = UploadedFile::fake()->createWithContent('narudzba.csv', "\xEF\xBB\xBFšifra/EAN;količina\n0000123456789;3\nTEST-SKU;2\nMISSING;4\nTEST;0\n");

        $response = $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), ['order_file' => $file])
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonCount(2, 'errors')
            ->assertJsonPath('items.0.product_id', $product->id)->assertJsonPath('items.0.quantity', 6)
            ->assertJsonPath('items.0.is_b2b_price', true)->assertJsonPath('items.0.unit_price', 7.5)
            ->assertJsonPath('items.0.maximum_quantity', 998)
            ->assertJsonPath('errors.0.line', 4)->assertJsonPath('errors.0.identifier', 'MISSING')
            ->assertJsonPath('errors.0.quantity', '4')->assertJsonPath('errors.1.line', 5);
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertEmpty(app(CartService::class)->lines());
        $this->assertEmpty($buyer->b2bAccount->fresh()->quick_order_draft);
    }

    public function test_xlsx_reads_first_sheet_and_preserves_text_and_zero_padded_numeric_identifiers(): void
    {
        [$buyer] = $this->buyer();
        $product = $this->product(['barcode' => '0000000000123']);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['EAN', 'količina'], [123, 2]]);
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('0000000000000');
        $sheet->setCellValueExplicit('A3', 'TEST-SKU', DataType::TYPE_STRING);
        $sheet->setCellValue('B3', 3);
        $book->createSheet()->fromArray([['MISSING', 99]]);
        $book->setActiveSheetIndex(1);

        $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), ['order_file' => $this->xlsx($book)])
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonCount(0, 'errors')
            ->assertJsonPath('items.0.product_id', $product->id)->assertJsonPath('items.0.quantity', 5);
    }

    public function test_formulas_are_rejected_without_using_cached_values(): void
    {
        [$buyer] = $this->buyer();
        $this->product();
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['SKU', 'quantity'], ['TEST', '=1+1']]);
        $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), ['order_file' => $this->xlsx($book)])
            ->assertUnprocessable()->assertJsonValidationErrors('order_file');
        $this->assertEmpty(app(CartService::class)->lines());
    }

    public function test_import_is_private_and_rejects_more_than_100_articles_and_oversized_files(): void
    {
        $file = fn () => UploadedFile::fake()->createWithContent('narudzba.csv', "TEST;1\n");
        $this->postJson(route('account.b2b.quick-order.resolve'), ['order_file' => $file()])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson(route('account.b2b.quick-order.resolve'), ['order_file' => $file()])->assertForbidden();
        [$buyer] = $this->buyer();
        $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), [
            'order_file' => UploadedFile::fake()->createWithContent('too-many.csv', "SKU;quantity\n".str_repeat("TEST;1\n", 101)),
        ])->assertUnprocessable()->assertJsonValidationErrors('order_file');
        $this->postJson(route('account.b2b.quick-order.resolve'), [
            'order_file' => UploadedFile::fake()->create('large.csv', 2049, 'text/csv'),
        ])->assertUnprocessable()->assertJsonValidationErrors('order_file');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_xlsx_rejects_extra_columns_and_external_links(): void
    {
        [$buyer] = $this->buyer();
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['TEST', 2, 'unsupported']]);
        $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), ['order_file' => $this->xlsx($book)])
            ->assertUnprocessable()->assertJsonValidationErrors('order_file');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['TEST', 2]]);
        $file = $this->xlsx($book);
        $archive = new ZipArchive;
        $archive->open($file->getRealPath());
        $archive->addFromString('xl/externalLinks/externalLink1.xml', '<externalLink/>');
        $archive->close();
        $this->postJson(route('account.b2b.quick-order.resolve'), ['order_file' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('order_file');
    }

    public function test_exact_100_rows_are_accepted_and_ui_offers_template_and_upload(): void
    {
        [$buyer] = $this->buyer();
        $this->product(['stock_qty' => 200]);
        $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), [
            'order_file' => UploadedFile::fake()->createWithContent('hundred.csv', str_repeat("TEST,1\n", 100)),
        ])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.quantity', 100);
        $this->get(route('account.b2b.quick-order'))->assertOk()
            ->assertSee('data-quick-order-file-import', false)
            ->assertSee('front-theme/downloads/quick-order-template.csv', false);
    }

    public function test_file_and_pasted_lines_cannot_be_processed_together(): void
    {
        [$buyer] = $this->buyer();
        $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), [
            'order_file' => UploadedFile::fake()->createWithContent('narudzba.csv', 'TEST;2'), 'lines' => 'TEST;3',
        ])->assertUnprocessable()->assertJsonValidationErrors(['order_file', 'lines']);
    }

    public function test_unused_rich_shared_strings_are_rejected_cleanly_under_the_server_memory_limit(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['TEST', 2]]);
        $file = $this->xlsx($book);
        $stringsPath = tempnam(sys_get_temp_dir(), 'quick-order-strings-');
        $scriptPath = tempnam(sys_get_temp_dir(), 'quick-order-reader-');
        try {
            $stream = fopen($stringsPath, 'w');
            fwrite($stream, '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="300001" uniqueCount="300001"><si><t>TEST</t></si>');
            $chunk = str_repeat('<si><r><t>x</t></r></si>', 1000);
            for ($index = 0; $index < 300; $index++) {
                fwrite($stream, $chunk);
            }
            fwrite($stream, '</sst>');
            fclose($stream);
            $archive = new ZipArchive;
            $archive->open($file->getRealPath());
            $archive->addFile($stringsPath, 'xl/sharedStrings.xml');
            $archive->close();
            $this->assertLessThan(2 * 1024 * 1024, filesize($file->getRealPath()));
            $this->assertLessThan(10 * 1024 * 1024, filesize($stringsPath));

            $script = '<?php require '.var_export(base_path('vendor/autoload.php'), true).';'
                .'$app = require '.var_export(base_path('bootstrap/app.php'), true).';'
                .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
                .'try {$file = new Illuminate\\Http\\UploadedFile($argv[1], "order.xlsx", null, null, true);'
                .'(new App\\Services\\Front\\B2BQuickOrderFileReader)->read($file); exit(2);'
                .'} catch (Illuminate\\Validation\\ValidationException $e) {'
                .'echo json_encode(["errors" => $e->errors(), "peak_bytes" => memory_get_peak_usage(true)]); }';
            file_put_contents($scriptPath, $script);
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', $scriptPath, $file->getRealPath()], base_path(), ['APP_ENV' => 'testing']);
            $process->setTimeout(15)->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('order_file', $result['errors']);
            $this->assertStringContainsString('neiskorištenih podataka', $result['errors']['order_file'][0]);
            $this->assertLessThan(100 * 1024 * 1024, $result['peak_bytes']);
        } finally {
            @unlink($stringsPath);
            @unlink($scriptPath);
        }
    }

    public function test_excessive_unused_styles_and_xml_entities_are_rejected_before_loading_the_workbook(): void
    {
        [$buyer] = $this->buyer();
        foreach (['styles' => '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts>'.str_repeat('<font><name val="Arial"/></font>', 1025).'</fonts></styleSheet>',
            'entities' => '<?xml version="1.0"?><!DOCTYPE root [<!ENTITY source SYSTEM "https://example.invalid/data">]><root>&source;</root>'] as $kind => $xml) {
            $book = new Spreadsheet;
            $book->getActiveSheet()->fromArray([['TEST', 2]]);
            $file = $this->xlsx($book);
            $archive = new ZipArchive;
            $archive->open($file->getRealPath());
            $archive->addFromString($kind === 'styles' ? 'xl/styles.xml' : 'xl/unused.xml', $xml);
            $archive->close();
            $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), ['order_file' => $file])
                ->assertUnprocessable()->assertJsonValidationErrors('order_file');
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_styled_100_article_workbook_remains_supported(): void
    {
        [$buyer] = $this->buyer();
        $this->product(['barcode' => '0000000000123', 'stock_qty' => 200]);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['EAN', 'količina']]);
        for ($row = 2; $row <= 101; $row++) {
            $sheet->setCellValue('A'.$row, 123);
            $sheet->setCellValue('B'.$row, 1);
            $sheet->getStyle('A'.$row)->getNumberFormat()->setFormatCode('0000000000000');
            $sheet->getStyle('A'.$row.':B'.$row)->getFont()->setBold($row % 2 === 0);
        }
        $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), ['order_file' => $this->xlsx($book)])
            ->assertOk()->assertJsonCount(0, 'errors')->assertJsonCount(1, 'items')->assertJsonPath('items.0.quantity', 100);
    }

    private function buyer(): array
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'file-import', 'name' => 'B2B', 'is_active' => true]);
        $user->customerGroups()->attach($group);
        B2BAccount::query()->create(['user_id' => $user->id, 'status' => B2BAccount::STATUS_APPROVED, 'company_name' => 'Test d.o.o.', 'oib' => '12345678901', 'customer_group_id' => $group->id]);

        return [$user, $group];
    }

    private function product(array $attributes = []): Product
    {
        $product = Product::query()->create(array_replace(['code' => 'TEST', 'sku' => 'TEST-SKU', 'base_price' => 20, 'stock_qty' => 100, 'is_active' => true], $attributes));
        $product->translations()->create(['locale' => 'hr', 'name' => 'Test proizvod', 'slug' => 'test-proizvod']);

        return $product;
    }

    private function xlsx(Spreadsheet $book): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'quick-order-');
        try {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);

            return UploadedFile::fake()->createWithContent('narudzba.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }
}
