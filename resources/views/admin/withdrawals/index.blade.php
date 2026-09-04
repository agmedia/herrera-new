<x-admin-layout title="Jednostrani raskidi ugovora">
    <div class="admin-stack">
        <div class="admin-panel admin-search-panel p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="admin-section-title">Prodaja</p>
                    <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900">Jednostrani raskidi ugovora</h1>
                    <p class="mt-2 text-sm text-slate-600">Centralna evidencija digitalno podnesenih izjava potrošača.</p>
                </div>
                <a href="{{ route('admin.settings.system.withdrawal-settings') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Postavke
                </a>
            </div>

            <form method="GET" action="{{ route('admin.withdrawals.index') }}" class="mt-5 grid gap-3 md:grid-cols-[minmax(0,1fr)_220px_auto]">
                <div>
                    <label for="withdrawal-search" class="sr-only">Pretraži</label>
                    <input id="withdrawal-search" type="search" name="search" value="{{ $search }}" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="Referenca, narudžba, kupac ili e-mail">
                </div>
                <div>
                    <label for="withdrawal-status" class="sr-only">Status</label>
                    <select id="withdrawal-status" name="status" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">
                        <option value="">Svi statusi</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-xl bg-slate-900 px-5 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filtriraj</button>
            </form>
        </div>

        <div class="admin-panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Referenca</th>
                            <th class="px-5 py-3">Kupac</th>
                            <th class="px-5 py-3">Narudžba</th>
                            <th class="px-5 py-3">Podneseno</th>
                            <th class="px-5 py-3">Potvrde</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3 text-right">Radnje</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse ($withdrawals as $withdrawal)
                            @php
                                $statusClass = match ($withdrawal->status) {
                                    'received' => 'bg-cyan-50 text-cyan-800 ring-cyan-200',
                                    'processing' => 'bg-amber-50 text-amber-800 ring-amber-200',
                                    'completed' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
                                    'declined' => 'bg-rose-50 text-rose-800 ring-rose-200',
                                    default => 'bg-slate-50 text-slate-700 ring-slate-200',
                                };
                            @endphp
                            <tr class="hover:bg-slate-50/70">
                                <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-900">{{ $withdrawal->reference }}</td>
                                <td class="px-5 py-4">
                                    <div class="font-medium text-slate-900">{{ $withdrawal->full_name }}</div>
                                    <div class="text-xs text-slate-500">{{ $withdrawal->email }}</div>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">{{ $withdrawal->order_number }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-600">{{ $withdrawal->submitted_at?->format('d.m.Y. H:i') }}</td>
                                <td class="whitespace-nowrap px-5 py-4">
                                    <span title="Korisnik" class="{{ $withdrawal->consumer_notified_at ? 'text-emerald-700' : 'text-rose-700' }}">K</span>
                                    <span class="text-slate-300">/</span>
                                    <span title="Administrator" class="{{ $withdrawal->admin_notified_at ? 'text-emerald-700' : 'text-rose-700' }}">A</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $statusClass }}">
                                        {{ $statuses[$withdrawal->status] ?? $withdrawal->status }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-right">
                                    <a href="{{ route('admin.withdrawals.show', $withdrawal) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        Otvori
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center text-slate-500">Nema evidentiranih raskida ugovora za odabrane filtre.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($withdrawals->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">{{ $withdrawals->links() }}</div>
            @endif
        </div>
    </div>
</x-admin-layout>
