<?php

namespace App\Livewire\Admin\Dashboard;

use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Services\Settings\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

class Overview extends Component
{
    public string $statisticsView = 'month';

    public string $statisticsYear = '';

    public string $statisticsMonth = '';

    public function mount(): void
    {
        $now = CarbonImmutable::now();

        $this->statisticsYear = (string) $now->year;
        $this->statisticsMonth = (string) $now->month;
    }

    public function selectStatisticsView(string $view): void
    {
        if (in_array($view, ['month', 'year'], true)) {
            $this->statisticsView = $view;
        }
    }

    public function render()
    {
        $now = CarbonImmutable::now();
        $settings = app(SystemSettingsService::class);
        $storeCurrencyCode = strtoupper((string) $settings->get('store_schema_product_currency', 'EUR'));
        $storeCurrencySymbol = \App\Support\Currency::symbol($storeCurrencyCode);

        $availableYears = $this->availableYears($now);
        $selectedYear = in_array((int) $this->statisticsYear, $availableYears, true)
            ? (int) $this->statisticsYear
            : $now->year;
        $selectedMonth = max(1, min(12, (int) $this->statisticsMonth));
        $statisticsView = in_array($this->statisticsView, ['month', 'year'], true)
            ? $this->statisticsView
            : 'month';

        $periodCards = collect([
            [
                'key' => 'today',
                'label' => __('Today'),
                'icon' => 'calendar-day',
                'start' => $now->startOfDay(),
                'end' => $now->endOfDay(),
            ],
            [
                'key' => 'month',
                'label' => __('This Month'),
                'icon' => 'chart-line',
                'start' => $now->startOfMonth(),
                'end' => $now->endOfMonth(),
            ],
            [
                'key' => 'year',
                'label' => __('This Year'),
                'icon' => 'circle-check',
                'start' => $now->startOfYear(),
                'end' => $now->endOfYear(),
            ],
        ])->map(function (array $period): array {
            $summary = $this->summarizeOrders($this->ordersInRange($period['start'], $period['end']));

            return [
                ...$period,
                ...$summary,
                'url' => route('admin.orders', [
                    'dateFrom' => $period['start']->toDateString(),
                    'dateTo' => $period['end']->toDateString(),
                ]),
            ];
        })->values()->all();

        if ($statisticsView === 'year') {
            $statisticsStart = CarbonImmutable::create($selectedYear, 1, 1)->startOfYear();
            $statisticsEnd = $statisticsStart->endOfYear();
        } else {
            $statisticsStart = CarbonImmutable::create($selectedYear, $selectedMonth, 1)->startOfMonth();
            $statisticsEnd = $statisticsStart->endOfMonth();
        }

        $statisticsQuery = $this->ordersInRange($statisticsStart, $statisticsEnd);
        $statistics = $this->summarizeOrders($statisticsQuery);
        $chartRows = $this->buildChartRows($statisticsQuery, $statisticsStart, $statisticsView);

        $dashboardChart = [
            'type' => 'line',
            'data' => [
                'labels' => $chartRows->pluck('label')->values()->all(),
                'datasets' => [
                    [
                        'label' => __('Order Value (:currency)', ['currency' => $storeCurrencySymbol]),
                        'data' => $chartRows->pluck('order_value')->values()->all(),
                        'yAxisID' => 'yValue',
                        'borderColor' => '#0891b2',
                        'backgroundColor' => 'rgba(8, 145, 178, 0.12)',
                        'pointBackgroundColor' => '#0891b2',
                        'pointRadius' => $statisticsView === 'year' ? 3 : 2,
                        'pointHoverRadius' => 5,
                        'tension' => 0.32,
                        'fill' => true,
                        'borderWidth' => 2,
                    ],
                    [
                        'label' => __('Orders'),
                        'data' => $chartRows->pluck('orders')->values()->all(),
                        'yAxisID' => 'yOrders',
                        'borderColor' => '#0f172a',
                        'backgroundColor' => 'rgba(15, 23, 42, 0.08)',
                        'pointBackgroundColor' => '#0f172a',
                        'pointRadius' => $statisticsView === 'year' ? 3 : 2,
                        'pointHoverRadius' => 5,
                        'tension' => 0.32,
                        'fill' => false,
                        'borderWidth' => 2,
                    ],
                ],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'interaction' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
                'plugins' => [
                    'legend' => [
                        'position' => 'top',
                        'labels' => [
                            'usePointStyle' => true,
                            'boxWidth' => 8,
                            'boxHeight' => 8,
                        ],
                    ],
                ],
                'scales' => [
                    'yValue' => [
                        'position' => 'left',
                        'beginAtZero' => true,
                        'grid' => ['color' => 'rgba(148, 163, 184, 0.18)'],
                    ],
                    'yOrders' => [
                        'position' => 'right',
                        'beginAtZero' => true,
                        'grid' => ['drawOnChartArea' => false],
                        'ticks' => ['precision' => 0],
                    ],
                    'x' => [
                        'grid' => ['display' => false],
                    ],
                ],
            ],
        ];

        $statusRows = OrderStatus::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'color']);

        $pipelineCounts = (clone $statisticsQuery)
            ->selectRaw('status_id, COUNT(*) as total')
            ->groupBy('status_id')
            ->pluck('total', 'status_id');
        $highestPipelineCount = max(1, (int) $pipelineCounts->max());

        $pipeline = $statusRows->map(function (OrderStatus $status) use (
            $pipelineCounts,
            $highestPipelineCount,
            $statisticsStart,
            $statisticsEnd
        ): array {
            $count = (int) ($pipelineCounts[$status->id] ?? 0);

            return [
                'id' => $status->id,
                'name' => $status->name,
                'code' => $status->code,
                'color' => $status->color,
                'count' => $count,
                'bar_width' => $count > 0 ? max(6, (int) round(($count / $highestPipelineCount) * 100)) : 0,
                'url' => route('admin.orders', [
                    'status' => $status->id,
                    'dateFrom' => $statisticsStart->toDateString(),
                    'dateTo' => $statisticsEnd->toDateString(),
                ]),
            ];
        });

        $recentOrders = Order::query()
            ->with('status:id,name,color')
            ->orderByRaw('COALESCE(placed_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(6)
            ->get([
                'id',
                'order_number',
                'status_id',
                'customer_name',
                'grand_total',
                'currency_code',
                'placed_at',
                'created_at',
            ]);

        $monthOptions = collect(range(1, 12))->mapWithKeys(function (int $month): array {
            $label = CarbonImmutable::create(2000, $month, 1)
                ->locale(app()->getLocale())
                ->translatedFormat('F');

            return [$month => Str::ucfirst($label)];
        })->all();

        return view('livewire.admin.dashboard.overview', [
            'periodCards' => $periodCards,
            'statisticsView' => $statisticsView,
            'selectedYear' => $selectedYear,
            'selectedMonth' => $selectedMonth,
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
            'statisticsStart' => $statisticsStart,
            'statisticsEnd' => $statisticsEnd,
            'statistics' => $statistics,
            'dashboardChart' => $dashboardChart,
            'pipeline' => $pipeline,
            'recentOrders' => $recentOrders,
            'storeCurrencyCode' => $storeCurrencyCode,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function availableYears(CarbonImmutable $now): array
    {
        $oldestOrderDate = Order::query()
            ->selectRaw('MIN(COALESCE(placed_at, created_at)) as oldest_order_date')
            ->value('oldest_order_date');
        $oldestYear = $oldestOrderDate
            ? min($now->year, CarbonImmutable::parse((string) $oldestOrderDate)->year)
            : $now->year;

        return range($now->year, $oldestYear, -1);
    }

    private function ordersInRange(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Order::query()->where(function (Builder $query) use ($start, $end): void {
            $query->whereBetween('placed_at', [$start, $end])
                ->orWhere(function (Builder $fallback) use ($start, $end): void {
                    $fallback->whereNull('placed_at')
                        ->whereBetween('created_at', [$start, $end]);
                });
        });
    }

    /**
     * @return array{orders: int, order_value: float, items_sold: int, average_order: float, average_items: float}
     */
    private function summarizeOrders(Builder $query): array
    {
        $summary = (clone $query)
            ->selectRaw('COUNT(*) as dashboard_orders')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as dashboard_order_value')
            ->selectRaw('COALESCE(SUM(item_qty), 0) as dashboard_items_sold')
            ->first();

        $orders = (int) ($summary?->dashboard_orders ?? 0);
        $orderValue = (float) ($summary?->dashboard_order_value ?? 0);
        $itemsSold = (int) ($summary?->dashboard_items_sold ?? 0);

        return [
            'orders' => $orders,
            'order_value' => $orderValue,
            'items_sold' => $itemsSold,
            'average_order' => $orders > 0 ? $orderValue / $orders : 0.0,
            'average_items' => $orders > 0 ? $itemsSold / $orders : 0.0,
        ];
    }

    /**
     * @return Collection<int, array{label: string, orders: int, order_value: float}>
     */
    private function buildChartRows(
        Builder $query,
        CarbonImmutable $statisticsStart,
        string $statisticsView
    ): Collection {
        $bucketExpression = $this->bucketExpression($statisticsView);
        $aggregates = (clone $query)
            ->selectRaw($bucketExpression.' as dashboard_bucket')
            ->selectRaw('COUNT(*) as dashboard_orders')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as dashboard_order_value')
            ->groupByRaw($bucketExpression)
            ->get()
            ->keyBy(fn (Order $row): int => (int) $row->dashboard_bucket);

        $bucketNumbers = $statisticsView === 'year'
            ? range(1, 12)
            : range(1, $statisticsStart->daysInMonth);

        return collect($bucketNumbers)->map(function (int $bucket) use (
            $aggregates,
            $statisticsView
        ): array {
            $aggregate = $aggregates->get($bucket);
            $label = $statisticsView === 'year'
                ? Str::ucfirst(CarbonImmutable::create(2000, $bucket, 1)
                    ->locale(app()->getLocale())
                    ->translatedFormat('M'))
                : (string) $bucket;

            return [
                'label' => $label,
                'orders' => (int) ($aggregate?->dashboard_orders ?? 0),
                'order_value' => (float) ($aggregate?->dashboard_order_value ?? 0),
            ];
        });
    }

    private function bucketExpression(string $statisticsView): string
    {
        $datePart = $statisticsView === 'year' ? 'month' : 'day';
        $dateExpression = 'COALESCE(placed_at, created_at)';

        return match (DB::connection()->getDriverName()) {
            'sqlite' => sprintf(
                "CAST(strftime('%s', %s) AS INTEGER)",
                $datePart === 'month' ? '%m' : '%d',
                $dateExpression
            ),
            'pgsql' => sprintf('CAST(EXTRACT(%s FROM %s) AS INTEGER)', strtoupper($datePart), $dateExpression),
            default => sprintf('%s(%s)', strtoupper($datePart), $dateExpression),
        };
    }
}
