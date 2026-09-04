<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Navigation\Manager as NavigationManager;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Category\CategoryTranslation;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Page\InfoPageTranslation;
use App\Models\Settings\Local\Language;
use App\Models\User;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ContentNavigationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_navigation_page(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get('/admin/content/navigation')
            ->assertOk()
            ->assertSee(__('admin.content.navigation.title'));
    }

    public function test_admin_can_save_navigation_config(): void
    {
        $user = $this->makeAdminUser();

        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'food',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 10,
        ]);

        CategoryTranslation::query()->create([
            'category_id' => $category->id,
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'en',
            'name' => 'Food',
            'slug' => 'food',
        ]);

        $page = InfoPage::query()->create([
            'code' => 'faq',
            'layout' => 'default',
            'is_active' => true,
            'show_in_footer' => false,
            'sort_order' => 20,
        ]);

        InfoPageTranslation::query()->create([
            'page_id' => $page->id,
            'locale' => 'en',
            'title' => 'FAQ',
            'slug' => 'faq',
        ]);

        Livewire::actingAs($user)
            ->test(NavigationManager::class)
            ->set('form.items', [
                [
                    'type' => 'category',
                    'label' => '',
                    'category_id' => $category->id,
                    'page_id' => 0,
                    'url' => '',
                    'open_in_new_tab' => false,
                    'show_dropdown' => true,
                    'is_highlighted' => false,
                    'is_active' => true,
                    'sort_order' => 0,
                ],
                [
                    'type' => 'page',
                    'label' => '',
                    'category_id' => 0,
                    'page_id' => $page->id,
                    'url' => '',
                    'open_in_new_tab' => false,
                    'show_dropdown' => false,
                    'is_highlighted' => true,
                    'is_active' => true,
                    'sort_order' => 1,
                ],
            ])
            ->set('form.appearance', [
                'container_width' => 1240,
                'header_content_width' => 1100,
                'item_height' => 58,
                'font_size' => 16,
                'logo_height' => 68,
                'background_color' => '#ffdf00',
                'text_color' => '#0f172a',
                'highlight_color' => '#b91c1c',
            ])
            ->set('form.top_bar', [
                'is_enabled' => true,
                'height' => 34,
                'font_size' => 13,
                'background_color' => '#eeeeee',
                'text_color' => '#303030',
                'border_color' => '#0057c8',
                'links' => [[
                    'label' => 'O nama',
                    'url' => 'https://www.termol.hr/o-nama.aspx',
                    'open_in_new_tab' => true,
                    'is_active' => true,
                    'sort_order' => 10,
                ]],
                'socials' => [[
                    'network' => 'facebook',
                    'url' => 'https://www.facebook.com/termoldoo/',
                    'is_active' => true,
                    'sort_order' => 0,
                ]],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $saved = app(SystemSettingsService::class)->get(NavigationMenuService::SETTINGS_KEY, []);
        $appearance = app(SystemSettingsService::class)->get(NavigationMenuService::APPEARANCE_SETTINGS_KEY, []);
        $topBar = app(SystemSettingsService::class)->get(NavigationMenuService::TOP_BAR_SETTINGS_KEY, []);

        $this->assertIsArray($saved);
        $this->assertCount(2, $saved);
        $this->assertSame('category', $saved[0]['type']);
        $this->assertSame((int) $category->id, (int) $saved[0]['category_id']);
        $this->assertSame('page', $saved[1]['type']);
        $this->assertSame((int) $page->id, (int) $saved[1]['page_id']);
        $this->assertTrue((bool) $saved[1]['is_highlighted']);
        $this->assertSame(1240, (int) $appearance['container_width']);
        $this->assertSame(1100, (int) $appearance['header_content_width']);
        $this->assertSame('#ffdf00', $appearance['background_color']);
        $this->assertSame('O nama', $topBar['links'][0]['label']);
        $this->assertSame('facebook', $topBar['socials'][0]['network']);
    }

    public function test_storefront_stylesheet_uses_admin_navigation_values(): void
    {
        app(SystemSettingsService::class)->putMany([
            NavigationMenuService::APPEARANCE_SETTINGS_KEY => [
                'container_width' => 1810,
                'header_content_width' => 1400,
                'item_height' => 64,
                'font_size' => 17,
                'logo_height' => 70,
                'background_color' => '#ff6b00',
                'text_color' => '#262626',
                'highlight_color' => '#003b75',
            ],
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => [
                'is_enabled' => true,
                'height' => 36,
                'font_size' => 13,
                'background_color' => '#eeeeee',
                'text_color' => '#303030',
                'border_color' => '#0057c8',
                'links' => [],
                'socials' => [],
            ],
        ]);

        $this->get('/storefront-settings.css')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertSee('--storefront-container-width:1810px', false)
            ->assertSee('--header-content-width:1400px', false)
            ->assertSee('--navigation-font-size:17px', false)
            ->assertSee('--header-logo-height:70px', false)
            ->assertSee('--navigation-background-color:#ff6b00', false)
            ->assertSee('--top-bar-font-size:13px', false)
            ->assertSee('--top-bar-border-color:#0057c8', false);
    }

    public function test_inactive_languages_remain_available_for_content_translation(): void
    {
        $user = $this->makeAdminUser();

        Language::query()->create([
            'code' => 'en',
            'locale' => 'en_US',
            'name' => 'English',
            'native_name' => 'English',
            'direction' => 'ltr',
            'is_default' => false,
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $this->actingAs($user)
            ->get('/admin/content/navigation')
            ->assertOk()
            ->assertSee('value="en"', false);
    }

    public function test_navigation_promo_content_is_resolved_per_locale(): void
    {
        config(['app.locale' => 'hr']);

        app(SystemSettingsService::class)->put(NavigationMenuService::SETTINGS_KEY, [[
            'type' => 'custom',
            'label' => 'Žene',
            'label_translations' => ['hr' => 'Žene', 'en' => 'Women'],
            'url' => '/shop',
            'url_translations' => ['hr' => '/shop', 'en' => '/shop'],
            'is_active' => true,
            'show_dropdown' => true,
            'open_in_new_tab' => false,
            'sort_order' => 0,
            'desktop_promo_title' => 'Nova kolekcija',
            'desktop_promo_title_translations' => ['hr' => 'Nova kolekcija', 'en' => 'New collection'],
            'desktop_promo_subtitle' => 'Istaknuti komadi sezone',
            'desktop_promo_subtitle_translations' => ['hr' => 'Istaknuti komadi sezone', 'en' => 'Season highlights'],
            'desktop_promo_cta_label' => 'Pogledaj više',
            'desktop_promo_cta_label_translations' => ['hr' => 'Pogledaj više', 'en' => 'Shop now'],
            'desktop_promo_cta_url' => '/category/zene',
            'desktop_promo_cta_url_translations' => ['hr' => '/category/zene', 'en' => '/category/women'],
        ]]);

        $service = app(NavigationMenuService::class);
        $croatian = $service->forLocale('hr')[0]['mega_promo'];
        $english = $service->forLocale('en')[0]['mega_promo'];

        $this->assertSame('Nova kolekcija', $croatian['title']);
        $this->assertSame('Pogledaj više', $croatian['cta_label']);
        $this->assertSame('/category/zene', $croatian['cta_url']);
        $this->assertSame('New collection', $english['title']);
        $this->assertSame('Shop now', $english['cta_label']);
        $this->assertSame('/category/women', $english['cta_url']);
    }

    public function test_catalog_navigation_item_resolves_all_visible_root_categories(): void
    {
        $originalLocale = (string) config('app.locale');
        config(['app.locale' => 'hr']);

        $climate = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'climate',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 10,
        ]);
        $climate->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'hr',
            'name' => 'Klimatizacija',
            'slug' => 'klimatizacija',
        ]);
        $climateIcon = UploadedFile::fake()->image('klimatizacija.jpg', 320, 320);
        $climate
            ->addMedia($climateIcon->getPathname())
            ->preservingOriginal()
            ->withCustomProperties([
                'alt' => ['hr' => 'Klima uređaj'],
            ])
            ->toMediaCollection('category_icon');

        $sets = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'climate-sets',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 10,
            'parent_id' => $climate->id,
        ]);
        $sets->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'hr',
            'name' => 'Setovi',
            'slug' => 'klimatizacija-setovi',
        ]);

        $bathroom = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'bathroom',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 20,
        ]);
        $bathroom->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'hr',
            'name' => 'Kupaonica i kuhinja',
            'slug' => 'kupaonica-i-kuhinja',
        ]);

        app(SystemSettingsService::class)->put(NavigationMenuService::SETTINGS_KEY, [
            [
                'type' => 'catalog',
                'label_translations' => ['hr' => 'Proizvodi'],
                'url_translations' => ['hr' => '/categories'],
                'show_dropdown' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'type' => 'category',
                'label_translations' => ['hr' => 'Kupaonica i kuhinja'],
                'category_id' => $bathroom->id,
                'show_dropdown' => true,
                'is_active' => true,
                'sort_order' => 30,
            ],
        ]);

        $menu = app(NavigationMenuService::class)->forLocale('hr');

        $this->assertCount(2, $menu);
        $this->assertSame('catalog', $menu[0]['type']);
        $this->assertSame('Proizvodi', $menu[0]['label']);
        $this->assertSame(['Klimatizacija'], array_column($menu[0]['children'], 'label'));
        $this->assertStringContainsString('/storage/', $menu[0]['children'][0]['image_url']);
        $this->assertSame('Klima uređaj', $menu[0]['children'][0]['image_alt']);
        $this->assertSame('Setovi', $menu[0]['children'][0]['children'][0]['label']);
        $this->assertSame('category', $menu[1]['type']);
        $this->assertSame('Kupaonica i kuhinja', $menu[1]['label']);

        config(['app.locale' => $originalLocale]);
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
