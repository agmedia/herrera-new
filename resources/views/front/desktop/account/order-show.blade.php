@extends('front.desktop.layouts.store')

@section('title', __('ui.account.order_show.page_title', ['number' => $order->order_number]))
@section('body_class', 'commerce-body account-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    @php
        $boxNow = is_array($order->payload['shipping']['boxnow'] ?? null) ? $order->payload['shipping']['boxnow'] : null;
        $glsPoint = is_array($order->payload['shipping']['gls_dpm'] ?? null) ? $order->payload['shipping']['gls_dpm'] : null;
        $loyaltyAvailable = app(\App\Services\Loyalty\LoyaltyService::class)->availableForUser($order->user_id);
        $visibleTotals = $loyaltyAvailable
            ? $order->totals
            : $order->totals->reject(fn ($total) => $total->code === 'loyalty_redemption');
    @endphp

    <section class="front-soft-hero mb-8 px-4 py-6 text-center sm:px-6">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.account.order_show.title', ['number' => $order->order_number]) }}</h1>
        <p class="mt-2 text-slate-600">{{ __('ui.account.order_show.status') }}: <span class="font-semibold">{{ $order->status?->name ?? __('ui.account.orders.status_new') }}</span></p>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.account.order_show.placed_at') }}: {{ optional($order->placed_at ?? $order->created_at)->format('Y-m-d H:i') }}</p>
        @if ($b2bAccount?->contractIsActive())
            <form method="POST" action="{{ route('account.orders.reorder', ['orderNumber' => $order->order_number]) }}" class="mt-4">
                @csrf
                <button type="submit" class="commerce-primary-action px-4 py-2.5 text-sm font-semibold">{{ __('Ponovi cijelu narudžbu') }}</button>
            </form>
        @endif
    </section>

    <div class="account-layout">
        @include('front.desktop.account.partials.nav', ['current' => 'order_show'])

        <div class="min-w-0 space-y-6">
            @php
                $visitedStatusIds = collect([$order->status_id])
                    ->merge($order->history->pluck('to_status_id'))
                    ->filter()
                    ->map(static fn ($id) => (int) $id)
                    ->unique()
                    ->values();
                $statusDateMap = [];
                foreach ($order->history->sortBy('id') as $historyEntry) {
                    $statusId = (int) ($historyEntry->to_status_id ?? 0);
                    if ($statusId > 0 && ! isset($statusDateMap[$statusId])) {
                        $statusDateMap[$statusId] = optional($historyEntry->created_at)->format('d.m.Y H:i');
                    }
                }
            @endphp
            @if (($statusSteps ?? collect())->isNotEmpty())
                <section class="border border-slate-200 bg-white p-8">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.order_show.progress.title') }}</h3>
                    <div class="relative mt-7">
                        <span class="absolute left-5 right-5 top-4 h-px bg-slate-200"></span>
                        <ol class="relative grid grid-cols-2 gap-x-4 gap-y-6 md:grid-cols-4">
                            @foreach ($statusSteps as $step)
                                @php
                                    $passed = $visitedStatusIds->contains((int) $step->id);
                                    $isCurrent = (int) $order->status_id === (int) $step->id;
                                    $stepDate = $statusDateMap[(int) $step->id] ?? null;
                                @endphp
                                <li class="flex flex-col items-center text-center">
                                    <span class="relative z-10 inline-flex h-8 w-8 items-center justify-center rounded-full border-2 {{ $isCurrent ? 'border-slate-900 bg-slate-900' : ($passed ? 'border-slate-700 bg-white' : 'border-slate-300 bg-white') }}">
                                        @if ($isCurrent)
                                            <span class="h-2 w-2 rounded-full bg-white"></span>
                                        @elseif ($passed)
                                            <svg class="h-3.5 w-3.5 text-slate-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.2 7.26a1 1 0 0 1-1.42 0l-3.8-3.83a1 1 0 1 1 1.42-1.408l3.09 3.115 6.49-6.544a1 1 0 0 1 1.414-.007Z" clip-rule="evenodd"/>
                                            </svg>
                                        @endif
                                    </span>
                                    <span class="mt-3 text-xs font-semibold uppercase tracking-wide leading-none {{ $isCurrent ? 'text-slate-900' : ($passed ? 'text-slate-700' : 'text-slate-500') }}">
                                        {{ $step->name }}
                                    </span>
                                    @if ($passed && $stepDate)
                                        <span class="mt-1 text-[11px] font-medium text-slate-500">{{ $stepDate }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </section>
            @endif

            <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                <section class="border border-slate-200 bg-white p-6">
                    <h2 class="text-xl font-bold text-slate-900">{{ __('ui.account.order_show.timeline.title') }}</h2>
                    <ul class="mt-4 space-y-3 text-sm text-slate-700">
                        @forelse ($order->history as $entry)
                            @php
                                $noteMap = [
                                    'Order placed from storefront checkout.' => __('ui.account.order_show.timeline.notes.order_placed_storefront'),
                                    'Order status updated from admin.' => __('ui.account.order_show.timeline.notes.status_updated_admin'),
                                    'Quick action: marked as paid from order detail.' => __('ui.account.order_show.timeline.notes.quick_paid'),
                                    'Quick action: marked as cancelled from order detail.' => __('ui.account.order_show.timeline.notes.quick_cancelled'),
                                ];
                                $entryComment = $entry->comment ? ($noteMap[$entry->comment] ?? $entry->comment) : null;
                            @endphp
                            <li class="border border-slate-200 bg-slate-50 px-3 py-2">
                                <span class="font-semibold">{{ optional($entry->created_at)->format('Y-m-d H:i') }}</span>
                                {{ __('ui.account.order_show.timeline.to') }} {{ $entry->toStatus?->name ?? __('ui.account.order_show.timeline.status_updated') }}
                                @if ($entryComment)
                                    <div class="mt-1 text-slate-600">{{ $entryComment }}</div>
                                @endif
                            </li>
                        @empty
                            <li class="text-slate-500">{{ __('ui.account.order_show.timeline.empty') }}</li>
                        @endforelse
                    </ul>
                </section>

                <section class="border border-slate-200 bg-white p-6">
                    <div class="space-y-2 text-sm">
                        <h2 class="text-lg font-semibold text-slate-900">{{ __('ui.account.order_show.totals.title') }}</h2>
                        @foreach ($visibleTotals as $total)
                            @php
                                $labelRaw = trim((string) $total->title);
                                $labelKey = strtolower(str_replace([' ', '-'], '_', $labelRaw));
                                $labelMap = [
                                    'subtotal' => __('ui.account.order_show.totals.labels.subtotal'),
                                    'shipping' => __('ui.account.order_show.totals.labels.shipping'),
                                    'payment_fee' => __('ui.account.order_show.totals.labels.payment_fee'),
                                    'tax' => __('ui.account.order_show.totals.labels.tax'),
                                    'loyalty_redemption' => __('Loyalty Redemption'),
                                    'grand_total' => __('ui.account.order_show.totals.labels.grand_total'),
                                ];
                                $totalLabel = $labelMap[$labelKey] ?? $labelRaw;
                            @endphp
                            <div class="flex items-center justify-between">
                                <dt class="text-slate-600">{{ $totalLabel }}</dt>
                                <dd class="font-semibold text-slate-900">{{ \App\Support\Currency::format((float) $total->value, $order->currency_code) }}</dd>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            @if (!empty($boxNow['locker_id']))
                <section class="border border-blue-200 bg-blue-50 p-6">
                    <h2 class="text-lg font-semibold text-slate-900">{{ __('ui.account.order_show.boxnow.title') }}</h2>
                    <p class="mt-2 break-words text-sm text-slate-700"><strong>{{ __('ui.account.order_show.boxnow.locker') }}:</strong> {{ $boxNow['locker_name'] ?: '-' }} ({{ $boxNow['locker_id'] }})</p>
                    <p class="mt-1 break-words text-sm text-slate-700"><strong>{{ __('ui.account.order_show.boxnow.address') }}:</strong> {{ trim(($boxNow['address_line_1'] ?? '').', '.($boxNow['postal_code'] ?? '').' '.($boxNow['city'] ?? ''), ', ') ?: '-' }}</p>
                </section>
            @endif

            @if (!empty($glsPoint['id']))
                <section class="border border-cyan-200 bg-cyan-50 p-6">
                    <h2 class="text-lg font-semibold text-slate-900">{{ __('GLS paketomat / ParcelShop') }}</h2>
                    <p class="mt-2 break-words text-sm text-slate-700"><strong>{{ __('Lokacija') }}:</strong> {{ $glsPoint['name'] ?: '-' }} ({{ $glsPoint['id'] }})</p>
                    <p class="mt-1 break-words text-sm text-slate-700"><strong>{{ __('Adresa') }}:</strong> {{ trim(($glsPoint['address_line_1'] ?? '').', '.($glsPoint['postal_code'] ?? '').' '.($glsPoint['city'] ?? ''), ', ') ?: '-' }}</p>
                </section>
            @endif

            <section class="overflow-hidden border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-4 py-3">
                    <h2 class="text-lg font-semibold text-slate-900">{{ __('ui.account.order_show.ordered_items') }}</h2>
                </div>
                <div class="overflow-x-auto lg:overflow-visible">
                    <table class="w-full min-w-[760px] text-sm lg:min-w-0">
                        <thead class="bg-slate-100/70 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">{{ __('ui.account.order_show.table.item') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.order_show.table.price') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.order_show.table.qty') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.order_show.table.total') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.orders.table.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($order->items as $item)
                            @php
                                $product = $item->product;
                                $productTranslation = $product?->translations?->firstWhere('locale', app()->getLocale())
                                    ?? $product?->translations?->firstWhere('locale', config('app.locale'))
                                    ?? $product?->translations?->first();
                                $productUrl = $product && $product->is_active && $productTranslation?->slug
                                    ? route('products.show', ['slug' => $productTranslation->slug])
                                    : null;
                                $valueTranslation = $item->productOptionValue?->optionValue?->translations?->firstWhere('locale', app()->getLocale())
                                    ?? $item->productOptionValue?->optionValue?->translations?->firstWhere('locale', config('app.locale'))
                                    ?? $item->productOptionValue?->optionValue?->translations?->first();
                                $valueOptionTranslation = $item->productOptionValue?->optionValue?->option?->translations?->firstWhere('locale', app()->getLocale())
                                    ?? $item->productOptionValue?->optionValue?->option?->translations?->firstWhere('locale', config('app.locale'))
                                    ?? $item->productOptionValue?->optionValue?->option?->translations?->first();
                                $parentTranslation = $item->productOptionValue?->parentOptionValue?->translations?->firstWhere('locale', app()->getLocale())
                                    ?? $item->productOptionValue?->parentOptionValue?->translations?->firstWhere('locale', config('app.locale'))
                                    ?? $item->productOptionValue?->parentOptionValue?->translations?->first();
                                $parentOptionTranslation = $item->productOptionValue?->parentOptionValue?->option?->translations?->firstWhere('locale', app()->getLocale())
                                    ?? $item->productOptionValue?->parentOptionValue?->option?->translations?->firstWhere('locale', config('app.locale'))
                                    ?? $item->productOptionValue?->parentOptionValue?->option?->translations?->first();
                                $parentOptionName = trim((string) ($parentOptionTranslation?->name ?? ''));
                                $parentValueName = trim((string) ($parentTranslation?->name ?? ''));
                                $valueOptionName = trim((string) ($valueOptionTranslation?->name ?? ''));
                                $valueName = trim((string) ($valueTranslation?->name ?? ''));
                                $optionParts = [];
                                if ($parentOptionName !== '' && $parentValueName !== '') {
                                    $optionParts[] = $parentOptionName.': '.$parentValueName;
                                }
                                if ($valueOptionName !== '' && $valueName !== '') {
                                    $optionParts[] = $valueOptionName.': '.$valueName;
                                }
                                $optionLabel = $optionParts !== [] ? implode(' / ', $optionParts) : ($valueName !== '' ? $valueName : $parentValueName);
                                $productImage = $product?->media?->firstWhere('collection_name', 'product_main')
                                    ?? $product?->media?->firstWhere('collection_name', 'product_gallery')
                                    ?? $product?->getFirstMedia('product_main')
                                    ?? $product?->getFirstMedia('product_gallery');
                                $productImageUrl = $productImage
                                    ? ($productImage->hasGeneratedConversion('thumb_100x100') ? $productImage->getUrl('thumb_100x100') : $productImage->getUrl())
                                    : null;
                            @endphp
                            <tr class="border-t border-slate-200">
                                <td class="px-4 py-4">
                                    <div class="flex items-start gap-3">
                                        @if ($productUrl)
                                            <a href="{{ $productUrl }}" class="block w-16 shrink-0 border border-slate-200 bg-slate-50 p-1">
                                                @if ($productImageUrl)
                                                    <img src="{{ $productImageUrl }}" alt="{{ $item->name }}" class="h-auto w-full" loading="lazy" decoding="async">
                                                @else
                                                    <span class="flex h-full w-full items-center justify-center text-[10px] font-semibold uppercase text-slate-500">{{ __('ui.product.no_image') }}</span>
                                                @endif
                                            </a>
                                        @else
                                            <div class="block w-16 shrink-0 border border-slate-200 bg-slate-50 p-1">
                                                @if ($productImageUrl)
                                                    <img src="{{ $productImageUrl }}" alt="{{ $item->name }}" class="h-auto w-full" loading="lazy" decoding="async">
                                                @else
                                                    <span class="flex h-full w-full items-center justify-center text-[10px] font-semibold uppercase text-slate-500">{{ __('ui.product.no_image') }}</span>
                                                @endif
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            @if ($productUrl)
                                                <a href="{{ $productUrl }}" class="break-words font-semibold text-slate-900 hover:text-blue-700">{{ $item->name }}</a>
                                            @else
                                                <span class="break-words font-semibold text-slate-900">{{ $item->name }}</span>
                                            @endif
                                            @if ($optionLabel !== '')
                                                <p class="mt-1 text-xs text-slate-500">{{ $optionLabel }}</p>
                                            @endif
                                            @if ($item->sku)
                                                <p class="mt-1 text-xs text-slate-500">SKU: {{ $item->sku }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">{{ \App\Support\Currency::format((float) $item->unit_price, $order->currency_code) }}</td>
                                <td class="px-4 py-4">{{ $item->quantity }}</td>
                                <td class="px-4 py-4 font-semibold">{{ \App\Support\Currency::format((float) $item->line_total, $order->currency_code) }}</td>
                                <td class="px-4 py-4">
                                    @if ($product && $product->is_active)
                                        <form method="POST" action="{{ route('cart.items.store') }}" class="inline-flex">
                                            @csrf
                                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                                            @if ($item->product_option_value_id)
                                                <input type="hidden" name="product_option_value_id" value="{{ (int) $item->product_option_value_id }}">
                                            @endif
                                            <input type="hidden" name="quantity" value="{{ max(1, (int) $item->quantity) }}">
                                            <button type="submit" class="commerce-secondary-action px-3 text-xs uppercase tracking-wide">
                                                {{ __('ui.account.order_show.reorder') }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
@endsection
