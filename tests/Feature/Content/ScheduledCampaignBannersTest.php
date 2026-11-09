<?php

namespace Tests\Feature\Content;

use App\Livewire\Admin\Content\Block\Form;
use App\Models\Content\ContentBlock;
use App\Models\User;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ScheduledCampaignBannersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'content_blocks.cache.ttl_seconds' => 3600,
            'storefront_cache.enabled' => false, 'storefront_cache.store' => 'array',
            'storefront_cache.ttl_seconds' => 3600]);
        $this->travelTo(now()->startOfSecond());
    }

    public function test_cached_mobile_campaign_starts_and_ends_at_the_exact_scheduled_time(): void
    {
        $fallback = $this->banner('campaign-fallback');
        $fallback->slots()->create(['placement' => 'home.hero', 'frontend_variant' => 'all', 'is_active' => true]);
        $campaign = $this->banner('scheduled-mobile-campaign');
        $campaign->slots()->create(['placement' => 'home.hero', 'frontend_variant' => 'mobile', 'is_active' => true,
            'starts_at' => now()->addMinute(), 'ends_at' => now()->addMinutes(2)]);
        $resolver = app(ContentBlockResolver::class);
        $version = Cache::get('content_blocks:version');
        $codes = fn () => $resolver->forPlacement('home.hero', 'hr', null, null, 'mobile')->pluck('block.code')->all();

        $this->assertSame(['campaign-fallback'], $codes());
        $this->travel(59)->seconds();
        $this->assertSame(['campaign-fallback'], $codes());
        $this->travel(1)->seconds();
        $this->assertSame(['scheduled-mobile-campaign'], $codes());
        $this->travel(59)->seconds();
        $this->assertSame(['scheduled-mobile-campaign'], $codes());
        $this->travel(1)->seconds();
        $this->assertSame(['campaign-fallback'], $codes());
        $this->assertSame($version, Cache::get('content_blocks:version'));
    }

    public function test_guest_html_is_invalidated_at_campaign_boundaries_without_database_changes(): void
    {
        $campaign = $this->banner('scheduled-guest-campaign');
        $campaign->slots()->create(['placement' => 'home.hero', 'frontend_variant' => 'all', 'is_active' => true,
            'starts_at' => now()->addMinute(), 'ends_at' => now()->addMinutes(2)]);
        $cache = app(GuestStorefrontCache::class);
        $request = Request::create('https://example.test/');
        $key = $cache->key($request, [], 'default');
        $cache->put($key, '<p>Before campaign</p>', str_repeat('a', 40));
        $revision = $cache->revision();
        $this->assertNotNull($cache->get($key));

        $this->travel(1)->minutes();
        $this->assertNull($cache->get($key));
        $activeKey = $cache->key($request, [], 'default');
        $this->assertNotSame($key, $activeKey);
        $cache->put($activeKey, '<p>Campaign</p>', str_repeat('a', 40));
        $this->assertNotNull($cache->get($activeKey));

        $this->travel(1)->minutes();
        $this->assertNull($cache->get($activeKey));
        $this->assertNotSame($activeKey, $cache->key($request, [], 'default'));
        $this->assertSame($revision, $cache->revision());
    }

    public function test_campaign_period_is_optional_and_displays_the_saved_schedule(): void
    {
        $campaign = $this->banner('campaign-period');
        $slot = $campaign->slots()->create(['placement' => 'home.hero', 'is_active' => true,
            'starts_at' => '2026-11-09 10:00:00', 'ends_at' => '2026-11-15 18:00:00']);
        $this->assertStringNotContainsString('data-campaign-period', view('front.content-blocks.campaign-period', ['block' => $campaign, 'slot' => $slot])->render());

        $campaign->payload = ['show_campaign_period' => true];
        $html = view('front.content-blocks.campaign-period', ['block' => $campaign, 'slot' => $slot])->render();
        $this->assertStringContainsString('data-campaign-period', $html);
        $this->assertStringContainsString('09.11.2026. 10:00', $html);
        $this->assertStringContainsString('15.11.2026. 18:00', $html);
        $this->assertStringContainsString('<time datetime=', $html);
    }

    public function test_admin_saves_and_reloads_the_period_option_without_replacing_existing_payload(): void
    {
        $admin = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($admin);
        $campaign = $this->banner('campaign-admin-period');
        $campaign->update(['payload' => ['preserved' => 'value']]);
        $campaign->slots()->create(['placement' => 'home.hero', 'frontend_variant' => 'all', 'is_active' => true]);

        try {
            Livewire::actingAs($admin)->test(Form::class, ['blockId' => $campaign->id])
                ->set('form.show_campaign_period', true)
                ->set('form.slot_starts_at', '2026-11-09T10:00')
                ->set('form.slot_ends_at', '2026-11-15T18:00')
                ->call('save')->assertHasNoErrors();
            $this->assertSame(['preserved' => 'value', 'show_campaign_period' => true], $campaign->fresh()->payload);

            Livewire::actingAs($admin)->test(Form::class, ['blockId' => $campaign->id])
                ->assertSet('form.show_campaign_period', true)
                ->assertSet('form.slot_ends_at', '2026-11-15T18:00')
                ->set('form.show_campaign_period', false)->call('save')->assertHasNoErrors();
            $this->assertSame(['preserved' => 'value'], $campaign->fresh()->payload);
        } finally {
            File::delete(resource_path('views/front/content-blocks/instances/campaign-admin-period.blade.php'));
        }
    }

    private function banner(string $code): ContentBlock
    {
        return ContentBlock::query()->create(['code' => $code, 'name' => $code, 'type' => 'banner', 'is_active' => true]);
    }
}
