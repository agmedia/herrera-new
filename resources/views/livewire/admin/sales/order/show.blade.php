@php
    $statusClasses = [
        'blue' => 'bg-blue-100 text-blue-800',
        'emerald' => 'bg-emerald-100 text-emerald-800',
        'green' => 'bg-emerald-100 text-emerald-800',
        'rose' => 'bg-rose-100 text-rose-800',
        'red' => 'bg-rose-100 text-rose-800',
        'amber' => 'bg-amber-100 text-amber-800',
        'yellow' => 'bg-amber-100 text-amber-800',
        'violet' => 'bg-violet-100 text-violet-800',
        'purple' => 'bg-violet-100 text-violet-800',
        'slate' => 'bg-slate-200 text-slate-700',
        'gray' => 'bg-slate-200 text-slate-700',
        'cyan' => 'bg-cyan-100 text-cyan-800',
    ];

    $statusColor = strtolower((string) ($order->status?->color ?? 'slate'));
    $statusClass = $statusClasses[$statusColor] ?? $statusClasses['slate'];
    $loyaltySettlement = $loyaltyEnabled
        ? $order->loyaltyTransactions->first(fn ($row) => $row->type === 'order_settlement')
        : null;
    $loyaltyRedemption = $loyaltyEnabled
        ? $order->loyaltyTransactions->first(fn ($row) => $row->type === 'order_redemption')
        : null;
    $visibleTotals = $loyaltyEnabled
        ? $order->totals
        : $order->totals->reject(fn ($total) => $total->code === 'loyalty_redemption');
    $boxNow = is_array($order->payload['shipping']['boxnow'] ?? null) ? $order->payload['shipping']['boxnow'] : null;
    $glsPoint = is_array($order->payload['shipping']['gls_dpm'] ?? null) ? $order->payload['shipping']['gls_dpm'] : null;
    $shippingDestination = is_array($order->payload['shipping']['destination'] ?? null) ? $order->payload['shipping']['destination'] : null;
    $glsState = is_array($order->payload['gls'] ?? null) ? $order->payload['gls'] : [];
    $glsShipment = is_array($glsState['shipment'] ?? null) ? $glsState['shipment'] : [];
    $glsLastError = is_array($glsState['last_error'] ?? null) ? $glsState['last_error'] : [];
    $isGls = \App\Support\GlsShipping::isGlsShippingMethod((string) $order->shipping_method_code);
@endphp

