<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class AdminDocumentationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_documentation_route_is_registered_in_the_admin_area(): void
    {
        $route = Route::getRoutes()->getByName('admin.help.index');

        $this->assertNotNull($route);
        $this->assertSame('admin/help', $route->uri());
        $this->assertContains('GET', $route->methods());
    }

    public function test_guest_is_redirected_to_login_and_customer_is_forbidden(): void
    {
        $this->get(route('admin.help.index'))
            ->assertRedirect(route('login'));

        $customer = $this->makeUserWithRole('customer');

        $this->actingAs($customer)
            ->get(route('admin.help.index'))
            ->assertForbidden();
    }

    public function test_admin_can_open_the_complete_documentation_page(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $response = $this->actingAs($admin)
            ->get(route('admin.help.index'));

        $response
            ->assertOk()
            ->assertSeeText('Upute za administraciju')
            ->assertSeeText('Centralno uređivanje proizvoda, cijene, zalihe')
            ->assertSeeText('Obrada narudžbi koje ostaju u CMS-u')
            ->assertSeeText('Konfiguriranje B2B veze, certifikata')
            ->assertSee('data-manual-section="nadzorna-ploca"', false)
            ->assertSee('data-manual-section="katalog"', false)
            ->assertSee('data-manual-section="prodaja"', false)
            ->assertSee('data-manual-section="sadrzaj"', false)
            ->assertSee('data-manual-section="integracije"', false)
            ->assertSee('data-manual-section="postavke"', false)
            ->assertSee('data-manual-section="korisnici"', false)
            ->assertSee('data-manual-entry="kategorije"', false)
            ->assertSee('data-manual-entry="artikli"', false)
            ->assertSee('data-manual-entry="msan-postavke"', false)
            ->assertSee('data-manual-entry="msan-artikli"', false)
            ->assertSee('data-manual-entry="nacini-dostave"', false)
            ->assertSee('data-manual-entry="uloge-ovlasti"', false)
            ->assertSee('data-manual-toc-topic="artikli"', false)
            ->assertSee('position: sticky;', false)
            ->assertSee('max-height: calc(100vh - 7rem);', false)
            ->assertSee('overflow-x: clip;', false);
    }

    public function test_manual_config_follows_navigation_order_and_has_unique_topic_ids(): void
    {
        $sections = config('admin_manual.sections', []);

        $this->assertSame([
            'nadzorna-ploca',
            'katalog',
            'prodaja',
            'sadrzaj',
            'integracije',
            'postavke',
            'korisnici',
        ], array_column($sections, 'id'));

        $topicIds = collect($sections)
            ->flatMap(fn (array $section): array => array_column($section['items'] ?? [], 'id'))
            ->values();

        $this->assertSame($topicIds->count(), $topicIds->unique()->count(), 'Documentation topic IDs must be unique.');

        foreach ([
            'kategorije',
            'artikli',
            'narudzbe',
            'blokovi',
            'msan-pregled',
            'msan-postavke',
            'msan-kategorije',
            'msan-specifikacije',
            'msan-artikli',
            'msan-izvrsavanja',
            'nacini-placanja',
            'nacini-dostave',
            'postavke-trgovine',
            'lista-korisnika',
        ] as $expectedTopicId) {
            $this->assertTrue(
                $topicIds->contains($expectedTopicId),
                "Missing documentation topic [{$expectedTopicId}].",
            );
        }
    }

    public function test_header_documentation_link_points_to_the_current_admin_topic(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $documentationUrl = route('admin.help.index');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('id="admin-documentation-link"', false)
            ->assertSee('href="'.$documentationUrl.'#nadzorna-ploca-pregled"', false)
            ->assertDontSee('id="admin-help-open"', false);

        $this->actingAs($admin)
            ->get(route('admin.products'))
            ->assertOk()
            ->assertSee('href="'.$documentationUrl.'#artikli"', false)
            ->assertDontSee('id="admin-help-open"', false);

        $this->actingAs($admin)
            ->get(route('admin.settings.local.resource', ['resource' => 'geo-zones']))
            ->assertOk()
            ->assertSee('href="'.$documentationUrl.'#geo-zone"', false)
            ->assertDontSee('id="admin-help-open"', false);

        $this->actingAs($admin)
            ->get($documentationUrl)
            ->assertOk()
            ->assertSee('href="'.$documentationUrl.'#uvod"', false)
            ->assertDontSee('id="admin-help-open"', false);
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }
}
