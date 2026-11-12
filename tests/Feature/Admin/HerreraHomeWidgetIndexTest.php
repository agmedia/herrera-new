<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Index;
use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Models\User;
use App\Services\Content\ContentBlockResolver;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class HerreraHomeWidgetIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
    }

    public function test_home_widgets_are_grouped_once_in_homepage_order_and_respect_filters(): void
    {
        $this->slot('Lower widget', 'home.bottom');
        $this->slot('Desktop hero widget', 'home.hero', ['frontend_variant' => 'desktop']);
        $this->slot('Mobile category widget', 'home.categories', ['frontend_variant' => 'mobile']);
        $this->slot('Other catalogue block', 'category.top');

        $index = Livewire::actingAs($this->admin('superadmin'))->test(Index::class)
            ->assertSee('Raspored početne stranice')
            ->assertSeeInOrder(['Desktop hero widget', 'Mobile category widget', 'Lower widget', 'Ostali blokovi', 'Other catalogue block']);
        $this->assertSame(1, substr_count($index->html(), '>Lower widget<'));

        $index->set('surface', 'desktop')->assertDontSee('Mobile category widget')->assertSee('Lower widget')
            ->set('search', 'hero')->assertSee('Desktop hero widget')->assertDontSee('Lower widget')->assertDontSee('Other catalogue block');
    }

    public function test_editor_can_reorder_widgets_and_toggle_only_the_selected_homepage_slot(): void
    {
        $first = $this->slot('First', 'home.before_products', ['sort_order' => 30]);
        $second = $this->slot('Second', 'home.before_products', ['sort_order' => 30]);
        $other = $second->block->slots()->create(['placement' => 'category.top', 'frontend_variant' => 'all', 'is_active' => true]);
        $resolver = app(ContentBlockResolver::class);
        $this->assertSame([$first->id, $second->id], $resolver->forPlacement('home.before_products')->pluck('slot.id')->all());

        $index = Livewire::actingAs($this->admin('admin'))->test(Index::class)
            ->call('moveHomeWidget', $second->id, 'up')->assertOk();
        $this->assertSame([$second->id, $first->id], $resolver->forPlacement('home.before_products')->pluck('slot.id')->all());

        $index->call('toggleHomeWidget', $second->id)->assertOk()->assertSee('Isključen');
        $this->assertFalse($second->fresh()->is_active);
        $this->assertTrue($other->fresh()->is_active);
        $this->assertTrue($second->block->fresh()->is_active);
        $this->assertSame([$first->id], $resolver->forPlacement('home.before_products')->pluck('slot.id')->all());

        $index->call('toggleHomeWidget', $second->id)->assertOk();
        $this->assertSame([$second->id, $first->id], $resolver->forPlacement('home.before_products')->pluck('slot.id')->all());
    }

    public function test_homepage_controls_require_update_permission_and_refuse_slots_outside_homepage(): void
    {
        $home = $this->slot('Home only', 'home.hero');
        $targeted = $this->slot('Targeted block', 'home.hero', ['target_type' => 'category', 'target_ref' => 'some-category']);
        $viewer = User::factory()->create();
        Bouncer::allow($viewer)->to('admin.access');
        Bouncer::allow($viewer)->to('content.blocks.view');

        Livewire::actingAs($viewer)->test(Index::class)
            ->assertSee('Home only')->assertDontSee('toggleHomeWidget', false)
            ->call('toggleHomeWidget', $home->id)->assertStatus(403);
        Livewire::actingAs($viewer)->test(Index::class)
            ->call('moveHomeWidget', $home->id, 'down')->assertStatus(403);
        Livewire::actingAs($this->admin('admin'))->test(Index::class)
            ->call('toggleHomeWidget', $targeted->id)->assertStatus(404);

        $this->assertTrue($home->fresh()->is_active);
        $this->assertTrue($targeted->fresh()->is_active);
    }

    private function slot(string $name, string $placement, array $attributes = []): ContentBlockSlot
    {
        $block = ContentBlock::query()->create(['code' => str($name)->slug()->toString(), 'name' => $name, 'type' => 'html', 'is_active' => true]);

        return $block->slots()->create($attributes + ['placement' => $placement, 'frontend_variant' => 'all', 'is_active' => true]);
    }

    private function admin(string $role): User
    {
        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }
}
