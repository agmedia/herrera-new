<x-admin-layout :title="'Statistika komercijalista · '.$report->rep->name">
    @php
        $months = $overview['months'];
        $money = fn ($value) => number_format($value, 2, ',', '.').' '.\App\Support\Currency::symbol($report->currency);
        $url = fn (array $overrides = []) => route('admin.users.rep-statistics', array_replace($filters, ['section' => $section, 'chart' => $chart], $overrides));
        $columns = $report->columns($section);
        $presets = ['Ova godina' => [now()->startOfYear()->format('Y-m'), now()->format('Y-m')], 'Prošla godina' => [now()->subYear()->startOfYear()->format('Y-m'), now()->subYear()->endOfYear()->format('Y-m')], 'Zadnjih 12 mjeseci' => [now()->subMonthsNoOverflow(11)->format('Y-m'), now()->format('Y-m')]];
        $labels = $months->map(fn ($row) => \Carbon\CarbonImmutable::parse($row['month'].'-01')->locale('hr')->translatedFormat('M Y'))->all();
        $series = $chart === 'sales'
            ? [['portfolio', 'Promet ('.$report->currency.')', '#0e7490'], ['portfolio_orders', 'Narudžbe', '#9a5312']]
            : [['visits', 'Posjeti i kontakti', '#0e7490'], ['sessions', 'Rad u računu', '#43549b'], ['products', 'ADV artikli', '#af641b']];
        $datasets = [];
        foreach ($series as [$key, $title, $color]) {
            $line = $key === 'portfolio_orders';
            $datasets[] = ['type' => $line ? 'line' : 'bar', 'label' => $title, 'data' => $months->pluck($key)->all(), 'borderColor' => $color,
                'backgroundColor' => $color, 'borderRadius' => 3, 'maxBarThickness' => 34, 'yAxisID' => $line ? 'orders' : 'y', 'order' => $line ? 0 : 1, 'tension' => 0.2];
        }
        $scales = ['y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => $chart === 'sales' ? $report->currency : 'Broj aktivnosti']], 'x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 12]]];
        if ($chart === 'sales') $scales['orders'] = ['position' => 'right', 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Narudžbe']];
        $chartConfig = ['type' => 'bar', 'data' => ['labels' => $labels, 'datasets' => $datasets], 'options' => ['locale' => 'hr-HR', 'responsive' => true, 'maintainAspectRatio' => false, 'interaction' => ['mode' => 'index', 'intersect' => false], 'plugins' => ['legend' => ['position' => 'bottom']], 'scales' => $scales]];
    @endphp
    <div class="space-y-5">
        <a href="{{ route('admin.users.rep-statistics') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-cyan-800 hover:underline"><x-fa-icon name="arrow-left" class="h-3.5 w-3.5" /> Odabir komercijalista</a>
        <header class="admin-panel admin-search-panel p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div><p class="admin-section-title">Statistika komercijalista</p><h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-900">{{ $report->rep->name }}</h1><p class="mt-1 text-sm text-slate-600">{{ $report->rep->email }}</p></div>
                <a href="{{ route('admin.users.rep-statistics.export', $filters) }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800"><x-fa-icon name="file-excel" class="h-4 w-4" /> Izvezi u Excel</a>
            </div>
            @if ($errors->any())<p role="alert" class="mt-3 text-sm text-rose-700">{{ $errors->first() }}</p>@endif
            <form method="get" class="mt-5 flex flex-wrap items-end gap-3 border-t border-slate-200 pt-4">
                <input type="hidden" name="rep" value="{{ $report->rep->id }}"><input type="hidden" name="section" value="{{ $section }}"><input type="hidden" name="chart" value="{{ $chart }}">
                @foreach (['from' => 'Od mjeseca', 'to' => 'Do mjeseca'] as $key => $label)
                    <label class="text-xs font-semibold text-slate-600">{{ $label }}<input type="month" required name="{{ $key }}" value="{{ $filters[$key] }}" class="mt-1 block max-w-full rounded-xl border-slate-300 text-sm"></label>
                @endforeach
                @if ($report->sales)
                    <label class="text-xs font-semibold text-slate-600">Prodajni kanal<select name="channel" class="mt-1 block rounded-xl border-slate-300 text-sm">@foreach (['all'=>'Svi kanali','B2B'=>'B2B','B2C'=>'B2C'] as $code=>$label)<option value="{{ $code }}" @selected($report->channel === $code)>{{ $label }}</option>@endforeach</select></label>
                    <label class="text-xs font-semibold text-slate-600">Valuta<select name="currency" class="mt-1 block rounded-xl border-slate-300 text-sm">@foreach ($currencies as $currency)<option @selected($report->currency === $currency)>{{ $currency }}</option>@endforeach</select></label>
                @endif
                <label class="min-w-0 max-w-full text-xs font-semibold text-slate-600">Dodijeljeni kupac
                    <select data-tom-select name="buyer" class="mt-1 block w-full max-w-xs rounded-xl border-slate-300 text-sm"><option value="">Svi dodijeljeni kupci</option>@foreach ($buyers as $option)<option value="{{ $option['id'] }}" @selected($report->buyer?->id === $option['id'])>{{ $option['name'] }}</option>@endforeach</select>
                </label>
                <button class="rounded-xl border border-cyan-300 bg-white px-4 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-50">Primijeni</button>
            </form>
            <div class="mt-3 flex flex-wrap gap-2">@foreach ($presets as $title => [$from, $to])
                <a class="rounded-full border px-3 py-1 text-xs font-semibold {{ $filters['from'] === $from && $filters['to'] === $to ? 'border-cyan-300 bg-cyan-50 text-cyan-800' : 'border-slate-200 bg-white text-slate-600' }}" href="{{ $url(compact('from','to')) }}">{{ $title }}</a>
            @endforeach</div>
        </header>
        @if ($report->sales)
            <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl bg-cyan-800 p-5 text-white"><dt class="text-sm font-semibold">Promet dodijeljenih kupaca</dt><dd class="mt-2 text-2xl font-bold tabular-nums">{{ $money($months->sum('portfolio')) }}</dd><p class="mt-1 text-xs text-cyan-100">Webshop · bez PDV-a</p></div>
                <div class="admin-panel p-5"><dt class="text-sm text-slate-600">Narudžbe kupaca</dt><dd class="mt-2 text-2xl font-bold tabular-nums">{{ $months->sum('portfolio_orders') }}</dd><a class="text-xs font-semibold text-cyan-800 hover:underline" href="{{ $url(['section'=>'orders']) }}#detalji">Pregled narudžbi</a></div>
                <div class="admin-panel p-5"><dt class="text-sm text-slate-600">Kupci s narudžbom</dt><dd class="mt-2 text-2xl font-bold tabular-nums">{{ $overview['active'] }} <span class="text-base font-normal text-slate-500">/ {{ $overview['assigned'] }}</span></dd><p class="text-xs text-slate-500">{{ $report->buyer ? 'u odabiru kupca' : 'od danas dodijeljenih kupaca' }}</p></div>
                <div class="admin-panel p-5"><dt class="text-sm text-slate-600">Prosječna narudžba</dt><dd class="mt-2 text-2xl font-bold tabular-nums">{{ $months->sum('portfolio_orders') ? $money($months->sum('portfolio') / $months->sum('portfolio_orders')) : '—' }}</dd><p class="text-xs text-slate-500">bez PDV-a</p></div>
            </dl>
        @endif
        <div class="grid gap-4 xl:grid-cols-3">
            <section id="trend" class="admin-panel min-w-0 p-5 xl:col-span-2">
                <div class="flex flex-wrap items-start justify-between gap-3"><h2 class="text-lg font-bold text-slate-900">{{ $chart === 'sales' ? 'Mjesečna kupnja dodijeljenih kupaca' : 'Aktivnosti po mjesecima' }}</h2>
                    @if ($report->sales)<div class="inline-flex gap-1 rounded-lg bg-slate-100 p-1 text-xs font-semibold">@foreach (['sales'=>'Kupnja','activity'=>'Aktivnosti'] as $key=>$label)<a @if($chart === $key) aria-current="true" @endif class="rounded-md px-2.5 py-1 {{ $chart === $key ? 'bg-white text-cyan-800 shadow-sm' : 'text-slate-600' }}" href="{{ $url(['chart'=>$key]) }}#trend">{{ $label }}</a>@endforeach</div>@endif
                </div>
                @if ($chart === 'sales')<p class="mt-1 text-xs text-slate-500">@if ($report->buyer)<strong>{{ $report->buyer->customerDisplayName() }}</strong> · @endif Webshop narudžbe danas dodijeljenih kupaca uključuju samostalnu kupnju i dostavu. Izuzete su otkazane narudžbe i nedovršene kupnje iz OpenCarta. Kupnja zajedničkog kupca prikazana je svakom dodijeljenom komercijalistu.</p>@endif
                @if (collect($series)->every(fn ($item) => $months->every(fn ($month) => $month[$item[0]] == 0)))
                    <p class="my-6 rounded-xl bg-slate-50 p-5 text-sm text-slate-600">{{ $chart === 'sales' ? 'Dodijeljeni kupci nemaju narudžbi za odabrane filtre.' : 'U odabranom razdoblju nema evidentiranih aktivnosti.' }}</p>
                @else
                    <div class="relative mt-4 h-64"><canvas data-dashboard-chart data-chart-payload="{{ json_encode($chartConfig, JSON_THROW_ON_ERROR) }}" role="img" aria-label="{{ $chart === 'sales' ? 'Mjesečni promet i broj narudžbi' : 'Mjesečne aktivnosti komercijalista' }}"></canvas></div>
                @endif
                <details class="mt-3 text-sm"><summary class="cursor-pointer font-semibold text-cyan-800">Vrijednosti po mjesecima</summary><div class="mt-2 overflow-x-auto"><table class="admin-items-table w-full text-left text-sm"><thead><tr><th class="p-2">Mjesec</th>@foreach ($series as [$key,$title])<th class="p-2 text-right">{{ $title }}</th>@endforeach</tr></thead><tbody>@foreach ($months as $i=>$month)<tr><th scope="row" class="p-2 font-normal">{{ $labels[$i] }}</th>@foreach ($series as [$key,$title])<td class="p-2 text-right tabular-nums">{{ $key === 'portfolio' ? $money($month[$key]) : $month[$key] }}</td>@endforeach</tr>@endforeach</tbody></table></div></details>
            </section>
            <aside class="admin-panel p-5"><h2 class="text-lg font-bold text-slate-900">Rad komercijalista</h2>
                @if ($report->logins)
                    <div class="my-3 rounded-xl bg-slate-50 p-3"><a href="{{ $url(['section'=>'staff']) }}#detalji" class="text-xs font-semibold text-cyan-800 hover:underline">Zadnja potvrđena prijava u admin u razdoblju</a>
                    @if ($last = $overview['lastLogin'])<p class="my-1 font-bold">{{ \Carbon\Carbon::parse($last->occurred_at)->format('d.m.Y. H:i') }}</p><div class="text-xs text-slate-600">@include('admin.users.partials.device-coordinates', ['loc'=>data_get(json_decode($last->payload, true), 'location')])</div>
                    @else<p class="mt-1 text-sm text-slate-600">Nema evidentirane prijave u admin.</p>@endif</div>
                @endif
                <dl class="divide-y divide-slate-100">@foreach (['visits'=>'Posjeti i kontakti','sessions'=>'Rad u računu kupca','products'=>'Predstavljeni artikli'] as $key=>$label)<div class="flex items-center justify-between gap-2 py-2.5 text-sm"><dt><a class="font-semibold text-cyan-800 hover:underline" href="{{ $url(['section'=>$key]) }}#detalji">{{ $label }}</a></dt><dd class="tabular-nums">{{ $months->sum($key) }}</dd></div>@endforeach</dl>
            </aside>
        </div>
        <section id="detalji" class="admin-panel min-w-0 overflow-hidden">
            <nav aria-label="Detalji izvještaja" class="flex flex-wrap gap-1 border-b border-slate-200 p-3">
                @foreach ($sections as $key=>$title)<a href="{{ $url(['section'=>$key]) }}#detalji" @if($section === $key) aria-current="page" @endif class="rounded-lg px-3 py-2 text-sm font-semibold {{ $section === $key ? 'bg-cyan-700 text-white' : 'text-slate-600 hover:bg-slate-100' }}">{{ $title }}</a>@endforeach
            </nav>
            <div class="p-5"><h2 class="text-lg font-bold text-slate-900">{{ $sections[$section] }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ match ($section) { 'customers' => 'Promet svakog dodijeljenog kupca po mjesecima, bez PDV-a.', 'orders' => 'Pojedinačne webshop narudžbe današnjih dodijeljenih kupaca.', 'personal' => 'Narudžbe izravno povezane s radom komercijalista u računu kupca.', 'staff' => 'Vrijeme, izvor prijave i lokacija koju je uređaj podijelio.', 'buyers' => 'Prijave i registracije trenutačno dodijeljenih kupaca.', 'products' => 'Artikli evidentirani kao predstavljeni kupcu.', default => 'Evidentirani rad odabranog komercijalista.' } }} Vrijeme: {{ config('app.timezone') }}.</p>
            </div>
            <div class="overflow-x-auto"><table class="admin-items-table w-full text-left text-sm"><thead><tr>@foreach ($columns as $key=>$title)<th class="whitespace-nowrap px-4 py-3 {{ in_array($key,['net','count']) ? 'text-right' : '' }}">{{ $title }}</th>@endforeach</tr></thead><tbody>
                @forelse ($rows as $row)<tr>@foreach ($columns as $key=>$title)<td class="px-4 py-3 align-top {{ in_array($key,['net','count']) ? 'text-right font-semibold tabular-nums' : '' }}">
                    @if ($key === 'location')@include('admin.users.partials.device-coordinates', ['loc'=>$row[$key]])
                    @elseif ($key === 'customer' && $section === 'customers')<a class="font-semibold text-cyan-800 hover:underline" href="{{ $url(['buyer'=>$row['buyer_id'], 'section'=>'orders', 'from'=>$row['month'], 'to'=>$row['month']]) }}#detalji">{{ $row['customer'] }}</a>
                    @elseif ($key === 'net'){{ $money($row[$key]) }}
                    @elseif (in_array($key,['time','ended']) && !empty($row[$key]))<span class="whitespace-nowrap">{{ \Carbon\Carbon::parse($row[$key])->format('d.m.Y. H:i') }}</span>
                    @else{{ $row[$key] ?? '—' }}@endif
                </td>@endforeach</tr>
                @empty<tr><td colspan="{{ count($columns) }}" class="p-8 text-center text-slate-500">Nema zapisa za odabrano razdoblje i filtre.</td></tr>@endforelse
            </tbody></table></div>
            @if ($page->hasPages())<div class="border-t border-slate-200 p-4">{{ $page->links() }}</div>@endif
        </section>
    </div>
</x-admin-layout>
