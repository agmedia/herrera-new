<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Seo\ProductSeoAgent;
use App\Services\Seo\ProductSeoSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ProductSeoSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['ai.providers.anthropic.key' => 'fake-test-key', 'ai.providers.xai.key' => 'fake-test-key']);
    }

    public function test_generation_is_a_reviewed_preview_and_preserves_existing_manual_text_until_explicit_acceptance(): void
    {
        ProductSeoAgent::fake([['meta_title' => 'Boston EHC002 zidni sat', 'meta_description' => 'Zidni sat Boston EHC002 iz Herrera ponude.']]);
        $product = Product::query()->create(['code' => 'EHC002', 'sku' => 'PRIVATE-SKU', 'base_price' => 100, 'stock_qty' => 7]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Boston EHC002 zidni sat', 'slug' => 'boston', 'meta_title' => 'Ručni SEO naslov']);
        $this->actingAs($admin = User::factory()->create(['account_type' => 'staff']));
        Bouncer::assign('superadmin')->to($admin);
        $component = Livewire::withQueryParams(['locale' => 'hr'])->test(Form::class, ['productId' => $product->id])
            ->set('activeTab', 'seo')->call('generateSeoSuggestion')->assertHasNoErrors()
            ->assertSet('form.meta_title', 'Ručni SEO naslov')->assertSee('Boston EHC002 zidni sat');
        $this->assertSame('Ručni SEO naslov', $product->translations()->sole()->meta_title);
        $component->call('applySeoSuggestion')->assertSet('form.meta_title', 'Ručni SEO naslov')
            ->assertSet('form.meta_description', 'Zidni sat Boston EHC002 iz Herrera ponude.');
        $component->call('applySeoSuggestion', true)->assertSet('form.meta_title', 'Boston EHC002 zidni sat');
        $this->assertSame('Ručni SEO naslov', $product->translations()->sole()->meta_title);
        $this->assertEquals(100, $product->fresh()->base_price);
        $this->assertSame('PRIVATE-SKU', $product->fresh()->sku);
        ProductSeoAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Boston EHC002') && ! str_contains($prompt->prompt, 'PRIVATE-SKU') && ! str_contains($prompt->prompt, 'stock_qty'));
        Http::assertNothingSent();
    }

    public function test_changed_product_facts_require_a_new_suggestion(): void
    {
        ProductSeoAgent::fake([['meta_title' => 'Boston EHC002', 'meta_description' => 'Boston EHC002 zidni sat.']]);
        $this->actingAs($admin = User::factory()->create());
        Bouncer::assign('superadmin')->to($admin);
        Livewire::test(Form::class)->set('form.locale', 'hr')->set('form.name', 'Boston EHC002')->set('form.code', 'EHC002')
            ->call('generateSeoSuggestion')->assertHasNoErrors()->set('form.name', 'Potpuno drugi artikl')
            ->call('applySeoSuggestion', true)->assertHasErrors('seoAi')->assertSet('form.meta_title', '');
    }

    public function test_unconfigured_provider_and_unauthorized_customer_cannot_trigger_generation(): void
    {
        ProductSeoAgent::fake();
        config(['ai.providers.anthropic.key' => null]);
        $this->actingAs($admin = User::factory()->create());
        Bouncer::assign('superadmin')->to($admin);
        Livewire::test(Form::class)->set('form.locale', 'hr')->set('form.name', 'Sat')->call('generateSeoSuggestion')
            ->assertHasErrors('seoAi')->assertSet('seoAiSuggestion', null);
        $this->actingAs(User::factory()->create());
        Livewire::test(Form::class)->call('generateSeoSuggestion')->assertForbidden();
        ProductSeoAgent::assertNeverPrompted();
    }

    public function test_grok_uses_xai_and_rejects_html_or_oversized_ai_text_without_editing_fields(): void
    {
        ProductSeoAgent::fake([['meta_title' => '<script>unsafe</script>', 'meta_description' => 'Opis artikla.']]);
        $this->actingAs($admin = User::factory()->create());
        Bouncer::assign('superadmin')->to($admin);
        Livewire::test(Form::class)->set('form.locale', 'hr')->set('form.name', 'Sat')->set('seoAiProvider', 'xai')
            ->call('generateSeoSuggestion')->assertHasErrors('seoAi')->assertSet('seoAiSuggestion', null)->assertSet('form.meta_title', '');
        $this->assertSame(['anthropic' => true, 'xai' => true], app(ProductSeoSuggestions::class)->availability());
    }

    public function test_oversized_output_and_excessive_generation_requests_fail_without_returning_a_draft(): void
    {
        ProductSeoAgent::fake([['meta_title' => str_repeat('a', 71), 'meta_description' => 'Opis artikla.']]);
        $service = app(ProductSeoSuggestions::class);
        $form = ['locale' => 'hr', 'name' => 'Boston sat'];
        try {
            $service->generate($form, 'anthropic', 1);
            $this->fail('An oversized title cannot become an accepted draft.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Postojeći SEO podaci nisu promijenjeni', $exception->getMessage());
        }
        \Illuminate\Support\Facades\RateLimiter::clear('seo-ai:1');
        ProductSeoAgent::fake(array_fill(0, 10, ['meta_title' => 'Boston sat', 'meta_description' => 'Boston zidni sat.']));
        for ($index = 0; $index < 10; $index++) {
            $this->assertSame('Boston sat', $service->generate($form, 'xai', 1)['meta_title']);
        }
        $this->expectExceptionMessage('Dosegnuto je 10 prijedloga u minuti');
        $service->generate($form, 'xai', 1);
    }
}
