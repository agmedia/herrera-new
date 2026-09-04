@php
    $productCode = trim((string) ($product->sku ?: $product->code ?: '—'));
    $brandName = trim((string) ($manufacturerTranslation?->name ?? ''));
    $categoryName = trim((string) ($firstCategoryTranslation?->name ?? ''));
    $productAvailable = $product->storefrontIsPurchasable();
@endphp

<div class="product-purchase-information" data-product-purchase-information>
    <details class="product-information-panel" open>
        <summary class="product-information-summary">
            <span class="product-information-summary-icon">
                <x-fa-icon name="circle-info" />
            </span>
            <span class="product-information-summary-label">{{ __('ui.product.basic_information') }}</span>
            <span class="product-information-summary-toggle" aria-hidden="true">
                <x-fa-icon name="plus" class="product-information-icon-plus" />
                <x-fa-icon name="minus" class="product-information-icon-minus" />
            </span>
        </summary>
        <div class="product-information-content">
            <dl class="product-information-list">
                <div class="product-information-row">
                    <dt>{{ __('ui.product.sku') }}</dt>
                    <dd data-product-sku-value>{{ $productCode }}</dd>
                </div>
                @if ($brandName !== '')
                    <div class="product-information-row">
                        <dt>{{ __('ui.product.brand') }}</dt>
                        <dd>{{ $brandName }}</dd>
                    </div>
                @endif
                @if ($categoryName !== '')
                    <div class="product-information-row">
                        <dt>{{ __('ui.product.category') }}</dt>
                        <dd>{{ $categoryName }}</dd>
                    </div>
                @endif
                <div class="product-information-row">
                    <dt>{{ __('ui.product.availability') }}</dt>
                    <dd class="{{ $productAvailable ? 'is-available' : 'is-unavailable' }}">
                        {{ $productAvailable ? __('ui.product.available') : __('ui.product.unavailable') }}
                    </dd>
                </div>
            </dl>
        </div>
    </details>

    <details class="product-information-panel">
        <summary class="product-information-summary">
            <span class="product-information-summary-icon">
                <x-fa-icon name="truck-fast" />
            </span>
            <span class="product-information-summary-label">{{ __('ui.product.shipping_methods') }}</span>
            <span class="product-information-summary-toggle" aria-hidden="true">
                <x-fa-icon name="plus" class="product-information-icon-plus" />
                <x-fa-icon name="minus" class="product-information-icon-minus" />
            </span>
        </summary>
        <div class="product-information-content">
            @forelse (($shippingMethods ?? collect()) as $method)
                <article class="product-method">
                    <h3>{{ $method->name }}</h3>
                    @if (trim((string) $method->description) !== '')
                        <p>{{ $method->description }}</p>
                    @endif
                    <div class="product-method-meta">
                        @if (strtolower((string) $method->pricing_type) === 'quote')
                            <span>{{ __('ui.product.shipping_quote') }}</span>
                        @elseif (strtolower((string) $method->pricing_type) === 'weight_tiers')
                            <span>{{ __('ui.product.shipping_calculated_at_checkout') }}</span>
                        @elseif ((float) $method->price <= 0)
                            <span>{{ __('ui.product.shipping_free') }}</span>
                        @else
                            <span>{{ __('ui.product.shipping_price', ['price' => number_format((float) $method->price, 2).' €']) }}</span>
                        @endif
                        @if ($method->free_over !== null)
                            <span>{{ __('ui.product.shipping_free_over', ['amount' => number_format((float) $method->free_over, 2).' €']) }}</span>
                        @endif
                    </div>
                </article>
            @empty
                <p class="product-method-empty">{{ __('ui.product.no_shipping_methods') }}</p>
            @endforelse
        </div>
    </details>

    <details class="product-information-panel">
        <summary class="product-information-summary">
            <span class="product-information-summary-icon">
                <x-fa-icon name="credit-card" />
            </span>
            <span class="product-information-summary-label">{{ __('ui.product.payment_methods') }}</span>
            <span class="product-information-summary-toggle" aria-hidden="true">
                <x-fa-icon name="plus" class="product-information-icon-plus" />
                <x-fa-icon name="minus" class="product-information-icon-minus" />
            </span>
        </summary>
        <div class="product-information-content">
            @forelse (($paymentMethods ?? collect()) as $method)
                <article class="product-method">
                    <h3>{{ $method->name }}</h3>
                    @if (trim((string) $method->description) !== '')
                        <p>{{ $method->description }}</p>
                    @endif
                    @if ((float) $method->fee_value > 0)
                        <div class="product-method-meta">
                            <span>
                                {{ __('ui.product.payment_fee', [
                                    'fee' => $method->fee_type === 'percent'
                                        ? number_format((float) $method->fee_value, 2).'%'
                                        : number_format((float) $method->fee_value, 2).' €',
                                ]) }}
                            </span>
                        </div>
                    @endif
                </article>
            @empty
                <p class="product-method-empty">{{ __('ui.product.no_payment_methods') }}</p>
            @endforelse
        </div>
    </details>
</div>
