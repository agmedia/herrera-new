@if (! $selectedGroup)
<section class="admin-panel overflow-hidden" data-customer-group-overview>
    <div class="p-5">
        <h2 class="admin-section-title">{{ __('Sve grupe kupaca') }}</h2>
        <p class="mt-2 text-sm text-slate-500">{{ __('Ovo su postojeće cijene u odabranoj verziji, uključujući prenesene cijene starog webshopa. Otvorite grupu da vidite artikle, izvore cijena i pravila.') }}</p>
    </div>
    <div class="overflow-x-auto"><table class="admin-items-table min-w-full text-sm">
        <thead><tr><th class="p-3 text-left">{{ __('Grupa kupaca') }}</th><th class="p-3 text-right">{{ __('Grupne cijene') }}</th><th class="p-3 text-right">{{ __('Akcijske stavke') }}</th><th class="p-3 text-right">{{ __('Količinske stavke') }}</th><th class="p-3 text-right">{{ __('Cijene iz novih pravila') }}</th><th class="p-3 text-right">{{ __('Pregled / izmjena') }}</th></tr></thead>
        <tbody>@foreach ($groups as $group)
            @php($counts = $groupOverview->get($group->id, []))
            <tr class="border-t border-slate-100" wire:key="group-overview-{{ $catalogId }}-{{ $group->id }}" data-customer-group="{{ $group->id }}">
                <td class="p-3"><strong>{{ $group->name }}</strong>@if (! $group->is_active)<p class="text-xs text-amber-700">{{ __('Neaktivna grupa') }}</p>@endif</td>
                <td class="p-3 text-right tabular-nums">{{ number_format($counts['group'] ?? 0, 0, ',', '.') }}</td>
                <td class="p-3 text-right tabular-nums">{{ number_format($counts['special'] ?? 0, 0, ',', '.') }}</td>
                <td class="p-3 text-right tabular-nums">{{ number_format($counts['quantity'] ?? 0, 0, ',', '.') }}</td>
                <td class="p-3 text-right tabular-nums">{{ number_format($counts['group_discount'] ?? 0, 0, ',', '.') }}</td>
                <td class="p-3 text-right"><button type="button" wire:click="openGroup({{ $group->id }})" class="admin-btn-secondary whitespace-nowrap">{{ __('Cijene i pravila') }}</button></td>
            </tr>
        @endforeach</tbody>
    </table></div>
    <p class="p-5 text-xs leading-5 text-slate-500">{{ __('Brojevi uključuju sve spremljene stavke: važeće, zakazane i istekle. Ako nema grupne cijene, koristi se osnovna cijena. Individualni ugovori kupaca pregledavaju se u „Provjera cijene”; nisu zajednička cijena grupe.') }}</p>
</section>
@else
<section class="admin-panel p-5" data-selected-customer-group="{{ $selectedGroup->id }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><button type="button" wire:click="backToGroups" class="mb-2 text-sm font-semibold text-cyan-700">← {{ __('Sve grupe kupaca') }}</button><h2 class="text-xl font-semibold text-slate-900">{{ $selectedGroup->name }} — {{ __('cijene i pravila') }}</h2></div>
        <div class="flex flex-wrap gap-2">
            @if ($catalog->status !== 'draft' && $canEditPricing)
                <button type="button" wire:click="beginGroupEditing" wire:loading.attr="disabled" class="admin-btn-primary">{{ __('Uredi ovu grupu') }}</button>
            @elseif ($catalog->status === 'draft' && $canEditPricing && $selectedGroup->is_active)
                <button type="button" wire:click="newGroupRule" class="admin-btn-primary">{{ __('Dodaj popust za ovu grupu') }}</button>
            @endif
        </div>
    </div>
    <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Cijene ispod su stvarne spremljene cijene, ne postotak pretpostavljen iz naziva grupe. Prikazana trenutna cijena vrijedi za 1 komad na današnji datum, bez individualnih ugovora kupca.') }}</p>
    @if ($catalog->status !== 'draft')<p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">{{ __('Ovaj cjenik je zaključan. „Uredi ovu grupu” otvara postojeću radnu kopiju ili priprema novu; objavljene cijene ostaju nepromijenjene.') }}</p>@else<p class="mt-3 rounded-lg bg-cyan-50 p-3 text-sm text-cyan-800">{{ __('Uređujete radnu kopiju. Izmjene će vrijediti kupcima tek nakon „Objavi cjenik”.') }}</p>@endif
    @if (! $selectedGroup->is_active)<p class="mt-3 text-sm text-amber-700">{{ __('Grupa je neaktivna; ovo je pregled spremljenih cijena, ne pravo kupca na kupnju.') }}</p>@endif
    <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('Redoslijed odabira: novo pravilo popusta → akcijska → količinska → individualna → grupna → osnovna cijena. Unutar iste vrste prednost ima manji prioritet, zatim niža cijena; količinski uvjet i datumi moraju biti zadovoljeni. Ručno zadana grupna cijena ima prednost nad uvezenom grupnom cijenom. Popusti se ne zbrajaju.') }}</p>
