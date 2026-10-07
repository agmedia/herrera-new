<?php

namespace Tests\Feature\Front;

use App\Support\HerreraFooterNavigation;
use Tests\TestCase;

class HerreraFooterNavigationFeatureTest extends TestCase
{
    public function test_cms_links_survive_when_default_footer_titles_are_kept(): void
    {
        $columns = collect([
            ['title' => 'Proizvodi', 'links' => [['label' => 'Baterije', 'url' => '/category/baterije']]],
            ['title' => 'Informacije', 'links' => [['label' => 'Kontakt', 'url' => '/contact']]],
            ['title' => 'B2B kupci', 'links' => [['label' => 'Moj katalog', 'url' => '/account/b2b/products']]],
        ]);

        $this->assertSame($columns->all(), HerreraFooterNavigation::columns($columns)->all());
    }
}
