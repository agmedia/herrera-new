<section class="admin-panel p-6">
    <h2 class="text-base font-semibold text-slate-900">Stavke, odobrenje i primitak robe</h2>
    <p class="mt-2 text-sm text-slate-600">Zahtjev odobrava {{ $approverEmail ?: 'osoba navedena u postavkama' }}. Prodane količine umanjuju se tek nakon stvarnog primitka robe.</p>
    @if ($withdrawal->lineItems->isNotEmpty())
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-xs text-slate-500"><th class="p-2">Artikl</th><th class="p-2">Zahtjev</th><th class="p-2">Zaprimljeno</th><th class="p-2">Dodano zalihi</th></tr></thead>
                <tbody>
                    @foreach ($withdrawal->lineItems as $line)
                        <tr class="border-b border-slate-100"><td class="p-2">{{ $line->name }}<br><span class="text-xs text-slate-500">{{ $line->sku ?: $line->code }}</span></td><td class="p-2">{{ $line->quantity }}</td><td class="p-2">{{ $line->received_quantity }}</td><td class="p-2">{{ $line->restocked_quantity }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($withdrawal->approved_at)
            <p class="mt-4 text-sm text-emerald-800">{{ $withdrawal->request_type === 'claim' ? 'Reklamacija odobrena' : 'Povrat odobren' }}: {{ $withdrawal->approver?->name ?: 'ovlaštena osoba' }}, {{ $withdrawal->approved_at->format('d.m.Y. H:i') }}.</p>
            <p class="mt-1 text-xs text-slate-500">Ovdje se evidentira odluka. Financijsko odobrenje ili isplata provodi se zasebno.</p>
            <form method="POST" action="{{ route('admin.withdrawals.update', $withdrawal) }}" class="mt-5 space-y-4 border-t border-slate-200 pt-5">
                @csrf @method('PATCH')
                <input type="hidden" name="action" value="receive">
                <h3 class="font-semibold text-slate-900">Zaprimanje u skladište</h3>
                <p class="text-sm text-slate-600">Upišite ukupne količine zaprimljene do danas. Ponovljeno spremanje istih brojeva ne mijenja zalihu. Neispravna roba ostaje izvan prodajne zalihe.</p>
                @foreach ($withdrawal->lineItems as $line)
                    <div class="grid gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-2">
                        <p class="text-sm font-semibold sm:col-span-2">{{ $line->name }}</p>
                        <label class="text-xs text-slate-600">Ukupno zaprimljeno (od {{ $line->quantity }})<input type="number" name="receipts[{{ $line->id }}][received]" value="{{ old('receipts.'.$line->id.'.received', $line->received_quantity) }}" min="{{ $line->received_quantity }}" max="{{ $line->quantity }}" step="1" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                        <label class="text-xs text-slate-600">Od toga pregledano i ispravno za zalihu<input type="number" name="receipts[{{ $line->id }}][restocked]" value="{{ old('receipts.'.$line->id.'.restocked', $line->restocked_quantity) }}" min="{{ $line->restocked_quantity }}" max="{{ $line->quantity }}" step="1" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                    </div>
                @endforeach
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="physical_receipt_confirmed" value="1" required class="mt-1">Potvrđujem da je navedena roba stvarno zaprimljena u skladište.</label>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="inspected_sellable" value="1" class="mt-1">Pregledao/la sam robu označenu za zalihu i potvrđujem da je ispravna za prodaju.</label>
                <label class="block text-xs font-semibold text-slate-500">Bilješka uz primitak<textarea name="internal_note" rows="2" maxlength="5000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea></label>
                <button class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">Evidentiraj primitak robe</button>
            </form>
        @elseif ($withdrawal->status !== 'declined')
            @if ($canApprove)
                <form method="POST" action="{{ route('admin.withdrawals.update', $withdrawal) }}" class="mt-4">
                    @csrf @method('PATCH')<input type="hidden" name="action" value="approve">
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">{{ $withdrawal->request_type === 'claim' ? 'Odobri reklamaciju' : 'Odobri povrat' }}</button>
                </form>
            @else
                <p class="mt-4 text-sm text-amber-800">Čeka se odluka ovlaštene osobe.</p>
            @endif
        @endif
        @if ($withdrawal->lineItems->contains(fn ($line) => $line->receipts->isNotEmpty()))
            <h3 class="mt-6 font-semibold text-slate-900">Povijest primitaka</h3>
            <ul class="mt-2 space-y-2 text-xs text-slate-600">
                @foreach ($withdrawal->lineItems as $line)
                    @foreach ($line->receipts as $receipt)
                        <li class="rounded-lg bg-slate-50 p-3">{{ $receipt->received_at->format('d.m.Y. H:i') }} · {{ $receipt->receiver?->name ?: 'Korisnik više nije dostupan' }} · {{ $line->name }}: primljeno +{{ $receipt->quantity }}, zaliha +{{ $receipt->restocked_quantity }}.@if ($receipt->note)<br>{{ $receipt->note }}@endif</li>
                    @endforeach
                @endforeach
            </ul>
        @endif
    @elseif ($withdrawal->order && ! in_array($withdrawal->status, ['completed', 'declined'], true))
        <p class="mt-3 text-sm text-amber-800">Stariji zahtjev s tekstualnim opisom. Povežite točne stavke prije odobrenja i zaprimanja; tekst kupca ostaje sačuvan.</p>
        <form method="POST" action="{{ route('admin.withdrawals.update', $withdrawal) }}" class="mt-4 space-y-3">
            @csrf @method('PATCH')<input type="hidden" name="action" value="link_items">
            @foreach ($withdrawal->order->items as $item)
                <label class="flex items-center justify-between gap-3 text-sm">{{ $item->name }} (kupljeno {{ $item->quantity }})<input type="number" name="line_quantities[{{ $item->id }}]" min="0" max="{{ $item->quantity }}" step="1" value="0" class="w-24 rounded-lg border border-slate-300 px-3 py-2"></label>
            @endforeach
            <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Poveži stavke zahtjeva</button>
        </form>
    @else
        <p class="mt-3 text-sm text-slate-600">Tekstualni zapis ostaje dostupan u povijesti. Bez povezanih stavki ovaj zahtjev ne mijenja prodane količine ni zalihu.</p>
    @endif
</section>
