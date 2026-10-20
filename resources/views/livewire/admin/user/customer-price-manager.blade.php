<div class="space-y-6" data-customer-price-manager="{{ $userId }}">
    <section class="admin-panel p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="admin-section-title">{{ __('B2B cjenik kupca') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-900">{{ $account?->company_name ?: $customer->name }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ __('Primarna cjenovna grupa') }}: <strong>{{ $account?->customerGroup?->name ?? __('Nije odabrana') }}</strong>@if ($account?->customerGroup) <span class="text-xs text-slate-500">({{ $account->customerGroup->code }})</span>@endif</p>
                @if ($account?->customerGroup?->default_discount_percent !== null)<p class="mt-1 text-sm text-slate-600">{{ __('Osnovni popust grupe') }}: <strong>{{ rtrim(rtrim($account->customerGroup->default_discount_percent, '0'), '.') }} %</strong></p>@endif
                @if ($catalog)<p class="mt-1 text-xs text-slate-500">{{ __('Objavljeni cjenik') }}: {{ $catalog->name }}</p>@endif
            </div>
            @if ($account && $canUpdate)
                <button type="button" wire:click="newRule" class="admin-btn-primary">{{ __('Dodaj poseban popust') }}</button>
            @endif
        </div>
        <p class="mt-4 text-sm leading-6 text-slate-600">{{ __('Za artikle obuhvaćene posebnim pravilom vrijedi popust kupca. Za ostale artikle vrijede uvjeti primarne grupe. Postotak se računa od osnovne cijene cjenika; popusti se ne zbrajaju. Spremljena pravila vrijede i nakon objave nove verzije cjenika.') }}</p>
        <p class="mt-3 rounded-lg bg-cyan-50 p-3 text-sm text-cyan-800">{{ __('Popusti prate kategorije: novi artikli i artikli naknadno dodani u kategoriju automatski dobivaju važeći popust. Kad artikl više ne pripada toj kategoriji, njezin popust prestaje vrijediti.') }}</p>
        @if (! $account)
            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ __('Najprije kreirajte i spremite B2B profil na kartici „Podaci kupca”. Ovdje zatim možete uređivati njegove posebne popuste.') }}</p>
        @elseif (! $accessAvailable)
            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ __('B2B pristup trenutačno nije aktivan. Pravila možete pripremiti, a cijene će kupcu biti dostupne nakon odobrenja profila, odabira aktivne primarne grupe i unutar važenja ugovora.') }}</p>
        @endif
        @if (! $catalog)<p class="mt-3 text-sm text-amber-800">{{ __('Nema objavljenog B2B cjenika. Posebni popusti primjenjuju se nakon objave cjenika.') }}</p>@endif
        @if ($savedMessage)<p class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ $savedMessage }}</p>@endif
    </section>

    @if ($showRuleForm && $account && $canUpdate)
    <form wire:submit="saveRule" class="admin-panel space-y-5 p-5" data-customer-discount-form>
        <h3 class="admin-section-title">{{ $ruleId ? __('Uredi popust kupca') : __('Novi popust za ovog kupca') }}</h3>
        @if ($errors->any())<div class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <div class="grid gap-4 md:grid-cols-3">
            <label class="text-sm md:col-span-2">{{ __('Naziv pravila') }}<input type="text" wire:model="ruleForm.name" maxlength="191" required placeholder="Npr. Portwest radne cipele – 60 %" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
            <label class="text-sm">{{ __('Popust (%)') }}<input type="text" inputmode="decimal" wire:model="ruleForm.percent" required placeholder="60" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"><span class="mt-1 block text-xs text-slate-500">{{ __('Ukupni popust od osnovne cijene, od 0 do 100 %.') }}</span></label>
        </div>
        <p class="text-sm text-slate-600">{{ __('Prazan izbor znači sve. Ako odaberete i dobavljače i kategorije, pravilo vrijedi samo za artikle koji odgovaraju oboma.') }}</p>
        <div class="grid gap-5 lg:grid-cols-2">
            <fieldset>
                <legend class="mb-2 text-sm font-semibold">{{ __('Dobavljači / brendovi') }}</legend>
                <input type="search" wire:model.live.debounce.300ms="manufacturerSearch" placeholder="Pretraži dobavljače / brendove" aria-label="Pretraži dobavljače / brendove" class="admin-search-input mb-2 w-full rounded-lg px-3 py-2">
                <div class="max-h-56 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-3">
                    @foreach ($manufacturers as $brand)
                        @if (($brand->is_active || in_array($brand->id, $ruleForm['manufacturer_ids'])) && ($manufacturerSearch === '' || str_contains(mb_strtolower($manufacturerLabels[$brand->id]), mb_strtolower($manufacturerSearch)) || in_array($brand->id, $ruleForm['manufacturer_ids'])))
                        <label class="flex items-start gap-2 text-sm" wire:key="customer-discount-brand-{{ $brand->id }}"><input type="checkbox" wire:model="ruleForm.manufacturer_ids" value="{{ $brand->id }}" class="mt-1 rounded border-slate-300"><span>{{ $manufacturerLabels[$brand->id] }}</span></label>
                        @endif
                    @endforeach
                </div>
            </fieldset>
            <fieldset>
                <legend class="mb-2 text-sm font-semibold">{{ __('Kategorije artikala') }}</legend>
                <input type="search" wire:model.live.debounce.300ms="categorySearch" placeholder="Pretraži kategorije" aria-label="Pretraži kategorije popusta kupca" class="admin-search-input mb-2 w-full rounded-lg px-3 py-2">
                <div class="max-h-56 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-3">
                    @foreach ($categories as $category)
                        @if ($categorySearch === '' || str_contains(mb_strtolower($categoryLabels[$category->id]), mb_strtolower($categorySearch)) || in_array($category->id, $ruleForm['category_ids']))
                        <label class="flex items-start gap-2 text-sm" wire:key="customer-discount-category-{{ $category->id }}"><input type="checkbox" wire:model="ruleForm.category_ids" value="{{ $category->id }}" class="mt-1 rounded border-slate-300"><span>{{ $categoryLabels[$category->id] }}</span></label>
                        @endif
                    @endforeach
                </div>
                <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="ruleForm.include_descendants" class="rounded border-slate-300">{{ __('Uključi podkategorije') }}</label>
            </fieldset>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <label class="text-sm">{{ __('Vrijedi od') }}<input type="datetime-local" step="1" wire:model="ruleForm.starts_at" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
            <label class="text-sm">{{ __('Vrijedi do') }}<input type="datetime-local" step="1" wire:model="ruleForm.ends_at" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
            <label class="text-sm">{{ __('Prioritet') }}<input type="number" wire:model="ruleForm.priority" class="admin-search-input mt-1 w-full rounded-lg px-3 py-2"></label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="ruleForm.is_active" class="rounded border-slate-300">{{ __('Pravilo je uključeno') }}</label>
        </div>
        <p class="text-xs leading-5 text-slate-500">{{ __('Prednost ima kombinacija kategorije i dobavljača, zatim kategorija, dobavljač, pa opće pravilo. Unutar istog opsega prednost ima manji prioritet, a kod izjednačenja ranije stvoreno pravilo. Bez datuma pravilo nema vremensko ograničenje.') }}</p>
        <details wire:ignore.self class="rounded-lg border border-slate-200 p-4">
            <summary class="cursor-pointer text-sm font-semibold">{{ __('Izuzeti artikli (neobavezno)') }} · {{ count($ruleForm['excluded_product_ids']) }}</summary>
            <input type="search" wire:model.live.debounce.300ms="excludedProductSearch" placeholder="Pronađi artikl po šifri ili nazivu" aria-label="Pretraži izuzete artikle" class="admin-search-input mt-3 w-full rounded-lg px-3 py-2">
            <div class="mt-3 max-h-44 space-y-2 overflow-y-auto">
                @foreach ($excludedProducts as $excluded)
                <label class="flex items-center gap-2 text-sm" wire:key="customer-excluded-{{ $excluded->id }}"><input type="checkbox" wire:model="ruleForm.excluded_product_ids" value="{{ $excluded->id }}" class="rounded border-slate-300"><span>{{ $excluded->sku ?: $excluded->code }} — {{ $excluded->translations->firstWhere('locale', app()->getLocale())?->name ?? $excluded->code }}</span></label>
                @endforeach
            </div>
        </details>
        <div class="flex flex-wrap gap-3">
            <button type="submit" wire:loading.attr="disabled" class="admin-btn-primary">{{ __('Spremi popust kupca') }}</button>
            <button type="button" wire:click="resetRule" class="admin-btn-secondary">{{ __('Odustani') }}</button>
        </div>
        <p class="text-xs text-slate-500">{{ __('Spremanjem se pravilo odmah primjenjuje za ovog kupca prema zadanim datumima. Primarna grupa i ostali kupci ostaju nepromijenjeni.') }}</p>
    </form>
    @endif

    <section class="admin-panel overflow-hidden" data-customer-discount-rules>
        <div class="p-5"><h3 class="admin-section-title">{{ __('Posebni popusti ovog kupca') }}</h3><p class="mt-2 text-sm text-slate-500">{{ __('Pregled svih posebnih uvjeta, uključujući zakazane, istekle i isključene popuste.') }}</p></div>
        @if ($rules->isNotEmpty())
        <div class="overflow-x-auto"><table class="admin-items-table min-w-full text-sm">
            <thead><tr><th class="p-3 text-left">{{ __('Pravilo / popust') }}</th><th class="p-3 text-left">{{ __('Dobavljači i kategorije') }}</th><th class="p-3 text-left">{{ __('Trajanje / status') }}</th><th class="p-3 text-right">{{ __('Akcije') }}</th></tr></thead>
            <tbody>@foreach ($rules as $rule)
                <tr class="border-t border-slate-100" wire:key="customer-discount-{{ $rule->id }}">
                    <td class="p-3 align-top"><strong>{{ $rule->name }}</strong><p class="mt-1 text-lg font-semibold text-cyan-700">{{ rtrim(rtrim($rule->percent, '0'), '.') }} %</p><p class="mt-1 text-xs text-slate-500">{{ $rule->source_key ? __('Izvorni popust po kategoriji sa stare stranice') : __('Prioritet').' '.$rule->priority }}</p></td>
                    <td class="p-3 align-top">@include('livewire.admin.user.partials.price-rule-scope')</td>
                    <td class="p-3 align-top"><span class="admin-chip">{{ ! $rule->is_active ? __('Isključeno') : ($rule->ends_at?->lte(now()) ? __('Isteklo') : ($rule->starts_at?->gt(now()) ? __('Zakazano') : __('Aktivno')) ) }}</span><p class="mt-2 text-xs text-slate-500">{{ $rule->starts_at?->format('d.m.Y. H:i') ?? __('Bez početka') }} → {{ $rule->ends_at?->format('d.m.Y. H:i') ?? __('Bez kraja') }}</p></td>
                    <td class="p-3 text-right align-top"><div class="flex justify-end gap-2">@if ($canUpdate)<button type="button" wire:click="editRule({{ $rule->id }})" class="admin-btn-secondary">{{ __('Uredi') }}</button>@endif @if ($canDelete)<button type="button" wire:click="deleteRule({{ $rule->id }})" wire:confirm="{{ __('Obrisati ovaj posebni popust kupca?') }}" class="admin-btn-secondary text-rose-700">{{ __('Obriši') }}</button>@endif</div></td>
                </tr>
            @endforeach</tbody>
        </table></div>
        @else<p class="px-5 pb-5 text-sm text-slate-500">{{ __('Kupac nema posebnih popusta. Koriste se cijene i pravila primarne grupe.') }}</p>@endif
    </section>

    @if ($catalog && $account?->customerGroup)
    <details wire:ignore.self class="admin-panel p-5" data-customer-inherited-rules>
        <summary class="cursor-pointer text-sm font-semibold text-slate-800">{{ __('Naslijeđeni uvjeti primarne grupe') }} — {{ $account->customerGroup->name }}</summary>
        <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('Ovdje su pravila primarne grupe iz B2B cjenika. Pravila se primjenjuju i na nove artikle koji zadovoljavaju njihov opseg. „Prilagodi kupcu” priprema posebno pravilo s istim kategorijama i dobavljačima; odaberite novi popust i spremite ga.') }}</p>
        @foreach (['group' => $groupRules, 'legacy' => $legacyReferences] as $source => $inheritedRules)
            @foreach ($inheritedRules as $rule)
            <div class="mt-4 grid gap-3 border-t border-slate-100 pt-4 text-sm lg:grid-cols-3" wire:key="customer-inherited-{{ $source }}-{{ $rule->id }}">
                <div><strong>{{ $rule->name }}</strong><p class="mt-1 font-semibold text-cyan-700">{{ $rule->percent !== null ? rtrim(rtrim($rule->percent, '0'), '.').' %' : __('Druga vrsta popusta') }}</p><p class="mt-1 text-xs text-slate-500">{{ $source === 'legacy' ? __('Izvorno pravilo starog webshopa') : __('Pravilo objavljenog cjenika') }} · {{ $source === 'legacy' && $rule->source_type === 'cigroup_template' ? __('Predložak za artikle bez grupne cijene') : ($rule->is_active ? __('Razdoblje važi') : __('Razdoblje ne važi')) }}</p><p class="mt-1 text-xs text-slate-500">{{ $rule->starts_at?->format('d.m.Y. H:i') ?? __('Bez početka') }} → {{ $rule->ends_at?->format('d.m.Y. H:i') ?? __('Bez kraja') }}</p></div>
                <div>@include('livewire.admin.user.partials.price-rule-scope')</div>
                <div class="lg:text-right">@if ($canUpdate && ($source === 'group' || ($rule->is_supported && $rule->percent !== null)))<button type="button" wire:click="personalizeRule({{ $rule->id }}, '{{ $source }}')" class="admin-btn-secondary">{{ __('Prilagodi kupcu') }}</button>@endif</div>
            </div>
            @endforeach
        @endforeach
        @if ($groupRules->isEmpty() && $legacyReferences->isEmpty())<p class="mt-3 text-sm text-slate-500">{{ __('Za ovu grupu nema zasebnih definicija popusta. Stvarne cijene i popusti vidljivi su u usporedbi artikala ispod.') }}</p>@endif
    </details>

    <section class="admin-panel overflow-hidden" data-customer-price-comparison>
        <div class="space-y-3 p-5">
            <h3 class="admin-section-title">{{ __('Usporedba cijena: primarna grupa i kupac') }}</h3>
            <input type="search" wire:model.live.debounce.300ms="priceSearch" placeholder="Pretraži šifru ili naziv artikla" aria-label="Pretraži cijene kupca" class="admin-search-input w-full rounded-lg px-3 py-2">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="onlyOverrides" class="rounded border-slate-300">{{ __('Samo artikli s aktivnim posebnim popustom kupca') }}</label>
            <p class="text-xs text-slate-500">{{ __('Cijene vrijede za 1 komad na današnji datum.') }} {{ $pricesIncludeTax ? __('Iznosi uključuju PDV.') : __('Iznosi su bez PDV-a.') }} {{ __('Postotak uz cijenu prikazuje razliku prema osnovnoj cijeni cjenika.') }}</p>
        </div>
        <div class="overflow-x-auto"><table class="admin-items-table min-w-full text-sm">
            <thead><tr><th class="p-3 text-left">{{ __('Artikl') }}</th><th class="p-3 text-right">{{ __('Osnovna cijena') }}</th><th class="p-3 text-right">{{ __('Primarna grupa') }}</th><th class="p-3 text-right">{{ __('Cijena kupca') }}</th><th class="p-3 text-left">{{ __('Primijenjeno pravilo') }}</th></tr></thead>
            <tbody>@forelse ($priceRows as $row)
                <tr class="border-t border-slate-100" wire:key="customer-price-{{ $row['product']->id }}">
                    <td class="p-3"><strong>{{ $row['product']->sku ?: $row['product']->code }}</strong><p class="mt-1 text-xs text-slate-500">{{ $row['product']->translations->firstWhere('locale', app()->getLocale())?->name ?? $row['product']->code }}</p></td>
                    <td class="p-3 text-right tabular-nums">{{ number_format((float) $row['base_price'], 4, ',', '.') }} EUR</td>
                    <td class="p-3 text-right tabular-nums">{{ number_format((float) $row['effective_price'], 4, ',', '.') }} EUR @if ($row['discount_percent'] !== null)<p class="mt-1 text-xs text-slate-500">{{ number_format((float) $row['discount_percent'], 2, ',', '.') }} %</p>@endif</td>
                    <td class="p-3 text-right font-semibold tabular-nums">@if ($row['customer_price'] !== null){{ number_format($row['customer_price'], 4, ',', '.') }} EUR @if ($row['customer_percent'] !== null)<p class="mt-1 text-xs text-cyan-700">{{ number_format($row['customer_percent'], 2, ',', '.') }} % @if ($row['is_override'] && $row['discount_percent'] !== null) · {{ $row['customer_percent'] - (float) $row['discount_percent'] > 0 ? '+' : '' }}{{ number_format($row['customer_percent'] - (float) $row['discount_percent'], 2, ',', '.') }} {{ __('p. b.') }}@endif</p>@endif @else<span class="text-xs text-slate-500">{{ __('B2B pristup nije aktivan') }}</span>@endif</td>
                    <td class="p-3 text-xs text-slate-600">{{ $row['customer_rule'] ?? __('Postojeći uvjeti cjenika') }}</td>
                </tr>
            @empty<tr><td colspan="5" class="p-5 text-sm text-slate-500">{{ $onlyOverrides ? __('Nema artikala s aktivnim posebnim popustom za ovaj odabir. Isključite filtar za pregled svih cijena.') : __('Nema artikala za ovaj odabir.') }}</td></tr>@endforelse</tbody>
        </table></div>
        @if ($products)<div class="p-5">{{ $products->links() }}</div>@endif
    </section>
    @endif
</div>
