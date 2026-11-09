<section class="admin-panel p-5 sm:p-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ __('XML za webshop kupca') }}</h2>
            <p class="mt-2 max-w-3xl text-sm text-slate-600">{{ __('Odobrite pojedine proizvode koje kupac smije preuzimati. XML sadrži punu veleprodajnu osnovicu bez PDV-a i osobnih ili grupnih rabata. Akcije i rasprodaja ne mijenjaju izvoznu osnovicu. Pristup zahtijeva važeći odobreni B2B račun.') }}</p>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $feed?->is_enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $feed?->is_enabled ? __('XML poveznica aktivna') : __('XML pristup isključen') }}</span>
    </div>
    @error('feed')<p role="alert" class="text-sm text-rose-700">{{ $message }}</p>@enderror
    @error('products')<p role="alert" class="text-sm text-rose-700">{{ $message }}</p>@enderror
    @error('products.*')<p role="alert" class="text-sm text-rose-700">{{ $message }}</p>@enderror
    <label class="flex items-start gap-3 text-sm text-slate-700"><input type="checkbox" wire:model="includePurchasedProducts" @disabled(! $canUpdate) class="mt-1 rounded border-slate-300 text-cyan-700"><span>{{ __('Dodatno odobravam proizvode iz B2B povijesti kupnje ovog kupca.') }}<span class="mt-1 block text-xs text-slate-500">{{ __('Ovo uključuje i buduće kupljene proizvode. Otkazane narudžbe i potpuno zaprimljeni povrati ne ulaze u taj skup. Cijeli katalog nikada se ne uključuje automatski.') }}</span></span></label>
    <div class="grid gap-5 lg:grid-cols-2">
        <div>
            <label class="block text-xs font-semibold text-slate-600" for="feed-search-{{ $userId }}">{{ __('Pronađi proizvod za odobrenje') }}</label>
            <input id="feed-search-{{ $userId }}" type="search" maxlength="191" wire:model.live.debounce.300ms="search" class="mt-2 w-full rounded-xl border-slate-300 text-sm" placeholder="{{ __('Šifra, SKU ili naziv') }}">
            <div class="mt-3 max-h-64 overflow-y-auto divide-y divide-slate-100 rounded-xl border border-slate-200">
                @forelse ($products as $product)
                    <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm" wire:key="feed-option-{{ $product->id }}"><span><span class="font-semibold">{{ $product->sku ?: $product->code }}</span> · {{ $product->translations->firstWhere('locale', 'hr')?->name ?: $product->translations->first()?->name }}</span>@if ($canUpdate)<button type="button" wire:click="{{ in_array($product->id, $productIds) ? 'removeProduct' : 'addProduct' }}({{ $product->id }})" class="shrink-0 font-semibold text-cyan-700 disabled:opacity-40">{{ in_array($product->id, $productIds) ? __('Ukloni') : __('Dodaj') }}</button>@endif</div>
                @empty
                    <p class="p-3 text-sm text-slate-500">{{ __('Nema odgovarajućih proizvoda.') }}</p>
                @endforelse
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ __('Prikazano do 25 rezultata. Sužite pretragu za ostale proizvode.') }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold text-slate-600">{{ __('Izričito odobreni proizvodi') }}: {{ count($productIds) }}</p>
            <div class="mt-2 max-h-72 overflow-y-auto divide-y divide-slate-100 rounded-xl border border-slate-200">
                @forelse ($selectedProducts as $product)
                    <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm" wire:key="feed-selected-{{ $product->id }}"><span>{{ $product->sku ?: $product->code }} · {{ $product->translations->firstWhere('locale', 'hr')?->name ?: $product->translations->first()?->name }}</span>@if ($canUpdate)<button type="button" wire:click="removeProduct({{ $product->id }})" class="shrink-0 font-semibold text-rose-700">{{ __('Ukloni') }}</button>@endif</div>
                @empty
                    <p class="p-3 text-sm text-slate-500">{{ __('Nema pojedinačno odobrenih proizvoda.') }}</p>
                @endforelse
            </div>
            @if (count($productIds) > 50)<p class="mt-2 text-xs text-slate-500">{{ __('Prikazano prvih 50 odobrenih proizvoda.') }}</p>@endif
        </div>
    </div>
    @if ($canUpdate)
        <div class="flex flex-wrap gap-3">
            <button type="button" wire:click="saveAssortment" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">{{ __('Spremi odobreni asortiman') }}</button>
            <button type="button" wire:click="issueToken" wire:confirm="{{ __('Spremiti asortiman i generirati novu XML poveznicu? Prethodna poveznica odmah prestaje vrijediti.') }}" class="rounded-xl border border-cyan-300 px-4 py-2 text-sm font-semibold text-cyan-800">{{ __('Spremi i generiraj novu poveznicu') }}</button>
            @if ($feed?->is_enabled)<button type="button" wire:click="revoke" wire:confirm="{{ __('Ukinuti XML pristup ovom kupcu?') }}" class="rounded-xl border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700">{{ __('Ukini XML pristup') }}</button>@endif
        </div>
    @endif
    @if ($issuedUrl)
        <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4">
            <label class="block text-sm font-semibold text-emerald-900" for="feed-url-{{ $userId }}">{{ __('Nova poveznica — kopirajte je sada za ovog kupca') }}</label>
            <input id="feed-url-{{ $userId }}" type="text" readonly value="{{ $issuedUrl }}" class="mt-2 w-full rounded-lg border-emerald-300 bg-white px-3 py-2 font-mono text-xs" onclick="this.select()">
            <p class="mt-2 text-xs text-emerald-900">{{ __('Poveznica se prikazuje samo nakon generiranja. Tko je posjeduje može preuzeti odobreni asortiman ovog kupca. Ako se izgubi ili procuri, generirajte novu ili ukinite pristup.') }}</p>
        </div>
    @endif
    <p class="text-xs text-slate-500">{{ __('Izvoz: XML UTF-8, cijene u valuti aktivnog cjenika s 4 decimale. Uključuje SKU, barkod, naziv, opis, kategorije, slike, minimalnu količinu i dostupnost bez točnih zaliha. Podaci se čitaju pri svakom preuzimanju. Podržano je do 10 preuzimanja u minuti; koristite HTTPS.') }}</p>
</section>