<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Sales / Orders') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Order') }} {{ $order->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('Review snapshot data, adjust status, and keep timeline notes.') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusClass }}">{{ $order->status?->name ?? __('Unknown') }}</span>
                <span class="admin-chip">{{ \App\Support\Currency::format((float) $order->grand_total, $order->currency_code) }}</span>
                <a
                    href="{{ route('admin.orders.invoice', ['order' => $order->id]) }}"
                    target="_blank"
                    class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                >
                    {{ __('Invoice / Print') }}
                </a>
                <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Back to List') }}</button>
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="admin-panel admin-form-panel p-6 xl:col-span-2">
            <p class="admin-section-title">{{ __('Order Snapshot') }}</p>

            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 4;">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Customer') }}</p>
                    <p class="mt-1 text-sm font-semibold text-slate-900">{{ $order->customer_name }}</p>
                    <p class="text-xs text-slate-600">{{ $order->customer_email }}</p>
                    @if ($order->customer_phone)
                        <p class="text-xs text-slate-600">{{ $order->customer_phone }}</p>
                    @endif
                </div>
                <div style="grid-column: span 4;">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Payment') }}</p>
                    <p class="mt-1 text-sm text-slate-800">{{ $order->payment_method_name ?: '-' }}</p>
                    <p class="text-xs text-slate-600">{{ $order->payment_method_code ?: '-' }}</p>
                    @if (!empty($bankTransfer) && !empty($bankTransfer['receiver_iban']))
                        <div class="mt-2 space-y-1 rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs text-slate-700">
                            <p><strong>{{ __('Recipient') }}:</strong> {{ $bankTransfer['receiver_name'] ?? '-' }}</p>
                            <p><strong>IBAN:</strong> {{ $bankTransfer['receiver_iban'] ?? '-' }}</p>
                            <p><strong>{{ __('Model') }}:</strong> {{ $bankTransfer['model'] ?? '-' }}</p>
                            <p><strong>{{ __('Reference') }}:</strong> {{ $bankTransfer['reference'] ?? '-' }}</p>
                            <p><strong>{{ __('Amount') }}:</strong> {{ $order->currency_code }} {{ number_format((float) ($bankTransfer['amount'] ?? 0), 2) }}</p>
                        </div>
                        @if (!empty($bankTransfer['qr_image_base64']))
                            <img
                                src="data:{{ $bankTransfer['qr_image_mime'] ?? 'image/png' }};base64,{{ $bankTransfer['qr_image_base64'] }}"
                                alt="UPI QR"
                                class="mt-2 h-auto w-full max-w-[210px] rounded border border-slate-200 bg-white p-1"
                            >
                        @endif
                    @endif
                </div>
                <div style="grid-column: span 4;">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Shipping') }}</p>
                    <p class="mt-1 text-sm text-slate-800">{{ $order->shipping_method_name ?: '-' }}</p>
                    <p class="text-xs text-slate-600">{{ $order->shipping_method_code ?: '-' }}</p>
                    @if (!empty($boxNow['locker_id']))
                        <p class="mt-1 text-xs text-slate-700"><strong>BOX NOW Locker:</strong> {{ $boxNow['locker_name'] ?: '-' }} ({{ $boxNow['locker_id'] }})</p>
                        @if (!empty($boxNow['address_line_1']) || !empty($boxNow['postal_code']) || !empty($boxNow['city']))
                            <p class="text-xs text-slate-600">{{ trim(($boxNow['address_line_1'] ?? '').', '.($boxNow['postal_code'] ?? '').' '.($boxNow['city'] ?? ''), ', ') }}</p>
                        @endif
                    @endif
                    @if (!empty($glsPoint['id']))
                        <p class="mt-1 text-xs text-slate-700"><strong>GLS lokacija:</strong> {{ $glsPoint['name'] ?: '-' }} ({{ $glsPoint['id'] }})</p>
                        @if (!empty($glsPoint['address_line_1']) || !empty($glsPoint['postal_code']) || !empty($glsPoint['city']))
                            <p class="text-xs text-slate-600">{{ trim(($glsPoint['address_line_1'] ?? '').', '.($glsPoint['postal_code'] ?? '').' '.($glsPoint['city'] ?? ''), ', ') }}</p>
                        @endif
                    @endif
                    @if ($shippingDestination)
                        @php
                            $destinationScopeLabel = match ($shippingDestination['scope'] ?? null) {
                                'hr_mainland' => __('Kopno'),
                                'hr_islands' => __('Otok'),
                                default => __('Nije klasificirano'),
                            };
                            $destinationPolicyLabel = match ($shippingDestination['policy'] ?? null) {
                                'all_islands' => __('svi otoci'),
                                'unconnected_only' => __('samo otoci bez cestovne veze'),
                                default => __('nepoznato pravilo'),
                            };
                        @endphp
                        <div class="mt-2 rounded-lg border border-cyan-200 bg-cyan-50 p-2 text-xs text-slate-700">
                            <p><strong>{{ __('Odredište') }}:</strong> {{ $destinationScopeLabel }}</p>
                            @if (!empty($shippingDestination['island']))
                                <p><strong>{{ __('Otok') }}:</strong> {{ $shippingDestination['island'] }}</p>
                            @endif
                            <p><strong>{{ __('Pravilo') }}:</strong> {{ $destinationPolicyLabel }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Billing Address') }}</p>
                    <div class="mt-2 text-sm text-slate-700">
                        <p>{{ trim(($order->billing_first_name ?? '').' '.($order->billing_last_name ?? '')) ?: '-' }}</p>
                        @if ($order->billing_company)<p>{{ $order->billing_company }}</p>@endif
                        <p>{{ $order->billing_address_line_1 ?: '-' }}</p>
                        @if ($order->billing_address_line_2)<p>{{ $order->billing_address_line_2 }}</p>@endif
                        <p>{{ trim(($order->billing_postal_code ?? '').' '.($order->billing_city ?? '')) ?: '-' }}</p>
                        <p>{{ $order->billing_country_code ?: '-' }}</p>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Shipping Address') }}</p>
                    <div class="mt-2 text-sm text-slate-700">
                        <p>{{ trim(($order->shipping_first_name ?? '').' '.($order->shipping_last_name ?? '')) ?: '-' }}</p>
                        @if ($order->shipping_company)<p>{{ $order->shipping_company }}</p>@endif
                        <p>{{ $order->shipping_address_line_1 ?: '-' }}</p>
                        @if ($order->shipping_address_line_2)<p>{{ $order->shipping_address_line_2 }}</p>@endif
                        <p>{{ trim(($order->shipping_postal_code ?? '').' '.($order->shipping_city ?? '')) ?: '-' }}</p>
                        <p>{{ $order->shipping_country_code ?: '-' }}</p>
                    </div>
                </div>
            </div>

            @if (!empty($boxNow['locker_id']))
                <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-blue-700">BOX NOW</p>
                    <div class="mt-2 text-sm text-slate-800">
                        <p><strong>{{ __('Locker') }}:</strong> {{ $boxNow['locker_name'] ?: '-' }} ({{ $boxNow['locker_id'] }})</p>
                        <p><strong>{{ __('Address') }}:</strong> {{ trim(($boxNow['address_line_1'] ?? '').', '.($boxNow['postal_code'] ?? '').' '.($boxNow['city'] ?? ''), ', ') ?: '-' }}</p>
                    </div>
                </div>
            @endif

            @if ($isGls)
                <div class="mt-4 rounded-xl border border-cyan-200 bg-cyan-50 p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-cyan-800">GLS pošiljka</p>
                            @if (!empty($glsPoint['id']))
                                <p class="mt-2 text-sm text-slate-800"><strong>Lokacija:</strong> {{ $glsPoint['name'] ?: '-' }} ({{ $glsPoint['id'] }})</p>
                                <p class="text-xs text-slate-600">{{ trim(($glsPoint['address_line_1'] ?? '').', '.($glsPoint['postal_code'] ?? '').' '.($glsPoint['city'] ?? ''), ', ') }}</p>
                            @endif
                            @if (!empty($glsShipment['parcel_number']))
                                <p class="mt-2 text-sm text-slate-800"><strong>Broj paketa:</strong> {{ $glsShipment['parcel_number'] }}</p>
                                <p class="text-xs text-slate-600">
                                    {{ strtoupper((string) ($glsShipment['mode'] ?? 'test')) }}
                                    @if (!empty($glsShipment['sent_at']))
                                        · {{ \Illuminate\Support\Carbon::parse($glsShipment['sent_at'])->format('d.m.Y. H:i') }}
                                    @endif
                                </p>
                            @else
                                <p class="mt-2 text-sm text-slate-700">Pošiljka još nije poslana u GLS.</p>
                            @endif
                            @if (!empty($glsLastError['message']))
                                <p class="mt-2 text-xs font-semibold text-rose-700">{{ $glsLastError['message'] }}</p>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('admin.orders.gls.send', $order) }}">
                                @csrf
                                <button type="submit" class="rounded-lg bg-cyan-700 px-3 py-2 text-xs font-semibold text-white hover:bg-cyan-800">
                                    {{ !empty($glsShipment['parcel_number']) ? __('Ponovno generiraj naljepnicu') : __('Pošalji u GLS') }}
                                </button>
                            </form>
                            @if (!empty($glsShipment['label_path']))
                                <a href="{{ route('admin.orders.gls.label', $order) }}" class="rounded-lg border border-cyan-300 bg-white px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100">
                                    {{ __('Preuzmi PDF naljepnicu') }}
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if ($order->customer_note || $order->admin_note)
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Customer Note') }}</p>
                        <p class="mt-2 text-sm text-slate-700">{{ $order->customer_note ?: '-' }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Latest Admin Note') }}</p>
                        <p class="mt-2 text-sm text-slate-700">{{ $order->admin_note ?: '-' }}</p>
                    </div>
                </div>
            @endif
        </div>

        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Status Workflow') }}</p>
            <div class="mt-4 space-y-3">
                @if ($quickStatuses->isNotEmpty())
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Quick Actions') }}</label>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach ($quickStatuses as $quickStatus)
                                <button
                                    type="button"
                                    wire:click="quickStatusByCode('{{ $quickStatus->code }}')"
                                    class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.08em] text-slate-700 hover:bg-slate-100"
                                >
                                    {{ __('Mark') }} {{ $quickStatus->name }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Target Status') }}</label>
                    <select wire:model="form.status_id" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($statuses as $statusItem)
                            <option value="{{ $statusItem->id }}">{{ $statusItem->name }}</option>
                        @endforeach
                    </select>
                    @error('form.status_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Timeline Note') }}</label>
                    <textarea rows="5" wire:model="form.comment" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('Optional note for this status update...') }}"></textarea>
                    @error('form.comment') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="admin-form-actions flex items-center gap-2 pt-2">
                    <button type="button" wire:click="updateStatus" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                        {{ __('Save Status Update') }}
                    </button>
                </div>
            </div>

            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                <p><strong>{{ __('Placed:') }}</strong> {{ optional($order->placed_at ?: $order->created_at)->format('Y-m-d H:i') }}</p>
                <p class="mt-1"><strong>{{ __('Paid At:') }}</strong> {{ optional($order->paid_at)->format('Y-m-d H:i') ?: '-' }}</p>
                <p class="mt-1"><strong>{{ __('Source:') }}</strong> {{ $order->source }}</p>
                @if ($loyaltyEnabled)
                    <p class="mt-1">
                        <strong>{{ __('Loyalty Settlement:') }}</strong>
                        @if ($loyaltySettlement)
                            {{ (int) $loyaltySettlement->points }} {{ __('points') }}
                        @else
                            -
                        @endif
                    </p>
                    <p class="mt-1">
                        <strong>{{ __('Loyalty Redemption:') }}</strong>
                        @if ($loyaltyRedemption)
                            {{ abs((int) $loyaltyRedemption->points) }} {{ __('points') }}
                        @else
                            -
                        @endif
                    </p>
                @endif
            </div>

            @if ($loyaltyEnabled)
                <div class="mt-4 rounded-xl border border-slate-200 bg-white p-3">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Loyalty Redemption') }}</label>
                    @if ($order->user_id)
                        <div class="grid gap-2" style="grid-template-columns: 1fr auto;">
                            <input
                                type="number"
                                min="0"
                                wire:model="redeemPoints"
                                class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                                placeholder="{{ __('Points to redeem...') }}"
                            />
                            <button type="button" wire:click="applyLoyaltyRedemption" class="rounded-xl bg-cyan-700 px-3 py-2 text-xs font-semibold text-white hover:bg-cyan-800">
                                {{ __('Apply') }}
                            </button>
                        </div>
                        @error('redeemPoints') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        <div class="mt-2 text-xs text-slate-600">
                            <p>{{ __('Available:') }} <strong>{{ $availableLoyaltyPoints ?? 0 }}</strong> {{ __('pts') }}</p>
                            <p>{{ __('Max redeemable on this order:') }} <strong>{{ $maxRedeemablePoints }}</strong> {{ __('pts') }}</p>
                            <p>{{ __('Value per point:') }} <strong>{{ \App\Support\Currency::format((float) $currencyValuePerPoint, $order->currency_code, 4) }}</strong></p>
                        </div>
                    @else
                        <p class="text-xs text-slate-600">{{ __('Assign user to order before redemption can be used.') }}</p>
                    @endif
                </div>
            @endif

            <div class="mt-4">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Internal Tags') }}</label>
                <div class="mb-2 flex flex-wrap items-center gap-1.5">
                    @forelse ($internalTags as $tag)
                        <button
                            type="button"
                            wire:click='removeInternalTag(@js($tag))'
                            class="inline-flex items-center gap-1 rounded-full border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-100"
                            title="{{ __('Remove tag') }}"
                        >
                            <span>{{ $tag }}</span>
                            <span class="text-slate-500">×</span>
                        </button>
                    @empty
                        <span class="text-xs text-slate-500">{{ __('No tags yet.') }}</span>
                    @endforelse
                </div>
                <div class="flex items-center gap-2">
                    <input
                        type="text"
                        wire:model.defer="tagInput"
                        class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                        placeholder="{{ __('e.g. priority, call-customer, waiting-stock') }}"
                    />
                    <button type="button" wire:click="addInternalTag" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        {{ __('Add') }}
                    </button>
                </div>
                @error('tagInput') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="admin-panel admin-panel-soft p-5 xl:col-span-2">
            <h2 class="admin-section-title">{{ __('Order Items') }}</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="admin-items-table min-w-full text-sm">
                    <thead class="text-slate-600">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">{{ __('Item') }}</th>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('Qty') }}</th>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('Unit') }}</th>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('Discount') }}</th>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('Tax') }}</th>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('Line Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($order->items as $item)
                            <tr>
                                <td class="px-3 py-2 text-slate-800">
                                    <div class="font-medium">{{ $item->name }}</div>
                                    <div class="text-xs text-slate-500">
                                        {{ $item->code ?: '-' }} @if($item->sku) / {{ $item->sku }} @endif
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center text-slate-700">{{ $item->quantity }}</td>
                                <td class="px-3 py-2 text-center text-slate-700">{{ \App\Support\Currency::format((float) $item->unit_price, $order->currency_code) }}</td>
                                <td class="px-3 py-2 text-center text-slate-700">{{ \App\Support\Currency::format((float) $item->discount_amount, $order->currency_code) }}</td>
                                <td class="px-3 py-2 text-center text-slate-700">{{ \App\Support\Currency::format((float) $item->tax_amount, $order->currency_code) }}</td>
                                <td class="px-3 py-2 text-center font-semibold text-slate-800">{{ \App\Support\Currency::format((float) $item->line_total, $order->currency_code) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-6 text-center text-sm text-slate-500">{{ __('No items recorded.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="admin-panel admin-panel-soft p-5">
            <h2 class="admin-section-title">{{ __('Totals') }}</h2>
            <div class="mt-4 space-y-2">
                @forelse ($visibleTotals as $total)
                    @php
                        $totalLabelMap = [
                            'subtotal' => __('ui.account.order_show.totals.labels.subtotal'),
                            'shipping' => __('ui.account.order_show.totals.labels.shipping'),
                            'payment_fee' => __('ui.account.order_show.totals.labels.payment_fee'),
                            'tax' => __('ui.account.order_show.totals.labels.tax'),
                            'loyalty_redemption' => __('Loyalty Redemption'),
                            'grand_total' => __('ui.account.order_show.totals.labels.grand_total'),
                        ];
                        $totalLabelRaw = trim((string) ($total->title ?? ''));
                        $isCustomLoyaltyLabel = (string) ($total->code ?? '') === 'loyalty_redemption'
                            && strcasecmp($totalLabelRaw, 'Loyalty Redemption') !== 0;
                        $totalLabel = $isCustomLoyaltyLabel
                            ? $totalLabelRaw
                            : ($totalLabelMap[(string) ($total->code ?? '')] ?? $totalLabelRaw);
                    @endphp
                    <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                        <span class="text-slate-700">{{ $totalLabel }}</span>
                        <span class="font-semibold text-slate-900">{{ \App\Support\Currency::format((float) $total->value, $order->currency_code) }}</span>
                    </div>
                @empty
                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500">{{ __('No total rows saved.') }}</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="admin-panel admin-panel-soft p-5">
            <h2 class="admin-section-title">{{ __('Status Timeline') }}</h2>
            <div class="mt-4 space-y-2">
                @forelse ($order->history as $entry)
                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                        <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
                            <span>{{ optional($entry->created_at)->format('Y-m-d H:i') }}</span>
                            <span>{{ $entry->changedBy?->name ?: __('System') }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-700">
                            {{ $entry->fromStatus?->name ?: '-' }}
                            <span class="px-1">-&gt;</span>
                            {{ $entry->toStatus?->name ?: '-' }}
                        </p>
                        @if ($entry->comment)
                            <p class="mt-1 text-xs text-slate-600">{{ $entry->comment }}</p>
                        @endif
                    </div>
                @empty
                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500">{{ __('No history rows yet.') }}</div>
                @endforelse
            </div>
        </div>

        <div class="admin-panel admin-panel-soft p-5">
            <h2 class="admin-section-title">{{ __('Transactions') }}</h2>
            <div class="mt-4 space-y-2">
                @forelse ($order->transactions as $transaction)
                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                        <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
                            <span>{{ optional($transaction->processed_at ?: $transaction->created_at)->format('Y-m-d H:i') }}</span>
                            <span>{{ $transaction->provider }}</span>
                        </div>
                        <p class="mt-1 text-sm font-semibold text-slate-800">{{ \App\Support\Currency::format((float) $transaction->amount, $transaction->currency_code) }}</p>
                        <p class="text-xs text-slate-600">{{ $transaction->status }} @if($transaction->transaction_ref) / {{ $transaction->transaction_ref }} @endif</p>
                    </div>
                @empty
                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500">{{ __('No transactions recorded.') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
