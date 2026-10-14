<?php

namespace Tests\Feature\Admin;

use App\Mail\CustomerOfferMail;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Offer\CustomerOffer;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Sales\CustomerOfferImage;
use App\Services\Sales\CustomerOfferPdf;
use App\Services\Sales\CustomerOfferService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CustomerOffersFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'legacy_media.enabled' => false]);
        Mail::fake();
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false, 'bool');
    }

    public function test_create_form_loads_the_runtime_needed_by_its_customer_and_product_picker(): void
    {
        $response = $this->actingAs($this->staff())->get(route('admin.customer-offers.create'))->assertOk();

        $this->assertSame(1, preg_match('~<script\b[^>]*\bsrc=["\'][^"\']*/livewire/livewire(?:\.min)?\.js(?:\?[^"\']*)?["\']~', $response->getContent()), 'The plain Blade offer form must load Livewire, which provides its Alpine picker runtime.');
        $response->assertSee('data-update-uri=', false);
    }

    public function test_approved_customer_can_be_found_by_exact_oib_with_a_selectable_identity(): void
    {
        [$buyer] = $this->buyer('Searchable offer buyer');
        $buyer->b2bAccount->update(['oib' => '12345678901']);

        $this->actingAs($this->staff())->getJson(route('admin.customer-offers.customers', ['search' => '12345678901']))
            ->assertOk()
            ->assertExactJson([['id' => $buyer->id, 'name' => 'Searchable offer buyer', 'email' => $buyer->email]]);
    }

    public function test_suspended_customer_is_not_returned_by_the_offer_picker(): void
    {
        [$buyer] = $this->buyer('Suspended offer buyer');
        $buyer->b2bAccount->update(['oib' => '12345678901', 'status' => B2BAccount::STATUS_SUSPENDED]);

        $this->actingAs($this->staff())->getJson(route('admin.customer-offers.customers', ['search' => '12345678901']))
            ->assertOk()->assertExactJson([]);
    }

    public function test_product_search_returns_the_exact_sku_and_its_minimum_quantity_for_an_approved_buyer(): void
    {
        [$buyer] = $this->buyer();
        $product = $this->product('Searchable lamp');
        $product->update(['sku' => 'BA13-32823', 'minimum_order_quantity' => 6]);
        $this->product('Different lamp');

        $this->actingAs($this->staff())->getJson(route('admin.customer-offers.products', ['customer_user_id' => $buyer->id, 'search' => 'BA13-32823']))
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.product_id', $product->id)
            ->assertJsonPath('0.sku', 'BA13-32823')
            ->assertJsonPath('0.name', 'Searchable lamp')
            ->assertJsonPath('0.quantity', 6)
            ->assertJsonPath('0.product_option_value_id', null);
    }

    public function test_product_search_requires_a_selected_buyer(): void
    {
        $this->actingAs($this->staff())->getJson(route('admin.customer-offers.products', ['search' => 'BA13-32823']))
            ->assertUnprocessable()->assertJsonValidationErrors('customer_user_id');
    }

    public function test_admin_creates_snapshot_with_final_customer_quantity_prices_without_double_discount(): void
    {
        [$buyer, $group] = $this->buyer();
        $admin = $this->staff();
        $product = $this->product();
        $catalog = PriceCatalog::query()->create(['name' => 'Offer catalogue', 'status' => 'draft', 'currency_code' => 'EUR']);
        foreach ([['kind' => 'group', 'price' => 80.1234, 'customer_group_id' => $group->id],
            ['kind' => 'customer', 'price' => 70.1234, 'user_id' => $buyer->id],
            ['kind' => 'quantity', 'price' => 60.1234, 'customer_group_id' => $group->id, 'minimum_quantity' => 5]] as $i => $row) {
            PriceCatalogEntry::query()->create($row + ['price_catalog_id' => $catalog->id, 'product_id' => $product->id, 'source_key' => 'offer-'.$i, 'minimum_quantity' => 1, 'is_active' => true]);
        }
        $catalog->update(['status' => 'active']);
        DB::table('catalog_price_catalog_state')->updateOrInsert(['id' => 1], ['price_catalog_id' => $catalog->id]);
        CatalogAction::query()->create(['code' => 'offer-double', 'name' => 'Extra 50%', 'scope' => 'product', 'type' => 'percentage', 'discount_value' => 50, 'audience_type' => 'all', 'target_type' => 'all', 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.customer-offers.store'), $this->input($buyer, $product, 5) + ['unit_net' => 0.01])->assertRedirect();
        $offer = CustomerOffer::query()->firstOrFail();
        $this->assertSame('60.1234', $offer->items->first()->unit_net);
        $this->assertSame('300.6170', $offer->net_total);
        $this->assertSame('375.7715', $offer->gross_total);
        $this->assertSame('price_catalog_quantity', $offer->items->first()->pricing_snapshot['b2b_source_type']);
        $this->assertSame('Buyer Example', $offer->customer_snapshot['company']);
        $this->actingAs($admin)->get(route('admin.customer-offers.edit', $offer))->assertOk()->assertSee($offer->number);
        Mail::assertNothingSent();
    }

    public function test_assigned_rep_can_work_only_with_assigned_buyers_and_customer_cannot_enter_admin(): void
    {
        [$assigned] = $this->buyer();
        [$other] = $this->buyer('Other buyer');
        $admin = $this->staff();
        $rep = $this->staff('order_manager');
        $product = $this->product();
        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $rep->id, 'customer_user_id' => $assigned->id, 'created_at' => now(), 'updated_at' => now()]);
        $own = app(CustomerOfferService::class)->saveDraft($this->input($assigned, $product), $admin);
        $foreign = app(CustomerOfferService::class)->saveDraft($this->input($other, $product), $admin);
        $this->actingAs($rep)->get(route('admin.customer-offers.index'))->assertOk()->assertSee($own->number)->assertDontSee($foreign->number);
        $this->actingAs($rep)->get(route('admin.customer-offers.edit', $foreign))->assertForbidden();
        $this->actingAs($rep)->get(route('admin.customer-offers.pdf', $foreign))->assertForbidden();
        $this->actingAs($rep)->post(route('admin.customer-offers.store'), $this->input($other, $product))->assertForbidden();
        $this->actingAs($rep)->get(route('admin.customer-offers.products', ['customer_user_id' => $other->id, 'search' => 'Product']))->assertForbidden();
        $this->actingAs($assigned)->get(route('admin.customer-offers.index'))->assertForbidden();
        $this->actingAs($rep)->post(route('admin.customer-offers.store'), $this->input($assigned, $product))->assertRedirect();
    }

    public function test_preview_and_pdf_have_four_products_per_page_and_escape_descriptions(): void
    {
        Mail::fake();
        [$buyer] = $this->buyer();
        $admin = $this->staff();
        $products = collect(range(1, 7))->map(fn ($index) => $this->product('Product '.$index));
        $data = $this->input($buyer, $products->first());
        $data['items'] = $products->map(fn ($product) => ['product_id' => $product->id, 'quantity' => 1, 'description' => '<script>alert(1)</script> Clear lamp description.'])->all();
        $offer = app(CustomerOfferService::class)->saveDraft($data, $admin);
        $preview = $this->actingAs($admin)->get(route('admin.customer-offers.preview', $offer))->assertOk();
        $this->assertSame(2, substr_count($preview->getContent(), 'data-offer-page="'));
        $this->assertStringNotContainsString('<script>', $preview->getContent());
        $response = $this->actingAs($admin)->get(route('admin.customer-offers.pdf', $offer))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame(2, preg_match_all('~/Type\s*/Page\b~', $response->getContent()));
        Mail::assertNothingSent();
    }

    public function test_safe_mode_records_blocked_attempt_without_mail_or_false_sent_state(): void
    {
        Mail::fake();
        config(['commerce.local_safe_mode' => true, 'mail.default' => 'smtp']);
        app(SystemSettingsService::class)->put('store_email_enabled', true, 'bool');
        [$buyer] = $this->buyer();
        $admin = $this->staff();
        $offer = app(CustomerOfferService::class)->saveDraft($this->input($buyer, $this->product()), $admin);
        $this->actingAs($admin)->post(route('admin.customer-offers.send', $offer), ['confirm_send' => 1])->assertRedirect();
        $this->assertSame('draft', $offer->fresh()->status);
        $this->assertNull($offer->fresh()->sent_at);
        $this->assertDatabaseHas('customer_offer_deliveries', ['customer_offer_id' => $offer->id, 'status' => 'blocked']);
        Mail::assertNothingSent();
    }

    public function test_long_descriptions_and_names_stay_on_the_expected_pdf_pages(): void
    {
        [$buyer] = $this->buyer();
        $admin = $this->staff();
        $product = $this->product(str_repeat('Dugačak naziv ', 14));
        $data = $this->input($buyer, $product);
        $data['intro'] = str_repeat('Uvodni tekst. ', 35);
        $data['notes'] = str_repeat('Napomena o ponudi. ', 50);
        $data['items'] = array_fill(0, 8, ['product_id' => $product->id, 'quantity' => 1, 'description' => str_repeat('Dugačak opis. ', 35)]);
        $offer = app(CustomerOfferService::class)->saveDraft($data, $admin);
        $pdf = app(CustomerOfferPdf::class)->render($offer);
        $this->assertSame(2, preg_match_all('~/Type\s*/Page\b~', $pdf));
    }

    public function test_explicit_send_attaches_pdf_once_locks_snapshot_and_only_owner_sees_it(): void
    {
        Mail::fake();
        config(['commerce.local_safe_mode' => false, 'mail.default' => 'smtp']);
        app(SystemSettingsService::class)->put('store_email_enabled', true, 'bool');
        [$buyer] = $this->buyer();
        [$other] = $this->buyer('Other');
        $admin = $this->staff();
        $product = $this->product();
        $offer = app(CustomerOfferService::class)->saveDraft($this->input($buyer, $product), $admin);
        $this->actingAs($buyer)->get(route('account.customer-offers.pdf', $offer))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.customer-offers.send', $offer), ['confirm_send' => 1])->assertRedirect();
        $this->assertSame('sent', $offer->fresh()->status);
        Mail::assertSent(CustomerOfferMail::class, fn ($mail) => $mail->hasTo($buyer->email) && count($mail->attachments()) === 1);
        $this->actingAs($admin)->post(route('admin.customer-offers.send', $offer), ['confirm_send' => 1])->assertSessionHasErrors('offer');
        $this->actingAs($admin)->put(route('admin.customer-offers.update', $offer), $this->input($buyer, $product, 9))->assertSessionHasErrors('offer');
        Mail::assertSentCount(1);
        $this->assertSame(1, $offer->fresh()->items->first()->quantity);
        $this->actingAs($buyer)->get(route('account.customer-offers.index'))->assertOk()->assertSee($offer->number);
        $this->actingAs($buyer)->get(route('account.customer-offers.pdf', $offer))->assertOk();
        $this->actingAs($other)->get(route('account.customer-offers.pdf', $offer))->assertNotFound();
        $this->actingAs($other)->get(route('account.customer-offers.index'))->assertOk()->assertDontSee($offer->number);
    }

    public function test_changed_customer_email_and_missing_send_confirmation_do_not_send(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        app(SystemSettingsService::class)->put('store_email_enabled', true, 'bool');
        [$buyer] = $this->buyer();
        $admin = $this->staff();
        $offer = app(CustomerOfferService::class)->saveDraft($this->input($buyer, $this->product()), $admin);
        $this->actingAs($admin)->post(route('admin.customer-offers.send', $offer))->assertSessionHasErrors('confirm_send');
        $buyer->update(['email' => 'changed@example.test']);
        $this->actingAs($admin)->post(route('admin.customer-offers.send', $offer), ['confirm_send' => 1, 'recipient' => 'arbitrary@example.test'])->assertSessionHasErrors('offer');
        Mail::assertNothingSent();
    }

    public function test_explicitly_forbidden_admin_cannot_manage_offers(): void
    {
        [$buyer] = $this->buyer();
        $admin = $this->staff();
        Bouncer::forbid($admin)->to('sales.offers.manage');
        Bouncer::refreshFor($admin);
        $this->actingAs($admin)->get(route('admin.customer-offers.create'))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.customer-offers.store'), $this->input($buyer, $this->product()))->assertForbidden();
        $this->assertDatabaseCount('customer_offers', 0);
    }

    public function test_log_fallback_transport_is_blocked_without_a_false_sent_offer(): void
    {
        config(['mail.default' => 'failover', 'commerce.local_safe_mode' => false]);
        app(SystemSettingsService::class)->put('store_email_enabled', true);
        [$buyer] = $this->buyer();
        $admin = $this->staff();
        $offer = app(CustomerOfferService::class)->saveDraft($this->input($buyer, $this->product()), $admin);
        $this->actingAs($admin)->post(route('admin.customer-offers.send', $offer), ['confirm_send' => 1])->assertRedirect();
        $this->assertSame('draft', $offer->fresh()->status);
        $this->assertDatabaseHas('customer_offer_deliveries', ['customer_offer_id' => $offer->id, 'status' => 'blocked']);
        Mail::assertNothingSent();
    }

    public function test_uncertain_transport_failure_locks_delivery_for_review_without_automatic_retry(): void
    {
        config(['mail.default' => 'smtp']);
        app(SystemSettingsService::class)->put('store_email_enabled', true);
        [$buyer] = $this->buyer();
        $admin = $this->staff();
        $offer = app(CustomerOfferService::class)->saveDraft($this->input($buyer, $this->product()), $admin);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Uncertain transport result'));
        $this->actingAs($admin)->post(route('admin.customer-offers.send', $offer), ['confirm_send' => 1])->assertRedirect();
        $this->assertSame('delivery_review', $offer->fresh()->status);
        $this->assertDatabaseHas('customer_offer_deliveries', ['customer_offer_id' => $offer->id, 'status' => 'failed']);
        $this->actingAs($admin)->post(route('admin.customer-offers.send', $offer), ['confirm_send' => 1])->assertSessionHasErrors('offer');
    }

    public function test_image_embedding_rejects_arbitrary_network_urls_and_non_images(): void
    {
        $images = app(CustomerOfferImage::class);
        $this->assertNull($images->dataUri('http://127.0.0.1/private.png'));
        $this->assertNull($images->dataUri('https://attacker.example/image/catalog/a.jpg'));
        $this->assertNull($images->dataUri('file:///etc/passwd'));
        $this->assertNull($images->dataUri(asset('../.env')));
    }

    public function test_variant_price_and_label_are_snapshotted_and_foreign_variant_is_rejected(): void
    {
        [$buyer] = $this->buyer();
        $admin = $this->staff();
        $product = $this->product();
        $other = $this->product('Other product');
        $option = \App\Models\Catalog\Option\Option::query()->create(['code' => 'offer-power', 'type' => 'select', 'is_active' => true]);
        $value = \App\Models\Catalog\Option\OptionValue::query()->create(['option_id' => $option->id, 'code' => '20w', 'is_active' => true]);
        $value->translations()->create(['locale' => 'hr', 'name' => '20 W', 'slug' => '20-w']);
        $product->options()->attach($option->id);
        $variant = $product->optionValues()->create(['option_value_id' => $value->id, 'mode' => 'variant', 'sku' => 'OFFER-20W', 'stock_qty' => 10, 'price_override' => 25.1234, 'is_active' => true, 'combination_hash' => hash('sha256', 'offer-20w')]);
        $input = $this->input($buyer, $product);
        $this->actingAs($admin)->post(route('admin.customer-offers.store'), $input)->assertSessionHasErrors('items.0.product_id');
        $input['items'][0]['product_option_value_id'] = $variant->id;
        $this->actingAs($admin)->post(route('admin.customer-offers.store'), $input)->assertRedirect();
        $item = CustomerOffer::query()->firstOrFail()->items->first();
        $this->assertSame('25.1234', $item->unit_net);
        $this->assertSame('OFFER-20W', $item->sku);
        $this->assertSame('20 W', $item->option_label);
        $input['items'][0]['product_id'] = $other->id;
        $this->actingAs($admin)->post(route('admin.customer-offers.store'), $input)->assertSessionHasErrors('items.0.product_id');
    }

    public function test_local_legacy_catalog_symlink_embeds_image_without_network(): void
    {
        $base = sys_get_temp_dir().'/herrera-offer-image-'.uniqid();
        mkdir($base.'/public/image', 0700, true);
        mkdir($base.'/legacy-catalog', 0700, true);
        symlink($base.'/legacy-catalog', $base.'/public/image/catalog');
        $image = imagecreatetruecolor(2, 2);
        imagepng($image, $base.'/legacy-catalog/photo.png');
        imagedestroy($image);
        $original = public_path();
        app()->usePublicPath($base.'/public');
        try {
            $this->assertStringStartsWith('data:image/jpeg;base64,', app(CustomerOfferImage::class)->dataUri('/image/catalog/photo.png') ?? '');
        } finally {
            app()->usePublicPath($original);
            unlink($base.'/public/image/catalog');
            unlink($base.'/legacy-catalog/photo.png');
            rmdir($base.'/legacy-catalog');
            rmdir($base.'/public/image');
            rmdir($base.'/public');
            rmdir($base);
        }
    }

    private function staff(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->forceFill(['account_type' => 'staff'])->save();
        Bouncer::assign($role)->to($user);
        if ($role === 'order_manager') {
            Bouncer::allow($user)->to(['sales.offers.view', 'sales.offers.manage', 'sales.offers.send']);
        }

        return $user;
    }

    private function buyer(string $company = 'Buyer Example'): array
    {
        $buyer = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'offer-'.uniqid(), 'name' => 'Offer buyers', 'is_active' => true]);
        B2BAccount::query()->create(['user_id' => $buyer->id, 'status' => 'approved', 'company_name' => $company, 'oib' => str_pad((string) $buyer->id, 11, '0', STR_PAD_LEFT), 'customer_group_id' => $group->id, 'payment_terms_days' => 30]);

        return [$buyer, $group];
    }

    private function product(string $name = 'Product Example'): Product
    {
        $tax = TaxRate::query()->firstOrCreate(['code' => 'offer25'], ['name' => 'VAT25', 'rate' => 25, 'rate_type' => 'percent', 'is_active' => true, 'is_default' => true]);
        $product = Product::query()->create(['code' => 'offer-'.uniqid(), 'sku' => 'offer-sku-'.uniqid(), 'base_price' => 100, 'tax_rate_id' => $tax->id, 'is_active' => true, 'stock_qty' => 100]);
        $product->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => Str::slug($name).'-'.$product->id, 'description' => 'A clear product description.']);

        return $product;
    }

    private function input(User $buyer, Product $product, int $quantity = 1): array
    {
        return ['customer_user_id' => $buyer->id, 'title' => 'Example offer', 'locale' => 'hr', 'valid_until' => now()->addDays(14)->format('Y-m-d'),
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]]];
    }
}
