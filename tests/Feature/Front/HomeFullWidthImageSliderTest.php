<?php

namespace Tests\Feature\Front;

use App\Models\Content\ContentBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class HomeFullWidthImageSliderTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_home_slider_uses_the_wide_storefront_frame(): void
    {
        $block = ContentBlock::query()->create([
            'code' => 'home-main-slider',
            'name' => 'Home Wide Slider Test',
            'type' => 'full_width_image_slider',
            'is_active' => true,
            'payload' => null,
        ]);

        $block->translations()->create([
            'locale' => 'hr',
            'title' => null,
            'subtitle' => null,
            'body_html' => null,
            'cta_label' => null,
            'cta_url' => null,
            'payload' => null,
        ]);

        $block->slots()->create([
            'placement' => 'home.hero',
            'frontend_variant' => 'desktop',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $block->slots()->create([
            'placement' => 'home.hero',
            'frontend_variant' => 'mobile',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $image = UploadedFile::fake()->image('home-slide.jpg', 1920, 820);
        $block
            ->addMedia($image->getPathname())
            ->usingName('Home slide')
            ->usingFileName('home-slide.jpg')
            ->withCustomProperties([
                'button_label' => ['hr' => 'Pogledajte ponudu'],
                'link_url' => ['hr' => '/shop'],
                'link_url_value' => '/shop',
            ])
            ->toMediaCollection('block_slides');

        $mobileImage = UploadedFile::fake()->image('home-slide-mobile.jpg', 1080, 1080);
        $block
            ->addMedia($mobileImage->getPathname())
            ->usingName('Home slide mobile')
            ->usingFileName('home-slide-mobile.jpg')
            ->toMediaCollection('block_slides_mobile');

        $sliderId = 'full-width-slider-'.$block->id;

        $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            ])
            ->get('/')
            ->assertOk()
            ->assertSee('id="'.$sliderId.'-shell"', false)
            ->assertSee('class="full-width-image-slider-shell', false)
            ->assertSee('full-width-image-slider-shell--home-main', false)
            ->assertSee('sizes="(max-width: 1860px) 100vw, 1860px"', false)
            ->assertSee('media="(max-width: 768px)"', false)
            ->assertSee('sizes="100vw"', false)
            ->assertSee('home-slide-mobile', false)
            ->assertSee('class="hero-slide-cta"', false)
            ->assertSee('Pogledajte ponudu')
            ->assertSee('data-fullwidth-splide', false);

        $sliderTemplate = file_get_contents(resource_path('views/front/content-blocks/types/full_width_image_slider.blade.php'));
        $storefrontCss = file_get_contents(resource_path('css/full-width-image-slider.css'));

        $this->assertStringNotContainsString('<style', $sliderTemplate);
        $this->assertStringNotContainsString('style="', $sliderTemplate);
        $this->assertStringContainsString('.full-width-image-slider-shell .hero-slide-cta', $storefrontCss);
        $this->assertStringContainsString('background: var(--navigation-background-color, #e65100);', $storefrontCss);
        $this->assertStringContainsString('background: #0057c8;', $storefrontCss);
        $this->assertStringContainsString('height: calc(100dvh - 223px);', $storefrontCss);
        $this->assertStringContainsString('aspect-ratio: 1 / 1;', $storefrontCss);
    }
}