</section>

<section class="admin-panel overflow-hidden" data-group-existing-rules>
    <div class="p-5"><h3 class="admin-section-title">{{ __('Izvorna pravila starog webshopa') }}</h3><p class="mt-2 text-xs leading-5 text-slate-500">{{ __('Definicije su preuzete iz baze radi pregleda. Stari webshop nije pohranio vezu svake konačne cijene s pravilom; zato su prenesene apsolutne cijene ispod mjerodavne. Pregled definicije sam po sebi ništa ne preračunava.') }}</p></div>
    @if ($legacyReferences->isNotEmpty())
    <div class="overflow-x-auto"><table class="admin-items-table min-w-full text-sm"><thead><tr><th class="p-3 text-left">{{ __('Pravilo / popust') }}</th><th class="p-3 text-left">{{ __('Brandovi / kategorije') }}</th><th class="p-3 text-left">{{ __('Grupe / trajanje') }}</th><th class="p-3 text-right">{{ __('Izmjena') }}</th></tr></thead><tbody>
        @foreach ($legacyReferences as $reference)
        <tr class="border-t border-slate-100" wire:key="legacy-rule-{{ $reference->id }}">
            <td class="p-3 align-top"><strong>{{ $reference->name }}</strong><p class="mt-1 font-semibold text-cyan-700">{{ $reference->percent !== null ? rtrim(rtrim($reference->percent, '0'), '.').' %' : __('Druga vrsta popusta') }}</p><p class="mt-1 text-xs text-slate-500">{{ __('Prioritet') }} {{ $reference->priority }} · {{ $reference->source_type === 'cigroup_template' ? __('Predložak za grupne cijene') : ($reference->is_active ? __('Razdoblje važi') : __('Razdoblje ne važi')) }}</p></td>
            <td class="p-3 align-top"><p>{{ count($reference->manufacturer_ids ?? []) ? $manufacturers->whereIn('id', $reference->manufacturer_ids)->map(fn ($brand) => $brand->translations->firstWhere('locale', app()->getLocale())?->name ?? $brand->code)->join(', ') : __('Svi brandovi') }}</p><p class="mt-1 text-xs text-slate-500">{{ count($reference->category_ids ?? []) ? collect($reference->category_ids)->map(fn ($id) => $categoryLabels[$id] ?? '#'.$id)->join(', ') : __('Sve kategorije') }}</p>@if (count($reference->excluded_product_ids ?? []))<p class="mt-1 text-xs text-slate-500">{{ count($reference->excluded_product_ids) }} {{ __('izuzetih artikala') }}</p>@endif
                @if (count($reference->category_ids ?? []))<p class="mt-1 text-xs text-slate-500">{{ $reference->include_descendants ? __('Uključuje podkategorije') : __('Bez podkategorija') }}</p>@endif
                @foreach ($reference->warnings ?? [] as $warning)@if (! str_starts_with($warning, 'Gotove uvezene cijene su mjerodavne.'))<p class="mt-2 text-xs text-amber-700">{{ $warning }}</p>@endif @endforeach
            </td>
            <td class="p-3 align-top"><p>{{ $groups->whereIn('id', $reference->customer_group_ids ?? [])->pluck('name')->join(', ') }}</p><p class="mt-1 text-xs text-slate-500">{{ $reference->starts_at?->format('d.m.Y. H:i') ?? __('Bez početka') }} → {{ $reference->ends_at?->format('d.m.Y. H:i') ?? __('Bez kraja') }}</p><p class="mt-1 text-xs text-slate-500">{{ $reference->ends_at?->lte(now()) ? __('Isteklo') : ($reference->starts_at?->gt(now()) ? __('Zakazano') : __('Razdoblje u tijeku')) }}</p></td>
            <td class="p-3 text-right align-top">@if ($catalog->status === 'draft' && $canEditPricing && $reference->is_supported)<button type="button" wire:click="prepareLegacyRule({{ $reference->id }})" class="admin-btn-secondary whitespace-nowrap">{{ __('Pripremi izmjenu') }}</button>@else<span class="text-xs text-slate-500">{{ $reference->is_supported ? __('Uredite u radnoj kopiji') : __('Potreban ručni pregled') }}</span>@endif</td>
        </tr>
        @endforeach
    </tbody></table></div>
    @else<p class="px-5 pb-5 text-sm text-slate-500">{{ __('Nema izvornih definicija pravila za ovu grupu. Postojeće cijene artikala prikazane su ispod.') }}</p>@endif
