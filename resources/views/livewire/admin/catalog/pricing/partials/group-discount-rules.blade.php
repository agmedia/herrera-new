<section class="admin-panel p-5" aria-labelledby="group-discounts-title">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 id="group-discounts-title" class="admin-section-title">{{ __('Popusti po grupama kupaca') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Jedno pravilo može vrijediti za više grupa. Popusti se ne zbrajaju.') }}</p>
        </div>
        @if ($catalog->status === 'draft' && $canEditPricing)
            <button type="button" wire:click="newRule" class="admin-btn-primary">{{ __('Dodaj popust') }}</button>
        @endif
    </div>
    @if ($catalog->status !== 'draft')
        <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">{{ __('Ovaj cjenik je zaključan. Za dodavanje ili izmjenu popusta odaberite „Nova radna kopija”. Trenutne cijene kupaca ostaju nepromijenjene.') }}</p>
    @endif
    @if ($showRuleForm && $catalog->status === 'draft' && $canEditPricing)
        <form wire:submit="saveRule" class="mt-5 space-y-5 border-t border-slate-200 pt-5" data-group-discount-form>
            <h3 class="font-semibold text-slate-800">{{ $ruleId ? __('Uredi pravilo popusta') : __('Novi popust') }}</h3>
            @if ($legacyRuleWarnings)
                <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800"><p class="font-semibold">{{ __('Priprema izmjene izvornog pravila — nije objavljeno') }}</p>@foreach ($legacyRuleWarnings as $warning)<p class="mt-1">{{ $warning }}</p>@endforeach</div>
            @endif
            <div class="grid gap-4 md:grid-cols-3">
                <label class="text-sm md:col-span-2">{{ __('Naziv pravila') }}
                    <input type="text" wire:model="ruleForm.name" maxlength="191" required placeholder="Npr. Portwest – B2B45, B2B50 i B2B55" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2">
                </label>
                <label class="text-sm">{{ __('Popust (%)') }}
                    <input type="text" inputmode="decimal" wire:model="ruleForm.percent" required placeholder="Npr. 60" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2">
                </label>
            </div>
            <fieldset>
                <legend class="mb-2 text-sm font-semibold">{{ __('Grupe kupaca') }}</legend>
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($groups->where('is_active', true) as $group)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 p-3 text-sm" wire:key="discount-group-{{ $group->id }}">
                            <input type="checkbox" wire:model="ruleForm.customer_group_ids" value="{{ $group->id }}" class="rounded border-slate-300">
                            <span>{{ $group->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <details wire:ignore.self class="rounded-lg border border-slate-200 p-4">
                <summary class="cursor-pointer text-sm font-semibold">{{ __('Brandovi, kategorije i trajanje (neobavezno)') }}</summary>
                <p class="mt-2 text-xs text-slate-500">{{ __('Prazan izbor znači sve. Ako odaberete brandove i kategorije, artikl mora odgovarati oboma.') }}</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <fieldset>
                        <legend class="mb-2 text-sm font-semibold">{{ __('Brandovi') }}</legend>
                        <div class="max-h-56 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-3">
                            @foreach ($manufacturers as $brand)
                                <label class="flex items-center gap-2 text-sm" wire:key="discount-brand-{{ $brand->id }}">
                                    <input type="checkbox" wire:model="ruleForm.manufacturer_ids" value="{{ $brand->id }}">
                                    <span>{{ $brand->translations->firstWhere('locale', app()->getLocale())?->name ?? $brand->code }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend class="mb-2 text-sm font-semibold">{{ __('Kategorije') }}</legend>
                        <input type="search" wire:model.live.debounce.300ms="categorySearch" aria-label="Pretraži kategorije pravila" placeholder="Pretraži kategorije" class="admin-search-input mb-2 w-full rounded-lg px-3 py-2">
                        <div class="max-h-44 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-3">
                            @foreach ($categories as $category)
                                @if ($categorySearch === '' || str_contains(mb_strtolower($categoryLabels[$category->id]), mb_strtolower($categorySearch)))
                                    <label class="flex items-start gap-2 text-sm" wire:key="discount-category-{{ $category->id }}">
                                        <input type="checkbox" wire:model="ruleForm.category_ids" value="{{ $category->id }}" class="mt-1">
                                        <span>{{ $categoryLabels[$category->id] }}</span>
                                    </label>
                                @endif
                            @endforeach
                        </div>
                        <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="ruleForm.include_descendants">{{ __('Uključi podkategorije') }}</label>
                    </fieldset>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="text-sm">{{ __('Vrijedi od') }}<input type="datetime-local" wire:model="ruleForm.starts_at" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
                    <label class="text-sm">{{ __('Vrijedi do') }}<input type="datetime-local" wire:model="ruleForm.ends_at" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
                    <label class="text-sm">{{ __('Prioritet') }}<input type="number" wire:model="ruleForm.priority" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"><span class="mt-1 block text-xs text-slate-500">{{ __('Manji broj pobjeđuje. Kod istog prioriteta vrijedi veći popust.') }}</span></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="ruleForm.is_active">{{ __('Pravilo je uključeno') }}</label>
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ __('Početak je uključen; u trenutku završetka popust prestaje vrijediti. Bez datuma pravilo nema vremensko ograničenje.') }}</p>
                <fieldset class="mt-4">
                    <legend class="mb-2 text-sm font-semibold">{{ __('Izuzeti artikli') }}</legend>
                    <input type="search" wire:model.live.debounce.300ms="excludedProductSearch" placeholder="Pronađi artikl po šifri ili nazivu" aria-label="Pretraži izuzete artikle" class="admin-search-input w-full rounded-lg px-3 py-2">
                    @if ($excludedProducts->isNotEmpty())
                        <div class="mt-2 max-h-44 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-3">
                            @foreach ($excludedProducts as $excluded)
                                <label class="flex items-center gap-2 text-sm" wire:key="discount-excluded-{{ $excluded->id }}"><input type="checkbox" wire:model="ruleForm.excluded_product_ids" value="{{ $excluded->id }}"><span>{{ $excluded->sku ?: $excluded->code }} — {{ $excluded->translations->firstWhere('locale', app()->getLocale())?->name ?? $excluded->code }}</span></label>
                            @endforeach
                        </div>
                    @endif
                </fieldset>
            </details>
            <div class="rounded-lg bg-cyan-50 p-3 text-sm text-slate-700">
                {{ __('Popust se računa od osnovne cijene odabranog cjenika, ne od već snižene cijene. Novo pravilo zamjenjuje ranije uvezene akcije za odabrane artikle i grupe samo dok vrijedi. Ostale cijene ostaju sačuvane.') }}
            </div>
            <div class="flex flex-wrap gap-3">
                <button type="button" wire:click="previewRule" wire:loading.attr="disabled" class="admin-btn-secondary">{{ __('Pregledaj učinak') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="admin-btn-primary">{{ __('Spremi popust u radnu verziju') }}</button>
                <button type="button" wire:click="resetRule" class="admin-btn-secondary">{{ __('Odustani') }}</button>
            </div>
            <p wire:loading wire:target="previewRule,saveRule" class="text-sm text-cyan-700" role="status">{{ __('Izračunavam cijene odabranih artikala…') }}</p>
            @if ($rulePreview)
                <div class="rounded-lg border border-cyan-200 p-4" data-discount-preview>
                    <p class="font-semibold">{{ number_format($rulePreview['product_count'], 0, ',', '.') }} {{ __('artikala') }} · {{ $rulePreview['group_count'] }} {{ __('grupa kupaca') }} · {{ number_format($rulePreview['entry_count'], 0, ',', '.') }} {{ __('cijena') }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Pregled ne sprema niti objavljuje promjene. Primjeri prikazuju samo ovo pravilo; ukupnu cijenu kupca provjerite u kartici „Provjera cijene”.') }}</p>
                    <div class="mt-3 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr><th class="p-2 text-left">{{ __('Šifra artikla') }}</th><th class="p-2 text-right">{{ __('Osnovna cijena') }}</th><th class="p-2 text-right">{{ __('Cijena s popustom') }}</th></tr></thead><tbody>
                        @foreach ($rulePreview['samples'] as $sample)<tr class="border-t border-slate-100"><td class="p-2">{{ $sample['sku'] }}</td><td class="p-2 text-right">{{ $sample['base_price'] }} EUR</td><td class="p-2 text-right font-semibold">{{ $sample['price'] }} EUR</td></tr>@endforeach
                    </tbody></table></div>
                    @if (! $rulePreview['product_count'])<p class="mt-2 text-sm text-amber-700">{{ __('Nijedan artikl ne odgovara izboru. Provjerite brandove, kategorije i izuzetke.') }}</p>@endif
                </div>
            @endif
        </form>
    @endif
</section>
<section class="admin-panel overflow-hidden" aria-label="Popis pravila popusta">
    <div class="p-5"><input type="search" wire:model.live.debounce.300ms="ruleSearch" placeholder="Pretraži naziv pravila" aria-label="Pretraži pravila popusta" class="admin-search-input w-full rounded-lg px-3 py-2"></div>
    @if ($discountRules->count())
    <div class="overflow-x-auto"><table class="admin-items-table min-w-full text-sm">
        <thead><tr><th class="p-3 text-left">{{ __('Pravilo / popust') }}</th><th class="p-3 text-left">{{ __('Grupe kupaca') }}</th><th class="p-3 text-left">{{ __('Obuhvat') }}</th><th class="p-3 text-left">{{ __('Trajanje / status') }}</th><th class="p-3 text-right">{{ __('Akcije') }}</th></tr></thead>
        <tbody>
        @forelse ($discountRules as $rule)
            <tr class="border-t border-slate-100" wire:key="discount-rule-{{ $rule->id }}">
                <td class="p-3"><strong>{{ $rule->name }}</strong><p class="mt-1 font-semibold text-cyan-700">{{ rtrim(rtrim($rule->percent, '0'), '.') }} %</p><p class="text-xs text-slate-500">{{ __('Prioritet') }} {{ $rule->priority }}</p></td>
                <td class="p-3">{{ $groups->whereIn('id', $rule->customer_group_ids)->pluck('name')->join(', ') }}</td>
                <td class="p-3"><p>{{ count($rule->manufacturer_ids ?? []) ? $manufacturers->whereIn('id', $rule->manufacturer_ids)->map(fn ($brand) => $brand->translations->firstWhere('locale', app()->getLocale())?->name ?? $brand->code)->join(', ') : __('Svi brandovi') }}</p><p class="mt-1 text-xs text-slate-500">{{ count($rule->category_ids ?? []) ? collect($rule->category_ids)->map(fn ($id) => $categoryLabels[$id] ?? '#'.$id)->join(', ') : __('Sve kategorije') }}</p><p class="mt-1 text-xs text-slate-500">{{ number_format($rule->materialized_product_count, 0, ',', '.') }} {{ __('artikala') }}@if (count($rule->excluded_product_ids ?? [])) · {{ count($rule->excluded_product_ids) }} {{ __('izuzetaka') }}@endif</p></td>
                <td class="p-3"><p>{{ $rule->starts_at?->format('d.m.Y. H:i') ?? __('Bez početka') }} → {{ $rule->ends_at?->format('d.m.Y. H:i') ?? __('Bez kraja') }}</p><p class="mt-1 text-xs text-slate-500">{{ ! $rule->is_active ? __('Isključeno') : ($rule->ends_at?->lte(now()) ? __('Isteklo') : ($rule->starts_at?->gt(now()) ? __('Zakazano') : __('Uključeno'))) }}</p></td>
                <td class="p-3 text-right">@if ($catalog->status === 'draft')@if ($canEditPricing)<button type="button" wire:click="editRule({{ $rule->id }})" class="font-semibold text-cyan-700">{{ __('Uredi') }}</button>@endif @if ($canDeletePricing)<button type="button" wire:click="deleteRule({{ $rule->id }})" wire:confirm="Ukloniti ovo pravilo iz radne verzije? Uvezene cijene ostaju sačuvane." class="ml-3 text-red-700">{{ __('Ukloni') }}</button>@endif @else<span class="text-xs text-slate-400">{{ __('Zaključano') }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="5" class="p-6 text-center text-slate-500"><p>{{ __('Nema novih pravila popusta za odabranu verziju.') }}</p><p class="mt-2 text-xs">{{ __('Uvezene ugovorene i akcijske cijene su sačuvane. Ovo je pregled novih pravila, ne popis svih prenesenih cijena.') }}</p></td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="p-5">{{ $discountRules->links() }}</div>
    @else
        <div class="p-6 text-sm text-slate-500"><p>{{ __('Nema novih pravila popusta za odabranu verziju.') }}</p><p class="mt-2 text-xs leading-5">{{ __('Uvezene ugovorene i akcijske cijene su sačuvane. Ovo je pregled novih pravila, ne popis svih prenesenih cijena.') }}</p></div>
    @endif
</section>
