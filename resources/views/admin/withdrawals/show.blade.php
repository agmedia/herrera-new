<x-admin-layout :title="'Raskid '.$withdrawal->reference">
    <div class="admin-stack">
        <div class="admin-panel admin-search-panel p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <a href="{{ route('admin.withdrawals.index') }}" class="text-xs font-semibold text-cyan-700 hover:underline">← Svi raskidi ugovora</a>
                    <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900">{{ $withdrawal->reference }}</h1>
                    <p class="mt-1 text-sm text-slate-600">Podneseno {{ $withdrawal->submitted_at?->format('d.m.Y. \u H:i:s') }}</p>
                </div>
                <div class="text-right text-xs text-slate-500">
                    <div>SHA-256 zapis</div>
                    <code class="mt-1 block max-w-xs break-all rounded-lg bg-white px-2 py-1 text-[10px] text-slate-600">{{ $withdrawal->snapshot_hash }}</code>
                </div>
            </div>
        </div>

        @if ($withdrawal->notification_error)
            <div class="rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-900">
                <p class="font-semibold">Pogreška pri slanju obavijesti</p>
                <p class="mt-1 whitespace-pre-line">{{ $withdrawal->notification_error }}</p>
            </div>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div class="space-y-6">
                <section class="admin-panel p-6">
                    <h2 class="text-base font-semibold text-slate-900">Izjava potrošača</h2>
                    <blockquote class="mt-4 border-l-4 border-cyan-600 bg-cyan-50 px-4 py-3 text-sm font-medium leading-6 text-slate-900">
                        {{ $withdrawal->declaration }}
                    </blockquote>

                    <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm md:grid-cols-2">
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ime i prezime</dt><dd class="mt-1 text-slate-900">{{ $withdrawal->full_name }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">E-mail za potvrdu</dt><dd class="mt-1"><a class="text-cyan-700 hover:underline" href="mailto:{{ $withdrawal->email }}">{{ $withdrawal->email }}</a></dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Telefon</dt><dd class="mt-1 text-slate-900">{{ $withdrawal->phone ?: '—' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Adresa</dt><dd class="mt-1 text-slate-900">{{ $withdrawal->address_line }}, {{ $withdrawal->postal_code }} {{ $withdrawal->city }}, {{ $withdrawal->country_code }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Broj narudžbe</dt><dd class="mt-1 text-slate-900">{{ $withdrawal->order_number }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Povezana narudžba</dt><dd class="mt-1">
                            @if ($withdrawal->order)
                                <a class="text-cyan-700 hover:underline" href="{{ route('admin.orders.show', $withdrawal->order) }}">{{ $withdrawal->order->order_number }}</a>
                            @else
                                <span class="text-slate-500">Nije automatski povezana</span>
                            @endif
                        </dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Datum ugovora/narudžbe</dt><dd class="mt-1 text-slate-900">{{ $withdrawal->contract_date?->format('d.m.Y.') ?: 'Nije naveden' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Datum primitka robe</dt><dd class="mt-1 text-slate-900">{{ $withdrawal->received_date?->format('d.m.Y.') ?: 'Nije naveden' }}</dd></div>
                    </dl>
                </section>

                <section class="admin-panel p-6">
                    <h2 class="text-base font-semibold text-slate-900">Proizvodi / dio ugovora koji se raskida</h2>
                    <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $withdrawal->items }}</p>
                    <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500">Dodatna napomena</h3>
                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $withdrawal->note ?: 'Nema dodatne napomene.' }}</p>
                </section>
            </div>

            <aside class="space-y-6">
                <form method="POST" action="{{ route('admin.withdrawals.update', $withdrawal) }}" class="admin-panel admin-form-panel space-y-4 p-6">
                    @csrf
                    @method('PATCH')
                    <h2 class="text-base font-semibold text-slate-900">Obrada zahtjeva</h2>
                    <div>
                        <label for="withdrawal-status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Status</label>
                        <select id="withdrawal-status" name="status" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $withdrawal->status) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('status') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="withdrawal-internal-note" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Interna napomena</label>
                        <textarea id="withdrawal-internal-note" name="internal_note" rows="7" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">{{ old('internal_note', $withdrawal->internal_note) }}</textarea>
                        @error('internal_note') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Spremi obradu</button>
                    @if ($withdrawal->handler)
                        <p class="text-xs text-slate-500">Zadnja obrada: {{ $withdrawal->handler->name }}, {{ $withdrawal->handled_at?->format('d.m.Y. H:i') }}</p>
                    @endif
                </form>

                <section class="admin-panel p-6">
                    <h2 class="text-base font-semibold text-slate-900">E-mail potvrde</h2>
                    <dl class="mt-3 space-y-3 text-sm">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Korisnik</dt>
                            <dd class="mt-1 {{ $withdrawal->consumer_notified_at ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ $withdrawal->consumer_notified_at?->format('d.m.Y. H:i:s') ?: 'Nije potvrđeno slanje' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Administrator</dt>
                            <dd class="mt-1 {{ $withdrawal->admin_notified_at ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ $withdrawal->admin_notified_at?->format('d.m.Y. H:i:s') ?: 'Nije potvrđeno slanje' }}
                            </dd>
                        </div>
                    </dl>
                    <form method="POST" action="{{ route('admin.withdrawals.resend', $withdrawal) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Ponovno pošalji obje poruke
                        </button>
                    </form>
                </section>
            </aside>
        </div>
    </div>
</x-admin-layout>
