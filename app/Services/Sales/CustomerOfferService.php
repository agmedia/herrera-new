<?php

namespace App\Services\Sales;

use App\Mail\CustomerOfferMail;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Offer\CustomerOffer;
use App\Models\Sales\Offer\CustomerOfferDelivery;
use App\Models\User;
use App\Services\Front\StoreSettingsService;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Pricing\TaxPricingService;
use App\Support\Media\LegacyCatalogImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerOfferService
{
    public function __construct(
        private readonly CustomerOfferAccess $access,
        private readonly ProductPricePresentationService $prices,
        private readonly TaxPricingService $tax,
    ) {}

    public function saveDraft(array $input, User $actor, ?CustomerOffer $offer = null): CustomerOffer
    {
        $this->access->assertStaff($actor, 'manage');
        if ($offer) {
            $this->access->assertOffer($offer, $actor, 'manage');
            abort_if(data_get($offer->customer_snapshot, 'demo', false), 422);
        }
        $data = Validator::make($input, [
            'customer_user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:160'], 'locale' => ['required', 'in:hr,en'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'intro' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_option_value_id' => ['nullable', 'integer', 'exists:catalog_product_option_values,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99999'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $customer = User::query()->with(['b2bAccount.customerGroup', 'profile', 'addresses'])->findOrFail($data['customer_user_id']);
        $this->access->assertCustomer($customer, $actor);
        app(B2BAccessService::class)->ensureCanPurchase($customer);
        $products = Product::query()->with(['translations', 'media', 'taxRate', 'categories'])->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
        $lines = [];
        foreach (array_values($data['items']) as $index => $row) {
            $product = $products->get((int) $row['product_id']);
            if (! $product?->is_active) {
                throw ValidationException::withMessages(['items.'.$index.'.product_id' => __('customer_offers.product_unavailable')]);
            }
            $option = empty($row['product_option_value_id']) ? null : ProductOptionValue::query()->findOrFail($row['product_option_value_id']);
            if (($option && ((int) $option->product_id !== (int) $product->id || ! $option->isVisibleOnStorefront()))
                || (! $option && $product->hasVisibleOptionRows())) {
                throw ValidationException::withMessages(['items.'.$index.'.product_id' => __('customer_offers.choose_variant')]);
            }
            $quantity = (int) $row['quantity'];
            $minimum = max(1, (int) $product->minimum_order_quantity);
            $step = max(1, (int) $product->order_quantity_step);
            if ($quantity < $minimum || ($quantity - $minimum) % $step !== 0) {
                throw ValidationException::withMessages(['items.'.$index.'.quantity' => __('customer_offers.quantity_invalid', ['minimum' => $minimum, 'step' => $step])]);
            }
            // Use the selected buyer, quantity and variant's stored base. Absolute
            // catalog prices and promotions follow the same single resolver as cart.
            $storedBase = (float) ($option?->price_override ?? $product->base_price);
            $pricing = $this->prices->forStoredBase($product, $storedBase, $customer, $quantity);
            $translation = $product->translations->firstWhere('locale', $data['locale']) ?? $product->translations->firstWhere('locale', config('app.locale')) ?? $product->translations->first();
            $rate = $this->tax->resolveRateForProduct($product, $customer);
            // Preserve four-decimal B2B unit prices; storefront presentation is
            // rounded for display. The same resolver decides finality, so an
            // imported final price never receives another promotional discount.
            $groupPrice = app(\App\Services\Pricing\ProductGroupPriceResolver::class)->resolve($product, $customer, $quantity, null, $storedBase);
            $storedPrice = (float) ($groupPrice?->price ?? $storedBase);
            if (! $groupPrice?->is_final && ($action = app(\App\Services\Catalog\ActionResolverService::class)->resolveProductAction($product, $customer))) {
                $storedPrice = app(\App\Services\Catalog\ActionResolverService::class)->applyToPrice($storedPrice, $action);
            }
            $taxValue = max(0.0, (float) ($rate?->rate ?? 0));
            $storedUnits = (int) round($storedPrice * 10000);
            $taxUnits = (int) round($taxValue * 10000);
            if ($this->tax->pricesIncludeTax()) {
                $unitGross = $storedUnits / 10000;
                $unitNet = ($rate?->rate_type === 'fixed' ? max(0, $storedUnits - $taxUnits) : round($storedUnits * 1000000 / (1000000 + $taxUnits))) / 10000;
            } else {
                $unitNet = $storedUnits / 10000;
                $unitGross = ($rate?->rate_type === 'fixed' ? $storedUnits + $taxUnits : round($storedUnits * (1000000 + $taxUnits) / 1000000)) / 10000;
            }
            $pricing['offer_unit_net'] = $unitNet;
            $pricing['offer_unit_gross'] = $unitGross;
            $lineNet = round($unitNet * $quantity, 4);
            $lineGross = round($unitGross * $quantity, 4);
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                $description = Str::limit($this->plain((string) ($translation?->excerpt ?: $translation?->description)), 500, '…');
            }
            $lines[] = [
                'product_id' => $product->id, 'product_option_value_id' => $option?->id,
                'name' => $translation?->name ?: ($product->sku ?: $product->code),
                'sku' => $option?->sku ?: $product->sku, 'code' => $product->code,
                'option_label' => $option ? trim(collect([$option->parentOptionValue, $option->optionValue])->filter()->map(fn ($value) => $value->translations->firstWhere('locale', $data['locale'])?->name ?? $value->translations->first()?->name)->filter()->implode(' / ')) : null,
                'description' => $description, 'image_url' => LegacyCatalogImage::first($product),
                'quantity' => $quantity, 'unit' => Product::unitOptions()[$product->unit_of_measure] ?? $product->unit_of_measure ?: 'kom',
                'unit_net' => $unitNet, 'unit_gross' => $unitGross,
                'tax_rate' => (float) ($rate?->rate ?? 0), 'tax_type' => $rate?->rate_type ?: 'percent',
                'line_net' => $lineNet, 'line_tax' => round($lineGross - $lineNet, 4), 'line_gross' => $lineGross,
                'pricing_snapshot' => $pricing, 'sort_order' => $index,
            ];
        }

        return DB::transaction(function () use ($offer, $data, $actor, $customer, $lines): CustomerOffer {
            $draft = $offer ? CustomerOffer::query()->lockForUpdate()->findOrFail($offer->id) : new CustomerOffer;
            if ($draft->exists && $draft->status !== 'draft') {
                throw ValidationException::withMessages(['offer' => __('customer_offers.immutable')]);
            }
            $account = $customer->b2bAccount;
            $draft->fill([
                'number' => $draft->number ?: 'PON-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'customer_user_id' => $customer->id, 'created_by' => $draft->created_by ?: $actor->id,
                'title' => $this->plain($data['title']), 'locale' => $data['locale'], 'valid_until' => $data['valid_until'],
                'intro' => $this->plain((string) ($data['intro'] ?? '')), 'notes' => $this->plain((string) ($data['notes'] ?? '')),
                'currency_code' => 'EUR', 'status' => 'draft', 'priced_at' => now(),
                'customer_snapshot' => [
                    'name' => $customer->name, 'company' => $customer->customerDisplayName(), 'email' => $customer->email,
                    'oib' => $account?->oib, 'address' => $account?->address_line_1,
                    'postal_code' => $account?->postal_code, 'city' => $account?->city, 'country' => $account?->country_code,
                    'payment_terms_days' => $account?->payment_terms_days,
                ],
                'net_total' => array_sum(array_column($lines, 'line_net')), 'tax_total' => array_sum(array_column($lines, 'line_tax')),
                'gross_total' => array_sum(array_column($lines, 'line_gross')),
            ])->save();
            $draft->items()->delete();
            $draft->items()->createMany($lines);

            return $draft->load('items');
        });
    }

    /** Explicit action only. Preview/download never calls this method. */
    public function send(CustomerOffer $offer, User $actor, CustomerOfferPdf $pdf): CustomerOfferDelivery
    {
        $this->access->assertOffer($offer, $actor, 'send');
        abort_if(data_get($offer->customer_snapshot, 'demo', false), 422);
        $delivery = DB::transaction(function () use ($offer, $actor): CustomerOfferDelivery {
            $locked = CustomerOffer::query()->lockForUpdate()->findOrFail($offer->id);
            if ($locked->status !== 'draft' || $locked->valid_until->endOfDay()->isPast()) {
                throw ValidationException::withMessages(['offer' => __('customer_offers.cannot_send')]);
            }
            app(B2BAccessService::class)->ensureCanPurchase($locked->customer);
            $recipient = (string) data_get($locked->customer_snapshot, 'email');
            if (! filter_var($recipient, FILTER_VALIDATE_EMAIL) || strcasecmp($recipient, (string) $locked->customer->email) !== 0) {
                throw ValidationException::withMessages(['offer' => __('customer_offers.email_changed')]);
            }
            $blocked = (bool) config('commerce.local_safe_mode') || ! (bool) data_get(app(StoreSettingsService::class)->email(), 'enabled')
                || ! $this->realMailTransport((string) config('mail.default'));
            $delivery = $locked->deliveries()->create([
                'sent_by' => $actor->id, 'recipient' => $recipient, 'status' => $blocked ? 'blocked' : 'sending',
                'error_code' => $blocked ? 'delivery_disabled' : null, 'completed_at' => $blocked ? now() : null,
            ]);
            if (! $blocked) {
                $locked->update(['status' => 'sending']);
            }

            return $delivery;
        });
        if ($delivery->status === 'blocked') {
            return $delivery;
        }
        try {
            $offer->refresh()->load('items');
            Mail::to($delivery->recipient)->locale($offer->locale)->send(new CustomerOfferMail($offer, $pdf->render($offer)));
            DB::transaction(function () use ($offer, $delivery): void {
                $offer->update(['status' => 'sent', 'sent_at' => now()]);
                $delivery->update(['status' => 'sent', 'completed_at' => now()]);
            });
        } catch (\Throwable $exception) {
            // Keep the offer locked after an uncertain delivery; a mail transport
            // can have accepted a message before reporting failure. No silent retry.
            $offer->update(['status' => 'delivery_review']);
            $delivery->update(['status' => 'failed', 'error_code' => 'delivery_failed', 'completed_at' => now()]);
            Log::warning('Customer offer delivery needs review', ['offer_id' => $offer->id, 'delivery_id' => $delivery->id, 'exception' => $exception::class]);
        }

        return $delivery->refresh();
    }

    private function plain(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
    }

    private function realMailTransport(string $mailer, array $seen = []): bool
    {
        if (in_array($mailer, $seen, true)) {
            return false;
        }
        $seen[] = $mailer;
        $transport = (string) config('mail.mailers.'.$mailer.'.transport');
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = (array) config('mail.mailers.'.$mailer.'.mailers', []);

            return $children !== [] && collect($children)->every(fn ($child) => $this->realMailTransport((string) $child, $seen));
        }

        return in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true);
    }
}
