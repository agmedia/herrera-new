<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Slot\Form as SlotForm;
use App\Livewire\Admin\Media\Manager as MediaManager;
use App\Models\Content\ContentBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class FullWidthSliderMediaManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_mobile_home_slot_for_the_slider(): void
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        $block = ContentBlock::query()->create([
            'code' => 'responsive-slider-slot-test',
            'name' => 'Responsive Slider Slot Test',
            'type' => 'full_width_image_slider',
            'is_active' => true,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(SlotForm::class)
            ->set('form.content_block_id', $block->id)
            ->set('form.placement', 'home.hero')
            ->set('form.frontend_variant', 'mobile')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.content.slots'));

        $this->assertDatabaseHas('content_block_slots', [
            'content_block_id' => $block->id,
            'placement' => 'home.hero',
            'frontend_variant' => 'mobile',
            'is_active' => true,
        ]);
    }

    public function test_slider_admin_has_separate_desktop_and_mobile_image_inputs(): void
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        $block = ContentBlock::query()->create([
            'code' => 'responsive-slider-admin-test',
            'name' => 'Responsive Slider Admin Test',
            'type' => 'full_width_image_slider',
            'is_active' => true,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test(MediaManager::class, [
                'modelClass' => ContentBlock::class,
                'modelId' => $block->id,
                'locale' => 'hr',
            ])
            ->assertSee('Desktop slike slidera')
            ->assertSee('1920 × 820 px')
            ->assertSee('Mobile slike slidera (square)')
            ->assertSee('1080 × 1080 px')
            ->assertSee('block_slides_mobile')
            ->assertSee('wire:loading.attr="disabled"', false)
            ->assertSee('wire:target="uploads.block_slides"', false)
            ->assertSee('wire:target="uploads.block_slides_mobile"', false);

        $component
            ->set('uploads.block_slides', [
                UploadedFile::fake()->image('desktop-slide.jpg', 1920, 820),
            ])
            ->call('uploadCollection', 'block_slides')
            ->assertDispatched('notify')
            ->assertSee('Tekst gumba')
            ->assertSee('Link gumba');

        $desktopMedia = $block->fresh()->getFirstMedia('block_slides');
        $this->assertNotNull($desktopMedia);

        $component
            ->set("meta.{$desktopMedia->id}.button_label", 'Pogledajte ponudu')
            ->set("meta.{$desktopMedia->id}.link_url", '/shop');

        $component
            ->set('uploads.block_slides_mobile', [
                UploadedFile::fake()->image('mobile-slide.jpg', 1080, 1080),
            ])
            ->call('uploadCollection', 'block_slides_mobile')
            ->assertDispatched('notify');

        $this->assertCount(1, $block->fresh()->getMedia('block_slides'));
        $this->assertCount(1, $block->fresh()->getMedia('block_slides_mobile'));

        $desktopMedia->refresh();
        $this->assertSame('Pogledajte ponudu', data_get($desktopMedia->custom_properties, 'button_label.hr'));
        $this->assertSame('/shop', data_get($desktopMedia->custom_properties, 'link_url.hr'));
    }
}
