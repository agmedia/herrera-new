<div class="header-cart-content" data-header-cart-content>
    @if ($cartLines->isEmpty())
        <div class="header-cart-empty">
            <span class="header-cart-empty-icon" aria-hidden="true">
                <x-fa-icon name="bag-shopping" />
            </span>
            <p>{{ __('ui.cart.empty') }}</p>
            <a href="{{ route('shop.index') }}" class="header-cart-empty-action">
                {{ __('ui.cart.actions.continue') }}
            </a>
        </div>
    @else
        <div
            class="header-cart-items"
            role="list"
            aria-label="{{ __('ui.cart.preview.items_label') }}"
            tabindex="0"
        >
            @foreach ($cartLines as $line)
                @php
                    $product = $line['product'];
                    $translation = $line['translation'];
                    $productName = (string) ($translation?->name ?? $product->code);
                    $productUrl = route('products.show', ['slug' => $translation?->slug ?? $product->id]);
                    $productImage = $product->getFirstMedia('product_main')
                        ?? $product->getFirstMedia('product_gallery');
                    $productImageUrl = $productImage
                        ? ($productImage->hasGeneratedConversion('thumb_100x100')
                            ? $productImage->getUrl('thumb_100x100')
                            : $productImage->getUrl())
                        : null;
                    $unitPrice = (float) ($line['display_unit_price'] ?? $line['unit_price'] ?? 0);
                    $lineTotal = (float) ($line['display_line_total'] ?? $line['line_total'] ?? 0);
                @endphp

                <article class="header-cart-item" role="listitem">
                    <a href="{{ $productUrl }}" class="header-cart-item-image">
                        @if ($productImageUrl)
                            <img
                                src="{{ $productImageUrl }}"
                                alt="{{ $productName }}"
                                loading="lazy"
                                decoding="async"
                            >
                        @else
                            <span>{{ __('ui.product.no_image') }}</span>
                        @endif
                    </a>

                    <div class="header-cart-item-copy">
                        <a href="{{ $productUrl }}" class="header-cart-item-name">
                            {{ $productName }}
                        </a>

                        @if (!empty($line['option_label']))
                            <p class="header-cart-item-option">{{ $line['option_label'] }}</p>
                        @endif

                        <div class="header-cart-item-meta">
                            <span>{{ (int) $line['quantity'] }} × {{ number_format($unitPrice, 2, ',', '.') }} €</span>
                            <strong>{{ number_format($lineTotal, 2, ',', '.') }} €</strong>
                        </div>
                        <x-front.energy-label-arrow :declaration="$line['energy_declaration'] ?? null" class="mt-1" />
                        <x-front.energy-information-sheet-link :declaration="$line['energy_declaration'] ?? null" class="mt-1" />
                    </div>

                    <form
                        method="POST"
                        action="{{ route('cart.items.destroy', ['product' => $product->id]) }}"
                        class="header-cart-remove-form"
                        data-header-cart-remove
                    >
                        @csrf
                        @method('DELETE')
                        @if (!empty($line['product_option_value_id']))
                            <input type="hidden" name="product_option_value_id" value="{{ (int) $line['product_option_value_id'] }}">
                        @endif
                        <button
                            type="submit"
                            class="header-cart-remove"
                            aria-label="{{ __('ui.cart.table.remove') }}: {{ $productName }}"
                            title="{{ __('ui.cart.table.remove') }}"
                        >
                            <x-fa-icon name="xmark" />
                        </button>
                    </form>
                </article>
            @endforeach
        </div>

        <footer class="header-cart-summary">
            <dl class="header-cart-summary-list">
                <div>
                    <dt>{{ __('ui.cart.summary.shipping') }}</dt>
                    <dd>{{ __('ui.cart.preview.shipping_pending') }}</dd>
                </div>
                <div class="header-cart-summary-total">
                    <dt>{{ __('ui.cart.summary.total') }}</dt>
                    <dd>{{ number_format((float) $cartSummary['grand_total'], 2, ',', '.') }} €</dd>
                </div>
            </dl>

            <a href="{{ route('cart.index') }}" class="header-cart-view-action">
                {{ __('ui.cart.preview.view_cart') }}
            </a>
        </footer>
    @endif
</div>