</section>

@if ($catalog->discountRules->contains(fn ($rule) => in_array($selectedGroup->id, $rule->customer_group_ids, true)))
<section class="admin-panel p-5"><h3 class="admin-section-title">{{ __('Nova pravila ove grupe') }}</h3>
    @foreach ($catalog->discountRules->filter(fn ($rule) => in_array($selectedGroup->id, $rule->customer_group_ids, true)) as $rule)
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3 text-sm"><p><strong>{{ $rule->name }}</strong> · {{ rtrim(rtrim($rule->percent, '0'), '.') }} % · {{ $rule->is_active ? __('Uključeno') : __('Isključeno') }}</p>@if ($catalog->status === 'draft' && $canEditPricing)<button type="button" wire:click="openGroupRule({{ $rule->id }})" class="admin-btn-secondary">{{ __('Uredi pravilo') }}</button>@endif</div>
    @endforeach
</section>
@endif

@if ($groupEntryId && $groupEntryContext)
<form wire:submit="saveGroupEntry" class="admin-panel p-5" data-group-entry-editor>
    <h3 class="admin-section-title">{{ __('Uredi postojeću cijenu') }} — {{ $groupEntryContext['sku'] }} · {{ $selectedGroup->name }}</h3>
    <p class="mt-2 text-xs text-slate-500">{{ $kinds[$groupEntryContext['kind']] }} · {{ __('Od') }} {{ $groupEntryContext['minimum_quantity'] }} {{ __('kom.') }} · {{ $groupEntryContext['source_key'] }}</p>
    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <label class="text-sm">{{ __('Cijena EUR') }}<input type="text" inputmode="decimal" wire:model="groupEntryForm.price" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2" required></label>
        <label class="text-sm">{{ __('Prioritet') }}<input type="number" wire:model="groupEntryForm.priority" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
        <label class="text-sm">{{ __('Vrijedi od') }}<input type="datetime-local" step="1" wire:model="groupEntryForm.starts_at" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
        <label class="text-sm">{{ __('Vrijedi do') }}<input type="datetime-local" step="1" wire:model="groupEntryForm.ends_at" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="groupEntryForm.is_active">{{ __('Stavka je uključena') }}</label>
    </div>
    <p class="mt-3 text-xs text-slate-500">{{ __('Mijenja se samo ova stavka za ovu grupu. Ako postoji jače važeće pravilo ili akcija, konačna cijena može ostati određena njime — provjerite izvor u tablici.') }}</p>
    <div class="mt-4 flex flex-wrap gap-3"><button type="submit" wire:loading.attr="disabled" class="admin-btn-primary">{{ __('Spremi cijenu u radnu kopiju') }}</button><button type="button" wire:click="cancelGroupEntry" class="admin-btn-secondary">{{ __('Odustani') }}</button></div>
</form>
@endif

