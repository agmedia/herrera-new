@php
    $statusColorClasses = [
        'blue' => 'bg-blue-100 text-blue-800',
        'emerald' => 'bg-emerald-100 text-emerald-800',
        'green' => 'bg-emerald-100 text-emerald-800',
        'rose' => 'bg-rose-100 text-rose-800',
        'red' => 'bg-rose-100 text-rose-800',
        'amber' => 'bg-amber-100 text-amber-800',
        'yellow' => 'bg-amber-100 text-amber-800',
        'violet' => 'bg-violet-100 text-violet-800',
        'purple' => 'bg-violet-100 text-violet-800',
        'cyan' => 'bg-cyan-100 text-cyan-800',
        'gray' => 'bg-slate-200 text-slate-700',
        'slate' => 'bg-slate-200 text-slate-700',
    ];

    $statusBarClasses = [
        'blue' => 'bg-blue-500',
        'emerald' => 'bg-emerald-500',
        'green' => 'bg-emerald-500',
        'rose' => 'bg-rose-500',
        'red' => 'bg-rose-500',
        'amber' => 'bg-amber-500',
        'yellow' => 'bg-amber-500',
        'violet' => 'bg-violet-500',
        'purple' => 'bg-violet-500',
        'cyan' => 'bg-cyan-600',
        'gray' => 'bg-slate-400',
        'slate' => 'bg-slate-400',
    ];

    $statisticsOrdersUrl = route('admin.orders', [
        'dateFrom' => $statisticsStart->toDateString(),
        'dateTo' => $statisticsEnd->toDateString(),
    ]);
@endphp

