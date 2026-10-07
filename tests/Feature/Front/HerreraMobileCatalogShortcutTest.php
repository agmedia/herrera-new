<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HerreraMobileCatalogShortcutTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('catalogModes')]
    public function test_mobile_catalog_shortcut_links_to_categories_when_cms_has_no_expandable_catalog(string $mode, bool $expandable): void
    {
        $category = Category::query()->create([
            'code' => 'mobile-shortcut-root',
            'scope' => Category::SCOPE_CATALOG,
            'is_active' => true,
            'show_in_menu' => true,
        ]);
        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'hr',
            'name' => 'Rasvjeta',
            'slug' => 'mobile-shortcut-root',
        ]);
        $navigation = [['type' => 'contact', 'label' => 'Kontakt']];
        if ($mode !== 'removed') {
            array_unshift($navigation, [
                'type' => 'catalog',
                'label' => 'Katalog',
                'is_active' => $mode !== 'disabled',
                'show_dropdown' => $mode !== 'no-dropdown',
            ]);
        }
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            NavigationMenuService::SETTINGS_KEY => $navigation,
        ]);

        $response = $this->get(route('home'))->assertOk();
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $shortcut = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " herrera-mobile-shortcuts ")]/*[1]')->item(0);

        if ($expandable) {
            $this->assertSame('button', $shortcut->nodeName);
            $this->assertTrue($shortcut->hasAttribute('data-mobile-menu-open-categories'));
            $this->assertSame(1, $xpath->query('//*[@data-mobile-menu-catalog]')->count());
        } else {
            $this->assertSame('a', $shortcut->nodeName);
            $this->assertSame(route('categories.index'), $shortcut->getAttribute('href'));
            $this->assertFalse($shortcut->hasAttribute('data-mobile-menu-open'));
        }
    }

    public static function catalogModes(): array
    {
        return [
            'catalog removed' => ['removed', false],
            'catalog disabled' => ['disabled', false],
            'dropdown disabled' => ['no-dropdown', false],
            'expandable catalog' => ['expandable', true],
        ];
    }
}
