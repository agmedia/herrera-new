<?php

namespace Tests\Feature\Import;

use App\Models\Content\Blog\BlogPost;
use App\Services\Import\HerreraBlogRefreshService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\HerreraBlogImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class HerreraBlogRefreshServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;

    private string $originalPublicPath;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'legacy_media.enabled' => false]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->fixtureRoot = sys_get_temp_dir().'/herrera-blog-refresh-'.bin2hex(random_bytes(8));
        $this->originalPublicPath = public_path();
        mkdir($this->fixtureRoot.'/public/assets/blog/herrera', 0777, true);
        $this->app->usePublicPath($this->fixtureRoot.'/public');
        file_put_contents($this->fixtureRoot.'/body.html', '<h2>Obnovljeni tekst</h2><p>Sadržaj članka.</p>');
        file_put_contents(public_path('assets/blog/herrera/bella-v1.jpg'), 'nonempty-test-cover');
        config(['herrera-blog-content' => ['herrera-oc-blog-10368' => $this->content()]]);
    }

    protected function tearDown(): void
    {
        $this->app->usePublicPath($this->originalPublicPath);
        File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_refresh_backs_up_original_croatian_content_preserves_identity_and_later_admin_edits(): void
    {
        $post = $this->blogPost('herrera-oc-blog-10368');
        $english = $post->translations()->create(['locale' => 'en', 'title' => 'English original', 'slug' => 'english-original', 'body_html' => 'English body']);
        $originalPost = $post->fresh()->getAttributes();
        $originalCroatian = $post->translations()->where('locale', 'hr')->sole()->attributesToArray();

        $service = app(HerreraBlogRefreshService::class);
        $this->assertSame(1, $service->apply());
        $post->refresh();
        $translation = $post->translations()->where('locale', 'hr')->sole();
        $this->assertSame('Novi naslov', $translation->title);
        $this->assertSame('Novi sažetak', $translation->excerpt);
        $this->assertSame(file_get_contents($this->fixtureRoot.'/body.html'), $translation->body_html);
        $this->assertSame('SEO naslov', $translation->meta_title);
        $this->assertSame('SEO opis', $translation->meta_description);
        $this->assertSame($originalCroatian['slug'], $translation->slug);
        $this->assertSame(['source' => 'original-translation'], $translation->payload);
        foreach (['code', 'is_active', 'is_featured', 'published_at', 'sort_order'] as $field) {
            $this->assertSame($originalPost[$field], $post->getRawOriginal($field));
        }
        $this->assertSame('English original', $english->fresh()->title);
        $this->assertSame('English body', $english->fresh()->body_html);
        $this->assertSame('assets/blog/herrera/bella-v1.jpg', $post->payload['herrera_cover_path']);
        $backup = $post->payload[HerreraBlogRefreshService::PAYLOAD_VERSION_KEY];
        $this->assertSame($originalCroatian, $backup['translation']);
        $this->assertSame(['opencart' => ['image' => 'catalog/blog/old-cover.jpg'], 'custom' => 'keep'], $backup['post_payload']);
        $this->assertSame('keep', $post->payload['custom']);

        $translation->update(['title' => 'Administratorov naslov', 'body_html' => '<p>Administratorov tekst.</p>']);
        $post->update(['payload' => array_replace($post->payload, ['herrera_cover_path' => 'assets/blog/herrera/admin-cover.jpg'])]);
        $this->assertSame(0, $service->apply());
        $this->assertSame('Administratorov naslov', $translation->fresh()->title);
        $this->assertSame('<p>Administratorov tekst.</p>', $translation->fresh()->body_html);
        $this->assertSame('assets/blog/herrera/admin-cover.jpg', $post->fresh()->payload['herrera_cover_path']);
        $this->assertSame($backup, $post->fresh()->payload[HerreraBlogRefreshService::PAYLOAD_VERSION_KEY]);
    }

    public function test_missing_posts_or_croatian_translation_remain_pending_until_imported(): void
    {
        $service = app(HerreraBlogRefreshService::class);
        $this->assertSame(0, $service->apply());
        $this->assertDatabaseCount('content_blog_posts', 0);

        $post = BlogPost::query()->create(['code' => 'herrera-oc-blog-10368', 'payload' => ['custom' => 'keep']]);
        $post->translations()->create(['locale' => 'en', 'title' => 'English', 'slug' => 'english-only']);
        $this->assertSame(0, $service->apply());
        $this->assertArrayNotHasKey(HerreraBlogRefreshService::PAYLOAD_VERSION_KEY, $post->fresh()->payload);

        $post->translations()->create(['locale' => 'hr', 'title' => 'Uvezeni naslov', 'slug' => 'preserved-import-slug']);
        $this->assertSame(1, $service->apply());
        $this->assertSame('preserved-import-slug', $post->translations()->where('locale', 'hr')->sole()->slug);
        $this->assertSame(0, $service->apply());
    }

    public function test_missing_source_image_rolls_back_all_translations_and_backup_markers(): void
    {
        $first = $this->blogPost('herrera-oc-blog-10368');
        $second = $this->blogPost('herrera-oc-blog-10369');
        config(['herrera-blog-content' => [
            $first->code => $this->content(),
            $second->code => array_replace($this->content(), ['image_path' => 'assets/blog/herrera/missing-v1.jpg']),
        ]]);

        try {
            app(HerreraBlogRefreshService::class)->apply();
            $this->fail('The refresh must require its source image.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($second->code, $exception->getMessage());
        }
        foreach ([$first, $second] as $post) {
            $this->assertSame('Izvorni naslov', $post->translations()->where('locale', 'hr')->sole()->title);
            $this->assertArrayNotHasKey(HerreraBlogRefreshService::PAYLOAD_VERSION_KEY, $post->fresh()->payload);
            $this->assertArrayNotHasKey('herrera_cover_path', $post->fresh()->payload);
        }
    }

    public function test_uploaded_cover_precedes_static_cover_while_gallery_remains_a_fallback(): void
    {
        $post = $this->blogPost('herrera-oc-blog-10368');
        $post->update(['payload' => ['herrera_cover_path' => 'assets/blog/herrera/bella-v1.jpg']]);
        $gallery = $this->media('blog_gallery', 'gallery');
        $post->setRelation('media', collect([$gallery]));
        $this->assertSame(asset('assets/blog/herrera/bella-v1.jpg'), HerreraBlogImage::first($post));
        $this->assertSame(public_path('assets/blog/herrera/bella-v1.jpg'), HerreraBlogImage::localPath($post));

        $cover = $this->media('blog_cover', 'cover');
        $post->setRelation('media', collect([$cover, $gallery]));
        $this->assertSame('https://example.test/cover.jpg', HerreraBlogImage::first($post));

        $post->setRelation('media', collect([$gallery]));
        $post->payload = ['herrera_cover_path' => 'assets/blog/herrera/missing-v1.jpg'];
        $this->assertSame('https://example.test/gallery.jpg', HerreraBlogImage::first($post));
    }

    public function test_static_cover_rejects_traversal_remote_paths_and_symlinks_outside_public_assets(): void
    {
        foreach ([null, '', 'https://example.test/cover.jpg', '/assets/blog/herrera/bella-v1.jpg',
            'assets/blog/herrera/../bella-v1.jpg', 'assets/blog/herrera/%2e%2e/bella-v1.jpg',
            'assets/blog/herrera/bella-v1.jpg?version=1', 'assets/blog/herrera/bella-v1.svg'] as $path) {
            $this->assertNull(HerreraBlogImage::assetPath($path));
        }
        file_put_contents($this->fixtureRoot.'/outside.jpg', 'outside');
        symlink($this->fixtureRoot.'/outside.jpg', public_path('assets/blog/herrera/outside.jpg'));
        $this->assertNull(HerreraBlogImage::assetPath('assets/blog/herrera/outside.jpg'));
    }

    public function test_other_stores_cannot_refresh_existing_content(): void
    {
        $post = $this->blogPost('herrera-oc-blog-10368');
        app(SystemSettingsService::class)->put('store_brand_name', 'Another store');
        try {
            app(HerreraBlogRefreshService::class)->apply();
            $this->fail('Another store must be rejected.');
        } catch (\LogicException) {
            $this->assertSame('Izvorni naslov', $post->translations()->where('locale', 'hr')->sole()->title);
            $this->assertArrayNotHasKey(HerreraBlogRefreshService::PAYLOAD_VERSION_KEY, $post->fresh()->payload);
        }
    }

    private function content(): array
    {
        return [
            'title' => 'Novi naslov', 'excerpt' => 'Novi sažetak',
            'body_path' => $this->fixtureRoot.'/body.html',
            'image_path' => 'assets/blog/herrera/bella-v1.jpg',
            'meta_title' => 'SEO naslov', 'meta_description' => 'SEO opis',
        ];
    }

    private function blogPost(string $code): BlogPost
    {
        $post = BlogPost::query()->create([
            'code' => $code, 'is_active' => false, 'is_featured' => true,
            'published_at' => '2025-01-02 12:30:00', 'sort_order' => 9,
            'payload' => ['opencart' => ['image' => 'catalog/blog/old-cover.jpg'], 'custom' => 'keep'],
        ]);
        $post->translations()->create([
            'locale' => 'hr', 'title' => 'Izvorni naslov', 'slug' => 'preserved-'.$code,
            'excerpt' => 'Izvorni sažetak', 'body_html' => '<p>Izvorni tekst</p>',
            'meta_title' => 'Izvorni SEO naslov', 'meta_description' => 'Izvorni SEO opis',
            'payload' => ['source' => 'original-translation'],
        ]);

        return $post;
    }

    private function media(string $collection, string $name): Media
    {
        $path = $this->fixtureRoot.'/'.$name.'.jpg';
        file_put_contents($path, 'uploaded-test-image');
        $media = Mockery::mock(Media::class)->makePartial();
        $media->collection_name = $collection;
        $media->shouldReceive('getPath')->withNoArgs()->andReturn($path);
        $media->shouldReceive('getUrl')->withNoArgs()->andReturn('https://example.test/'.$name.'.jpg');

        return $media;
    }
}
