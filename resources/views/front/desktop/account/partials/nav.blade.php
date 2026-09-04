@php
    $current = $current ?? 'dashboard';
    try {
        $loyaltyEnabled = app(\App\Services\Loyalty\LoyaltyService::class)->availableForUser(auth()->user());
    } catch (\Throwable) {
        $loyaltyEnabled = false;
    }

    $items = [
        [
            'key' => 'dashboard',
            'label' => __('ui.account.nav.dashboard'),
            'url' => route('account.dashboard'),
            'active' => $current === 'dashboard',
        ],
        [
            'key' => 'orders',
            'label' => __('ui.account.nav.orders'),
            'url' => route('account.orders'),
            'active' => $current === 'orders' || $current === 'order_show',
        ],
        [
            'key' => 'profile',
            'label' => __('ui.account.nav.edit_account'),
            'url' => route('account.profile'),
            'active' => $current === 'profile',
        ],
    ];

    $accountUser = auth()->user();
    $accountUser?->loadMissing('b2bAccount');
    if ($accountUser?->b2bAccount?->contractIsActive()) {
        array_splice($items, 2, 0, [
            [
                'key' => 'b2b_quick_order',
                'label' => __('B2B brza kupnja'),
                'url' => route('account.b2b.quick-order'),
                'active' => $current === 'b2b_quick_order',
            ],
            [
                'key' => 'b2b_frequent_products',
                'label' => __('Često naručivani artikli'),
                'url' => route('account.b2b.frequent-products'),
                'active' => $current === 'b2b_frequent_products',
            ],
            [
                'key' => 'b2b_favorite_products',
                'label' => __('Favoriti'),
                'url' => route('account.b2b.favorite-products'),
                'active' => $current === 'b2b_favorite_products',
            ],
        ]);
    }

    if ($loyaltyEnabled) {
        $items[] = [
            'key' => 'loyalty',
            'label' => __('ui.account.nav.loyalty'),
            'url' => route('account.loyalty'),
            'active' => $current === 'loyalty',
        ];
    }
@endphp

<aside class="commerce-account-nav min-w-0 h-fit self-start border border-slate-200 bg-white lg:sticky lg:top-28">
    <div class="border-b border-slate-200 px-4 py-3">
        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">{{ __('ui.account.nav.title') }}</h2>
    </div>
    <nav class="p-3">
        <ul class="space-y-2">
            @foreach ($items as $item)
                <li>
                    <a
                        href="{{ $item['url'] }}"
                        class="commerce-account-nav-link flex items-center justify-between border px-4 py-3 text-sm font-semibold transition {{ $item['active'] ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 text-slate-800 hover:bg-slate-100' }}"
                        @if ($item['active']) aria-current="page" @endif
                    >
                        <span class="min-w-0 break-words">{{ $item['label'] }}</span>
                        @if ($item['active'])
                            <span aria-hidden="true">•</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        <form method="POST" action="{{ route('logout') }}" class="mt-4">
            @csrf
            <button type="submit" class="flex min-h-12 w-full items-center justify-between rounded-[3px] border border-rose-200 px-4 py-3 text-sm font-semibold text-rose-700 transition hover:bg-rose-50">
                <span>{{ __('ui.account.nav.logout') }}</span>
                <span aria-hidden="true">↗</span>
            </button>
        </form>
    </nav>
</aside>
