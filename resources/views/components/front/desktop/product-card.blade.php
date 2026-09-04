@php
    $hasOptionErrorForCard = (int) old('product_id', 0) === $productId && $errors->has('product_option_value_id');
@endphp

<article class="group w-full min-w-0 overflow-hidden {{ $lined ? 'product-card-lined flex h-full flex-col bg-white p-0' : ($flat ? 'bg-white p-0' : 'rounded-2xl bg-white p-5 shadow-sm') }}" data-product-card data-product-id="{{ $productId }}" @if ($lined) data-product-card-lined @endif>
    <div class="relative {{ $flat ? 'overflow-hidden' : '-mt-5 overflow-hidden rounded-t-2xl' }}">
        <a href="{{ $productUrl }}" class="group block">
            @if ($imageUrl)
                <div class="relative overflow-hidden bg-slate-50 {{ $flat ? '' : 'rounded-xl' }}" data-product-card-image-frame>
                    <img
                        src="{{ $imageUrl }}"
                        @if (!empty($imageSrcset)) srcset="{{ $imageSrcset }}" @endif
                        sizes="{{ $imageSizes }}"
                        alt="{{ $productName }}"
                        class="block h-full w-full object-contain object-center {{ $hoverImageUrl ? 'transition-opacity duration-300 group-hover:opacity-0' : '' }}"
                        width="{{ (int) $imageWidth }}"
                        height="{{ (int) $imageHeight }}"
                        loading="{{ $priorityImage ? 'eager' : 'lazy' }}"
                        @if ($priorityImage) fetchpriority="high" @endif
                        decoding="async"
                    >
                    @if ($hoverImageUrl)
                        <img
                            src="{{ $hoverImageUrl }}"
                            @if (!empty($hoverImageSrcset)) srcset="{{ $hoverImageSrcset }}" @endif
                            sizes="{{ $imageSizes }}"
                            alt="{{ $productName }}"
                            class="absolute inset-0 h-full w-full object-contain object-center opacity-0 transition-opacity duration-300 group-hover:opacity-100"
                            width="{{ (int) $hoverImageWidth }}"
                            height="{{ (int) $hoverImageHeight }}"
                            loading="lazy"
                            decoding="async"
                        >
                    @endif
                </div>
            @else
                <div class="{{ $flat ? 'flex w-full items-center justify-center bg-slate-100 text-xs font-semibold uppercase text-slate-500' : 'flex w-full items-center justify-center rounded-xl bg-slate-100 text-xs font-semibold uppercase text-slate-500' }}" data-product-card-image-frame>
                    {{ __('ui.product.no_image') }}
                </div>
            @endif
        </a>
        <form
            method="POST"
            action="{{ route('wishlist.items.toggle', ['product' => $productId]) }}"
            class="absolute right-2 top-2"
            data-wishlist-form
            data-product-id="{{ $productId }}"
            data-wishlisted="{{ $isWishlisted ? '1' : '0' }}"
            data-label-add="{{ __('ui.wishlist.add') }}"
            data-label-remove="{{ __('ui.wishlist.remove') }}"
            data-msg-failed="{{ __('ui.wishlist.status.failed') }}"
        >
            @csrf
            <button
                type="submit"
                class="product-card-wishlist inline-flex h-9 w-9 items-center justify-center transition {{ $isWishlisted ? 'is-active' : '' }}"
                data-wishlist-button
                aria-label="{{ $isWishlisted ? __('ui.wishlist.remove') : __('ui.wishlist.add') }}"
            >
                <x-fa-icon name="heart" style="{{ $isWishlisted ? 'solid' : 'regular' }}" class="h-5 w-5" />
            </button>
        </form>
        @if (! empty($discountPercent))
            <span class="absolute left-2 top-2 inline-flex h-7 items-center border border-rose-600 bg-rose-600 px-2 text-xs font-bold text-white">
                -{{ $discountPercent }}%
            </span>
        @endif

        @if ($isPurchasable && ! $lined)
            <form
                method="POST"
                action="{{ route('cart.items.store') }}"
                class="pointer-events-none absolute inset-x-0 bottom-0 z-20 space-y-2.5 bg-black/80 p-2.5 opacity-0 transition-all duration-200 {{ $hasOptionErrorForCard ? 'opacity-100 pointer-events-auto' : '' }}"
                data-card-overlay
                data-product-card-form
                data-auto-submit-on-option="{{ $optionRows !== [] ? '1' : '0' }}"
                data-ga4-add-to-cart-form
                data-ga4-item-id="{{ $productSku }}"
                data-ga4-item-name="{{ $productName }}"
                data-ga4-item-price="{{ number_format((float) $productPriceValue, 2, '.', '') }}"
                data-ga4-item-brand="{{ $productBrand }}"
                data-ga4-item-category="{{ $productCategory }}"
                data-ga4-currency="EUR"
                data-product-name="{{ $productName }}"
                data-product-image="{{ (string) ($cartImageUrl ?? $imageUrl ?? '') }}"
                data-cart-url="{{ route('cart.index') }}"
                data-modal-title="{{ __('ui.cart.modal.title') }}"
                data-modal-close="{{ __('Close') }}"
                data-modal-continue="{{ __('ui.cart.modal.continue') }}"
                data-modal-go-cart="{{ __('ui.cart.modal.go_to_cart') }}"
                data-modal-option="{{ __('ui.cart.modal.option') }}"
                data-modal-quantity="{{ __('ui.cart.modal.quantity') }}"
            >
                @csrf
                <input type="hidden" name="product_id" value="{{ $productId }}">
                @if ($optionRows !== [])
                    <div class="w-full">
                        <div class="mb-1.5 flex flex-wrap gap-1.5">
                            @foreach ($optionRows as $row)
                                <label class="inline-flex cursor-pointer">
                                    <input
                                        type="radio"
                                        name="product_option_value_id"
                                        value="{{ $row['id'] }}"
                                        class="sr-only product-size-radio"
                                        data-option-label="{{ $row['label'] }}"
                                    >
                                    <span class="product-size-label-text inline-flex h-8 min-w-8 items-center justify-center border border-white/55 bg-transparent px-2 text-[11px] font-semibold text-white transition hover:border-white hover:bg-white/10">
                                        {{ $row['label'] }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <p
                            class="{{ $hasOptionErrorForCard ? '' : 'hidden' }} text-xs font-semibold text-rose-300"
                            data-option-error
                            aria-live="polite"
                        >
                            {{ __('ui.cart.errors.select_size') }}
                        </p>
                    </div>
                @endif
                <div class="flex items-center gap-1.5">
                    <div class="inline-flex h-8 items-stretch bg-transparent" data-qty-control>
                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center border border-white/55 text-sm font-semibold text-white hover:border-white hover:bg-white/10" data-qty-dec aria-label="{{ __('ui.cart.modal.quantity') }} -">
                            <x-fa-icon name="minus" class="h-3 w-3" />
                        </button>
                        <input type="text" name="quantity" value="1" inputmode="numeric" readonly aria-label="{{ __('ui.cart.modal.quantity') }}" class="h-8 w-8 border-y border-r border-white/55 border-l-0 bg-transparent p-0 text-center text-xs font-semibold text-white focus:ring-0" data-qty-input data-qty-value>
                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center border border-white/55 text-sm font-semibold text-white hover:border-white hover:bg-white/10" data-qty-inc aria-label="{{ __('ui.cart.modal.quantity') }} +">
                            <x-fa-icon name="plus" class="h-3 w-3" />
                        </button>
                    </div>
                    @if ($optionRows === [])
                        <button type="submit" class="inline-flex h-8 min-w-0 flex-1 items-center justify-center gap-1.5 whitespace-nowrap border border-white/55 bg-transparent px-2.5 text-xs font-semibold text-white hover:border-white hover:bg-white/10 sm:text-sm" aria-label="{{ __('ui.product.to_cart') }}">
                            <x-fa-icon name="bag-shopping" class="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4" />
                            <span class="truncate">{{ __('ui.product.to_cart') }}</span>
                        </button>
                    @endif
                </div>
            </form>
        @endif
    </div>

    @if ($flat && $lined)
        <div class="product-card-lined-content flex flex-1 flex-col px-3 pb-3 pt-3 sm:px-4 sm:pb-4">
            @if ((int) ($reviewSummary['count'] ?? 0) > 0)
                <div class="mb-1.5">
                    @include('front.partials.product-review-summary', [
                        'count' => (int) ($reviewSummary['count'] ?? 0),
                        'average' => (float) ($reviewSummary['avg'] ?? 0),
                        'href' => $productUrl.'#product-comments',
                        'size' => 'compact',
                    ])
                </div>
            @endif
            <a href="{{ $productUrl }}" class="block">
                @if ($headingLevel === 2)
                    <h2 class="text-[12px] font-extrabold uppercase leading-[1.25] text-slate-900 sm:text-[13px]">{{ $productName }}</h2>
                @else
                    <h3 class="text-[12px] font-extrabold uppercase leading-[1.25] text-slate-900 sm:text-[13px]">{{ $productName }}</h3>
                @endif
            </a>
            @if ($productBrand !== '')
                <p class="mt-1 text-[11px] font-bold uppercase leading-tight text-slate-400 sm:text-[12px]">{{ $productBrand }}</p>
            @endif
            @if ($materialLabel !== '')
                <p class="mt-1 text-[10px] leading-tight text-slate-500 sm:text-[11px]">{{ $materialLabel }}</p>
            @endif
            <div class="product-card-lined-price mt-auto pt-4">
                <p class="text-[11px] leading-none {{ $isB2BPrice ? 'font-semibold text-cyan-800' : 'text-slate-700' }}">
                    {{ $isB2BPrice ? __('ui.product.b2b_contract_price') : __('ui.shop.filters.price') }}
                </p>
                <div class="mt-1 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    @if (! empty($oldPrice))
                        <p class="text-[11px] leading-none text-slate-500 line-through sm:text-[12px]">{{ $oldPrice }}</p>
                    @endif
                    <p class="text-[14px] font-extrabold leading-none text-slate-900 sm:text-[15px]">{{ $price }}</p>
                    <x-front.energy-label-arrow :declaration="$energyDeclaration ?? null" />
                    <x-front.energy-information-sheet-link :declaration="$energyDeclaration ?? null" />
                </div>
                @if (! empty($lowest30DaysPrice))
                    <p class="mt-1 text-[9px] leading-tight text-slate-500 sm:text-[10px]">{{ __('ui.product.lowest_price_30_days', ['price' => $lowest30DaysPrice]) }}</p>
                @endif
            </div>

            @if ($isPurchasable)
                <form
                    method="POST"
                    action="{{ route('cart.items.store') }}"
                    class="product-card-lined-form mt-3"
                    data-product-card-form
                    data-auto-submit-on-option="0"
                    data-ga4-add-to-cart-form
                    data-ga4-item-id="{{ $productSku }}"
                    data-ga4-item-name="{{ $productName }}"
                    data-ga4-item-price="{{ number_format((float) $productPriceValue, 2, '.', '') }}"
                    data-ga4-item-brand="{{ $productBrand }}"
                    data-ga4-item-category="{{ $productCategory }}"
                    data-ga4-currency="EUR"
                    data-product-name="{{ $productName }}"
                    data-product-image="{{ (string) ($cartImageUrl ?? $imageUrl ?? '') }}"
                    data-cart-url="{{ route('cart.index') }}"
                    data-modal-title="{{ __('ui.cart.modal.title') }}"
                    data-modal-close="{{ __('Close') }}"
                    data-modal-continue="{{ __('ui.cart.modal.continue') }}"
                    data-modal-go-cart="{{ __('ui.cart.modal.go_to_cart') }}"
                    data-modal-option="{{ __('ui.cart.modal.option') }}"
                    data-modal-quantity="{{ __('ui.cart.modal.quantity') }}"
                >
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $productId }}">
                    @if ($optionRows !== [])
                        <div class="mb-2 w-full">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($optionRows as $row)
                                    <label class="inline-flex cursor-pointer">
                                        <input
                                            type="radio"
                                            name="product_option_value_id"
                                            value="{{ $row['id'] }}"
                                            class="sr-only product-size-radio"
                                            data-option-label="{{ $row['label'] }}"
                                        >
                                        <span class="product-size-label-text inline-flex h-8 min-w-8 items-center justify-center border border-slate-300 bg-white px-2 text-[11px] font-semibold text-slate-800 transition">
                                            {{ $row['label'] }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="{{ $hasOptionErrorForCard ? '' : 'hidden' }} mt-1 text-xs font-semibold text-rose-600" data-option-error aria-live="polite">
                                {{ __('ui.cart.errors.select_size') }}
                            </p>
                        </div>
                    @endif
                    <div class="product-card-lined-controls flex items-stretch gap-2">
                        <div class="product-card-lined-qty border border-slate-300 bg-white" data-qty-control>
                            <button type="button" class="inline-flex h-full items-center justify-center text-slate-900 transition hover:bg-slate-100" data-qty-dec aria-label="{{ __('ui.cart.modal.quantity') }} -">
                                <x-fa-icon name="minus" class="h-3 w-3" />
                            </button>
                            <label class="flex min-w-0 flex-col items-center justify-center border-x border-slate-300">
                                <input type="text" name="quantity" value="1" inputmode="numeric" readonly aria-label="{{ __('ui.cart.modal.quantity') }}" class="h-full w-full border-0 bg-transparent p-0 text-center text-xs font-semibold text-slate-900 focus:ring-0" data-qty-input data-qty-value>
                            </label>
                            <button type="button" class="inline-flex h-full items-center justify-center text-slate-900 transition hover:bg-slate-100" data-qty-inc aria-label="{{ __('ui.cart.modal.quantity') }} +">
                                <x-fa-icon name="plus" class="h-3 w-3" />
                            </button>
                        </div>
                        <button type="submit" class="product-card-lined-cart inline-flex shrink-0 items-center justify-center transition" aria-label="{{ __('ui.product.add_to_cart') }}" title="{{ __('ui.product.add_to_cart') }}">
                            <x-fa-icon name="bag-shopping" class="h-4 w-4" />
                        </button>
                    </div>
                </form>
            @else
                <span class="mt-3 inline-flex min-h-12 items-center justify-center border border-slate-200 bg-slate-100 px-3 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                    {{ __('ui.product.unavailable') }}
                </span>
            @endif
        </div>
    @elseif ($flat)
        <div class="relative mt-3 px-2 pb-3">
            @if ((int) ($reviewSummary['count'] ?? 0) > 0)
                <div class="mb-1.5 pr-12">
                    @include('front.partials.product-review-summary', [
                        'count' => (int) ($reviewSummary['count'] ?? 0),
                        'average' => (float) ($reviewSummary['avg'] ?? 0),
                        'href' => $productUrl.'#product-comments',
                        'size' => 'compact',
                    ])
                </div>
            @endif
            <a href="{{ $productUrl }}" class="block pr-12">
                @if ($headingLevel === 2)
                    <h2 class="text-[14px] font-medium leading-tight text-slate-900 sm:text-[15px]">{{ $productName }}</h2>
                @else
                    <h3 class="text-[14px] font-medium leading-tight text-slate-900 sm:text-[15px]">{{ $productName }}</h3>
                @endif
            </a>
            @if ($materialLabel !== '')
                <p class="mt-1 pr-12 text-[11px] leading-tight text-slate-500 sm:text-[12px]">{{ $materialLabel }}</p>
            @endif
            @if ($isPurchasable)
                <button
                    type="button"
                    class="absolute right-2 top-0 inline-flex h-8 w-8 shrink-0 items-center justify-center border border-slate-900 bg-white text-slate-900 transition hover:bg-slate-100 sm:h-9 sm:w-9"
                    data-card-overlay-toggle
                    aria-expanded="{{ $hasOptionErrorForCard ? 'true' : 'false' }}"
                    aria-label="{{ __('ui.product.add_to_cart') }}"
                >
                    <x-fa-icon name="bag-shopping" class="h-4 w-4 sm:h-5 sm:w-5" />
                </button>
            @else
                <span class="absolute right-2 top-0 inline-flex min-h-8 items-center justify-center border border-slate-200 bg-slate-100 px-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500 sm:min-h-9">
                    {{ __('ui.product.unavailable') }}
                </span>
            @endif
            <div class="mt-1.5 flex flex-col gap-1">
                @if ($isB2BPrice)
                    <p class="text-[10px] font-semibold leading-tight text-cyan-800 sm:text-[11px]">{{ __('ui.product.b2b_contract_price') }}</p>
                @endif
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    @if (! empty($oldPrice))
                        <p class="text-[11px] leading-none text-slate-500 line-through sm:text-[13px]">{{ $oldPrice }}</p>
                    @endif
                    <p class="text-[13px] font-bold leading-none text-slate-900 sm:text-[15px]">{{ $price }}</p>
                    <x-front.energy-label-arrow :declaration="$energyDeclaration ?? null" />
                    <x-front.energy-information-sheet-link :declaration="$energyDeclaration ?? null" />
                </div>
                @if (! empty($lowest30DaysPrice))
                    <p class="text-[10px] leading-tight text-slate-500 sm:text-[11px]">{{ __('ui.product.lowest_price_30_days', ['price' => $lowest30DaysPrice]) }}</p>
                @endif
            </div>
        </div>
    @else
        @if ((int) ($reviewSummary['count'] ?? 0) > 0)
            <div class="mt-4">
                @include('front.partials.product-review-summary', [
                    'count' => (int) ($reviewSummary['count'] ?? 0),
                    'average' => (float) ($reviewSummary['avg'] ?? 0),
                    'href' => $productUrl.'#product-comments',
                    'size' => 'compact',
                ])
            </div>
        @endif
        <a href="{{ $productUrl }}" class="{{ (int) ($reviewSummary['count'] ?? 0) > 0 ? 'mt-2 block' : 'mt-4 block' }}">
            @if ($headingLevel === 2)
                <h2 class="text-[14px] font-medium leading-tight text-slate-900 sm:text-[15px]">{{ $productName }}</h2>
            @else
                <h3 class="text-[14px] font-medium leading-tight text-slate-900 sm:text-[15px]">{{ $productName }}</h3>
            @endif
        </a>
        @if ($materialLabel !== '')
            <p class="mt-1 text-[11px] leading-tight text-slate-500 sm:text-[12px]">{{ $materialLabel }}</p>
        @endif
        <div class="mt-2 flex items-end justify-between">
            <div class="flex flex-col gap-1">
                @if ($isB2BPrice)
                    <p class="text-[10px] font-semibold leading-tight text-cyan-800 sm:text-[11px]">{{ __('ui.product.b2b_contract_price') }}</p>
                @endif
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    @if (! empty($oldPrice))
                        <p class="text-[11px] leading-none text-slate-500 line-through sm:text-[13px]">{{ $oldPrice }}</p>
                    @endif
                    <p class="text-[13px] font-bold leading-none text-slate-900 sm:text-[15px]">{{ $price }}</p>
                    <x-front.energy-label-arrow :declaration="$energyDeclaration ?? null" />
                    <x-front.energy-information-sheet-link :declaration="$energyDeclaration ?? null" />
                </div>
                @if (! empty($lowest30DaysPrice))
                    <p class="text-[10px] leading-tight text-slate-500 sm:text-[11px]">{{ __('ui.product.lowest_price_30_days', ['price' => $lowest30DaysPrice]) }}</p>
                @endif
            </div>
            @if ($isPurchasable)
                <button
                    type="button"
                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center self-end border border-slate-900 bg-white text-slate-900 transition hover:bg-slate-100 sm:h-9 sm:w-9"
                    data-card-overlay-toggle
                    aria-expanded="{{ $hasOptionErrorForCard ? 'true' : 'false' }}"
                    aria-label="{{ __('ui.product.add_to_cart') }}"
                >
                    <x-fa-icon name="bag-shopping" class="h-4 w-4 sm:h-5 sm:w-5" />
                </button>
            @else
                <span class="inline-flex min-h-8 shrink-0 items-center justify-center self-end border border-slate-200 bg-slate-100 px-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500 sm:min-h-9">
                    {{ __('ui.product.unavailable') }}
                </span>
            @endif
        </div>
    @endif

</article>

@once
    @push('scripts')
        @include('front.partials.cart-modal-script')
        <script defer src="{{ asset('front-theme/scripts/product-card-cart-modal.js') }}?v={{ filemtime(public_path('front-theme/scripts/product-card-cart-modal.js')) }}"></script>
    @endpush
@endonce
