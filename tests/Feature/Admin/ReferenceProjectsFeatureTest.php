<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\ReferenceProjects\Manager;
use App\Models\Content\Reference\ReferenceProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ReferenceProjectsFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config(['app.locale' => 'hr']);
        app()->setLocale('hr');
    }

    public function test_customer_cannot_access_reference_management_or_direct_livewire_component(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin/content/references')->assertForbidden();
        Livewire::actingAs($customer)->test(Manager::class)->assertForbidden();
    }

    public function test_read_only_editor_cannot_create_update_or_delete_projects(): void
    {
        $viewer = $this->userWithAbilities(['view']);
        $project = $this->project('Pravi projekt');
        Livewire::actingAs($viewer)->test(Manager::class)->assertOk()->call('create')->assertForbidden();
        Livewire::actingAs($viewer)->test(Manager::class)->call('edit', $project->id)->assertForbidden();
        Livewire::actingAs($viewer)->test(Manager::class)->call('delete', $project->id)->assertForbidden();
        $this->assertModelExists($project);
    }

    public function test_editor_can_save_a_bilingual_draft_and_publish_with_an_uploaded_photo(): void
    {
        $editor = $this->userWithAbilities(['view', 'create', 'update', 'delete']);
        $component = Livewire::actingAs($editor)->test(Manager::class)->call('create')
            ->set('form.hr.title', 'Rasvjeta hotela')
            ->set('form.hr.description', "Prostor hotela.\nIzvedba rasvjete.")
            ->set('form.en.title', 'Hotel lighting')
            ->set('form.location', 'Zagreb')
            ->set('form.completed_year', 2026)
            ->call('save')->assertHasNoErrors();
        $project = ReferenceProject::query()->sole();
        $this->assertFalse($project->is_active);
        $this->assertSame('rasvjeta-hotela', $project->translations()->where('locale', 'hr')->sole()->slug);
        $this->assertSame('hotel-lighting', $project->translations()->where('locale', 'en')->sole()->slug);

        $component->set('photos', [UploadedFile::fake()->image('projekt.jpg', 1200, 800)])
            ->set('form.is_active', true)->call('save')->assertHasNoErrors();
        $project->refresh();
        $this->assertTrue($project->is_active);
        $photo = $project->getFirstMedia(ReferenceProject::GALLERY);
        $this->assertNotNull($photo);
        $this->assertTrue($photo->hasGeneratedConversion('reference_card_webp'));
        $this->assertFileExists($photo->getPath());
        $this->get('/reference/rasvjeta-hotela')->assertOk()->assertSee('Rasvjeta hotela')->assertSee('Zagreb');
    }

    public function test_publishing_without_a_real_photo_is_rejected_but_a_draft_can_be_saved(): void
    {
        $component = Livewire::actingAs($this->userWithAbilities(['view', 'create', 'update']))->test(Manager::class)
            ->call('create')->set('form.hr.title', 'Još neobjavljeni projekt')->set('form.is_active', true)
            ->call('save')->assertHasErrors('form.is_active');
        $this->assertDatabaseCount('content_reference_projects', 0);
        $component->set('form.is_active', false)->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('content_reference_projects', 1);
    }

    public function test_only_create_permission_can_save_a_draft_without_update_permission(): void
    {
        Livewire::actingAs($this->userWithAbilities(['view', 'create']))->test(Manager::class)
            ->call('create')->set('form.hr.title', 'Novi nacrt')->call('save')->assertHasNoErrors()->assertSet('showForm', false);
        $this->assertDatabaseCount('content_reference_projects', 1);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Livewire::actingAs($this->userWithAbilities(['view', 'create']))->test(Manager::class)
            ->call('create')->set('form.hr.title', 'Dokument nije fotografija')
            ->set('photos', [UploadedFile::fake()->create('attachment.pdf', 30, 'application/pdf')])
            ->call('save')->assertHasErrors('photos.0');
        $this->assertDatabaseCount('content_reference_projects', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_gallery_order_caption_and_deleting_last_photo_unpublishes_project(): void
    {
        $editor = $this->userWithAbilities(['view', 'update', 'delete']);
        $project = $this->project('Galerija', true);
        $first = $this->photo($project, 'prva.jpg');
        $second = $this->photo($project, 'druga.jpg');
        $component = Livewire::actingAs($editor)->test(Manager::class)->call('edit', $project->id)
            ->call('movePhoto', $second->id, 'up')->assertHasNoErrors();
        $this->assertSame($second->id, $project->fresh()->getMedia(ReferenceProject::GALLERY)->sortBy('order_column')->first()->id);
        $component->set('captions.'.$second->id, '<script>alert(1)</script> Dnevni prostor')
            ->call('saveCaption', $second->id)->assertHasNoErrors();
        $this->get('/reference/galerija')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $component->call('deletePhoto', $second->id)->call('deletePhoto', $first->id);
        $this->assertFalse($project->fresh()->is_active);
        $this->get('/reference/galerija')->assertNotFound();
    }

    public function test_gallery_operations_are_scoped_to_current_project(): void
    {
        $editor = $this->userWithAbilities(['view', 'update', 'delete']);
        $project = $this->project('Prvi projekt');
        $other = $this->project('Drugi projekt');
        $otherPhoto = $this->photo($other);
        Livewire::actingAs($editor)->test(Manager::class)->call('edit', $project->id)->call('deletePhoto', $otherPhoto->id)->assertNotFound();
        Livewire::actingAs($editor)->test(Manager::class)->call('edit', $project->id)->set('captions.'.$otherPhoto->id, 'Changed')->call('saveCaption', $otherPhoto->id)->assertNotFound();
        Livewire::actingAs($editor)->test(Manager::class)->call('edit', $project->id)->call('movePhoto', $otherPhoto->id, 'up')->assertNotFound();
        $this->assertModelExists($otherPhoto);
        $this->assertFileExists($otherPhoto->getPath());
    }

    public function test_public_section_hides_drafts_future_projects_and_projects_without_photos(): void
    {
        $published = $this->project('Objavljeni projekt', true);
        $this->photo($published);
        $draft = $this->project('Tajni nacrt');
        $this->photo($draft);
        $future = $this->project('Budući projekt', true, now()->addDay());
        $this->photo($future);
        $this->project('Bez fotografije', true);
        $this->get('/reference')->assertOk()->assertSee('Objavljeni projekt')->assertDontSee('Tajni nacrt')->assertDontSee('Budući projekt')->assertDontSee('Bez fotografije');
        $this->get('/reference/tajni-nacrt')->assertNotFound();
        $this->get('/reference/buduci-projekt')->assertNotFound();
        $this->get('/reference/bez-fotografije')->assertNotFound();
    }

    public function test_empty_section_has_contact_link_and_no_fabricated_projects(): void
    {
        $this->get('/reference')->assertOk()->assertSee('Pripremamo pregled izvedenih projekata')->assertSee(route('contact.create'), false);
        $this->assertDatabaseCount('content_reference_projects', 0);
    }

    public function test_slug_is_unique_per_language_and_plain_text_description_is_escaped(): void
    {
        $project = $this->project('Projekt sa sadržajem', true);
        $project->translations()->where('locale', 'hr')->update(['description' => '<img src=x onerror=alert(1)>']);
        $this->photo($project);
        $this->get('/reference/projekt-sa-sadrzajem')->assertOk()->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->assertDontSee('<img src=x onerror=alert(1)>', false);
        Livewire::actingAs($this->userWithAbilities(['view', 'create']))->test(Manager::class)->call('create')
            ->set('form.hr.title', 'Drugi naziv')->set('form.hr.slug', 'projekt-sa-sadrzajem')->call('save')->assertHasErrors('form.hr.slug');
    }

    public function test_deleting_project_removes_its_translations_and_gallery_files(): void
    {
        $project = $this->project('Projekt za brisanje');
        $photo = $this->photo($project);
        $path = $photo->getPath();
        Livewire::actingAs($this->userWithAbilities(['view', 'delete']))->test(Manager::class)->call('delete', $project->id)->assertHasNoErrors();
        $this->assertModelMissing($project);
        $this->assertDatabaseCount('content_reference_project_translations', 0);
        $this->assertDatabaseCount('media', 0);
        $this->assertFileDoesNotExist($path);
    }

    public function test_http_admin_route_allows_authorized_editor_and_superadmin(): void
    {
        $editor = $this->userWithAbilities(['view']);
        $this->actingAs($editor)->get('/admin/content/references')->assertOk()->assertSee('Reference i izvedeni projekti');
        $superadmin = User::factory()->create();
        Bouncer::assign('superadmin')->to($superadmin);
        Bouncer::refresh();
        $this->actingAs($superadmin)->get('/admin/content/references')->assertOk();
    }

    public function test_draft_photos_are_private_and_only_reference_editors_can_preview_them(): void
    {
        $project = $this->project('Privatni nacrt');
        $photo = $this->photo($project);
        $this->assertSame('local', $photo->disk);
        $this->assertStringContainsString('/reference/'.$project->id.'/photos/'.$photo->id, $project->photoUrl($photo));
        $this->get($project->photoUrl($photo))->assertNotFound();
        $customer = User::factory()->create();
        $this->actingAs($customer)->get($project->photoUrl($photo, 'original'))->assertNotFound();
        $editor = $this->userWithAbilities(['view']);
        $this->actingAs($editor)->get($project->photoUrl($photo))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_published_photo_route_checks_project_ownership_and_stops_after_unpublishing(): void
    {
        $project = $this->project('Objavljene fotografije', true);
        $photo = $this->photo($project);
        $other = $this->project('Drugi album', true);
        $otherPhoto = $this->photo($other);
        $this->get($project->photoUrl($photo))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(route('references.photo', ['project' => $project->id, 'photo' => $otherPhoto->id, 'size' => 'original']))->assertNotFound();
        $project->update(['is_active' => false]);
        $this->get($project->photoUrl($photo))->assertNotFound();
        $this->get($project->photoUrl($photo, 'original'))->assertNotFound();
    }

    public function test_update_only_editor_cannot_delete_gallery_photos(): void
    {
        $project = $this->project('Zaštićena fotografija');
        $photo = $this->photo($project);
        Livewire::actingAs($this->userWithAbilities(['view', 'update']))->test(Manager::class)
            ->call('edit', $project->id)->call('deletePhoto', $photo->id)->assertForbidden();
        $this->assertModelExists($photo);
    }

    public function test_large_galleries_show_three_photos_above_project_details_and_all_photos_in_lightbox(): void
    {
        $project = $this->project('Projekt s većom galerijom', true);
        foreach (range(1, 5) as $number) {
            $this->photo($project, 'fotografija-'.$number.'.jpg');
        }
        $response = $this->get('/reference/projekt-s-vecom-galerijom')->assertOk()
            ->assertSee('Pogledaj cijelu galeriju (5)')
            ->assertSee('data-reference-open="3"', false);
        $html = $response->getContent();
        $this->assertSame(3, substr_count($html, 'class="reference-gallery__tile"'));
        $this->assertSame(5, substr_count($html, 'data-reference-lightbox-thumb="'));
        $this->assertLessThan(strpos($html, 'class="reference-project-overview"'), strpos($html, 'class="reference-gallery reference-gallery--lead"'));
        $this->assertSame(1, substr_count($html, 'id="reference-gallery-title"'));
        $this->assertStringNotContainsString('class="reference-cover"', $html);
        preg_match('/data-reference-gallery-items>(.*?)<\/script>/s', $html, $matches);
        $this->assertCount(5, json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
    }

    private function userWithAbilities(array $actions): User
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to('admin.access');
        foreach ($actions as $action) {
            Bouncer::allow($user)->to('content.references.'.$action);
        }
        Bouncer::refresh();

        return $user;
    }

    private function project(string $title, bool $active = false, mixed $publishedAt = null): ReferenceProject
    {
        $project = ReferenceProject::query()->create(['is_active' => $active, 'published_at' => $publishedAt]);
        $project->translations()->create(['locale' => 'hr', 'title' => $title, 'slug' => \Illuminate\Support\Str::slug($title)]);

        return $project;
    }

    private function photo(ReferenceProject $project, string $name = 'projekt.jpg'): \Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        return $project->addMedia(UploadedFile::fake()->image($name, 100, 75))->toMediaCollection(ReferenceProject::GALLERY);
    }
}
