@php
    $floatingCartFormId = 'product-detail-cart-form-'.$product->id;
    $floatingCartName = trim((string) ($translation?->name ?? $product->code));
    $floatingCartImage = trim((string) (($gallery->first()['full'] ?? '') ?: ''));
@endphp

@if ($isPurchasable)
    <aside
        class="product-floating-cart"
        data-product-floating-cart
        data-cart-form-id="{{ $floatingCartFormId }}"
        aria-label="{{ __('ui.product.add_to_cart') }}"
        aria-hidden="true"
    >
        <div class="product-floating-cart-shell">
            <div class="product-floating-cart-product">
                @if ($floatingCartImage !== '')
                    <img
                        src="{{ $floatingCartImage }}"
                        alt=""
                        width="72"
                        height="72"
                        loading="lazy"
                        class="product-floating-cart-image"
                    >
                @endif

                <p class="product-floating-cart-name" title="{{ $floatingCartName }}">
                    {{ $floatingCartName }}
                </p>
            </div>

            <p class="product-floating-cart-price" data-product-price-current>
                {{ $productPriceData['current'] }}
            </p>

            <div class="product-detail-quantity-control product-floating-cart-quantity">
                <button type="button" data-product-floating-qty-dec aria-label="{{ __('ui.cart.modal.quantity') }} -">-</button>
                <input
                    type="text"
                    value="1"
                    inputmode="numeric"
                    readonly
                    tabindex="-1"
                    aria-label="{{ __('ui.cart.modal.quantity') }}"
                    data-product-floating-qty-input
                >
                <button type="button" data-product-floating-qty-inc aria-label="{{ __('ui.cart.modal.quantity') }} +">+</button>
            </div>

            <button
                type="submit"
                form="{{ $floatingCartFormId }}"
                class="product-detail-action product-detail-cart-button product-floating-cart-action"
                aria-label="{{ __('ui.product.add_to_cart') }}"
                data-product-floating-submit
            >
                <x-fa-icon name="bag-shopping" class="product-floating-cart-icon" />
                <span>{{ __('ui.product.add_to_cart') }}</span>
            </button>
        </div>
    </aside>
@endif
