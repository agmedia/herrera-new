<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManufacturerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_directory_is_translated_sorted_and_grouped_by_letter(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_manufacturers', true);

        foreach (['WEBER', 'Ariston', 'QTHERM', 'Alca', 'BOSCH'] as $index => $name) {
            $manufacturer = Manufacturer::query()->create([
                'code' => strtolower($name),
                'is_active' => true,
                'sort_order' => $index,
            ]);

            $manufacturer->translations()->create([
                'locale' => 'hr',
                'name' => $name,
                'slug' => strtolower($name),
            ]);
        }

        $response = $this->get('/brendovi');

        $response
            ->assertOk()
            ->assertSee('>Brendovi</h1>', false)
            ->assertSee('aria-label="Breadcrumb"', false)
            ->assertSee('data-brand-letter="Q"', false)
            ->assertSee('data-brand-letter="W"', false)
            ->assertSeeInOrder([
                'data-brand-group="A"',
                'Alca',
                'Ariston',
                'data-brand-group="B"',
                'BOSCH',
                'data-brand-group="Q"',
                'QTHERM',
                'data-brand-group="W"',
                'WEBER',
            ], false)
            ->assertSee('https://cdn.simpleicons.org/bosch', false)
            ->assertSee('0 proizvoda')
            ->assertDontSee('Nema brendova na slovo Č')
            ->assertDontSee('Nema brendova na slovo DŽ')
            ->assertDontSee('Nema brendova na slovo Ž')
            ->assertDontSee('Manufacturers');
    }
}
