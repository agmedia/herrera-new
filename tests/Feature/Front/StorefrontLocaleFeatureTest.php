<?php

namespace Tests\Feature\Front;

use App\Models\Settings\Local\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontLocaleFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_front_locale_switch_persists_selected_language_between_requests(): void
    {
        $this->seedLanguages();

        $this->get('/contact')
            ->assertOk()
            ->assertSee('lang="hr"', false);

        $this->from('/contact')
            ->get('/locale/en')
            ->assertRedirect('/contact')
            ->assertSessionHas('front_locale', 'en');

        $this->get('/contact')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSessionHas('front_locale', 'en');
    }

    public function test_return_request_form_has_localized_slugs(): void
    {
        $this->seedLanguages();

        $this->get('/forma-za-povrat-i-reklamacije')
            ->assertOk()
            ->assertSee('Raskid ugovora');

        $this->from('/contact')->get('/locale/en');

        $this->get('/returns-and-claims')
            ->assertOk()
            ->assertSee('Withdraw from contract')
            ->assertSee('/returns-and-claims', false);

        $this->from('/returns-and-claims')->get('/locale/de');

        $this->get('/rucksendungen-und-reklamationen')
            ->assertOk()
            ->assertSee('Vertrag widerrufen')
            ->assertSee('/rucksendungen-und-reklamationen', false);
    }

    private function seedLanguages(): void
    {
        Language::query()->create([
            'code' => 'hr',
            'locale' => 'hr_HR',
            'name' => 'Croatian',
            'native_name' => 'Hrvatski',
            'direction' => 'ltr',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Language::query()->create([
            'code' => 'en',
            'locale' => 'en_US',
            'name' => 'English',
            'native_name' => 'English',
            'direction' => 'ltr',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Language::query()->updateOrCreate([
            'code' => 'de',
        ], [
            'locale' => 'de_DE',
            'name' => 'German',
            'native_name' => 'Deutsch',
            'direction' => 'ltr',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 3,
        ]);
    }
}
