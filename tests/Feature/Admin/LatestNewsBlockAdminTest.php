<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LatestNewsBlockAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_blogs_carousel_defaults_to_three_latest_posts_at_the_bottom_of_home(): void
    {
        Livewire::test(Form::class)
            ->set('form.type', 'blogs_carousel')
            ->assertSet('form.items_limit', 3)
            ->assertSet('form.blog_source', 'latest')
            ->assertSet('form.slot_placement', 'home.bottom')
            ->assertSet('form.slot_frontend_variant', 'desktop')
            ->assertSee('Number of blog posts to show')
            ->assertSee('Blog source');
    }
}
