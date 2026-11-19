<x-admin-layout title="Statistika komercijalista">
    <div class="space-y-6">
        <section class="admin-panel admin-search-panel p-5 sm:p-7">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Statistika komercijalista</h1>
            <p class="mt-2 text-sm text-slate-600">Odaberite komercijalista za pregled mjesečne kupnje njegovih kupaca.</p>
            <form method="get" class="mt-6 flex max-w-xl flex-wrap items-end gap-2">
                <label class="min-w-0 flex-1 text-xs font-semibold text-slate-600" for="rep-search">Ime ili e-mail
                    <input id="rep-search" type="search" name="q" value="{{ $search }}" maxlength="191" placeholder="Pretražite komercijaliste" class="mt-1 block w-full rounded-xl border-slate-300 text-sm">
                </label>
                <button class="rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800">Pretraži</button>
                @if ($search !== '')<a class="px-2 py-2 text-sm font-semibold text-cyan-800" href="{{ route('admin.users.rep-statistics') }}">Poništi</a>@endif
            </form>
        </section>
        <section aria-labelledby="rep-list">
            <h2 id="rep-list" class="mb-3 text-lg font-semibold text-slate-900">Komercijalisti <span class="text-sm font-normal text-slate-500">({{ $reps->total() }})</span></h2>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($reps as $rep)
                    <a href="{{ route('admin.users.rep-statistics', ['rep' => $rep->id]) }}" class="admin-panel group flex items-center gap-4 p-4 hover:border-cyan-400 focus-visible:ring-2 focus-visible:ring-cyan-600">
                        <span aria-hidden="true" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-cyan-50 font-bold text-cyan-800">{{ mb_strtoupper(mb_substr(trim($rep->name), 0, 1)) }}</span>
                        <span class="min-w-0 flex-1"><strong class="block truncate text-slate-900 group-hover:text-cyan-800">{{ $rep->name }}</strong><span class="block truncate text-xs text-slate-500">{{ $rep->email }}</span>
                            @if ($rep->account_type === 'staff' && ! $rep->admin_login_enabled)<span class="text-xs text-amber-700">Neaktivan račun</span>@endif
                        </span>
                        <x-fa-icon name="chevron-right" class="h-3.5 w-3.5 text-slate-400 group-hover:text-cyan-700" />
                    </a>
                @endforeach
            </div>
            @if ($reps->isEmpty())<p class="admin-panel p-6 text-sm text-slate-600">{{ $search === '' ? 'Nema dostupnih komercijalista.' : 'Nema komercijalista koji odgovaraju pretrazi.' }}</p>@endif
            @if ($reps->hasPages())<div class="mt-4">{{ $reps->links() }}</div>@endif
        </section>
    </div>
</x-admin-layout>