<section class="admin-panel overflow-hidden" data-group-price-list>
    <div class="p-5"><h3 class="admin-section-title">{{ __('Postojeće cijene artikala') }} — {{ $selectedGroup->name }}</h3><input type="search" wire:model.live.debounce.300ms="groupPriceSearch" aria-label="Pretraži cijene odabrane grupe" placeholder="Pretraži šifru ili naziv artikla" class="admin-search-input mt-3 w-full rounded-lg px-3 py-2"><p class="mt-2 text-xs text-slate-500">{{ __('Otvorite „Sve stavke i uvjeti” za grupnu cijenu, akcije, datume i prioritet. Postotak uz cijenu samo je izračun razlike prema osnovnoj cijeni, nije definicija pravila.') }}</p></div>
    <div class="overflow-x-auto"><table class="admin-items-table min-w-full text-sm"><thead><tr><th class="p-3 text-left">{{ __('Artikl') }}</th><th class="p-3 text-right">{{ __('Osnovna cijena') }}</th><th class="p-3 text-right">{{ __('Trenutna cijena grupe') }}</th><th class="p-3 text-left">{{ __('Odabrani izvor / postojeće stavke') }}</th></tr></thead><tbody>
        @forelse ($groupRows as $row)
            @php($chosen = $row['effective_entry'])
            <tr class="border-t border-slate-100" wire:key="group-price-{{ $catalogId }}-{{ $selectedGroupId }}-{{ $row['product']->id }}">
                <td class="p-3 align-top"><strong>{{ $row['product']->sku ?: $row['product']->code }}</strong><p class="mt-1 text-xs text-slate-500">{{ $row['product']->translations->firstWhere('locale', app()->getLocale())?->name ?? $row['product']->code }}</p>@if (! $row['product']->is_active)<p class="mt-1 text-xs text-amber-700">{{ __('Artikl nije aktivan') }}</p>@endif<button type="button" wire:click="openGroupProduct({{ $row['product']->id }})" class="mt-2 text-xs font-semibold text-cyan-700">{{ __('Cijene ovog artikla u svim grupama') }}</button></td>
                <td class="p-3 text-right align-top tabular-nums whitespace-nowrap">{{ $row['base_price'] }} EUR</td>
                <td class="p-3 text-right align-top tabular-nums whitespace-nowrap"><strong>{{ $row['effective_price'] }} EUR</strong>@if ($row['discount_percent'] !== null)<p class="mt-1 text-xs text-slate-500">{{ $row['discount_percent'] }} % {{ __('razlike') }}</p>@endif</td>
                <td class="p-3 align-top"><p class="font-semibold">{{ $chosen?->discountRule?->name ?? ($kinds[$chosen?->kind ?? 'base'] ?? '—') }}</p>
                    <details wire:ignore.self class="mt-2" data-group-price-sources><summary class="cursor-pointer text-xs font-semibold text-cyan-700">{{ __('Sve stavke i uvjeti') }} ({{ $row['entries']->count() }})</summary>
                        <div class="mt-2 space-y-3">@foreach ($row['entries'] as $source)
                            <div class="border-l-2 {{ $chosen?->id === $source->id ? 'border-cyan-500' : 'border-slate-200' }} pl-3 text-xs" wire:key="group-source-{{ $source->id }}">
                                <p><strong>{{ $source->price }} EUR</strong> · {{ $source->discountRule?->name ?? $kinds[$source->kind] }} @if ($chosen?->id === $source->id)<span class="font-semibold text-cyan-700"> · {{ __('Vrijedi za 1 kom. sada') }}</span>@endif</p>
                                <p class="mt-1 text-slate-500">{{ __('Prioritet') }} {{ $source->priority }} · {{ __('Od') }} {{ $source->minimum_quantity }} {{ __('kom.') }} · {{ ! $source->is_active ? __('Isključeno') : ($source->ends_at?->lte(now()) ? __('Isteklo') : ($source->starts_at?->gt(now()) ? __('Zakazano') : __('Uključeno'))) }}</p>
                                <p class="text-slate-500">{{ $source->starts_at?->format('d.m.Y. H:i:s') ?? __('Bez početka') }} → {{ $source->ends_at?->format('d.m.Y. H:i:s') ?? __('Bez kraja') }}</p><p class="mt-1 break-all text-slate-400">{{ $source->source_key }}</p>
                                @if ($source->customer_group_id === $selectedGroup->id && $catalog->status === 'draft' && $canEditPricing)<button type="button" wire:click="editGroupEntry({{ $source->id }})" class="mt-1 font-semibold text-cyan-700">{{ $source->discount_rule_id ? __('Uredi pravilo') : __('Uredi ovu cijenu') }}</button>@endif
                            </div>
                        @endforeach</div>
                    </details>
                </td>
            </tr>
        @empty<tr><td colspan="4" class="p-5 text-center text-slate-500">{{ __('Nema artikala za odabranu pretragu.') }}</td></tr>@endforelse
    </tbody></table></div><div class="p-5">{{ $groupProducts->links() }}</div>
</section>
@endif
