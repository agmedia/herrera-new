<x-admin-layout :title="__('Fakture e-Računi')">
    <div class="space-y-6">
        <section class="admin-panel p-6">
            <h1 class="text-2xl font-bold">{{ __('Fakture e-Računi') }} — {{ $selectedCustomer->customerDisplayName() }}</h1>
            <a class="mt-3 inline-block font-semibold text-cyan-700" href="{{ route('admin.users.statistics', $filters) }}">{{ __('Statistika shopa') }}</a>
            <p class="mt-3 text-sm text-slate-600">{{ __('Izdane fakture povezane OIB-om kupca, uključujući storna s njihovim predznakom. Iznosi uključuju PDV. Prikaz je odvojen od shopa; iznosi se ne zbrajaju. Podaci se osvježavaju svakih 10 minuta.') }}</p>
            <form method="GET" class="mt-5 flex flex-wrap items-end gap-3">
                <input type="hidden" name="tab" value="invoices"><input type="hidden" name="user_id" value="{{ $selectedCustomer->id }}">
                <label>{{ __('Od datuma') }}<input class="block rounded border-slate-300" type="date" name="dateFrom" required value="{{ $filters['dateFrom'] }}"></label>
                <label>{{ __('Do datuma') }}<input class="block rounded border-slate-300" type="date" name="dateTo" required value="{{ $filters['dateTo'] }}"></label>
                <label>{{ __('Valuta') }}<select name="currency" class="block rounded border-slate-300">@foreach($currencies as $code)<option @selected($filters['currency'] === $code)>{{ $code }}</option>@endforeach</select></label>
                <button class="rounded bg-cyan-700 px-4 py-2 text-white">{{ __('Prikaži fakture') }}</button>
            </form>
        </section>
        @if($invoiceError)
            <div role="alert" class="admin-panel p-6 text-rose-700">{{ $invoiceError }}</div>
        @else
            <section class="admin-panel p-6">
                <p>{{ __('Broj faktura') }}: <strong>{{ $invoices->count() }}</strong></p>
                <p>{{ __('Ukupno s PDV-om') }}: <strong>{{ \App\Support\Currency::format($invoices->sum('documentAmount'), $filters['currency']) }}</strong></p>
                <div class="mt-5 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">{{ __('Faktura') }}</th><th class="p-3">{{ __('Datum') }}</th><th class="p-3">{{ __('Dospijeće') }}</th><th class="p-3">{{ __('Iznos s PDV-om') }}</th><th class="p-3">{{ __('Plaćeno') }}</th></tr></thead><tbody>
                @forelse($invoices as $invoice)
                    <tr class="border-t"><td class="p-3">{{ $invoice['number'] ?? '—' }} @if(!empty($invoice['cancelledDocumentRef']))<span>{{ __('Storno za') }} {{ $invoice['cancelledDocumentRef'] }}</span>@endif</td><td class="p-3">{{ $invoice['date'] }}</td><td class="p-3">{{ $invoice['paymentDueDate'] ?? '—' }}</td><td class="p-3">{{ \App\Support\Currency::format($invoice['documentAmount'], $filters['currency']) }}</td><td class="p-3">{{ isset($invoice['amountAlreadyPaid']) ? \App\Support\Currency::format($invoice['amountAlreadyPaid'], $filters['currency']) : '—' }}</td></tr>
                @empty<tr><td class="p-6" colspan="5">{{ __('Nema faktura za OIB kupca u odabranom razdoblju i valuti.') }}</td></tr>@endforelse
                </tbody></table></div>
            </section>
        @endif
    </div>
</x-admin-layout>
