<?php

namespace App\Services\Analytics;

use App\Models\User;
use App\Services\Admin\OrderManagerAccess;
use App\Support\Export\XlsxWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as UserQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RepresentativeStatistics
{
    private const DATE = 'COALESCE(orders.placed_at, orders.created_at)';

    private const NET = 'SUM(orders.grand_total - orders.tax_total)';

    private const ACTIVITY = [
        'visits' => ['customer_visits', 'visited_at'],
        'sessions' => ['customer_sales_sessions', 'started_at'],
        'products' => ['customer_product_presentations', 'presented_at'],
    ];

    public readonly bool $sales;

    public readonly bool $logins;

    public function __construct(
        public readonly User $actor,
        public readonly User $rep,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $currency,
        public readonly string $channel,
        public readonly ?User $buyer = null,
    ) {
        $this->sales = $actor->isA('superadmin') || $actor->can('sales.orders.view');
        $this->logins = $actor->isA('superadmin') || $actor->can('users.activity.view');
    }

    public function sections(): array
    {
        $tabs = $this->sales ? ['customers' => 'Kupnja po kupcu', 'orders' => 'Narudžbe kupaca', 'personal' => 'Osobna prodaja'] : [];
        if ($this->logins) {
            $tabs['staff'] = 'Prijave komercijalista';
        }
        $tabs += ['visits' => 'Posjeti i kontakti', 'sessions' => 'Rad u računu kupca', 'products' => 'ADV artikli'];
        if ($this->logins) {
            $tabs['buyers'] = 'Prijave kupaca';
        }

        return $tabs;
    }

    public static function assignedCustomers(User $actor, User $rep): UserQuery
    {
        return app(OrderManagerAccess::class)->scopeCustomers(User::query(), $actor)
            ->where('users.account_type', 'customer')
            ->whereIn('users.id', DB::table('admin_customer_assignments')->where('admin_user_id', $rep->id)->select('customer_user_id'));
    }

    private function customerIds(bool $assigned = false): UserQuery
    {
        $query = $assigned ? self::assignedCustomers($this->actor, $this->rep)
            : app(OrderManagerAccess::class)->scopeCustomers(User::query(), $this->actor)->where('users.account_type', 'customer');

        return $query->when($this->buyer, fn ($q) => $q->whereKey($this->buyer->id))->select('users.id');
    }

    private function orders(bool $portfolio): Builder
    {
        return SalesActivityOrders::query($this->customerIds(), $this->from, $this->to, $this->channel, $this->rep->id, $portfolio)
            ->where('orders.currency_code', $this->currency)->toBase();
    }

    private function month(string $column): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "strftime('%Y-%m', $column)",
            'pgsql' => "TO_CHAR($column, 'YYYY-MM')",
            default => "DATE_FORMAT($column, '%Y-%m')",
        };
    }

    private function activities(string $tab): Builder
    {
        [$table, $date] = self::ACTIVITY[$tab];

        return DB::table($table)->where('sales_rep_id', $this->rep->id)->whereIn('customer_user_id', $this->customerIds())
            ->where(fn ($q) => $q->whereNull('performed_by_id')->orWhereColumn('performed_by_id', 'sales_rep_id'))
            ->whereBetween($date, [$this->from, $this->to]);
    }

    private function events(bool $staff): Builder
    {
        $query = DB::table('user_tracking_events')->whereBetween('occurred_at', [$this->from, $this->to]);

        return $staff ? $query->where('user_id', $this->rep->id)->where('event', 'staff_login')
            : $query->whereIn('user_id', $this->customerIds(true))->whereIn('event', ['customer_login', 'customer_registration']);
    }

    public function overview(): array
    {
        $months = [];
        for ($month = $this->from->startOfMonth(); $month <= $this->to; $month = $month->addMonth()) {
            $months[$month->format('Y-m')] = ['month' => $month->format('Y-m'), 'portfolio' => 0.0, 'personal' => 0.0,
                'portfolio_orders' => 0, 'personal_orders' => 0, 'visits' => 0, 'sessions' => 0, 'products' => 0];
        }
        if ($this->sales) {
            $bucket = $this->month(self::DATE);
            foreach (['portfolio' => true, 'personal' => false] as $scope => $portfolio) {
                foreach ($this->orders($portfolio)->selectRaw("$bucket AS month, COUNT(*) AS total, ".self::NET.' AS net')->groupByRaw($bucket)->get() as $row) {
                    $months[$row->month][$scope] = (float) $row->net;
                    $months[$row->month][$scope.'_orders'] = (int) $row->total;
                }
            }
        }

        foreach (self::ACTIVITY as $section => [, $date]) {
            $bucket = $this->month($date);
            foreach ($this->activities($section)->selectRaw("$bucket AS month, COUNT(*) AS total")->groupByRaw($bucket)->get() as $row) {
                $months[$row->month][$section] = (int) $row->total;
            }
        }
        $months = collect(array_values($months));
        $lastLogin = null;
        $adminLogins = 0;
        if ($this->logins) {
            $admin = $this->events(true)->where('payload->login_surface', 'admin');
            $adminLogins = (clone $admin)->count();
            $lastLogin = $admin->orderByDesc('occurred_at')->orderByDesc('id')->first();
        }

        return [
            'months' => $months, 'assigned' => $this->customerIds(true)->count(),
            'active' => $this->sales ? $this->orders(true)->distinct()->count('orders.user_id') : null,
            'lastLogin' => $lastLogin, 'adminLogins' => $adminLogins,
        ];
    }

    public function detailQuery(string $section): Builder
    {
        abort_unless(isset($this->sections()[$section]), 403);
        if (in_array($section, ['customers', 'personal'], true)) {
            $bucket = $this->month(self::DATE);

            return $this->orders($section === 'customers')->select('orders.user_id as customer_user_id')
                ->selectRaw("$bucket AS month, COUNT(*) AS orders_count, ".self::NET.' AS net')
                ->groupBy('orders.user_id')->groupByRaw($bucket)
                ->orderByDesc('month')->orderByDesc('net')->orderBy('orders.user_id');
        }
        if ($section === 'orders') {
            return $this->orders(true)->select('orders.user_id as customer_user_id', 'orders.id', 'orders.order_number', 'orders.customer_type')
                ->selectRaw(self::DATE.' AS ordered_at, orders.grand_total - orders.tax_total AS net')
                ->orderByDesc('ordered_at')->orderByDesc('orders.id');
        }
        if (isset(self::ACTIVITY[$section])) {
            return $this->activities($section)->orderByDesc(self::ACTIVITY[$section][1])->orderByDesc('id');
        }

        return $this->events($section === 'staff')->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function columns(string $section): array
    {
        return match ($section) {
            'customers', 'personal' => ['month' => 'Mjesec', 'customer' => 'Kupac', 'count' => 'Narudžbe', 'net' => 'Neto promet ('.$this->currency.')'],
            'orders' => ['time' => 'Datum narudžbe', 'customer' => 'Kupac', 'number' => 'Broj narudžbe', 'channel' => 'Kanal', 'net' => 'Neto promet ('.$this->currency.')'],
            'staff' => ['time' => 'Vrijeme prijave', 'event' => 'Mjesto prijave', 'location' => 'Lokacija uređaja', 'ip' => 'IP adresa'],
            'buyers' => ['time' => 'Vrijeme', 'customer' => 'Kupac', 'event' => 'Događaj', 'location' => 'Lokacija uređaja', 'ip' => 'IP adresa'],
            'visits' => ['time' => 'Vrijeme', 'customer' => 'Kupac', 'type' => 'Kontakt', 'outcome' => 'Ishod', 'note' => 'Bilješka', 'location' => 'Lokacija uređaja'],
            'sessions' => ['time' => 'Početak', 'ended' => 'Završetak', 'customer' => 'Kupac', 'outcome' => 'Ishod', 'location' => 'Lokacija uređaja'],
            'products' => ['time' => 'Vrijeme', 'customer' => 'Kupac', 'sku' => 'SKU', 'product' => 'Predstavljeni artikl', 'note' => 'Bilješka', 'location' => 'Lokacija uređaja'],
        };
    }

    public function rows(string $section, Collection $records): Collection
    {
        $users = collect();
        if ($section !== 'staff') {
            $ids = $records->pluck($section === 'buyers' ? 'user_id' : 'customer_user_id')->unique();
            $users = User::with(['b2bAccount:id,user_id,company_name', 'profile:id,user_id,company', 'addresses' => fn ($q) => $q->where('type', 'billing')->select('id', 'user_id', 'type', 'company')])
                ->whereKey($ids)->get()->keyBy('id');
        }

        return $records->map(function ($row) use ($section, $users) {
            $name = $users->get($row->customer_user_id ?? $row->user_id ?? null)?->customerDisplayName();
            if (in_array($section, ['customers', 'personal'], true)) {
                return ['buyer_id' => $row->customer_user_id, 'month' => $row->month, 'customer' => $name, 'count' => (int) $row->orders_count, 'net' => (float) $row->net];
            }
            if ($section === 'orders') {
                return ['time' => $row->ordered_at, 'customer' => $name, 'number' => (string) $row->order_number,
                    'channel' => $row->customer_type, 'net' => (float) $row->net];
            }
            $payload = json_decode($row->payload ?? 'null', true);
            $location = json_decode($row->location ?? 'null', true) ?? ($payload['location'] ?? null);
            $result = ['customer' => $name, 'location' => $location, 'note' => $row->note ?? null];
            $outcome = ['order' => 'Narudžba', 'quote' => 'Ponuda', 'nothing' => 'Bez prodaje', 'open' => 'Otvoreno'][$row->outcome ?? ''] ?? 'Nepotvrđeno';

            return $result + match ($section) {
                'visits' => ['time' => $row->visited_at, 'type' => ['visit' => 'Posjet', 'email' => 'E-mail', 'phone' => 'Poziv'][$row->contact_type] ?? $row->contact_type, 'outcome' => $outcome],
                'sessions' => ['time' => $row->started_at, 'ended' => $row->ended_at, 'outcome' => $outcome],
                'products' => ['time' => $row->presented_at, 'sku' => $row->sku, 'product' => $row->product_name],
                'staff' => ['time' => $row->occurred_at, 'ip' => $row->ip_address, 'event' => match ($payload['login_surface'] ?? null) {
                    'admin' => 'Administracija', 'storefront' => 'Webshop', 'checkout' => 'Blagajna', default => 'Izvor nije zabilježen',
                }],
                'buyers' => ['time' => $row->occurred_at, 'ip' => $row->ip_address, 'event' => $row->event === 'customer_registration' ? 'Registracija kupca' : 'Prijava kupca'],
            };
        });
    }

    public function workbook(array $overview): string
    {
        $summary = [['Komercijalist', $this->rep->name], ['Obuhvat', 'Webshop narudžbe trenutačno dodijeljenih kupaca. Iznosi uključuju dostavu i isključuju PDV, otkazane narudžbe i nedovršene kupnje iz OpenCarta. Zajednički kupac ulazi u pregled svakog dodijeljenog komercijalista.'], ['Od', $this->from->toDateString()], ['Do', $this->to->toDateString()],
            ['Vremenska zona', config('app.timezone')], ['Dodijeljeni kupci danas', $overview['assigned']]];
        if ($this->buyer) {
            $summary[] = ['Odabrani kupac', $this->buyer->customerDisplayName()];
        }
        $metrics = [];
        if ($this->sales) {
            $summary[] = ['Valuta', $this->currency];
            $summary[] = ['Prodajni kanal', $this->channel];
            $summary[] = ['Kupci s narudžbom', $overview['active']];
            $metrics = ['portfolio' => 'Promet dodijeljenih kupaca', 'portfolio_orders' => 'Narudžbe kupaca', 'personal' => 'Osobno ugovoreni promet', 'personal_orders' => 'Osobne narudžbe'];
        }
        $metrics += ['visits' => 'Posjeti i kontakti', 'sessions' => 'Rad u računu kupca', 'products' => 'Predstavljeni artikli'];
        foreach ($metrics as $key => $label) {
            $summary[] = [$label, $overview['months']->sum($key)];
        }
        if ($this->logins) {
            $summary[] = ['Potvrđene prijave u admin', $overview['adminLogins']];
            $summary[] = ['Zadnja prijava u admin u razdoblju', $overview['lastLogin']->occurred_at ?? ''];
        }
        $sheets = ['Sažetak' => ['headers' => ['Pokazatelj', 'Vrijednost'], 'widths' => [42, 32], 'rows' => $summary],
            'Mjesečni pregled' => ['headers' => ['Mjesec', ...array_values($metrics)], 'widths' => [16, ...array_fill(0, count($metrics), 27)],
                'decimals' => $this->sales ? [1, 3] : [], 'dateFormats' => [0 => 'month'], 'rows' => $overview['months']->map(fn ($month) => [$month['month'], ...array_map(fn ($key) => $month[$key], array_keys($metrics))])]];
        foreach ($this->sections() as $section => $title) {
            $columns = $this->columns($section);
            $headers = [];
            foreach ($columns as $key => $label) {
                array_push($headers, ...($key === 'location' ? ['Geografska širina', 'Geografska dužina', 'Točnost (m)'] : [$label]));
            }
            $sheets[$title] = ['headers' => $headers, 'widths' => array_fill(0, count($headers), 26), 'decimals' => array_keys(array_keys($columns), 'net', true),
                'dateFormats' => array_intersect(array_keys($columns), ['month', 'time', 'ended']), 'rows' => $this->exportRows($section, $columns)];
        }

        return (new XlsxWorkbook)->create($sheets);
    }

    private function exportRows(string $section, array $columns): iterable
    {
        foreach ($this->detailQuery($section)->lazy(400)->chunk(400) as $chunk) {
            foreach ($this->rows($section, $chunk->collect()) as $record) {
                $values = [];
                foreach ($columns as $key => $label) {
                    $value = $record[$key] ?? null;
                    if ($key === 'location') {
                        array_push($values, $value['latitude'] ?? null, $value['longitude'] ?? null, $value['accuracy'] ?? null);
                    } else {
                        $values[] = $value;
                    }
                }
                yield $values;
            }
        }
    }
}
