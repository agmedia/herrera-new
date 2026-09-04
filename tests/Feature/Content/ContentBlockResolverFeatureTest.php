<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ContentBlockResolverFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_blocks_are_excluded_even_when_their_slot_is_active(): void
    {
        $active = ContentBlock::query()->create([
            'code' => 'active-home-block',
            'name' => 'Active Home Block',
            'type' => 'banner',
            'is_active' => true,
            'payload' => null,
        ]);

        $inactive = ContentBlock::query()->create([
            'code' => 'inactive-home-block',
            'name' => 'Inactive Home Block',
            'type' => 'banner',
            'is_active' => false,
            'payload' => null,
        ]);

        foreach ([$active, $inactive] as $block) {
            $block->slots()->create([
                'placement' => 'home.hero',
                'frontend_variant' => 'all',
                'target_type' => null,
                'target_ref' => null,
                'sort_order' => 0,
                'is_active' => true,
            ]);
        }

        Cache::flush();

        $rows = app(ContentBlockResolver::class)->forPlacement('home.hero', 'en');

        $this->assertSame(['active-home-block'], $rows->pluck('block.code')->all());
    }

    public function test_mobile_variant_prefers_mobile_slots_over_all_slots(): void
    {
        $fallback = ContentBlock::query()->create([
            'code' => 'hero-fallback-all',
            'name' => 'Hero Fallback',
            'type' => 'banner',
            'is_active' => true,
            'payload' => null,
        ]);

        $fallback->slots()->create([
            'placement' => 'home.hero',
            'frontend_variant' => 'all',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $mobile = ContentBlock::query()->create([
            'code' => 'hero-mobile',
            'name' => 'Hero Mobile',
            'type' => 'mobile_hero_banner',
            'is_active' => true,
            'payload' => null,
        ]);

        $mobile->slots()->create([
            'placement' => 'home.hero',
            'frontend_variant' => 'mobile',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Cache::flush();

        $rows = app(ContentBlockResolver::class)->forPlacement('home.hero', 'en', null, null, 'mobile');

        $this->assertCount(1, $rows);
        $this->assertSame('hero-mobile', (string) $rows->first()['block']->code);
    }

    public function test_mobile_variant_falls_back_to_all_slots_when_mobile_is_missing(): void
    {
        $fallback = ContentBlock::query()->create([
            'code' => 'hero-only-all',
            'name' => 'Hero Only All',
            'type' => 'banner',
            'is_active' => true,
            'payload' => null,
        ]);

        $fallback->slots()->create([
            'placement' => 'home.hero',
            'frontend_variant' => 'all',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        Cache::flush();

        $rows = app(ContentBlockResolver::class)->forPlacement('home.hero', 'en', null, null, 'mobile');

        $this->assertCount(1, $rows);
        $this->assertSame('hero-only-all', (string) $rows->first()['block']->code);
    }

    public function test_strict_mobile_variant_does_not_fall_back_to_all_slots(): void
    {
        $fallback = ContentBlock::query()->create([
            'code' => 'hero-all-only-strict-check',
            'name' => 'Hero All Only Strict Check',
            'type' => 'banner',
            'is_active' => true,
            'payload' => null,
        ]);

        $fallback->slots()->create([
            'placement' => 'home.hero',
            'frontend_variant' => 'all',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        Cache::flush();

        $rows = app(ContentBlockResolver::class)->forPlacement('home.hero', 'en', null, null, 'mobile', true);

        $this->assertCount(0, $rows);
    }

    public function test_category_target_ref_resolves_only_for_matching_category(): void
    {
        $global = ContentBlock::query()->create([
            'code' => 'category-global-top',
            'name' => 'Category Global Top',
            'type' => 'banner',
            'is_active' => true,
            'payload' => null,
        ]);

        $global->slots()->create([
            'placement' => 'category.top',
            'frontend_variant' => 'all',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $targeted = ContentBlock::query()->create([
            'code' => 'category-zene-editorial',
            'name' => 'Category Zene Editorial',
            'type' => 'category_editorial_tiles',
            'is_active' => true,
            'payload' => null,
        ]);

        $targeted->slots()->create([
            'placement' => 'category.top',
            'frontend_variant' => 'all',
            'target_type' => 'category',
            'target_ref' => 'zene',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        Cache::flush();

        $womenRows = app(ContentBlockResolver::class)->forPlacement('category.top', 'hr', 'category', 'zene', 'desktop');
        $menRows = app(ContentBlockResolver::class)->forPlacement('category.top', 'hr', 'category', 'muskarci', 'desktop');

        $this->assertSame(['category-global-top', 'category-zene-editorial'], $womenRows->pluck('block.code')->all());
        $this->assertSame(['category-global-top'], $menRows->pluck('block.code')->all());
    }
}
