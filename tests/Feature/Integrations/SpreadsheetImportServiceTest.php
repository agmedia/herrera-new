<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Spreadsheet\SpreadsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class SpreadsheetImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_default_xlsx_preview_keeps_disabled_fields_and_applies_supplier_stock_after_review(): void
    {
        $product = $this->product('NATIVE', ['model' => '00123', 'sku' => 'LEGACY-SKU', 'ean' => '0123456789012', 'product_id' => 77]);
        $file = $this->workbook([['Model', 'Supplier stock'], ['00123', '8']]);
        $before = $product->fresh()->only(['base_price', 'stock_qty', 'supplier_stock_qty', 'payload']);
        $service = app(SpreadsheetImportService::class);

        $run = $service->preview($file, []);

        $this->assertSame('preview', $run->status);
        $this->assertSame(1, $run->updated_count);
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
        $item = $run->items()->sole();
        $this->assertSame('00123', $item->identifier);
        $this->assertSame(['supplier_stock_qty' => 8], $item->new_values);
        $this->assertSame('ready', $item->status);

        $applied = $service->apply($run->id);

        $this->assertSame('completed', $applied->status);
        $this->assertSame('import', $applied->kind);
        $this->assertSame(8, $product->fresh()->supplier_stock_qty);
        $this->assertSame($before['stock_qty'], $product->fresh()->stock_qty);
        $this->assertSame($before['base_price'], $product->fresh()->base_price);
        $this->assertSame('applied', $item->fresh()->status);
    }

    public function test_csv_preserves_leading_zero_ean_and_normalizes_comma_price_markup_without_changing_stock(): void
    {
        $product = $this->product('CSV-PRODUCT', ['ean' => '0012345678901']);
        $historyCount = $product->priceHistory()->count();
        $file = $this->file("EAN;Price;Own stock;Supplier stock\n0012345678901;12,50;invalid;invalid\n");
        $options = ['identifier_type' => 'ean', 'update_prices' => true, 'markup_percentage' => 20, 'update_supplier_stock' => false];
        $service = app(SpreadsheetImportService::class);

        $run = $service->preview($file, $options);
        $this->assertSame(['base_price' => '15.0000'], $run->items()->sole()->new_values);
        $this->assertSame(0, $run->invalid_count);
        $applied = $service->apply($run->id);

        $this->assertSame('completed', $applied->status);
        $this->assertSame('15.0000', $product->fresh()->base_price);
        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertSame(4, $product->fresh()->supplier_stock_qty);
        $this->assertSame($historyCount + 1, $product->priceHistory()->count());
        Http::assertNothingSent();
    }

    public function test_default_model_mapping_also_finds_new_erp_and_ideus_drafts(): void
    {
        $erp = Product::query()->create(['code' => 'eracuni-hash', 'sku' => '00001', 'base_price' => 1, 'stock_qty' => 0, 'supplier_stock_qty' => 0, 'payload' => ['eracuni' => ['productCode' => '00001']]]);
        $csv = Product::query()->create(['code' => 'ideus-hash', 'sku' => '00002', 'base_price' => 1, 'stock_qty' => 0, 'supplier_stock_qty' => 0, 'payload' => ['ideus_csv' => ['model' => '00002']]]);
        $service = app(SpreadsheetImportService::class);

        $run = $service->preview($this->file("Model;Qty\n00001;3\n00002;4\n"), []);

        $this->assertSame(2, $run->updated_count);
        $this->assertSame(0, $run->unmatched_count);
        $this->assertSame('completed', $service->apply($run->id)->status);
        $this->assertSame(3, $erp->fresh()->supplier_stock_qty);
        $this->assertSame(4, $csv->fresh()->supplier_stock_qty);
    }

    public function test_price_and_both_stock_columns_can_be_reviewed_and_applied_together(): void
    {
        $product = $this->product('ALL-FIELDS', ['sku' => 'SOURCE-SKU']);
        $file = $this->workbook([['SKU', 'Price', 'Own stock', 'Supplier stock'], ['SOURCE-SKU', '12,3456', 5, 6]]);
        $service = app(SpreadsheetImportService::class);
        $run = $service->preview($file, [
            'identifier_type' => 'sku', 'update_prices' => true, 'markup_percentage' => 10,
            'update_stock' => true, 'stock_column' => 3, 'supplier_stock_column' => 4,
        ]);

        $this->assertSame(['base_price' => '13.5802', 'stock_qty' => 5, 'supplier_stock_qty' => 6], $run->items()->sole()->new_values);
        $this->assertSame('completed', $service->apply($run->id)->status);
        $this->assertSame('13.5802', $product->fresh()->base_price);
        $this->assertSame(5, $product->fresh()->stock_qty);
        $this->assertSame(6, $product->fresh()->supplier_stock_qty);
    }

    public function test_only_the_active_sheet_is_read_and_xls_content_is_supported(): void
    {
        $product = $this->product('ACTIVE', ['model' => 'ACTIVE-MODEL']);
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Model', 'Qty'], ['WRONG', 99]]);
        $book->createSheet()->setTitle('Aktivni')->fromArray([['Model', 'Qty'], ['ACTIVE-MODEL', 11]]);
        $book->setActiveSheetIndex(1);
        $file = $this->saveBook($book, 'Xls');

        $run = app(SpreadsheetImportService::class)->preview($file, []);

        $this->assertSame('Aktivni', $run->summary['sheet_name']);
        $this->assertSame($product->id, $run->items()->sole()->product_id);
        $this->assertSame(['supplier_stock_qty' => 11], $run->items()->sole()->new_values);
    }

    public function test_legacy_and_current_product_ids_are_explicitly_distinct(): void
    {
        $product = $this->product('ID-PRODUCT', ['product_id' => 77]);
        $file = $this->workbook([['Id', 'Qty'], ['77', 9]]);
        $service = app(SpreadsheetImportService::class);

        $old = $service->preview($file, ['identifier_type' => 'legacy_product_id']);
        $current = $service->preview($file, ['identifier_type' => 'product_id']);

        $this->assertSame($product->id, $old->items()->sole()->product_id);
        $this->assertSame('ready', $old->items()->sole()->status);
        $this->assertSame('unmatched', $current->items()->sole()->status);
    }

    public function test_ambiguous_legacy_identity_and_conflicting_duplicates_block_the_whole_import(): void
    {
        $this->product('FIRST', ['model' => 'AMBIGUOUS']);
        $this->product('SECOND', ['model' => 'AMBIGUOUS']);
        $unique = $this->product('UNIQUE', ['model' => 'UNIQUE-MODEL']);
        $file = $this->workbook([['Model', 'Qty'], ['AMBIGUOUS', 2], ['UNIQUE-MODEL', 8], ['UNIQUE-MODEL', 9]]);
        $service = app(SpreadsheetImportService::class);
        $run = $service->preview($file, []);

        $this->assertSame(3, $run->conflict_count);
        $this->assertSame(0, $run->updated_count);
        $this->expectException(RuntimeException::class);
        try {
            $service->apply($run->id);
        } finally {
            $this->assertSame(4, $unique->fresh()->supplier_stock_qty);
        }
    }

    public function test_equal_duplicate_rows_are_reported_and_applied_once(): void
    {
        $product = $this->product('DUPLICATE', ['model' => 'SAME']);
        $file = $this->workbook([['Model', 'Qty'], ['SAME', 8], ['SAME', 8]]);
        $service = app(SpreadsheetImportService::class);
        $run = $service->preview($file, []);

        $this->assertSame(1, $run->summary['duplicate_count']);
        $this->assertSame(1, $run->updated_count);
        $applied = $service->apply($run->id);

        $this->assertSame('completed', $applied->status);
        $this->assertSame(1, $applied->updated_count);
        $this->assertSame(8, $product->fresh()->supplier_stock_qty);
    }

    public function test_stale_product_values_roll_back_every_row_and_record_a_failed_import(): void
    {
        $first = $this->product('FIRST', ['model' => 'FIRST-MODEL']);
        $second = $this->product('SECOND', ['model' => 'SECOND-MODEL']);
        $file = $this->workbook([['Model', 'Qty'], ['FIRST-MODEL', 8], ['SECOND-MODEL', 9]]);
        $service = app(SpreadsheetImportService::class);
        $run = $service->preview($file, []);
        $second->update(['supplier_stock_qty' => 99]);

        $result = $service->apply($run->id);

        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $result->updated_count);
        $this->assertStringContainsString('promijenjeni', $result->error_message);
        $this->assertSame(4, $first->fresh()->supplier_stock_qty);
        $this->assertSame(99, $second->fresh()->supplier_stock_qty);
        $this->assertSame(0, $result->items()->where('status', 'applied')->count());
    }

    public function test_identifier_changes_after_preview_cannot_update_the_wrong_product(): void
    {
        $product = $this->product('CHANGED', ['model' => 'OLD']);
        $file = $this->workbook([['Model', 'Qty'], ['OLD', 8]]);
        $service = app(SpreadsheetImportService::class);
        $run = $service->preview($file, []);
        $product->update(['payload' => ['opencart' => ['model' => 'NEW']]]);

        $result = $service->apply($run->id);

        $this->assertSame('failed', $result->status);
        $this->assertSame(4, $product->fresh()->supplier_stock_qty);
    }

    public function test_unknown_products_are_reported_while_valid_reviewed_rows_can_apply(): void
    {
        $product = $this->product('MATCHED', ['model' => 'KNOWN']);
        $file = $this->workbook([['Model', 'Qty'], ['UNKNOWN', 7], ['KNOWN', 8]]);
        $service = app(SpreadsheetImportService::class);
        $run = $service->preview($file, []);

        $this->assertSame(1, $run->unmatched_count);
        $this->assertSame(1, $run->updated_count);
        $this->assertSame('completed', $service->apply($run->id)->status);
        $this->assertSame(8, $product->fresh()->supplier_stock_qty);
    }

    public function test_formula_without_saved_result_is_invalid_and_never_calculated(): void
    {
        $this->product('FORMULA', ['model' => 'FORMULA-MODEL']);
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Model', 'Qty'], ['FORMULA-MODEL', '=2+3']]);
        $book->getActiveSheet()->getCell('B2')->setCalculatedValue(null);
        $file = $this->saveBook($book);

        $run = app(SpreadsheetImportService::class)->preview($file, []);

        $this->assertSame(1, $run->invalid_count);
        $this->assertStringContainsString('spremljeni rezultat', $run->items()->sole()->message);
    }

    public function test_cached_formula_is_used_and_external_formula_is_invalid_even_with_cache(): void
    {
        $this->product('FORMULA', ['model' => 'FORMULA-MODEL']);
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Model', 'Qty'], ['FORMULA-MODEL', '=2+3']]);
        $book->getActiveSheet()->getCell('B2')->setCalculatedValue(5);
        $file = $this->saveBook($book);
        $this->cachedFormula($file, 'B2', 5);
        $service = app(SpreadsheetImportService::class);

        $run = $service->preview($file, []);
        $this->assertSame(['supplier_stock_qty' => 5], $run->items()->sole()->new_values);

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Model', 'Qty'], ['FORMULA-MODEL', '=WEBSERVICE("https://external.invalid/stock")']]);
        $book->getActiveSheet()->getCell('B2')->setCalculatedValue(5);
        $external = $this->saveBook($book);
        $this->cachedFormula($external, 'B2', 5);
        $run = $service->preview($external, []);
        $this->assertSame(1, $run->invalid_count);
        Http::assertNothingSent();
    }

    public function test_blank_enabled_stock_is_zero_but_blank_enabled_price_is_invalid(): void
    {
        $product = $this->product('BLANK', ['model' => 'BLANK-MODEL']);
        $file = $this->workbook([['Model', 'Value'], ['BLANK-MODEL', '']]);
        $service = app(SpreadsheetImportService::class);
        $stock = $service->preview($file, []);
        $price = $service->preview($file, ['update_prices' => true, 'update_supplier_stock' => false]);

        $this->assertSame(['supplier_stock_qty' => 0], $stock->items()->sole()->new_values);
        $this->assertSame(1, $price->invalid_count);
        $this->assertSame('10.1234', $product->fresh()->base_price);
    }

    public function test_disabled_module_and_active_stock_job_lock_stop_import_without_writes(): void
    {
        $product = $this->product('LOCKED', ['model' => 'LOCKED-MODEL']);
        $file = $this->workbook([['Model', 'Value'], ['LOCKED-MODEL', 8]]);
        $service = app(SpreadsheetImportService::class);
        try {
            $service->preview($file, ['enabled' => false]);
            $this->fail('Disabled module should not preview.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('isključen', $exception->getMessage());
        }
        $run = $service->preview($file, []);
        $lock = Cache::lock('herrera-stock-sync:supplier_stock_qty', 600);
        $this->assertTrue($lock->get());
        try {
            $service->apply($run->id);
            $this->fail('An active stock job should block the spreadsheet import.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('već u tijeku', $exception->getMessage());
        } finally {
            $lock->release();
        }
        $this->assertSame('preview', $run->fresh()->status);
        $this->assertSame(4, $product->fresh()->supplier_stock_qty);
    }

    public function test_rows_beyond_limit_are_rejected_even_with_a_gap_after_valid_rows(): void
    {
        $this->product('LIMIT', ['model' => 'LIMIT-MODEL']);
        $file = $this->file("Model;Qty\nLIMIT-MODEL;8\n".str_repeat(";\n", 10000)."LIMIT-MODEL;9\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('10.000 redaka');
        app(SpreadsheetImportService::class)->preview($file, []);
    }

    public function test_source_zero_number_mask_is_preserved_for_identifier_matching(): void
    {
        $product = $this->product('PADDED', ['model' => '000123']);
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Model', 'Qty'], [123, 8]]);
        $book->getActiveSheet()->getStyle('A2')->getNumberFormat()->setFormatCode('000000');

        $run = app(SpreadsheetImportService::class)->preview($this->saveBook($book), []);

        $this->assertSame('000123', $run->items()->sole()->identifier);
        $this->assertSame($product->id, $run->items()->sole()->product_id);
    }

    public function test_macro_or_external_relationship_archive_is_rejected_before_workbook_loading(): void
    {
        $file = $this->workbook([['Model', 'Qty'], ['ANY', 8]]);
        $archive = new ZipArchive;
        $archive->open($file);
        $archive->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<Relationships><Relationship TargetMode="External" Target="https://external.invalid/image"/></Relationships>');
        $archive->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vanjske veze');
        app(SpreadsheetImportService::class)->preview($file, []);
    }

    public function test_oversized_file_is_rejected_before_parsing(): void
    {
        $file = $this->file('');
        $handle = fopen($file, 'w');
        ftruncate($handle, 10 * 1024 * 1024 + 1);
        fclose($handle);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('10 MB');
        app(SpreadsheetImportService::class)->preview($file, []);
    }

    public function test_an_already_applied_run_cannot_be_applied_again(): void
    {
        $this->product('ONCE', ['model' => 'ONCE-MODEL']);
        $service = app(SpreadsheetImportService::class);
        $run = $service->preview($this->workbook([['Model', 'Qty'], ['ONCE-MODEL', 8]]), []);
        $service->apply($run->id);

        $this->expectException(RuntimeException::class);
        try {
            $service->apply($run->id);
        } finally {
            $this->assertSame('completed', $run->fresh()->status);
        }
    }

    private function product(string $code, array $legacy = []): Product
    {
        return Product::query()->create([
            'code' => $code, 'sku' => $code.'-SKU', 'base_price' => '10.1234', 'stock_qty' => 3,
            'supplier_stock_qty' => 4, 'is_active' => true, 'payload' => ['opencart' => $legacy],
        ]);
    }

    private function workbook(array $rows): string
    {
        $book = new Spreadsheet;
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                $book->getActiveSheet()->getCell([$column + 1, $index + 1])->setValueExplicit($value, is_string($value) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
            }
        }

        return $this->saveBook($book);
    }

    private function saveBook(Spreadsheet $book, string $format = 'Xlsx'): string
    {
        $file = $this->file('');
        $writer = IOFactory::createWriter($book, $format);
        $writer->setPreCalculateFormulas(false);
        $writer->save($file);
        $book->disconnectWorksheets();

        return $file;
    }

    private function file(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'spreadsheet-import-test-');
        file_put_contents($file, $contents);
        $this->files[] = $file;

        return $file;
    }

    private function cachedFormula(string $file, string $cell, int $value): void
    {
        // Current writers deliberately omit caches when formula calculation is
        // disabled. Add a saved result as Excel does, without evaluating it.
        $archive = new ZipArchive;
        $archive->open($file);
        $xml = $archive->getFromName('xl/worksheets/sheet1.xml');
        $xml = preg_replace_callback('/(<c\b[^>]*\br="'.preg_quote($cell, '/').'"[^>]*>)(.*?)(<\/c>)/s', function ($matches) use ($value) {
            return $matches[1].preg_replace('/<v(?:\s[^>]*)?>.*?<\/v>/s', '', $matches[2]).'<v>'.$value.'</v>'.$matches[3];
        }, $xml);
        $archive->addFromString('xl/worksheets/sheet1.xml', $xml);
        $archive->close();
    }
}
