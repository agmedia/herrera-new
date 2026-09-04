<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Product\Product;
use App\Models\Import\CatalogImportRun;
use App\Models\Import\CatalogSourceMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use JsonException;
use RuntimeException;
use Tests\TestCase;

class ImportNormalizedCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_invalid_json_top_level_shape_and_source_mismatch_fail_without_writes(): void
    {
        $cases = [
            [$this->writeFile('{invalid'), []],
            [$this->writeFile([]), []],
            [$this->writeFile($this->catalogPayload()), ['--source' => 'other-source']],
        ];

        foreach ($cases as [$file, $options]) {
            $exitCode = Artisan::call('catalog:import-normalized', array_merge(
                ['file' => $file],
                $options,
            ));

            $this->assertSame(1, $exitCode);
            $this->assertDatabaseCount('catalog_import_runs', 0);
            $this->assertDatabaseCount('catalog_source_mappings', 0);
            $this->assertDatabaseCount('products', 0);
        }
    }

    public function test_default_command_is_a_discovered_read_only_import_dry_run(): void
    {
        $exitCode = Artisan::call('catalog:import-normalized', [
            'file' => $this->writeFile($this->catalogPayload()),
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('IMPORT DRY RUN completed.', $output);
        $this->assertStringContainsString('Dry run only. No database records were written.', $output);
        $this->assertDatabaseCount('catalog_import_runs', 0);
        $this->assertDatabaseCount('catalog_source_mappings', 0);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_adoption_dry_run_finds_an_exact_local_product_without_writing(): void
    {
        $product = $this->createLocalProduct();

        $exitCode = Artisan::call('catalog:import-normalized', [
            'file' => $this->writeFile($this->catalogPayload()),
            '--adopt' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('ADOPTION DRY RUN completed.', $output);
        $this->assertStringContainsString('adopt', $output);
        $this->assertDatabaseCount('catalog_source_mappings', 0);
        $this->assertDatabaseCount('catalog_import_runs', 0);
        $this->assertSame('50.00', $product->refresh()->base_price);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_adoption_apply_writes_only_one_idempotent_mapping(): void
    {
        $product = $this->createLocalProduct();
        $file = $this->writeFile($this->catalogPayload());

        $firstExitCode = Artisan::call('catalog:import-normalized', [
            'file' => $file,
            '--adopt' => true,
            '--apply' => true,
        ]);

        $this->assertSame(0, $firstExitCode);
        $this->assertStringContainsString('Only source mappings were written.', Artisan::output());
        $this->assertDatabaseHas('catalog_source_mappings', [
            'source' => 'konto',
            'entity_type' => CatalogSourceMapping::ENTITY_PRODUCT,
            'source_id' => 'konto-product-1',
            'local_id' => $product->id,
            'last_import_run_id' => null,
        ]);
        $this->assertDatabaseCount('catalog_source_mappings', 1);
        $this->assertDatabaseCount('catalog_import_runs', 0);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame('50.00', $product->refresh()->base_price);
        $this->assertFalse($product->translations()->exists());

        $secondExitCode = Artisan::call('catalog:import-normalized', [
            'file' => $file,
            '--adopt' => true,
            '--apply' => true,
        ]);

        $this->assertSame(0, $secondExitCode);
        $this->assertDatabaseCount('catalog_source_mappings', 1);
        $this->assertDatabaseCount('catalog_import_runs', 0);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame($product->id, CatalogSourceMapping::query()->sole()->local_id);
    }

    public function test_import_apply_after_adoption_keeps_the_local_id_and_completes_a_run(): void
    {
        $product = $this->createLocalProduct();
        $file = $this->writeFile($this->catalogPayload());

        $this->assertSame(0, Artisan::call('catalog:import-normalized', [
            'file' => $file,
            '--adopt' => true,
            '--apply' => true,
        ]));

        $exitCode = Artisan::call('catalog:import-normalized', [
            'file' => $file,
            '--apply' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('IMPORT APPLY completed.', Artisan::output());
        $this->assertDatabaseCount('products', 1);
        $this->assertSame($product->id, Product::query()->sole()->id);
        $this->assertSame('125.40', $product->refresh()->base_price);
        $this->assertSame('Imported product', $product->translations()->sole()->name);
        $this->assertDatabaseCount('catalog_import_runs', 1);

        $run = CatalogImportRun::query()->sole();
        $mapping = CatalogSourceMapping::query()->sole();

        $this->assertSame(CatalogImportRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame($run->id, $mapping->last_import_run_id);
        $this->assertSame($product->id, $mapping->local_id);
    }

    public function test_import_apply_refuses_an_unmanaged_collision_without_writes(): void
    {
        $product = $this->createLocalProduct();

        $exitCode = Artisan::call('catalog:import-normalized', [
            'file' => $this->writeFile($this->catalogPayload()),
            '--apply' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Import plan contains conflicts. Nothing was written.', Artisan::output());
        $this->assertDatabaseCount('catalog_import_runs', 0);
        $this->assertDatabaseCount('catalog_source_mappings', 0);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame('50.00', $product->refresh()->base_price);
        $this->assertFalse($product->translations()->exists());
    }

    public function test_json_output_is_machine_readable_and_contains_the_plan(): void
    {
        $exitCode = Artisan::call('catalog:import-normalized', [
            'file' => $this->writeFile($this->catalogPayload()),
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ok']);
        $this->assertSame('import', $result['mode']);
        $this->assertSame('dry-run', $result['execution']);
        $this->assertFalse($result['applied']);
        $this->assertSame('konto', $result['source']);
        $this->assertSame(1, $result['summary']['create']);
        $this->assertSame('konto-product-1', $result['operations'][0]['source_id']);
        $this->assertNull($result['import_run']);
        $this->assertDatabaseCount('catalog_import_runs', 0);
        $this->assertDatabaseCount('catalog_source_mappings', 0);
        $this->assertDatabaseCount('products', 0);
    }

    private function createLocalProduct(): Product
    {
        return Product::query()->create([
            'code' => 'P-001',
            'sku' => 'SKU-001',
            'barcode' => '385000000001',
            'is_active' => true,
            'base_price' => 50,
            'stock_qty' => 2,
        ]);
    }

    /** @return array<string, mixed> */
    private function catalogPayload(): array
    {
        return [
            'schema_version' => 1,
            'source' => 'konto',
            'products' => [[
                'source_id' => 'konto-product-1',
                'status' => 'w',
                'code' => 'P-001',
                'sku' => 'SKU-001',
                'barcode' => '385000000001',
                'base_price' => '125.40',
                'stock_qty' => 7,
                'translations' => [[
                    'locale' => 'hr',
                    'name' => 'Imported product',
                    'slug' => 'imported-product',
                ]],
            ]],
        ];
    }

    /** @param array<string, mixed>|string $contents */
    private function writeFile(array|string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'termol-catalog-command-');
        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary catalog file.');
        }

        if (is_array($contents)) {
            try {
                $contents = json_encode($contents, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                unlink($path);

                throw $exception;
            }
        }

        if (file_put_contents($path, $contents) === false) {
            unlink($path);

            throw new RuntimeException('Unable to write a temporary catalog file.');
        }

        $this->temporaryFiles[] = $path;

        return $path;
    }
}
