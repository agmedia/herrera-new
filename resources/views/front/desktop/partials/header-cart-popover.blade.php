<section
    id="header-cart-popover"
    class="header-cart-popover"
    aria-label="{{ __('ui.cart.preview.label') }}"
    aria-hidden="true"
    data-header-cart-popover
    data-preview-url="{{ route('cart.preview') }}"
>
    @include('front.desktop.partials.header-cart-popover-content', [
        'cartLines' => $cartLines,
        'cartSummary' => $cartSummary,
    ])
</section>