@push('page-styles')
    <style>
        .termol-dashboard {
            --dashboard-accent: #0e7490;
            --dashboard-accent-soft: #ecfeff;
            --dashboard-ink: #10151f;
            --dashboard-muted: #4f5e72;
            --dashboard-line: #dbe4ee;
        }

        .termol-dashboard-period-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 17rem), 1fr));
            gap: .85rem;
        }

        .termol-dashboard-period-card {
            display: flex;
            min-height: 12.25rem;
            flex-direction: column;
            padding: 1.15rem;
            border: 1px solid var(--dashboard-line);
            border-radius: 1rem;
            color: var(--dashboard-ink);
            background: #fff;
            box-shadow: 0 10px 24px -20px rgba(15, 23, 42, .5);
            transition: border-color 150ms ease, box-shadow 150ms ease, transform 150ms ease;
        }

        .termol-dashboard-period-card:hover,
        .termol-dashboard-period-card:focus-visible {
            border-color: #a5d8e4;
            box-shadow: 0 16px 32px -24px rgba(14, 116, 144, .65);
            outline: none;
            transform: translateY(-2px);
        }

        .termol-dashboard-period-icon {
            display: inline-flex;
            width: 2.45rem;
            height: 2.45rem;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            color: #0e7490;
            background: #e6f7fb;
        }

        .termol-dashboard-period-icon[data-period-icon="month"] {
            color: #0369a1;
            background: #e0f2fe;
        }

        .termol-dashboard-period-icon[data-period-icon="year"] {
            color: #047857;
            background: #ecfdf5;
        }

        .termol-dashboard-period-value {
            margin-top: .3rem;
            overflow: hidden;
            font-size: clamp(1.55rem, 2.2vw, 1.95rem);
            font-weight: 700;
            letter-spacing: -.035em;
            line-height: 1.12;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .termol-dashboard-period-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .75rem;
            margin-top: auto;
            padding-top: .85rem;
            border-top: 1px solid #e8eef4;
        }

        .termol-dashboard-period-meta > div + div {
            padding-left: .75rem;
            border-left: 1px solid #e8eef4;
        }

        .termol-dashboard-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .75rem;
        }

        .termol-dashboard-summary-card {
            min-width: 0;
            padding: .95rem 1rem;
            border: 1px solid var(--dashboard-line);
            border-radius: .85rem;
            background: linear-gradient(180deg, #fff 0%, #f8fbff 100%);
        }

        .termol-dashboard-summary-card strong {
            display: block;
            margin-top: .3rem;
            overflow: hidden;
            color: var(--dashboard-ink);
            font-size: 1.25rem;
            line-height: 1.2;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .termol-dashboard-chart {
            height: 20rem;
        }

        .termol-dashboard-lower-grid {
            display: grid;
            align-items: start;
            gap: 1rem;
        }

        @media (min-width: 80rem) {
            .termol-dashboard-summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .termol-dashboard-lower-grid {
                grid-template-columns: minmax(18rem, .78fr) minmax(30rem, 1.22fr);
            }
        }

        @media (max-width: 40rem) {
            .termol-dashboard-summary-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .termol-dashboard-period-card {
                min-height: 11.5rem;
            }

            .termol-dashboard-chart {
                height: 16rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .termol-dashboard-period-card {
                transition: none;
            }

            .termol-dashboard-period-card:hover,
            .termol-dashboard-period-card:focus-visible {
                transform: none;
            }
        }
    </style>
@endpush

<div class="termol-dashboard space-y-6">
    <section class="admin-panel admin-search-panel p-5 sm:p-6" aria-labelledby="dashboard-title">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <p class="admin-section-title">{{ __('Termol Admin') }}</p>
                <h1 id="dashboard-title" class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Sales Overview') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('Sales, orders, and recent activity at a glance.') }}</p>
            </div>
            <a
                href="{{ route('admin.orders') }}"
                class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-cyan-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600 focus-visible:ring-offset-2"
            >
                <x-fa-icon name="list-check" class="h-4 w-4" />
                <span>{{ __('View All Orders') }}</span>
                <x-fa-icon name="arrow-right" class="h-3.5 w-3.5" />
            </a>
        </div>
    </section>

    <section aria-labelledby="period-overview-title">
        <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
            <div>
                <p class="admin-section-title text-cyan-700">{{ __('Quick Overview') }}</p>
                <h2 id="period-overview-title" class="mt-1 text-lg font-semibold tracking-tight text-slate-900">{{ __('Sales by Period') }}</h2>
            </div>
            <p class="text-xs text-slate-500">{{ __('Select a card to open matching orders.') }}</p>
        </div>

        <div class="termol-dashboard-period-grid">
            @foreach ($periodCards as $period)
                <a
                    href="{{ $period['url'] }}"
                    class="termol-dashboard-period-card"
                    data-dashboard-period="{{ $period['key'] }}"
                >
                    <div class="flex items-center gap-3">
                        <span class="termol-dashboard-period-icon" data-period-icon="{{ $period['key'] }}" aria-hidden="true">
                            <x-fa-icon :name="$period['icon']" class="h-4 w-4" />
                        </span>
                        <span class="text-sm font-semibold text-slate-900">{{ $period['label'] }}</span>
                        <x-fa-icon name="chevron-right" class="ml-auto h-3.5 w-3.5 text-slate-400" />
                    </div>

                    <p class="mt-5 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Order Value') }}</p>
                    <p class="termol-dashboard-period-value">{{ \App\Support\Currency::format($period['order_value'], $storeCurrencyCode) }}</p>

                    <dl class="termol-dashboard-period-meta">
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('Orders') }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900">{{ number_format($period['orders']) }}</dd>
                        </div>
                        <div>
                            <dt class="truncate text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('Items / Order') }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900">{{ number_format($period['average_items'], 2) }}</dd>
                        </div>
                    </dl>
                </a>
            @endforeach
        </div>
    </section>

    <section class="admin-panel overflow-hidden" aria-labelledby="sales-statistics-title">
        <div class="flex flex-col gap-4 border-b border-slate-200 bg-slate-50/70 px-4 py-4 sm:px-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h2 id="sales-statistics-title" class="text-base font-semibold tracking-tight text-slate-900">{{ __('Sales Statistics') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ __('Order value and count by order date.') }}</p>
            </div>

            <div class="grid grid-cols-2 gap-2 sm:flex sm:items-end">
                <div>
                    <label for="dashboard-statistics-year" class="mb-1 block text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{{ __('Year') }}</label>
                    <select
                        id="dashboard-statistics-year"
                        wire:model.live="statisticsYear"
                        class="admin-select min-h-10 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 sm:w-36"
                    >
                        @foreach ($availableYears as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($statisticsView === 'month')
                    <div>
                        <label for="dashboard-statistics-month" class="mb-1 block text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{{ __('Month') }}</label>
                        <select
                            id="dashboard-statistics-month"
                            wire:model.live="statisticsMonth"
                            data-tom-preserve-order="1"
                            class="admin-select min-h-10 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 sm:w-40"
                        >
                            @foreach ($monthOptions as $month => $monthLabel)
                                <option value="{{ $month }}">{{ $monthLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
        </div>

        <div class="p-4 sm:p-5">
            <div class="mb-4 flex gap-1 border-b border-slate-200" aria-label="{{ __('Statistics Period') }}">
                <button
                    type="button"
                    wire:click="selectStatisticsView('month')"
                    aria-pressed="{{ $statisticsView === 'month' ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600 {{ $statisticsView === 'month' ? 'border-cyan-700 text-cyan-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}"
                >
                    <x-fa-icon name="calendar-day" class="h-3.5 w-3.5" />
                    {{ __('Monthly Overview') }}
                </button>
                <button
                    type="button"
                    wire:click="selectStatisticsView('year')"
                    aria-pressed="{{ $statisticsView === 'year' ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600 {{ $statisticsView === 'year' ? 'border-cyan-700 text-cyan-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}"
                >
                    <x-fa-icon name="chart-line" class="h-3.5 w-3.5" />
                    {{ __('Yearly Overview') }}
                </button>
            </div>

            <div wire:loading.class="opacity-60" wire:target="statisticsView,statisticsYear,statisticsMonth,selectStatisticsView">
                <div class="termol-dashboard-summary-grid">
                    <article class="termol-dashboard-summary-card">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                            {{ $statisticsView === 'year' ? __('Yearly Order Value') : __('Monthly Order Value') }}
                        </p>
                        <strong>{{ \App\Support\Currency::format($statistics['order_value'], $storeCurrencyCode) }}</strong>
                        <p class="mt-1 text-xs text-slate-500">{{ $statisticsStart->format('d.m.Y.') }} – {{ $statisticsEnd->format('d.m.Y.') }}</p>
                    </article>
                    <article class="termol-dashboard-summary-card">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{{ __('Orders') }}</p>
                        <strong>{{ number_format($statistics['orders']) }}</strong>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Orders in selected period') }}</p>
                    </article>
                    <article class="termol-dashboard-summary-card">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{{ __('Items Sold') }}</p>
                        <strong>{{ number_format($statistics['items_sold']) }}</strong>
                        <p class="mt-1 text-xs text-slate-500">{{ number_format($statistics['average_items'], 2) }} {{ __('per order') }}</p>
                    </article>
                    <article class="termol-dashboard-summary-card">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{{ __('Average Order') }}</p>
                        <strong>{{ \App\Support\Currency::format($statistics['average_order'], $storeCurrencyCode) }}</strong>
                        <p class="mt-1 text-xs text-slate-500">{{ __('In selected period') }}</p>
                    </article>
                </div>

                <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
                    @if ($statistics['orders'] > 0)
                        <div class="termol-dashboard-chart p-3 sm:p-4" wire:key="sales-chart-{{ $statisticsView }}-{{ $selectedYear }}-{{ $selectedMonth }}">
                            <canvas
                                data-dashboard-chart
                                data-chart-key="sales_statistics"
                                data-chart-payload='@json($dashboardChart)'
                                role="img"
                                aria-label="{{ __('Chart of order value and order count for selected period') }}"
                            ></canvas>
                        </div>
                    @else
                        <div class="flex min-h-56 flex-col items-center justify-center px-5 py-10 text-center" data-dashboard-empty-state>
                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-cyan-50 text-cyan-700" aria-hidden="true">
                                <x-fa-icon name="chart-line" class="h-5 w-5" />
                            </span>
                            <h3 class="mt-3 text-sm font-semibold text-slate-900">{{ __('No sales data for selected period.') }}</h3>
                            <p class="mt-1 max-w-md text-xs leading-5 text-slate-500">{{ __('The chart will appear when orders exist in this period.') }}</p>
                            <a href="{{ $statisticsOrdersUrl }}" class="mt-4 inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                {{ __('Open Orders') }}
                                <x-fa-icon name="arrow-right" class="h-3 w-3" />
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <div class="termol-dashboard-lower-grid">
        <section class="admin-panel overflow-hidden" aria-labelledby="order-statuses-title">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
                <div>
                    <h2 id="order-statuses-title" class="text-sm font-semibold text-slate-900">{{ __('Order Statuses') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Selected statistics period') }}</p>
                </div>
                <a href="{{ $statisticsOrdersUrl }}" class="text-xs font-semibold text-cyan-700 hover:text-cyan-900">{{ __('All Orders') }}</a>
            </div>

            <div class="space-y-3 p-4 sm:p-5">
                @forelse ($pipeline as $status)
                    @php
                        $colorKey = strtolower((string) ($status['color'] ?? 'slate'));
                        $statusClass = $statusColorClasses[$colorKey] ?? $statusColorClasses['slate'];
                        $barClass = $statusBarClasses[$colorKey] ?? $statusBarClasses['slate'];
                    @endphp
                    <a href="{{ $status['url'] }}" class="group block rounded-xl border border-transparent p-1 transition hover:border-slate-200 hover:bg-slate-50">
                        <div class="flex items-center justify-between gap-3">
                            <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $statusClass }}">{{ $status['name'] }}</span>
                            <span class="text-sm font-semibold text-slate-900">{{ number_format($status['count']) }}</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full {{ $barClass }}" style="width: {{ $status['bar_width'] }}%;"></div>
                        </div>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">{{ __('No statuses configured.') }}</div>
                @endforelse
            </div>
        </section>

        <section class="admin-panel overflow-hidden" aria-labelledby="recent-orders-title">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
                <div>
                    <h2 id="recent-orders-title" class="text-sm font-semibold text-slate-900">{{ __('Recent Orders') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Latest store orders') }}</p>
                </div>
                <a href="{{ route('admin.orders') }}" class="text-xs font-semibold text-cyan-700 hover:text-cyan-900">{{ __('View All') }}</a>
            </div>

            <div class="overflow-x-auto">
                <table class="admin-items-table min-w-full text-xs">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left">{{ __('Order') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('Customer') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentOrders as $order)
                            @php
                                $orderColorKey = strtolower((string) ($order->status?->color ?? 'slate'));
                                $orderStatusClass = $statusColorClasses[$orderColorKey] ?? $statusColorClasses['slate'];
                            @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.orders.show', ['order' => $order->id]) }}" class="font-semibold text-cyan-700 hover:text-cyan-900">{{ $order->order_number }}</a>
                                    <span class="mt-1 block whitespace-nowrap text-[11px] text-slate-500">{{ ($order->placed_at ?? $order->created_at)?->format('d.m.Y. H:i') }}</span>
                                </td>
                                <td class="max-w-52 truncate px-4 py-3 text-slate-700">{{ $order->customer_name }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $orderStatusClass }}">{{ $order->status?->name ?? __('Unknown') }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-900">{{ \App\Support\Currency::format((float) $order->grand_total, $order->currency_code) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10 text-center text-sm text-slate-500">{{ __('No orders yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
