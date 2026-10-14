<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductDocumentsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_technical_documents_are_visible_with_their_labels_and_download_urls(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $document = $product->addMedia(UploadedFile::fake()->createWithContent('technical-sheet.pdf', "%PDF-1.4\nTechnical sheet\n%%EOF"))
            ->usingName('Tehnički list svjetiljke')->withCustomProperties(['caption' => 'Dimenzije i upute za montažu'])
            ->toMediaCollection('product_documents');

        $this->get(route('products.show', ['slug' => 'document-product']))->assertOk()
            ->assertSee('data-product-documents', false)->assertSee('Tehnički list svjetiljke')
            ->assertSee('Dimenzije i upute za montažu')->assertSee('href="'.$document->getUrl().'"', false);
    }

    public function test_missing_document_files_do_not_create_broken_links_or_empty_panels(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $document = $product->addMedia(UploadedFile::fake()->createWithContent('technical-sheet.pdf', "%PDF-1.4\nTechnical sheet\n%%EOF"))
            ->toMediaCollection('product_documents');
        unlink($document->getPath());

        $this->get(route('products.show', ['slug' => 'document-product']))->assertOk()
            ->assertDontSee('data-product-documents', false)->assertDontSee($document->getUrl(), false);
    }

    public function test_supplier_pdf_links_open_directly_without_fetching_or_storing_the_document(): void
    {
        Http::fake();
        $product = $this->product();
        $url = 'https://www.bodex.net.pl/files/manual.pdf';
        $product->update(['payload' => ['supplier_documents' => [
            ['source' => 'vayox', 'name' => 'Upute dobavljača', 'url' => $url],
            ['source' => 'vayox', 'name' => 'Nedopuštena adresa', 'url' => 'https://www.bodex.net.pl@127.0.0.1/private.pdf'],
        ]]]);

        $this->get(route('products.show', ['slug' => 'document-product']))->assertOk()
            ->assertSee('href="'.$url.'"', false)->assertSee('data-product-external-document="vayox"', false)
            ->assertDontSee('Otvara se izravno na stranici dobavljača.')
            ->assertSee('Upute dobavljača')->assertDontSee('Nedopuštena adresa')->assertDontSee('127.0.0.1/private.pdf', false);
        $this->assertSame(0, $product->getMedia('product_documents')->count());
        Http::assertNothingSent();
    }

    private function product(): Product
    {
        $product = Product::query()->create(['code' => 'DOCUMENT-PRODUCT', 'is_active' => true, 'stock_qty' => 1, 'base_price' => 10]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Document product', 'slug' => 'document-product']);

        return $product;
    }
}
