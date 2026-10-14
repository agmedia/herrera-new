<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\SupplierCatalog\Manager;
use App\Models\Content\SupplierCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class SupplierCatalogsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_cannot_manage_or_upload_catalogues(): void
    {
        $customer = User::factory()->create();
        $catalog = $this->catalog();
        $this->actingAs($customer)->get(route('admin.content.supplier-catalogs.index'))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.content.supplier-catalogs.upload', $catalog), [
            'document' => $this->pdf(),
        ])->assertForbidden();
        Livewire::actingAs($customer)->test(Manager::class)->assertForbidden();
    }

    public function test_administrator_can_create_edit_and_delete_catalogue_metadata(): void
    {
        $admin = $this->admin();
        Livewire::actingAs($admin)->test(Manager::class)
            ->set('form.code', 'braytron-test')->set('form.brand', 'Braytron')
            ->set('form.title', 'General catalogue')->set('form.year', 2026)
            ->set('form.external_url', 'https://braytron.com/en-gb/catalog/')
            ->set('form.format', 'web')->set('form.is_active', true)
            ->call('save')->assertHasNoErrors();
        $catalog = SupplierCatalog::query()->where('code', 'braytron-test')->sole();
        $this->assertTrue($catalog->is_active);
        $this->assertSame($admin->id, $catalog->created_by);

        Livewire::actingAs($admin)->test(Manager::class)->call('edit', $catalog->id)
            ->set('form.title', 'Updated title')->set('form.sort_order', 50)
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Updated title', $catalog->fresh()->title);
        Livewire::actingAs($admin)->test(Manager::class)->call('delete', $catalog->id);
        $this->assertDatabaseMissing('supplier_catalogs', ['id' => $catalog->id]);
    }

    public function test_unsafe_external_urls_are_rejected_and_never_rendered(): void
    {
        $admin = $this->admin();
        foreach (['javascript:alert(1)', 'https://videx.com.pl@127.0.0.1/catalog.pdf', 'https://localhost/catalog.pdf', 'http://braytron.com/catalog.pdf', 'https://127.0.0.1/catalog.pdf', 'https://example.test/file.pdf'] as $index => $url) {
            Livewire::actingAs($admin)->test(Manager::class)
                ->set('form.code', 'unsafe-'.$index)->set('form.brand', 'Unsafe')
                ->set('form.title', 'Unsafe catalogue')->set('form.external_url', $url)
                ->call('save')->assertHasErrors(['form.external_url']);
        }
        $this->catalog(['title' => 'Unsafe historical link', 'is_active' => true, 'external_url' => 'javascript:alert(1)']);
        $this->get(route('supplier-catalogs.index'))->assertOk()->assertDontSee('Unsafe historical link');
    }

    public function test_only_published_catalogues_are_visible_and_brand_filter_is_exact(): void
    {
        $this->catalog(['code' => 'published', 'title' => 'Published catalogue', 'brand' => 'Braytron', 'is_active' => true]);
        $this->catalog(['code' => 'hidden', 'title' => 'Hidden catalogue', 'brand' => 'VIDEX']);
        $this->catalog(['code' => 'other', 'title' => 'VIDEX catalogue', 'brand' => 'VIDEX', 'is_active' => true]);

        $this->get(route('supplier-catalogs.index'))->assertOk()->assertSee('Published catalogue')->assertSee('VIDEX catalogue')->assertDontSee('Hidden catalogue');
        $this->get(route('supplier-catalogs.index', ['brand' => 'Braytron']))->assertOk()->assertSee('Published catalogue')->assertDontSee('VIDEX catalogue');
    }

    public function test_external_links_are_direct_and_no_pdf_is_fetched_or_stored(): void
    {
        Http::fake();
        Storage::fake('local');
        $url = 'https://videx.com.pl/catalog/catalogue-videx-led-2026.pdf';
        $catalog = $this->catalog(['is_active' => true, 'external_url' => $url]);
        $this->get(route('supplier-catalogs.index'))->assertOk()
            ->assertSee('href="'.$url.'"', false)->assertSee('rel="noopener noreferrer"', false);
        $this->assertSame($url, $catalog->publicUrl());
        $this->assertSame([], Storage::disk('local')->allFiles('supplier-catalogs'));
        Http::assertNothingSent();
    }

    public function test_pdf_upload_is_private_and_can_only_be_opened_after_publication(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $catalog = $this->catalog(['source_type' => 'upload', 'external_url' => null]);
        $this->actingAs($admin)->post(route('admin.content.supplier-catalogs.upload', $catalog), [
            'document' => $this->pdf(),
        ])->assertRedirect(route('admin.content.supplier-catalogs.index', ['edit' => $catalog->id]))->assertSessionHasNoErrors();
        $catalog->refresh();
        $this->assertNotNull($catalog->document_path);
        $this->assertTrue($catalog->ownsPath($catalog->document_path, 'documents'));
        Storage::disk('local')->assertExists($catalog->document_path);
        $this->assertFalse($catalog->is_active);
        $this->get(route('supplier-catalogs.document', $catalog))->assertNotFound();
        $catalog->update(['is_active' => true]);
        $this->get(route('supplier-catalogs.document', $catalog))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $catalog->update(['is_active' => false]);
        $this->get(route('supplier-catalogs.document', $catalog))->assertNotFound();
    }

    public function test_uploads_reject_non_pdf_content_and_oversized_files_without_changing_catalogue(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $catalog = $this->catalog();
        $this->actingAs($admin)->post(route('admin.content.supplier-catalogs.upload', $catalog), [
            'document' => UploadedFile::fake()->createWithContent('fake.pdf', '<html>Not a PDF</html>'),
        ])->assertSessionHasErrors('document');
        config(['supplier_catalogs.max_pdf_kb' => 1]);
        $this->actingAs($admin)->post(route('admin.content.supplier-catalogs.upload', $catalog), [
            'document' => UploadedFile::fake()->createWithContent('large.pdf', "%PDF-1.4\n".str_repeat('x', 2048)),
        ])->assertSessionHasErrors('document');
        $this->assertNull($catalog->fresh()->document_path);
        $this->assertSame([], Storage::disk('local')->allFiles('supplier-catalogs'));
    }

    public function test_upload_replacement_removes_only_previous_catalogue_file(): void
    {
        Storage::fake('local');
        $catalog = $this->catalog(['source_type' => 'upload', 'external_url' => null]);
        $oldPath = 'supplier-catalogs/'.$catalog->id.'/documents/old.pdf';
        Storage::disk('local')->put($oldPath, "%PDF-1.4\nold");
        Storage::disk('local')->put('unrelated/keep.pdf', 'keep');
        $catalog->update(['document_path' => $oldPath]);

        $this->actingAs($this->admin())->post(route('admin.content.supplier-catalogs.upload', $catalog), [
            'document' => $this->pdf(),
        ])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($catalog->fresh()->document_path);
        Storage::disk('local')->assertExists('unrelated/keep.pdf');
    }

    public function test_missing_or_foreign_document_paths_are_not_published_or_served(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private/secrets.pdf', "%PDF-1.4\nsecret");
        $catalog = $this->catalog(['source_type' => 'upload', 'title' => 'Missing file catalogue', 'is_active' => true, 'document_path' => 'private/secrets.pdf']);
        $this->get(route('supplier-catalogs.document', $catalog))->assertNotFound();
        $this->get(route('supplier-catalogs.index'))->assertOk()->assertDontSee('Missing file catalogue');
        $catalog->update(['document_path' => 'supplier-catalogs/'.$catalog->id.'/documents/missing.pdf']);
        $this->get(route('supplier-catalogs.document', $catalog))->assertNotFound();
        $this->get(route('supplier-catalogs.index'))->assertOk()->assertDontSee('Missing file catalogue');
    }

    public function test_uploaded_catalogue_cannot_be_published_before_pdf_is_available(): void
    {
        Livewire::actingAs($this->admin())->test(Manager::class)
            ->set('form.code', 'empty-upload')->set('form.brand', 'Braytron')
            ->set('form.title', 'Upload catalogue')->set('form.source_type', 'upload')
            ->set('form.is_active', true)->call('save')->assertHasErrors('form.is_active');
        $this->assertDatabaseMissing('supplier_catalogs', ['code' => 'empty-upload']);
    }

    public function test_seed_command_is_idempotent_and_preserves_admin_changes(): void
    {
        Http::fake();
        $this->artisan('supplier-catalogs:seed', ['--publish' => true])->assertSuccessful();
        $count = SupplierCatalog::query()->count();
        $catalog = SupplierCatalog::query()->where('code', 'videx-led-2026')->sole();
        $catalog->update(['title' => 'Custom title', 'is_active' => false]);
        $this->artisan('supplier-catalogs:seed', ['--publish' => true])->assertSuccessful();
        $this->assertSame($count, SupplierCatalog::query()->count());
        $this->assertSame('Custom title', $catalog->fresh()->title);
        $this->assertFalse($catalog->fresh()->is_active);
        Http::assertNothingSent();
    }

    public function test_missing_cover_backfill_is_explicit_idempotent_and_preserves_admin_content(): void
    {
        $official = collect(config('supplier_catalogs.official_catalogs'))->firstWhere('code', 'videx-led-2026');
        $catalog = $this->catalog([
            'code' => $official['code'], 'external_url' => $official['external_url'],
            'title' => 'An administrator title', 'description' => 'An administrator description',
            'sort_order' => 63, 'is_active' => false,
        ]);
        $this->artisan('supplier-catalogs:covers')->assertSuccessful();
        $this->assertNull($catalog->fresh()->cover_url);
        $this->artisan('supplier-catalogs:covers', ['--apply' => true])->assertSuccessful();
        $catalog->refresh();
        $this->assertSame($official['cover_url'], $catalog->cover_url);
        $this->assertSame('An administrator title', $catalog->title);
        $this->assertSame('An administrator description', $catalog->description);
        $this->assertSame(63, $catalog->sort_order);
        $this->assertFalse($catalog->is_active);
        $catalog->update(['cover_url' => 'https://example.com/custom-cover.jpg']);
        $this->artisan('supplier-catalogs:covers', ['--apply' => true])->assertSuccessful();
        $this->assertSame('https://example.com/custom-cover.jpg', $catalog->fresh()->cover_url);
    }

    public function test_cover_backfill_preserves_uploaded_covers_and_changed_catalogue_sources(): void
    {
        $official = collect(config('supplier_catalogs.official_catalogs'))->firstWhere('code', 'videx-led-2026');
        $catalog = $this->catalog([
            'code' => $official['code'], 'external_url' => $official['external_url'],
            'cover_path' => 'supplier-catalogs/1/covers/custom.jpg',
        ]);
        $this->artisan('supplier-catalogs:covers', ['--apply' => true])->assertSuccessful();
        $this->assertNull($catalog->fresh()->cover_url);
        $catalog->update(['cover_path' => null, 'external_url' => 'https://example.com/replacement-catalogue.pdf']);
        $this->artisan('supplier-catalogs:covers', ['--apply' => true])->assertSuccessful();
        $this->assertNull($catalog->fresh()->cover_url);
    }

    public function test_all_bundled_official_catalogues_have_real_small_cover_images(): void
    {
        foreach (config('supplier_catalogs.official_catalogs') as $catalog) {
            $this->assertNotEmpty($catalog['source_url']);
            $this->assertNotEmpty($catalog['cover_url']);
            $path = public_path(ltrim($catalog['cover_url'], '/'));
            $this->assertFileExists($path);
            $image = getimagesize($path);
            $this->assertSame('image/jpeg', $image['mime']);
            $this->assertGreaterThanOrEqual(500, $image[0]);
            $this->assertLessThanOrEqual(200 * 1024, filesize($path));
        }
    }

    private function catalog(array $data = []): SupplierCatalog
    {
        return SupplierCatalog::query()->create($data + [
            'code' => 'catalog-'.SupplierCatalog::query()->count(), 'brand' => 'Braytron', 'title' => 'Test catalogue',
            'source_type' => 'external', 'format' => 'pdf', 'external_url' => 'https://braytron.com/catalog.pdf',
            'is_active' => false,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('catalogue.pdf', "%PDF-1.4\nCatalogue\n%%EOF");
    }
}
