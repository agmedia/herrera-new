<div class="b2b-price-manager space-y-6" x-data x-on:group-price-editor-opened.window="$nextTick(() => $el.querySelector('[data-group-entry-editor]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))">
    <div class="admin-panel p-5">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ __('B2B cjenici i popusti') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('Odaberite grupu kupaca za pregled postojećih cijena i pravila. U radnoj kopiji možete urediti cijenu artikla ili dodati popust za grupu.') }}</p>
        <p class="mt-1 text-xs text-slate-500">{{ __('Izmjene spremite u radnu kopiju, provjerite cijene i tek zatim objavite. Iznosi su u EUR, s najviše četiri decimale.') }} {{ $pricesIncludeTax ? __('Cijene uključuju PDV.') : __('Cijene su bez PDV-a.') }}</p>
        @if ($errors->any())
            <div class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700" role="alert">
                @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif
        <div class="mt-5 flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <label class="block text-xs font-semibold text-slate-500">{{ __('Verzija') }}</label>
                <select wire:change="selectCatalog($event.target.value)" class="admin-search-input admin-select mt-1 w-full rounded-lg px-3 py-2">
                    <option value="" @selected(!$catalogId) disabled>{{ __('Odaberite cjenik') }}</option>
                    @foreach ($catalogs as $version)
                        <option value="{{ $version->id }}" @selected($catalogId === $version->id)>#{{ $version->id }} {{ $version->name }} — {{ ['draft' => 'Radna verzija', 'active' => 'Objavljeno', 'retired' => 'Arhiva'][$version->status] ?? $version->status }}</option>
                    @endforeach
                </select>
            </div>
            @if ($catalog && $canCreatePricing)
                <button type="button" wire:click="cloneCatalog" wire:loading.attr="disabled" class="admin-btn-secondary">{{ __('Nova radna kopija') }}</button>
            @endif
            @if ($catalog && $catalog->status === 'draft' && $canEditPricing)
                    <button type="button" wire:click="activate" wire:confirm="Objaviti ovu verziju za sve odobrene kupce? Prethodna verzija ostaje u arhivi." wire:loading.attr="disabled" class="admin-btn-primary">{{ __('Objavi cjenik') }}</button>
            @endif
        </div>
        <p wire:loading wire:target="cloneCatalog,activate" class="mt-3 text-sm text-cyan-700" role="status">{{ __('Priprema cjenika može potrajati zbog broja artikala. Pričekajte završetak.') }}</p>
        @if ($canCreatePricing)
        <details class="mt-4 text-sm"><summary class="cursor-pointer text-slate-500">{{ __('Napredno: novi prazni cjenik') }}</summary>
        <form wire:submit="createDraft" class="mt-3 flex flex-wrap gap-3">
            <input type="text" wire:model="name" placeholder="Naziv novog praznog cjenika" class="admin-search-input min-w-64 flex-1 rounded-lg px-3 py-2" maxlength="191">
            <button type="submit" wire:loading.attr="disabled" class="admin-btn-secondary">{{ __('Novi prazni cjenik') }}</button>
        </form>
        </details>
        @endif
        @if ($catalog)
            <p class="mt-3 text-xs text-slate-500">{{ __('Izvor') }}: {{ $catalog->source_system ?? 'Ručno' }} · {{ $catalog->source_snapshot ?? '—' }} @if ($catalog->activated_at) · {{ __('Objavljeno') }} {{ $catalog->activated_at->format('d.m.Y. H:i') }}@endif</p>
        @endif
    </div>

    @if ($catalog)
        <nav class="admin-panel flex flex-wrap gap-2 p-3" aria-label="Upravljanje B2B cjenikom">
            @foreach (['groups' => 'Grupe kupaca i cijene', 'rules' => 'Pravila popusta', 'product' => 'Cijene po artiklu', 'check' => 'Provjera cijene', 'advanced' => 'Napredne stavke', 'history' => 'Povijest promjena'] as $key => $label)
                <button type="button" wire:click="switchTab('{{ $key }}')" @if ($tab === $key) aria-current="page" @endif class="{{ $tab === $key ? 'admin-btn-primary' : 'admin-btn-secondary' }}">{{ __($label) }}</button>
            @endforeach
        </nav>
        @if ($tab === 'groups')
            @include('livewire.admin.catalog.pricing.partials.customer-group-overview')
        @elseif ($tab === 'rules')
            @include('livewire.admin.catalog.pricing.partials.group-discount-rules')
        @elseif ($tab === 'product')
            @include('livewire.admin.catalog.pricing.partials.product-group-prices')
        @endif
        @if (in_array($tab, ['advanced', 'check'], true))
        <div class="admin-panel p-5">
            <h2 class="admin-section-title">{{ __('Pretraga za unos i provjeru') }}</h2>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <input type="search" wire:model.live.debounce.300ms="productSearch" placeholder="Proizvod: šifra, SKU ili naziv (do 50 rezultata)" class="admin-search-input rounded-lg px-3 py-2">
                <input type="search" wire:model.live.debounce.300ms="customerSearch" placeholder="Kupac: tvrtka, e-mail ili OIB (do 50 rezultata)" class="admin-search-input rounded-lg px-3 py-2">
            </div>
        </div>
        @endif

        @if ($tab === 'advanced' && $catalog->status === 'draft' && $canEditPricing)
            <form wire:submit="saveEntry" class="admin-panel p-5">
                <h2 class="admin-section-title">{{ $entryId ? __('Uredi cijenu') : __('Nova cijena') }}</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <label class="text-sm">{{ __('Proizvod') }}
                        <select wire:model="form.product_id" class="admin-search-input admin-select mt-1 w-full rounded-lg px-3 py-2">
                            <option value="">{{ __('Odaberite proizvod iz pretrage') }}</option>
                            @foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->sku ?? $product->code }} — {{ $product->translations->firstWhere('locale', app()->getLocale())?->name ?? $product->code }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-sm">{{ __('Vrsta cijene') }}
                        <select wire:model.live="form.kind" class="admin-search-input admin-select mt-1 w-full rounded-lg px-3 py-2">
                            @foreach ($manualKinds as $kind => $label)<option value="{{ $kind }}">{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    @if ($form['kind'] === 'customer')
                        <label class="text-sm">{{ __('Kupac') }}
                            <select wire:model="form.user_id" class="admin-search-input admin-select mt-1 w-full rounded-lg px-3 py-2">
                                <option value="">{{ __('Odaberite kupca iz pretrage') }}</option>
                                @foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->b2bAccount?->company_name ?? $customer->name }} — {{ $customer->email }}</option>@endforeach
                            </select>
                        </label>
                    @elseif ($form['kind'] !== 'base')
                        <label class="text-sm">{{ __('Grupa kupaca') }}
                            <select wire:model="form.customer_group_id" class="admin-search-input admin-select mt-1 w-full rounded-lg px-3 py-2">
                                <option value="">{{ __('Odaberite grupu') }}</option>
                                @foreach ($groups as $group)<option value="{{ $group->id }}">{{ $group->name }} {{ $group->is_active ? '' : '(neaktivna)' }}</option>@endforeach
                            </select>
                        </label>
                    @endif
                    <label class="text-sm">{{ __('Cijena EUR') }}<input type="number" min="0" step="0.0001" wire:model="form.price" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
                    <label class="text-sm">{{ __('Minimalna količina') }}<input type="number" min="1" wire:model="form.minimum_quantity" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
                    <label class="text-sm">{{ __('Prioritet (manji broj pobjeđuje)') }}<input type="number" wire:model="form.priority" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
                    <label class="text-sm">{{ __('Vrijedi od (nije obavezno)') }}<input type="datetime-local" wire:model="form.starts_at" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
                    <label class="text-sm">{{ __('Vrijedi do (nije obavezno)') }}<input type="datetime-local" wire:model="form.ends_at" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.is_active">{{ __('Aktivna stavka') }}</label>
                </div>
                <div class="mt-4 flex gap-3"><button type="submit" wire:loading.attr="disabled" class="admin-btn-primary">{{ __('Spremi u radni cjenik') }}</button><button type="button" wire:click="resetEntry" class="admin-btn-secondary">{{ __('Novi unos') }}</button></div>
            </form>
        @endif

        @if ($tab === 'check')
        <form wire:submit="simulate" class="admin-panel p-5">
            <h2 class="admin-section-title">{{ __('Provjeri stvarnu cijenu kupca') }}</h2>
            <p class="mt-1 text-xs text-slate-500">{{ __('Provjera koristi odabranu verziju, grupu iz važećeg odobrenog računa, količinu i odabrani datum cijene. Ne objavljuje izmjene.') }}</p>
            <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <select aria-label="Proizvod za provjeru" wire:model="simulation.product_id" class="admin-search-input admin-select rounded-lg px-3 py-2"><option value="">{{ __('Proizvod iz pretrage') }}</option>@foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->sku ?? $product->code }}</option>@endforeach</select>
                <select aria-label="Kupac za provjeru" wire:model="simulation.user_id" class="admin-search-input admin-select rounded-lg px-3 py-2"><option value="">{{ __('Kupac iz pretrage') }}</option>@foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->b2bAccount?->company_name ?? $customer->name }} — {{ $customer->email }}</option>@endforeach</select>
                <input aria-label="Količina za provjeru" type="number" min="1" wire:model="simulation.quantity" class="admin-search-input rounded-lg px-3 py-2">
                <input aria-label="Datum za provjeru" type="datetime-local" wire:model="simulation.at" class="admin-search-input rounded-lg px-3 py-2">
            </div>
            <button type="submit" class="admin-btn-secondary mt-3">{{ __('Provjeri cijenu i izvor') }}</button>
            @if ($preview)<div class="mt-3 rounded-xl bg-cyan-50 p-4 text-sm"><strong>{{ $preview['price'] }} EUR</strong> · {{ $preview['source_label'] }} · {{ $preview['group_name'] }}@if ($preview['rule_name'])<p class="mt-1">{{ __('Pravilo') }}: {{ $preview['rule_name'] }}</p>@endif<details class="mt-2 text-xs text-slate-500"><summary class="cursor-pointer">{{ __('Detalji izvora cijene') }}</summary><p class="mt-1 font-mono">{{ $preview['source_key'] ?? 'Osnovna cijena proizvoda' }} {{ $preview['entry_id'] ? '(stavka #'.$preview['entry_id'].')' : '' }}</p></details></div>@endif
        </form>
        @endif

        @if ($tab === 'advanced')
        <div class="admin-panel overflow-hidden">
            <div class="flex flex-wrap gap-3 p-5">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Pretraži stavke, SKU, naziv ili kupca" class="admin-search-input min-w-64 flex-1 rounded-lg px-3 py-2">
                <select wire:model.live="kindFilter" class="admin-search-input admin-select rounded-lg px-3 py-2"><option value="">{{ __('Sve vrste cijena') }}</option>@foreach ($kinds as $kind => $label)<option value="{{ $kind }}">{{ $label }}</option>@endforeach</select>
            </div>
            <div class="overflow-x-auto">
                <table class="admin-items-table min-w-full text-sm">
                    <thead><tr><th class="p-3 text-left">{{ __('Proizvod') }}</th><th class="p-3 text-left">{{ __('Cijena / publika') }}</th><th class="p-3 text-left">{{ __('Uvjeti') }}</th><th class="p-3 text-left">{{ __('Izvor') }}</th>@if ($catalog->status === 'draft')<th class="p-3 text-right">{{ __('Akcije') }}</th>@endif</tr></thead>
                    <tbody>@forelse ($rows as $row)<tr class="border-t border-slate-100" wire:key="price-entry-{{ $row->id }}">
                        <td class="p-3"><strong>{{ $row->product?->sku ?? $row->product?->code }}</strong><p class="text-xs text-slate-500">{{ $row->product?->translations->firstWhere('locale', app()->getLocale())?->name }}</p></td>
                        <td class="p-3"><strong>{{ $row->price }} EUR</strong><p>{{ $kinds[$row->kind] ?? $row->kind }}</p><p class="text-xs text-slate-500">{{ $row->user?->b2bAccount?->company_name ?? $row->user?->email ?? $row->customerGroup?->name ?? 'Svi kupci' }}</p></td>
                        <td class="p-3"><p>{{ __('Od') }} {{ $row->minimum_quantity }} {{ __('kom.') }} · P{{ $row->priority }} · {{ $row->is_active ? 'Aktivno' : 'Neaktivno' }}</p><p class="text-xs text-slate-500">{{ $row->starts_at?->format('d.m.Y. H:i') ?? 'Bez početka' }} → {{ $row->ends_at?->format('d.m.Y. H:i') ?? 'Bez kraja' }}</p></td>
                        <td class="p-3 font-mono text-xs">{{ $row->source_key }}</td>
                        @if ($catalog->status === 'draft')<td class="p-3 text-right">@if ($row->discount_rule_id)<span class="text-xs text-slate-500">{{ __('Uredite pripadajuće pravilo popusta.') }}</span>@else @if ($canEditPricing)<button type="button" wire:click="editEntry({{ $row->id }})" class="text-cyan-700">{{ __('Uredi') }}</button>@endif @if ($canDeletePricing)<button type="button" wire:click="deleteEntry({{ $row->id }})" wire:confirm="Ukloniti ovu stavku iz radne verzije?" class="ml-2 text-red-700">{{ __('Ukloni') }}</button>@endif @endif</td>@endif
                    </tr>@empty<tr><td colspan="5" class="p-6 text-center text-slate-500">{{ __('Nema stavki za odabranu pretragu.') }}</td></tr>@endforelse</tbody>
                </table>
            </div>
            <div class="p-5">{{ $rows->links() }}</div>
        </div>
        @endif
        @if ($tab === 'history')
        <div class="admin-panel p-5"><h2 class="admin-section-title">{{ __('Zadnje promjene i objave') }}</h2><div class="mt-3 space-y-2">@forelse ($audits as $audit)<details class="border-b border-slate-100 pb-2 text-sm"><summary class="cursor-pointer">{{ $audit->created_at->format('d.m.Y. H:i') }} · {{ $audit->actor?->name ?? 'Sustav' }} · {{ $audit->event }} @if (isset($audit->payload['entry_id'])) · #{{ $audit->payload['entry_id'] }}@endif</summary><pre class="mt-2 overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($audit->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>@empty<p class="text-sm text-slate-500">{{ __('Još nema zabilježenih promjena.') }}</p>@endforelse</div></div>
        @endif
    @endif
</div>
