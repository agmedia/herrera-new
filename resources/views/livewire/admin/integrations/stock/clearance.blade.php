<section class="admin-panel p-5 sm:p-6">
    <h2 class="admin-section-title">Rasprodaja — 100 dana bez prodaje tvrtke</h2>
    <p class="mt-2 text-sm text-slate-600">Artikli s vlastitom zalihom i najmanje 100 dana bez prodaje cijele tvrtke ulaze u Rasprodaju / B2B60. Cijena je 40% pune veleprodajne osnovice, bez dodatnog B2B rabata, maloprodajne akcije ili kupona. Nakon prodaje vlastite zalihe artikl ostaje trajno deaktiviran do vaše ručne aktivacije; povijest kupnje ostaje sačuvana.</p>
    <p class="mt-2 text-xs text-slate-500">Artikli s varijantama preskaču se dok izvor ne daje zasebne podatke izlaza i vlastite zalihe za varijante.</p>
    <div class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">
        <p>Provjera koristi račune, POS račune i otpremnice cijele tvrtke u e-Računima, kroz sva skladišta i mjesta troška. Uspoređuje ih s vlastitom ERP zalihom; nepotpuni podaci ili nejednoznačne šifre ne otvaraju nove rasprodaje. Nakon uključivanja provjera se ponavlja svakodnevno u 04:30.</p>
        <p class="mt-2">Zadnja potvrda: {{ $lastSnapshot ?: 'još nema potpune provjere' }}. API provjera: {{ match($salesSync['status'] ?? '') { 'completed' => 'dovršena', 'running' => 'u tijeku', 'failed' => ($salesSync['stage'] ?? '') === 'clearance' ? 'primjena nije dovršena — provjerite stanje artikala' : 'provjera nije uspjela — nove rasprodaje nisu pokrenute', default => 'još nije pokrenuta' } }}.</p>
        @if (($salesSync['status'] ?? '') === 'completed')<p class="mt-1">Presjek: {{ $salesSync['as_of'] }} · Provjerenih dokumenata: {{ $salesSync['document_count'] }} · Bez prodaje 100 dana: {{ $salesSync['observed'] }} · Nedavna prodaja: {{ $salesSync['recent'] }} · Nepotvrđena veza ili zaliha: {{ $salesSync['unproven'] }}.</p>@endif
        @if (($salesSync['unlinked_line_count'] ?? 0) > 0)<p class="mt-2 text-amber-800">Nepovezanih tekstualnih stavki dokumenata: {{ $salesSync['unlinked_line_count'] }}. One nemaju šifru niti poveznicu na ERP artikl i ne ulaze u promet artikala. Robu treba evidentirati preko odgovarajućih ERP šifri.</p>@endif
        <button type="button" wire:click="refreshCompanySales" wire:loading.attr="disabled" class="mt-3 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Osvježi ukupnu prodaju iz e-Računa</button>
    </div>
    <form wire:submit="saveSettings" class="mt-4 flex flex-wrap items-end gap-4">
        <label class="min-w-64 flex-1 text-xs font-semibold text-slate-600">Kategorija Rasprodaja
            <select wire:model="settings.category_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm"><option value="">Odaberite postojeću kategoriju</option>@foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->translations->firstWhere('locale', 'hr')?->name ?: $category->code }}</option>@endforeach</select>
        </label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="settings.enabled" class="rounded border-slate-300">Uključi automatiku nakon potvrđenih ERP podataka</label>
        <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">Spremi postavke</button>
    </form>
    <details class="mt-5 rounded-xl border border-slate-200 p-4">
        <summary class="cursor-pointer text-sm font-semibold">Uvoz potvrđenog ERP izvještaja</summary>
        <p class="mt-2 text-xs text-slate-600">CSV odvojen zarezom: <code>product_code,last_outgoing_at,coverage_from,as_of</code>. Datumi GGGG-MM-DD. Šifra je ERP model artikla. Prazan datum zadnjeg izlaza dopušten je samo ako izvještaj potvrđuje da nije bilo izlaza u cijelom razdoblju. Za novu rasprodaju razdoblje mora pokrivati najmanje 100 dana i izvještaj mora biti današnji. Stariji izvještaj ne otvara nove rasprodaje.</p>
        <input type="file" wire:model="report" accept=".csv,text/csv,text/plain" class="mt-3 block w-full text-sm" aria-label="ERP izvještaj ukupnih izlaza">
        <button type="button" wire:click="previewReport" wire:loading.attr="disabled" class="mt-3 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Pregledaj izvještaj</button>
        @if ($preview)
            <p class="mt-3 text-sm">Povezano artikala: {{ $preview['products'] }} · Svjež izvještaj: {{ $preview['fresh'] }} · Stariji: {{ $preview['stale'] }}.</p>
            <label class="mt-3 flex items-start gap-2 text-sm"><input type="checkbox" wire:model="confirmedCompanyScope" class="mt-1 rounded border-slate-300">Potvrđujem da izvještaj e-Računi obuhvaća ukupne izlaze/prodaju tvrtke kroz sva relevantna skladišta u navedenom razdoblju.</label>
            <button type="button" wire:click="importReport" wire:loading.attr="disabled" class="mt-3 rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">Spremi potvrđeni izvještaj</button>
        @endif
    </details>
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <span class="text-xs text-slate-500">Aktivna rasprodaja: {{ $counts['active'] ?? 0 }} · Prodana vlastita zaliha: {{ $counts['sold_out'] ?? 0 }} · Ručno aktivirano: {{ $counts['manual_override'] ?? 0 }}</span>
        <button type="button" wire:click="previewCandidates" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Pregledaj kandidate</button>
    </div>
    @if ($candidatePreview)
        <p class="mt-3 text-sm">{{ $candidatePreview['disabled'] ? 'Automatika je isključena.' : 'Novi kandidati: '.$candidatePreview['eligible'].' · Prodana vlastita zaliha: '.$candidatePreview['retired'].' · Bez svježeg dokaza: '.$candidatePreview['stale_or_unproven'] }}</p>
        @if (!$candidatePreview['disabled'])<button type="button" wire:click="applyCandidates" wire:confirm="Primijeniti rasprodaju na provjerene kandidate i deaktivirati artikle s prodanom vlastitom zalihom?" class="mt-3 rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">Primijeni pregledanu obradu</button>@endif
    @endif
    @if ($notice !== '')<p role="status" class="mt-3 rounded-lg bg-slate-50 p-3 text-sm">{{ $notice }}</p>@endif
    @if ($errors->any())<div role="alert" class="mt-3 text-sm text-rose-700">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
</section>
