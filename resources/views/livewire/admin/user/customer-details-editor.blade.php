<div class="admin-panel space-y-5 p-6">
    <h2 class="admin-section-title">
        {{ __('Podaci kupca i komercijalisti') }}
    </h2>
    <form wire:submit="save" class="space-y-3">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (['city' => 'Mjesto', 'postal_code' => 'Poštanski broj', 'county' => 'Županija', 'customer_type' => 'Tip kupca'] as $field => $label)
                <label class="text-sm text-slate-600">
                    {{ __($label) }}
                    <input wire:model="details.{{ $field }}" @disabled(! $canUpdate) class="mt-1 w-full rounded-lg border px-3 py-2">
                    @error('details.'.$field)
                        <span class="mt-1 block text-xs text-rose-600">
                            {{ $message }}
                        </span>
                    @enderror
                </label>
            @endforeach
        </div>
        @if ($canUpdate)
            <button class="rounded-lg bg-cyan-700 px-3 py-2 text-sm font-semibold text-white">
                {{ __('Spremi podatke kupca') }}
            </button>
        @endif
    </form>
    <div class="grid gap-5 lg:grid-cols-2">
        <form wire:submit="saveRepresentatives" class="space-y-3 rounded-lg border p-4">
            <h3 class="font-semibold">
                {{ __('Dodijeljeni komercijalisti') }}
            </h3>
            @if ($canAssign)
                <div class="max-h-44 space-y-2 overflow-y-auto">
                    @foreach ($representatives as $rep)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" value="{{ $rep->id }}" wire:model="representativeIds">
                            {{ $rep->name }}
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500">
                    {{ __('Spremanje zamjenjuje postojeću dodjelu odabranim komercijalistima.') }}
                </p>
                <button class="rounded-lg border px-3 py-2 text-sm font-semibold">
                    {{ __('Spremi dodjelu') }}
                </button>
            @else
                @forelse ($customer->salesRepresentatives as $rep)
                    <span class="admin-chip">
                        {{ $rep->name }}
                    </span>
                @empty
                    <p class="text-sm text-slate-500">
                        {{ __('Nije dodijeljen komercijalist.') }}
                    </p>
                @endforelse
            @endif
        </form>
        <form wire:submit="saveSegments" class="space-y-3 rounded-lg border p-4">
            <h3 class="font-semibold">
                {{ __('Djelatnosti i divizije') }}
            </h3>
            <div class="max-h-44 space-y-2 overflow-y-auto">
                @forelse ($segments as $segment)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" value="{{ $segment->id }}" wire:model="segmentIds" @disabled(! $canSegment)>
                        {{ $segment->name }}
                        <span class="text-xs text-slate-500">
                            {{ \App\Models\User\CustomerGroup::classificationOptions()[$segment->classificationType()] }}
                        </span>
                    </label>
                @empty
                    <p class="text-sm text-slate-500">
                        {{ __('Djelatnosti i divizije još nisu unesene.') }}
                    </p>
                @endforelse
            </div>
            @if ($canSegment)
                <div class="flex flex-wrap gap-2">
                    <button class="rounded-lg border px-3 py-2 text-sm font-semibold">
                        {{ __('Spremi razvrstavanje') }}
                    </button>
                    <a href="{{ route('admin.users.groups') }}" class="rounded-lg border px-3 py-2 text-sm">
                        {{ __('Upravljaj grupama') }}
                    </a>
                </div>
            @endif
        </form>
    </div>
    <div class="border-t pt-5">
        <h3 class="font-semibold">
            {{ __('Pregled rabata i podrabata') }}
        </h3>
        <p class="mt-1 text-sm text-slate-600">
            {{ __('Glavna cjenovna grupa') }}:
            <strong>
                {{ $customer->b2bAccount?->customerGroup?->name ?: '—' }}
            </strong>
        </p>
        <div class="mt-3 grid gap-4 lg:grid-cols-2">
            <div>
                <h4 class="text-sm font-semibold">
                    {{ __('Rabati objavljenog cjenika') }}
                </h4>
                <ul class="mt-2 space-y-2 text-sm">
                    @forelse ($catalogRules as $rule)
                        <li class="rounded-lg border p-3">
                            <strong>
                                {{ $rule->name }} · {{ rtrim(rtrim($rule->percent, '0'), '.') }}%
                            </strong>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ __('Kategorije') }}: {{ collect($rule->category_ids ?? [])->map(fn ($id) => $categories->get($id)?->translations->first()?->name ?: '#'.$id)->join(', ') ?: __('Sve kategorije') }}
                                @if ($rule->manufacturer_ids)
                                    · {{ __('Ograničeno na odabrane brendove') }}
                                @endif
                                @if ($rule->excluded_product_ids)
                                    · {{ __('S izuzecima proizvoda') }}
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="text-slate-500">
                            {{ __('Nema primjenjivih kategorijskih rabata u objavljenom cjeniku.') }}
                        </li>
                    @endforelse
                </ul>
            </div>
            <div>
                <h4 class="text-sm font-semibold">
                    {{ __('Dodatna pravila kupca i grupe') }}
                </h4>
                <ul class="mt-2 space-y-2 text-sm">
                    @forelse ($extraRules as $rule)
                        <li class="rounded-lg border p-3">
                            <strong>
                                {{ $rule->name }}
                            </strong>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ \App\Models\Catalog\Pricing\B2BPriceRule::calculationTypeOptions()[$rule->calculation_type] ?? $rule->calculation_type }}: {{ rtrim(rtrim($rule->value, '0'), '.') }}{{ $rule->calculation_type === 'percentage_discount' ? '%' : ' '.$rule->currency_code }} · {{ \App\Models\Catalog\Pricing\B2BPriceRule::targetTypeOptions()[$rule->target_type] ?? $rule->target_type }} · {{ $rule->user_id ? __('Pravilo kupca') : __('Pravilo grupe') }}
                            </div>
                        </li>
                    @empty
                        <li class="text-slate-500">
                            {{ __('Nema aktivnih dodatnih pravila kupca ili njegove grupe.') }}
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-500">
            {{ __('Popusti se primjenjuju prema prioritetu i uvjetima pravila. Prikaz nije zbroj postotaka.') }}
        </p>
    </div>
</div>
