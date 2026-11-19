<x-admin-layout :title="__('Izvještaji po kupcima i komercijalistima')">
    <div class="space-y-5">
        <header><h1 class="text-2xl font-semibold">Izvještaji po kupcima i komercijalistima</h1></header>
        <form method="get" class="admin-panel flex flex-wrap items-end gap-3 p-4">
            <label>Izvještaj<select name="tab" class="admin-select block">
                @foreach (['sales'=>'Webshop promet','sessions'=>'Rad u računu kupca','visits'=>'Posjeti i kontakti','products'=>'Predstavljeni artikli (ADV)','logins'=>'Prijave i registracije'] as $key=>$label)
                    @if (($key !== 'sales' || $canSeeSales) && ($key !== 'logins' || $canSeeLogins))<option value="{{ $key }}" @selected($key === $tab)>{{ $label }}</option>@endif
                @endforeach
            </select></label>
            <label>Kupac<select name="customer" data-tom-select class="admin-select block"><option value="">Svi dostupni kupci</option>
                @foreach ($customers as $buyer)<option value="{{ $buyer->id }}" @selected($customer == $buyer->id)>{{ $buyer->customerDisplayName() }} · {{ $buyer->b2bAccount?->city ?: '#'.$buyer->id }}</option>@endforeach
            </select></label>
            <label>Komercijalist<select name="rep" class="admin-select block"><option value="">Svi dostupni</option>@foreach ($reps as $agent)<option value="{{ $agent->id }}" @selected($rep == $agent->id)>{{ $agent->name }}</option>@endforeach</select></label>
            @if ($tab === 'sales')
                <label>Obuhvat prometa<select name="scope" class="admin-select block">
                    <option value="actor" @selected(! $portfolio)>Po autoru narudžbe</option>
                    <option value="portfolio" @selected($portfolio)>Promet dodijeljenih kupaca</option>
                </select></label>
                <label>Kanal prometa<select name="channel" class="admin-select block">@foreach (['B2B'=>'B2B', 'B2C'=>'B2C', 'all'=>'Oba kanala'] as $key=>$label)<option value="{{ $key }}" @selected($channel === $key)>{{ $label }}</option>@endforeach</select></label>@endif
            <label>Od<input type="date" name="from" value="{{ $from->toDateString() }}" class="block rounded border"></label>
            <label>Do<input type="date" name="to" value="{{ $to->toDateString() }}" class="block rounded border"></label>
            <button class="rounded bg-cyan-700 px-4 py-2 text-white">Prikaži</button>
        </form>
        <section class="admin-panel overflow-x-auto p-4">
        @if ($tab === 'sales')
            <p>Neto vrijednost webshop narudžbi uključuje dostavu, uz izuzete otkazane narudžbe. @if ($portfolio)Promet dodijeljenih kupaca obuhvaća i njihove samostalne narudžbe. Koristi trenutačne dodjele, a svaku narudžbu zbraja jednom i kad kupac ima više komercijalista.@else Promet po autoru obuhvaća rad u računu kupca. Narudžbe bez evidentiranog komercijalista prikazane su zasebno.@endif</p>
            @if (auth()->user()->isA('superadmin') || auth()->user()->can('integrations.eracuni.manage'))
                <a class="my-2 inline-block font-semibold text-cyan-800" href="{{ route('admin.orders.erp-cost-centres', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">Preuzmi sva B2C mjesta troška za usklađenje s e-Računima (CSV za razdoblje)</a>
            @endif
            @foreach ($totals as $total)<p class="my-2 font-semibold">{{ $total->currency_code }} · {{ number_format($total->net, 2, ',', '.') }} bez PDV-a · {{ $total->orders_count }} narudžbi</p>@endforeach
        @elseif ($tab === 'logins')
            <p class="mb-2 font-semibold">Prijave kupaca {{ $totals->get('customer_login', 0) }} · Prijave komercijalista {{ $totals->get('staff_login', 0) }} · Registracije kupaca {{ $totals->get('customer_registration', 0) }}</p>
            <p>Prijave vlastitim računom i registracije. Filtar komercijalista za prijave kupaca koristi trenutačnu dodjelu kupca.</p>
        @elseif ($tab === 'products')
            <p>Artikli koje je komercijalist izričito označio kao predstavljene kupcu.</p>
        @endif
        @if (in_array($tab, ['visits', 'products', 'sessions'], true))<p class="my-2 font-semibold">Broj zapisa u razdoblju: {{ $rows->total() }}</p>@endif
        <table class="my-3 w-full text-left text-sm"><thead class="bg-slate-50"><tr>
            @if ($tab === 'logins')
                <th class="p-2">Vrijeme</th><th>Račun</th><th>Događaj</th><th>Lokacija uređaja</th>
            @else
                <th class="p-2">Kupac</th><th>{{ $portfolio ? 'Dodijeljeni komercijalisti' : 'Komercijalist' }}</th>
                @if ($tab === 'sales')<th>Narudžbe</th><th>Neto vrijednost</th>
                @elseif ($tab === 'products')<th>Datum</th><th>Šifra i artikl</th><th>Bilješka</th><th>Lokacija uređaja</th>
                @else<th>Početak / kraj</th><th>Vrsta i ishod</th><th>Lokacija uređaja</th>@endif
            @endif
        </tr></thead><tbody>
        @forelse ($rows as $row)
            <tr class="border-t align-top">
                @if ($tab === 'logins')
                    <td class="p-2">{{ $row->occurred_at->format('d.m.Y. H:i') }}</td><td>{{ $row->user?->customerDisplayName() }}</td><td>{{ ['customer_login'=>'Prijava kupca','staff_login'=>'Prijava komercijalista','customer_registration'=>'Registracija kupca'][$row->event] }}</td>
                    <td>@include('admin.users.partials.device-coordinates', ['loc' => data_get($row->payload, 'location')])
                        @if ($row->ip_address)<small class="block">IP prijave {{ $row->ip_address }}</small>@endif
                    </td>
                @else
                    <td class="p-2">{{ $names->get($row->customer_user_id)?->customerDisplayName() ?: ($row->guest_email ?? 'Gost') }}<small class="block">{{ $names->get($row->customer_user_id)?->b2bAccount?->city }} {{ $row->customer_type ?? '' }}</small></td>
                    <td>{{ $portfolio ? $names->get($row->customer_user_id)?->salesRepresentatives?->pluck('name')->implode(', ') : ($names->get($row->sales_rep_id)?->name ?: 'Bez evidentiranog komercijalista') }}</td>
                    @if ($tab === 'sales')
                        <td>{{ $row->orders_count }}</td><td>{{ number_format($row->net, 2, ',', '.') }} {{ $row->currency_code }}</td>
                    @elseif ($tab === 'products')
                        <td>{{ \Carbon\Carbon::parse($row->presented_at)->format('d.m.Y. H:i') }}</td><td>{{ $row->sku }} · {{ $row->product_name }}</td><td>{{ $row->note }}</td><td>@include('admin.users.partials.device-coordinates', ['loc' => json_decode($row->location ?? 'null', true)])</td>
                    @else
                        <td>{{ ($row->started_at ?? $row->visited_at)->format('d.m.Y. H:i') }} @if ($row->ended_at)<small class="block">do {{ $row->ended_at->format('d.m.Y. H:i') }}</small>@endif</td>
                        <td>{{ ['visit'=>'Posjet','email'=>'E-mail','phone'=>'Poziv'][$row->contact_type] ?? 'Rad u računu' }} · {{ ['order'=>'Narudžba','quote'=>'Ponuda','nothing'=>'Bez prodaje','open'=>'Otvoreno'][$row->outcome] ?? 'Nepotvrđeno' }} @if ($row->quote_reference)<small class="block">{{ $row->quote_reference }}</small>@endif</td>
                        <td>@include('admin.users.partials.device-coordinates', ['loc' => $tab === 'visits' ? $row->location : data_get($row->payload, 'location')])</td>
                    @endif
                @endif
            </tr>
        @empty<tr><td colspan="{{ $tab === 'products' ? 6 : (in_array($tab, ['sessions', 'visits'], true) ? 5 : 4) }}" class="p-5">Nema zapisa za odabrane filtre.</td></tr>@endforelse
        </tbody></table>{{ $rows->links() }}
        </section>
    </div>
</x-admin-layout>
