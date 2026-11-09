<x-admin-layout :title="__('Prodajne prilike')">
    <div class="space-y-6">
        <section class="admin-panel p-5 sm:p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Komercijala') }}</p>
            <h1 class="mt-2 text-2xl font-semibold text-slate-900">{{ __('Prodajne prilike za partnera') }}</h1>
            <p class="mt-2 max-w-4xl text-sm text-slate-600">{{ __('Najprodavaniji artikli cijele tvrtke u posljednjih 90 dana koje odabrani partner nije kupovao u posljednjih 365 dana. Poredak daje prednost broju poznatih kupaca, zatim neto prodanoj količini. Kategorijom možete suziti prijedloge.') }}</p>
            <p class="mt-2 text-xs text-slate-500">{{ __('Izvor su izdani ERP računi, POS računi i odobrenja. Otpremnice se ne zbrajaju ponovno. Storna i odobrenja umanjuju neto količine. Anonimna POS prodaja ulazi u količinu, a ne u broj poznatih kupaca. Podaci drugih kupaca nisu prikazani.') }}</p>
            @if (auth()->user()->isA('superadmin') || auth()->user()->can('integrations.eracuni.manage'))
            <form method="POST" action="{{ route('admin.users.opportunities.refresh') }}" class="mt-4">
                @csrf
                <button class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">{{ __('Osvježi ERP pregled') }}</button>
            </form>
            @endif
            @if (session('status'))<p role="status" class="mt-3 rounded-xl bg-cyan-50 p-3 text-sm text-cyan-900">{{ session('status') }}</p>@endif
            @if ($latestAttempt?->status === 'running')<p role="status" class="mt-3 text-sm text-cyan-800">{{ __('ERP pregled je u obradi. Osvježite ovu stranicu nakon dovršetka.') }}</p>@endif
            @if ($latestAttempt?->status === 'failed')<p role="status" class="mt-3 text-sm text-amber-800">{{ $latestAttempt->message }}</p>@endif
            @if ($snapshot)
                <p class="mt-3 text-xs text-slate-500">{{ __('Presjek') }}: {{ $snapshot->as_of }} · {{ __('Dokumenti') }}: {{ number_format($snapshot->document_count, 0, ',', '.') }} · {{ __('Stavke bez jednoznačnog artikla u katalogu') }}: {{ number_format($snapshot->unmapped_lines, 0, ',', '.') }}.</p>
                @if ($snapshot->as_of !== today()->toDateString())<p class="mt-2 text-sm text-amber-800">{{ __('Prikazan je stariji ERP presjek. Nove potvrđene webshop narudžbe odabranog partnera izuzimaju se odmah; za ostalu prodaju osvježite ERP pregled.') }}</p>@endif
            @else
                <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">{{ __('Još nema potpunog ERP pregleda. Pokrenite osvježavanje prije prikaza prodajnih prilika.') }}</p>
            @endif
        </section>
        <form method="GET" class="admin-panel flex flex-wrap items-end gap-4 p-5 sm:p-6">
            <label class="min-w-64 flex-1 text-sm font-semibold text-slate-600">{{ __('Partner') }}
                <select name="user_id" class="mt-2 block w-full rounded-xl border-slate-300 text-sm" required>
                    <option value="">{{ __('Odaberite partnera') }}</option>
                    @foreach ($customers as $customer)<option value="{{ $customer->id }}" @selected($selectedCustomer?->id === $customer->id)>{{ $customer->customerDisplayName() }}</option>@endforeach
                </select>
            </label>
            <label class="min-w-56 flex-1 text-sm font-semibold text-slate-600">{{ __('Kategorija') }}
                <select name="category_id" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                    <option value="">{{ __('Sve kategorije') }}</option>
                    @foreach ($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->translations->firstWhere('locale', 'hr')?->name ?: $category->code }}</option>@endforeach
                </select>
            </label>
            <button class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">{{ __('Prikaži prilike') }}</button>
        </form>
        @if ($products)
            <section class="admin-panel overflow-hidden">
                <div class="border-b border-slate-200 p-5"><h2 class="font-semibold">{{ $selectedCustomer->customerDisplayName() }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('Nakon nove potvrđene kupnje artikl nestaje iz prijedloga, a sljedeći po rangu zauzima njegovo mjesto.') }}</p></div>
                <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="p-4">{{ __('Artikl') }}</th><th class="p-4">{{ __('Šifra / SKU') }}</th><th class="p-4 text-right">{{ __('Poznati kupci · 90 dana') }}</th><th class="p-4 text-right">{{ __('Neto količina · 90 dana') }}</th></tr></thead>
                    <tbody>
                    @forelse ($products as $product)
                        @php($translation = $product->translations->firstWhere('locale', 'hr') ?: $product->translations->first())
                        <tr class="border-t border-slate-200"><td class="p-4 font-semibold">@if ($translation?->slug)<a href="{{ route('products.show', ['slug' => $translation->slug]) }}" target="_blank" rel="noopener" class="text-cyan-800 hover:underline">{{ $translation->name }}</a>@else{{ $translation?->name ?: $product->code }}@endif</td><td class="p-4 font-mono text-xs">{{ $product->code }}@if ($product->sku && $product->sku !== $product->code)<span class="mt-1 block text-slate-500">{{ $product->sku }}</span>@endif</td><td class="p-4 text-right">{{ number_format($product->opportunity_buyers, 0, ',', '.') }}</td><td class="p-4 text-right">{{ number_format($product->opportunity_quantity, 2, ',', '.') }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="p-6 text-slate-600">{{ __('Za ovaj presjek i odabranu kategoriju nema novih prijedloga.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                <div class="p-5">{{ $products->links() }}</div>
            </section>
        @endif
    </div>
</x-admin-layout>
