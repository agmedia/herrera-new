<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form;
use App\Models\Catalog\Manufacturer\Manufacturer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PopularBrandsBlockAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_popular_brands_type_defaults_below_products_and_lists_active_brands(): void
    {
        $activeBrand = $this->createManufacturer('active-brand', 'Active Brand', true);
        $this->createManufacturer('inactive-brand', 'Inactive Brand', false);

        Livewire::test(Form::class)
            ->set('form.type', 'popular_brands')
            ->assertSet('form.slot_placement', 'home.after_products')
            ->assertSet('form.slot_frontend_variant', 'desktop')
            ->assertSee('Popularni brendovi')
            ->assertSee('Active Brand')
            ->assertDontSee('Inactive Brand')
            ->set('pickerItemId', $activeBrand->id)
            ->call('addSelectedItem')
            ->assertSet('form.selected_item_ids', [$activeBrand->id]);
    }

    private function createManufacturer(string $code, string $name, bool $isActive): Manufacturer
    {
        $manufacturer = Manufacturer::query()->create([
            'code' => $code,
            'is_active' => $isActive,
            'is_featured' => true,
            'sort_order' => 0,
        ]);
        $manufacturer->translations()->create([
            'locale' => 'en',
            'name' => $name,
            'slug' => $code,
        ]);

        return $manufacturer;
    }
}
