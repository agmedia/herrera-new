<?php

namespace Tests\Feature\Front;

use App\Support\FontAwesomeIcon;
use DOMDocument;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FontAwesomeProIconFeatureTest extends TestCase
{
    public function test_existing_icon_component_uses_versioned_pro_sprites_and_keeps_layout_and_accessibility_hooks(): void
    {
        $html = Blade::render('<x-fa-icon name="heart" style="regular" class="h-5 w-5" data-icon-test="preserved" />');
        $this->assertStringContainsString(FontAwesomeIcon::url('heart', 'regular'), $html);
        $this->assertStringContainsString('fa6-icon', $html);
        $this->assertStringContainsString('h-5 w-5', $html);
        $this->assertStringContainsString('data-icon-test="preserved"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('focusable="false"', $html);
        $this->assertStringContainsString('fill="currentColor"', $html);
        $this->assertStringContainsString('?v=7.3.1-', $html);
        $this->assertStringNotContainsString('front-theme/fonts/', $html);
    }

    public function test_optimized_common_icons_use_the_new_package_and_manifest_symbols_exist(): void
    {
        $basePath = public_path('vendor/fontawesome-pro-7.3.1/storefront-sprites');
        $this->assertFileExists($basePath.'/manifest.json');
        $manifest = json_decode(file_get_contents($basePath.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest as $style => $names) {
            $source = file_get_contents($basePath.'/'.$style.'.svg');
            foreach ($names as $name) {
                $url = FontAwesomeIcon::url($name, $style);
                $this->assertStringContainsString('/storefront-sprites/'.$style.'.svg?v=7.3.1-', $url);
                $this->assertSame($name, parse_url($url, PHP_URL_FRAGMENT));
                $this->assertStringContainsString('id="'.$name.'"', $source);
            }
        }
        $this->assertContains('x-twitter', $manifest['brands']);
        $this->assertContains('linkedin-in', $manifest['brands']);
    }

    public function test_opted_in_icons_embed_the_bundled_geometry_without_an_external_sprite_request(): void
    {
        foreach ([['bag-shopping', 'solid'], ['user', 'regular'], ['youtube', 'brands']] as [$name, $style]) {
            $html = Blade::render('<x-fa-icon :name="$name" :style="$style" :inline="true" class="h-5 w-5" data-icon-test="preserved" />', compact('name', 'style'));
            $rendered = new DOMDocument;
            $this->assertTrue($rendered->loadXML(trim($html)));
            $source = new DOMDocument;
            $source->load(public_path('vendor/fontawesome-pro-7.3.1/storefront-sprites/'.$style.'.svg'));
            $symbol = null;
            foreach ($source->getElementsByTagName('symbol') as $candidate) {
                if ($candidate->getAttribute('id') === $name) {
                    $symbol = $candidate;
                    break;
                }
            }

            $this->assertSame($symbol->getAttribute('viewBox'), $rendered->documentElement->getAttribute('viewBox'));
            $this->assertSame($symbol->getElementsByTagName('path')->length, $rendered->getElementsByTagName('path')->length);
            foreach ($symbol->getElementsByTagName('path') as $index => $path) {
                $this->assertSame($path->getAttribute('d'), $rendered->getElementsByTagName('path')->item($index)->getAttribute('d'));
            }
            $this->assertSame(0, $rendered->getElementsByTagName('use')->length);
            $this->assertStringContainsString('data-icon-test="preserved"', $html);
            $this->assertStringContainsString('h-5 w-5', $html);
            $this->assertStringContainsString('aria-hidden="true"', $html);
            $this->assertStringContainsString('focusable="false"', $html);
            $this->assertStringNotContainsString('storefront-sprites/', $html);
        }
    }

    public function test_inline_icons_normalize_inputs_and_keep_the_existing_fallback_for_unbundled_symbols(): void
    {
        $this->assertSame(FontAwesomeIcon::inline('heart', 'regular'), FontAwesomeIcon::inline(' HEART ', ' REGULAR '));
        $this->assertSame(FontAwesomeIcon::inline('heart'), FontAwesomeIcon::inline('heart', '../../brands'));
        $this->assertNull(FontAwesomeIcon::inline('hand-fingers-crossed', 'duotone'));
        $html = Blade::render('<x-fa-icon name="hand-fingers-crossed" style="duotone" :inline="true" />');
        $this->assertStringContainsString(FontAwesomeIcon::url('hand-fingers-crossed', 'duotone'), $html);
        $this->assertStringContainsString('<use ', $html);

        $html = Blade::render('<x-fa-icon name="../heart?test=1" style="../../brands" :inline="true" />');
        $this->assertStringContainsString(FontAwesomeIcon::url('circle-question'), $html);
        $this->assertStringNotContainsString('../', $html);
        $this->assertStringNotContainsString('test=1', $html);
    }

    public function test_every_installed_pro_plus_style_resolves_its_own_real_symbol_without_legacy_fallback(): void
    {
        $basePath = public_path('vendor/fontawesome-pro-7.3.1/sprites');
        $installed = array_map(fn ($path): string => pathinfo($path, PATHINFO_FILENAME), glob($basePath.'/*.svg'));
        $supported = FontAwesomeIcon::STYLES;
        sort($installed);
        sort($supported);
        $this->assertSame($installed, $supported);
        $this->assertCount(37, $installed);

        foreach ($supported as $style) {
            $stream = fopen($basePath.'/'.$style.'.svg', 'rb');
            $sourcePrefix = fread($stream, 8192);
            fclose($stream);
            $this->assertSame(1, preg_match('/<symbol\s+id="([^"]+)"/', $sourcePrefix, $matches));
            $url = FontAwesomeIcon::url($matches[1], $style);
            $this->assertStringContainsString('/'.$style.'.svg?v=7.3.1-', $url);
            $this->assertSame($matches[1], parse_url($url, PHP_URL_FRAGMENT));
            $this->assertFileExists(public_path(ltrim(parse_url($url, PHP_URL_PATH), '/')));
        }

        $proOnlyHtml = Blade::render('<x-fa-icon name="hand-fingers-crossed" style="duotone" />');
        $this->assertStringContainsString('/sprites/duotone.svg?v=7.3.1-', $proOnlyHtml);
        $this->assertStringContainsString('#hand-fingers-crossed', $proOnlyHtml);
        $duotone = file_get_contents($basePath.'/duotone.svg');
        $this->assertStringContainsString('fill:var(--fa-secondary-color,currentColor);opacity:var(--fa-secondary-opacity,.4)', $duotone);
    }

    public function test_invalid_icon_inputs_cannot_become_paths_queries_or_markup_and_legacy_arrow_alias_is_supported(): void
    {
        foreach (['../heart', 'heart/icon', 'heart?test=1', 'heart#other', 'heart%2fother', '<svg>', ''] as $name) {
            $this->assertSame(FontAwesomeIcon::url('circle-question'), FontAwesomeIcon::url($name, 'brands'));
        }
        foreach (['../../brands', 'brands?query=1', '<svg>', 'unknown', ''] as $style) {
            $this->assertSame(FontAwesomeIcon::url('heart'), FontAwesomeIcon::url('heart', $style));
        }
        $this->assertSame(FontAwesomeIcon::url('heart', 'regular'), FontAwesomeIcon::url(' HEART ', ' REGULAR '));
        $this->assertSame(FontAwesomeIcon::url('arrow-right-long'), FontAwesomeIcon::url('long-arrow-right'));
        $html = Blade::render('<x-fa-icon name="../heart?test=1" style="../../brands" />');
        $this->assertStringNotContainsString('../', $html);
        $this->assertStringNotContainsString('test=1', $html);
    }
}
