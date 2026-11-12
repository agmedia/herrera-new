<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form;
use App\Models\Content\ContentBlock;
use App\Models\User;
use App\Services\Import\HerreraHomeWidgetsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class HerreraHomeWidgetFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_widget_saves_configuration_and_keeps_its_tracked_renderer(): void
    {
        $admin = $this->setupWidgets();
        $block = ContentBlock::query()->where('code', 'herrera-home-popular-products')->firstOrFail();
        $instancePath = resource_path('views/front/content-blocks/instances/'.$block->code.'.blade.php');

        try {
            Livewire::actingAs($admin)->test(Form::class, ['blockId' => $block->id])
                ->assertSet('form.items_limit', 8)
                ->assertSet('form.widget_fields.days', 30)
                ->set('form.title', 'Najtraženiji artikli')
                ->set('form.items_limit', 10)
                ->set('form.widget_fields.days', 60)
                ->set('form.widget_fields.eyebrow', 'Odabir kupaca')
                ->set('form.slot_sort_order', 75)
                ->call('save')
                ->assertHasNoErrors()
                ->assertRedirect(route('admin.content.blocks'));

            $translation = $block->translations()->where('locale', config('app.locale'))->firstOrFail();
            $this->assertSame('Najtraženiji artikli', $translation->title);
            $this->assertSame(60, $translation->payload['days']);
            $this->assertSame(10, $translation->payload['items_limit']);
            $this->assertSame('Odabir kupaca', $translation->payload['eyebrow']);
            $this->assertSame(75, $block->slots()->sole()->sort_order);
            $this->assertFileDoesNotExist($instancePath);
        } finally {
            File::delete($instancePath);
        }
    }

    public function test_categories_support_can_be_hidden_and_homepage_links_are_validated(): void
    {
        $admin = $this->setupWidgets();
        $block = ContentBlock::query()->where('code', 'herrera-home-category-catalog')->firstOrFail();

        Livewire::actingAs($admin)->test(Form::class, ['blockId' => $block->id])
            ->assertSee('Sve glavne kategorije osim Rasprodaje')
            ->assertSet('form.widget_fields.support_visible', true)
            ->set('form.widget_fields.support_visible', false)
            ->set('form.widget_fields.support_contact_url', '//example.test')
            ->call('save')
            ->assertHasErrors(['form.widget_fields.support_contact_url'])
            ->set('form.widget_fields.support_contact_url', '/contact')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.content.blocks'));

        $translation = $block->translations()->where('locale', config('app.locale'))->firstOrFail();
        $this->assertFalse(data_get($translation->payload, 'widget_fields.support_visible'));
        $this->assertSame('/contact', data_get($translation->payload, 'widget_fields.support_contact_url'));
        $this->assertSame('all_root', $block->fresh()->payload['category_source']);
    }

    private function setupWidgets(): User
    {
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        app(HerreraHomeWidgetsService::class)->apply();

        $admin = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('superadmin')->to($admin);

        return $admin;
    }
}
