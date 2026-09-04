<?php

namespace Tests\Feature\Front;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_found_page_keeps_the_storefront_header_and_footer(): void
    {
        $this->get('/adresa-koja-ne-postoji')
            ->assertNotFound()
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertSee('front-theme/styles/error-404.css', false)
            ->assertSee('class="site-main-header', false)
            ->assertSee('class="error-404__code"', false)
            ->assertSee('Stranica nije pronađena')
            ->assertSee('action="'.route('shop.index').'"', false)
            ->assertSee('class="error-404__quick-links"', false)
            ->assertSee('class="site-footer', false);
    }
}
