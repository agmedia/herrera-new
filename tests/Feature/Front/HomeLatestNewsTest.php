<?php

namespace Tests\Feature\Front;

use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeLatestNewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_home_renders_only_the_three_latest_published_posts_at_the_bottom(): void
    {
        $older = $this->createPost('older', 'Starija novost', '2026-01-01 10:00:00');
        $third = $this->createPost('third', 'Treća novost', '2026-02-01 10:00:00');
        $second = $this->createPost('second', 'Druga novost', '2026-05-20 10:00:00');
        $latest = $this->createPost('latest', 'Najnovija novost', '2026-06-10 10:00:00');
        $this->createPost('inactive', 'Neaktivna novost', '2026-07-01 10:00:00', false);
        $this->createPost('future', 'Buduća novost', now()->addMonth()->toDateTimeString());

        $block = ContentBlock::query()->create([
            'code' => 'home-latest-news-test',
            'name' => 'Novosti',
            'type' => 'blogs_carousel',
            'is_active' => true,
        ]);
        $block->translations()->create([
            'locale' => 'hr',
            'title' => 'Novosti',
            'cta_label' => 'Sve novosti',
            'cta_url' => '/blog',
            'payload' => [
                'items_limit' => 3,
                'blog_source' => 'latest',
            ],
        ]);
        $block->slots()->create([
            'placement' => 'home.bottom',
            'frontend_variant' => 'desktop',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $response = $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0 Safari/537.36',
            ])
            ->get('/');

        $response
            ->assertOk()
            ->assertSee('data-latest-news', false)
            ->assertSee('class="home-news-heading storefront-widget-heading--split"', false)
            ->assertSee('class="storefront-widget-heading-title"', false)
            ->assertSee('Sve novosti')
            ->assertSee('href="/blog"', false)
            ->assertSeeInOrder([
                'Najnovija novost',
                'Druga novost',
                'Treća novost',
            ])
            ->assertDontSee('Starija novost')
            ->assertDontSee('Neaktivna novost')
            ->assertDontSee('Buduća novost')
            ->assertSee(route('blog.show', ['slug' => $latest->code]), false)
            ->assertSee(route('blog.show', ['slug' => $second->code]), false)
            ->assertSee(route('blog.show', ['slug' => $third->code]), false)
            ->assertDontSee(route('blog.show', ['slug' => $older->code]), false);

        $template = file_get_contents(resource_path('views/front/content-blocks/types/blogs_carousel.blade.php'));
        $this->assertIsString($template);
        $this->assertStringNotContainsString('<style', $template);
        $this->assertStringContainsString('const desktopPerPage = Math.min(3, Math.max(1, count));', $template);
        $this->assertStringContainsString('arrows: canSlideDesktop', $template);
        $this->assertStringContainsString('drag: canSlideDesktop', $template);
        $this->assertStringContainsString('arrows: canSlideTablet', $template);
        $this->assertStringContainsString('arrows: canSlideMobile', $template);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('.home-news-carousel .splide__arrow {', $css);
        $this->assertStringContainsString('.home-news-carousel .splide__pagination {', $css);
    }

    private function createPost(
        string $code,
        string $title,
        string $publishedAt,
        bool $isActive = true
    ): BlogPost {
        $post = BlogPost::query()->create([
            'code' => $code,
            'is_active' => $isActive,
            'is_featured' => false,
            'published_at' => $publishedAt,
            'sort_order' => 0,
        ]);
        $post->translations()->create([
            'locale' => 'hr',
            'title' => $title,
            'slug' => $code,
            'excerpt' => 'Sažetak '.$title,
            'body_html' => '<p>Sadržaj '.$title.'</p>',
        ]);

        return $post;
    }
}
