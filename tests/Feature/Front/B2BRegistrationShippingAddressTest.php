<?php

namespace Tests\Feature\Front;

use App\Models\User;
use App\Models\User\UserAddress;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class B2BRegistrationShippingAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_saves_a_separate_default_shipping_address_without_changing_company_details(): void
    {
        $this->post(route('front.auth.b2b-register.store'), $this->registration([
            'ship_to_different_address' => '1',
            ...$this->shipping(),
        ]))->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));

        $user = User::query()->where('email', 'ana@tvrtka.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertCount(2, $user->addresses);
        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $user->id,
            'type' => UserAddress::TYPE_BILLING,
            'first_name' => 'Ana',
            'address_line_1' => 'Ilica 1',
            'city' => 'Zagreb',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $user->id,
            'type' => UserAddress::TYPE_SHIPPING,
            'first_name' => 'Marko',
            'last_name' => 'Horvat',
            'company' => 'Tvrtka d.o.o.',
            'oib' => '12345678901',
            'phone' => '+385 1 555 123',
            'address_line_1' => 'Industrijska 2',
            'address_line_2' => 'Skladište B',
            'postal_code' => '51000',
            'city' => 'Rijeka',
            'country_code' => 'HR',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('b2b_accounts', [
            'user_id' => $user->id,
            'address_line_1' => 'Ilica 1',
            'city' => 'Zagreb',
        ]);
    }

    public function test_separate_shipping_contact_can_have_their_own_phone_number(): void
    {
        $this->post(route('front.auth.b2b-register.store'), $this->registration([
            'ship_to_different_address' => '1',
            ...$this->shipping(),
            'shipping_phone' => '+385 51 123 456',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_addresses', [
            'type' => UserAddress::TYPE_SHIPPING,
            'phone' => '+385 51 123 456',
        ]);
        $this->assertDatabaseHas('user_profiles', ['phone' => '+385 1 555 123']);
    }

    public function test_registration_without_a_shipping_selection_keeps_the_existing_billing_fallback(): void
    {
        $this->post(route('front.auth.b2b-register.store'), $this->registration())
            ->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));

        $user = User::query()->where('email', 'ana@tvrtka.test')->firstOrFail();
        $this->assertCount(1, $user->addresses);
        $this->assertSame(UserAddress::TYPE_BILLING, $user->addresses->sole()->type);
        $this->assertTrue($user->addresses->sole()->is_default);
    }

    public function test_unchecked_shipping_fields_are_ignored_even_if_submitted(): void
    {
        $this->post(route('front.auth.b2b-register.store'), $this->registration([
            'ship_to_different_address' => '0',
            'shipping_first_name' => ['invalid hidden input'],
            'shipping_city' => 'Unwanted city',
            'shipping_country_code' => 'invalid',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));

        $this->assertDatabaseCount('user_addresses', 1);
        $this->assertDatabaseMissing('user_addresses', ['type' => UserAddress::TYPE_SHIPPING]);
    }

    public function test_selected_shipping_address_requires_complete_delivery_details_before_any_account_is_created(): void
    {
        $this->from(route('front.auth.b2b-register'))
            ->post(route('front.auth.b2b-register.store'), $this->registration([
                'ship_to_different_address' => '1',
            ]))
            ->assertRedirect(route('front.auth.b2b-register'))
            ->assertSessionHasErrors([
                'shipping_first_name', 'shipping_last_name', 'shipping_address_line_1',
                'shipping_postal_code', 'shipping_city', 'shipping_country_code',
            ]);

        $this->assertDatabaseMissing('users', ['email' => 'ana@tvrtka.test']);
        $this->assertDatabaseCount('user_addresses', 0);
        $this->assertGuest();
    }

    public function test_invalid_shipping_data_is_rejected_and_the_form_keeps_the_shipping_section_open(): void
    {
        $this->from(route('front.auth.b2b-register'))
            ->post(route('front.auth.b2b-register.store'), $this->registration([
                'ship_to_different_address' => '1',
                ...$this->shipping(),
                'shipping_first_name' => ['invalid name'],
                'shipping_phone' => str_repeat('1', 81),
                'shipping_country_code' => 'HRV',
            ]))
            ->assertRedirect(route('front.auth.b2b-register'))
            ->assertSessionHasErrors(['shipping_first_name', 'shipping_phone', 'shipping_country_code']);

        $this->assertDatabaseMissing('users', ['email' => 'ana@tvrtka.test']);
        $response = $this->get(route('front.auth.b2b-register'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//*[@id="b2b-ship-to-different-address"][@checked]')->count());
        $this->assertSame(1, $xpath->query('//*[@id="b2b-shipping-address"][not(@hidden)][not(@disabled)]')->count());
        $this->assertSame(1, $xpath->query('//*[@name="shipping_city"][@value="Rijeka"]')->count());
        $this->assertSame(1, $xpath->query('//*[@id="b2b-shipping_first_name-error"]')->count());
    }

    public function test_shipping_section_starts_hidden_and_disabled_with_its_own_postal_autofill_scope(): void
    {
        $response = $this->get(route('front.auth.b2b-register'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(1, $xpath->query('//*[@id="b2b-shipping-address"][@hidden][@disabled][@data-address-scope="shipping"]')->count());
        $this->assertSame(1, $xpath->query('//*[@id="b2b-shipping-address"]//*[@name="shipping_postal_code"][@data-address-postal]')->count());
        $this->assertSame(1, $xpath->query('//*[@id="b2b-shipping-address"]//*[@name="shipping_city"][@data-address-city]')->count());
        $this->assertSame(1, $xpath->query('//*[@id="b2b-shipping-address"]//*[@name="shipping_country_code"][@data-address-country]')->count());
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Ana',
            'last_name' => 'Horvat',
            'email' => 'ana@tvrtka.test',
            'phone' => '+385 1 555 123',
            'company_name' => 'Tvrtka d.o.o.',
            'oib' => '12345678901',
            'address_line_1' => 'Ilica 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'HR',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => '1',
        ], $overrides);
    }

    private function shipping(): array
    {
        return [
            'shipping_first_name' => 'Marko',
            'shipping_last_name' => 'Horvat',
            'shipping_address_line_1' => 'Industrijska 2',
            'shipping_address_line_2' => 'Skladište B',
            'shipping_postal_code' => '51000',
            'shipping_city' => 'Rijeka',
            'shipping_country_code' => 'HR',
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }
}
