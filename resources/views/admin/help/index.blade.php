@php
    $manual = (array) config('admin_manual', []);
    $manualSections = (array) ($manual['sections'] ?? []);
    $manualEntryCount = collect($manualSections)->sum(fn (array $section): int => count((array) ($section['items'] ?? [])));
    $manualUser = auth()->user();
    $catalogFeatures = app(\App\Services\Catalog\CatalogFeatureService::class);
    $manualFeatureStates = [
        'attributes' => $catalogFeatures->useAttributes(),
        'options' => $catalogFeatures->useOptions(),
        'manufacturers' => $catalogFeatures->useManufacturers(),
        'actions' => $catalogFeatures->useActions(),
        'blog' => $catalogFeatures->useBlog(),
        'api' => $catalogFeatures->useApi(),
        'loyalty' => (bool) app(\App\Services\Settings\SystemSettingsService::class)->get(
            'user_loyalty_enabled',
            (bool) config('user_features.flags.user_loyalty_enabled', false),
        ),
    ];
@endphp

@push('page-styles')
    <style>
        #admin-manual {
            --manual-ink: #0f172a;
            --manual-muted: #64748b;
            --manual-line: #dbe4ee;
            --manual-soft: #f8fafc;
            --manual-accent: #0e7490;
        }

        .admin-manual-hero-grid,
        .admin-manual-layout,
        .admin-manual-quick-grid {
            display: grid;
        }

        .admin-manual-hero-grid {
            gap: 1.75rem;
        }

        .admin-manual-hero {
            border: 1px solid #cfe8ef;
            background: #f5fafc;
            color: #0f172a;
            box-shadow: 0 18px 38px -28px rgba(15, 23, 42, .38);
        }

        .admin-manual-search-panel {
            border: 1px solid rgba(148, 163, 184, .32);
            background: #fff;
            box-shadow: 0 14px 30px -24px rgba(15, 23, 42, .4);
        }

        .admin-manual-hero-chip {
            border: 1px solid #dbe4ee;
            background: rgba(255, 255, 255, .82);
            color: #475569;
            box-shadow: 0 4px 12px -10px rgba(15, 23, 42, .45);
        }

        .admin-manual-layout {
            align-items: start;
            gap: 1.5rem;
        }

        .admin-manual-sidebar {
            min-width: 0;
        }

        .admin-manual-sidebar-scroll {
            scrollbar-color: #cbd5e1 transparent;
            scrollbar-width: thin;
        }

        .admin-manual-sidebar-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .admin-manual-sidebar-scroll::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: #cbd5e1;
        }

        .admin-manual-toc-groups {
            display: flex;
            gap: .5rem;
            overflow-x: auto;
            padding: .75rem;
            scrollbar-width: thin;
        }

        .admin-manual-toc-group {
            flex: 0 0 auto;
        }

        .manual-toc-link {
            border: 1px solid transparent;
        }

        .manual-toc-link.is-active {
            border-color: #0f172a;
            background: #0f172a;
            color: #fff !important;
            box-shadow: 0 8px 18px -14px rgba(15, 23, 42, .9);
        }

        .manual-toc-link.is-active .manual-toc-count {
            background: rgba(255, 255, 255, .14);
            color: #fff !important;
        }

        .admin-manual-toc-topics {
            display: none;
        }

        .manual-toc-topic-link {
            position: relative;
            display: block;
            overflow: hidden;
            border-left: 1px solid #e2e8f0;
            padding: .34rem .5rem .34rem 1rem;
            color: #64748b;
            font-size: .72rem;
            font-weight: 600;
            line-height: 1.35;
            text-overflow: ellipsis;
            white-space: nowrap;
            transition: color 120ms ease, border-color 120ms ease, background-color 120ms ease;
        }

        .manual-toc-topic-link::before {
            position: absolute;
            top: 50%;
            left: -.18rem;
            width: .34rem;
            height: .34rem;
            border-radius: 999px;
            background: #cbd5e1;
            content: '';
            transform: translateY(-50%);
        }

        .manual-toc-topic-link:hover,
        .manual-toc-topic-link:focus-visible {
            border-left-color: #0891b2;
            background: #ecfeff;
            color: #155e75;
            outline: none;
        }

        .admin-manual-quick-grid {
            gap: .75rem;
        }

        .admin-manual-quick-card {
            position: relative;
            min-height: 7.25rem;
            overflow: hidden;
        }

        .admin-manual-quick-card::after {
            position: absolute;
            right: -1.75rem;
            bottom: -2.35rem;
            width: 5.5rem;
            height: 5.5rem;
            border-radius: 999px;
            background: #ecfeff;
            content: '';
            opacity: .7;
        }

        .manual-entry {
            border-left-width: 3px !important;
            border-left-color: #dbe4ee !important;
        }

        .manual-entry[open] {
            border-left-color: #0891b2 !important;
        }

        .manual-entry[open] > summary {
            background: #f0fdff;
        }

        .manual-entry-summary-purpose {
            display: block;
            max-width: 52rem;
            margin-top: .2rem;
            overflow: hidden;
            color: #64748b;
            font-size: .72rem;
            font-weight: 500;
            line-height: 1.35;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (min-width: 640px) {
            .admin-manual-quick-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1280px) {
            .admin-manual-layout {
                grid-template-columns: 19rem minmax(0, 1fr);
            }

            .admin-manual-sidebar {
                position: sticky;
                top: 5.5rem;
                align-self: start;
            }

            .admin-manual-sidebar-scroll {
                max-height: calc(100vh - 7rem);
                overflow-x: hidden;
                overflow-y: auto;
                overscroll-behavior: contain;
                padding-right: .25rem;
            }

            .admin-manual-toc-groups {
                display: block;
                overflow: visible;
            }

            .admin-manual-toc-group + .admin-manual-toc-group {
                margin-top: .3rem;
            }

            .admin-manual-toc-topics {
                display: grid;
                gap: .06rem;
                margin: .2rem .4rem .45rem 1.12rem;
            }
        }

        @media (min-width: 1280px) {
            .admin-manual-hero-grid {
                grid-template-columns: minmax(0, 1fr) minmax(24rem, .72fr);
                align-items: end;
            }
        }
    </style>
@endpush

<x-admin-layout :title="$manual['title'] ?? 'Upute za administraciju'">
    <div id="admin-manual" class="mx-auto max-w-[100rem] space-y-6" data-manual-entry-count="{{ $manualEntryCount }}">
        <section id="uvod" class="admin-manual-hero relative scroll-mt-24 overflow-hidden rounded-3xl px-5 py-7 sm:px-8 sm:py-9">
            <div class="admin-manual-hero-grid relative">
                <div class="max-w-3xl">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-700">Termol administracija</p>
                    <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">{{ $manual['title'] ?? 'Upute za administraciju' }}</h1>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-600 sm:text-base">{{ $manual['intro'] ?? '' }}</p>

                    <div class="mt-6 flex flex-wrap gap-2 text-xs font-semibold">
                        <span class="admin-manual-hero-chip rounded-full px-3 py-1.5">{{ count($manualSections) }} područja</span>
                        <span class="admin-manual-hero-chip rounded-full px-3 py-1.5">{{ $manualEntryCount }} detaljnih uputa</span>
                        <span class="admin-manual-hero-chip rounded-full px-3 py-1.5">Redoslijed kao u navigaciji</span>
                    </div>
                </div>

                <div class="admin-manual-search-panel rounded-2xl p-4 sm:p-5">
                    <label for="admin-manual-search" class="text-sm font-bold text-slate-900">Što želite napraviti?</label>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Pretražite naziv, polje ili postupak, primjerice „zaliha”, „M SAN” ili „dostava”.</p>
                    <div class="relative mt-3">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <circle cx="8.75" cy="8.75" r="5.25" stroke="currentColor" stroke-width="1.6" />
                            <path d="m12.75 12.75 3.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <input
                            id="admin-manual-search"
                            type="search"
                            autocomplete="off"
                            placeholder="Pretraži sve upute…"
                            class="h-12 w-full rounded-xl border border-white/20 bg-white py-2 pl-10 pr-12 text-sm font-medium text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-cyan-300 focus:ring-4 focus:ring-cyan-300/20"
                        >
                        <button
                            id="admin-manual-search-clear"
                            type="button"
                            class="absolute right-2 top-1/2 hidden h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg text-lg leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600"
                            aria-label="Očisti pretragu"
                            title="Očisti pretragu"
                        >×</button>
                    </div>
                    <p id="admin-manual-result-count" class="mt-2 text-xs font-semibold text-cyan-700" aria-live="polite">
                        Prikazano je svih {{ $manualEntryCount }} uputa.
                    </p>
                </div>
            </div>
        </section>

        <div class="admin-manual-layout">
            <aside class="admin-manual-sidebar" aria-label="Sadržaj uputa">
                <div class="admin-manual-sidebar-scroll">
                <nav class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                        <p class="text-xs font-bold uppercase tracking-[0.15em] text-slate-500">Sadržaj</p>
                        <p class="mt-1 text-sm font-extrabold text-slate-900">Brza navigacija kroz upute</p>
                    </div>
                    <div class="admin-manual-toc-groups">
                        @foreach ($manualSections as $section)
                            <div class="admin-manual-toc-group" data-manual-toc-group="{{ $section['id'] }}">
                                <a
                                    href="#sekcija-{{ $section['id'] }}"
                                    class="manual-toc-link flex shrink-0 items-center justify-between gap-3 rounded-xl px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 hover:text-slate-950 lg:w-full"
                                    data-manual-toc="{{ $section['id'] }}"
                                >
                                    <span>{{ $section['title'] }}</span>
                                    <span class="manual-toc-count rounded-full bg-slate-100 px-2 py-0.5 text-[0.68rem] font-bold text-slate-500">{{ count((array) ($section['items'] ?? [])) }}</span>
                                </a>
                                <div class="admin-manual-toc-topics">
                                    @foreach ((array) ($section['items'] ?? []) as $tocItem)
                                        <a href="#{{ $tocItem['id'] }}" class="manual-toc-topic-link" data-manual-toc-topic="{{ $tocItem['id'] }}">
                                            {{ $tocItem['title'] }}
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </nav>

                <div class="mt-3 hidden rounded-2xl border border-cyan-200 bg-cyan-50 p-4 text-xs leading-5 text-cyan-950 xl:block">
                    <p class="font-extrabold">Izravno na željeni ekran</p>
                    <p class="mt-1 text-cyan-800">U otvorenoj uputi koristite gumb „Otvori u administraciji”. Prikazuje se samo kada imate potrebnu ovlast.</p>
                </div>
                </div>
            </aside>

            <div class="min-w-0 space-y-8">
                <section aria-labelledby="admin-manual-quick-start-title" class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 shadow-sm sm:p-5">
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-700">Siguran način rada</p>
                            <h2 id="admin-manual-quick-start-title" class="mt-1 text-xl font-extrabold tracking-tight text-slate-950">Prije svake promjene</h2>
                        </div>
                        <div class="flex gap-2">
                            <button id="admin-manual-expand-all" type="button" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-sm transition hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600">Otvori sve</button>
                            <button id="admin-manual-collapse-all" type="button" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-sm transition hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600">Zatvori sve</button>
                        </div>
                    </div>

                    <div class="admin-manual-quick-grid">
                        @foreach ((array) ($manual['quick_start'] ?? []) as $quickStartIndex => $quickStart)
                            <article class="admin-manual-quick-card rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="relative z-[1] flex gap-3">
                                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-xs font-extrabold text-white">{{ $quickStartIndex + 1 }}</span>
                                    <div>
                                        <h3 class="text-sm font-extrabold text-slate-900">{{ preg_replace('/^\d+\.\s*/', '', (string) ($quickStart['title'] ?? '')) }}</h3>
                                        <p class="mt-1.5 text-xs leading-5 text-slate-600">{{ $quickStart['text'] ?? '' }}</p>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                @foreach ($manualSections as $sectionIndex => $section)
                    <section
                        id="sekcija-{{ $section['id'] }}"
                        class="manual-section scroll-mt-24"
                        data-manual-section="{{ $section['id'] }}"
                    >
                        <div class="mb-3 flex items-start gap-3">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-sm font-extrabold text-white shadow-sm">{{ $sectionIndex + 1 }}</span>
                            <div>
                                <h2 class="text-xl font-extrabold tracking-tight text-slate-950 sm:text-2xl">{{ $section['title'] }}</h2>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $section['description'] ?? '' }}</p>
                            </div>
                        </div>

                        <div class="space-y-3">
                            @foreach ((array) ($section['items'] ?? []) as $itemIndex => $item)
                                @php
                                    $manualAbility = (string) ($item['ability'] ?? '');
                                    $manualFeature = (string) ($item['feature'] ?? '');
                                    $manualRoute = (string) ($item['route'] ?? '');
                                    $manualRouteParameters = (array) ($item['route_parameters'] ?? []);
                                    $manualAllowed = $manualAbility === ''
                                        || ($manualUser && ($manualUser->isA('superadmin') || $manualUser->can($manualAbility)));
                                    $manualFeatureEnabled = $manualFeature === '' || ($manualFeatureStates[$manualFeature] ?? false);
                                    $manualRouteExists = $manualRoute !== '' && \Illuminate\Support\Facades\Route::has($manualRoute);
                                    $manualCanOpen = $manualAllowed && $manualFeatureEnabled && $manualRouteExists;
                                    $manualSearchParts = [
                                        $section['title'] ?? '',
                                        $section['description'] ?? '',
                                        $item['eyebrow'] ?? '',
                                        $item['title'] ?? '',
                                        $item['purpose'] ?? '',
                                        ...((array) ($item['steps'] ?? [])),
                                        ...collect((array) ($item['fields'] ?? []))->flatMap(fn (array $field): array => [
                                            $field['name'] ?? '',
                                            $field['description'] ?? '',
                                        ])->all(),
                                        ...((array) ($item['tips'] ?? [])),
                                        $item['warning'] ?? '',
                                    ];
                                    $manualSearchText = implode(' ', array_filter($manualSearchParts, fn (mixed $part): bool => is_scalar($part) && trim((string) $part) !== ''));
                                @endphp

                                <details
                                    id="{{ $item['id'] }}"
                                    class="manual-entry group scroll-mt-24 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition open:border-slate-300 open:shadow-md"
                                    data-manual-entry="{{ $item['id'] }}"
                                    data-manual-search="{{ $manualSearchText }}"
                                    @if ($sectionIndex === 0 && $itemIndex === 0) open @endif
                                >
                                    <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-4 outline-none transition hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cyan-600 sm:px-5 [&::-webkit-details-marker]:hidden [&::marker]:content-['']">
                                        <span class="flex min-w-0 flex-1 items-center gap-3">
                                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-cyan-50 text-xs font-extrabold text-cyan-800 ring-1 ring-inset ring-cyan-100">{{ $itemIndex + 1 }}</span>
                                            <span class="min-w-0 flex-1">
                                                @if (! empty($item['eyebrow']))
                                                    <span class="block text-[0.65rem] font-extrabold uppercase tracking-[0.14em] text-cyan-700">{{ $item['eyebrow'] }}</span>
                                                @endif
                                                <span class="block text-sm font-extrabold text-slate-900 sm:text-base">{{ $item['title'] }}</span>
                                                <span class="manual-entry-summary-purpose">{{ $item['purpose'] ?? '' }}</span>
                                            </span>
                                        </span>

                                        @if (! $manualFeatureEnabled)
                                            <span class="hidden rounded-full bg-amber-50 px-2.5 py-1 text-[0.68rem] font-bold text-amber-800 ring-1 ring-inset ring-amber-200 sm:inline-flex">Modul je isključen</span>
                                        @elseif (! $manualAllowed)
                                            <span class="hidden rounded-full bg-slate-100 px-2.5 py-1 text-[0.68rem] font-bold text-slate-600 sm:inline-flex">Ograničen pristup</span>
                                        @endif

                                        <svg class="h-5 w-5 shrink-0 text-slate-400 transition duration-200 group-open:rotate-180" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                            <path d="m5.5 7.5 4.5 4.5 4.5-4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </summary>

                                    <div class="border-t border-slate-200 px-4 py-5 sm:px-5 sm:py-6">
                                        <div class="flex flex-col gap-4 border-b border-slate-100 pb-5 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="max-w-3xl">
                                                <p class="text-[0.68rem] font-extrabold uppercase tracking-[0.15em] text-slate-500">Čemu služi</p>
                                                <p class="mt-2 text-sm leading-6 text-slate-700">{{ $item['purpose'] ?? '' }}</p>
                                            </div>

                                            <div class="shrink-0">
                                                @if ($manualCanOpen)
                                                    <a href="{{ route($manualRoute, $manualRouteParameters) }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-3.5 py-2 text-xs font-extrabold text-white shadow-sm transition hover:bg-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600 focus-visible:ring-offset-2">
                                                        Otvori u administraciji
                                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                            <path d="M7 5h8v8M15 5 5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                                                        </svg>
                                                    </a>
                                                @elseif (! $manualFeatureEnabled)
                                                    <span class="inline-flex rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">Modul je isključen u postavkama</span>
                                                @elseif (! $manualAllowed)
                                                    <span class="inline-flex rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600">Nemate ovlast za otvaranje</span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="mt-5 grid gap-6 2xl:grid-cols-2">
                                            <div>
                                                <h3 class="flex items-center gap-2 text-sm font-extrabold text-slate-900">
                                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-emerald-50 text-xs text-emerald-700">✓</span>
                                                    Preporučeni postupak
                                                </h3>
                                                <ol class="mt-3 space-y-3">
                                                    @foreach ((array) ($item['steps'] ?? []) as $stepIndex => $step)
                                                        <li class="flex gap-3 text-sm leading-6 text-slate-700">
                                                            <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[0.65rem] font-extrabold text-slate-600">{{ $stepIndex + 1 }}</span>
                                                            <span>{{ $step }}</span>
                                                        </li>
                                                    @endforeach
                                                </ol>
                                            </div>

                                            @if (! empty($item['fields']))
                                                <div>
                                                    <h3 class="flex items-center gap-2 text-sm font-extrabold text-slate-900">
                                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-violet-50 text-xs font-extrabold text-violet-700">i</span>
                                                        Ključna polja i podaci
                                                    </h3>
                                                    <dl class="mt-3 divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-slate-50/70">
                                                        @foreach ((array) $item['fields'] as $field)
                                                            <div class="grid gap-1 px-3.5 py-3 sm:grid-cols-[9rem_minmax(0,1fr)] sm:gap-3">
                                                                <dt class="text-xs font-extrabold text-slate-800">{{ $field['name'] ?? '' }}</dt>
                                                                <dd class="text-xs leading-5 text-slate-600">{{ $field['description'] ?? '' }}</dd>
                                                            </div>
                                                        @endforeach
                                                    </dl>
                                                </div>
                                            @endif
                                        </div>

                                        @if (! empty($item['tips']))
                                            <div class="mt-5 rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm text-cyan-950">
                                                <p class="font-extrabold">Dobro je znati</p>
                                                <ul class="mt-2 space-y-1.5 text-xs leading-5 text-cyan-800">
                                                    @foreach ((array) $item['tips'] as $tip)
                                                        <li class="flex gap-2"><span aria-hidden="true">•</span><span>{{ $tip }}</span></li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif

                                        @if (! empty($item['warning']))
                                            <div class="mt-5 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-950">
                                                <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-200 text-xs font-black" aria-hidden="true">!</span>
                                                <div>
                                                    <p class="text-xs font-extrabold uppercase tracking-[0.12em] text-amber-800">Prije spremanja</p>
                                                    <p class="mt-1 text-xs leading-5 text-amber-900">{{ $item['warning'] }}</p>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <div id="admin-manual-no-results" class="hidden rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center shadow-sm">
                    <span class="mx-auto inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-100 text-xl text-slate-500" aria-hidden="true">?</span>
                    <h2 class="mt-3 text-base font-extrabold text-slate-900">Nema pronađenih uputa</h2>
                    <p class="mt-1 text-sm text-slate-600">Pokušajte s kraćim pojmom ili nazivom iz lijeve navigacije.</p>
                    <button type="button" data-manual-clear-search class="mt-4 rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-700">Prikaži sve upute</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const root = document.getElementById('admin-manual');
            if (!root) return;

            const search = root.querySelector('#admin-manual-search');
            const clearButton = root.querySelector('#admin-manual-search-clear');
            const resultCount = root.querySelector('#admin-manual-result-count');
            const noResults = root.querySelector('#admin-manual-no-results');
            const sections = Array.from(root.querySelectorAll('[data-manual-section]'));
            const entries = Array.from(root.querySelectorAll('[data-manual-entry]'));
            const tocLinks = Array.from(root.querySelectorAll('[data-manual-toc]'));
            const tocGroups = Array.from(root.querySelectorAll('[data-manual-toc-group]'));
            const tocTopicLinks = Array.from(root.querySelectorAll('[data-manual-toc-topic]'));
            const totalEntries = entries.length;

            const normalize = (value) => String(value ?? '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLocaleLowerCase('hr');

            const clearSearch = () => {
                search.value = '';
                search.dispatchEvent(new Event('input'));
                search.focus();
            };

            const updateResults = () => {
                const query = normalize(search.value).trim();
                let visibleEntries = 0;

                sections.forEach((section) => {
                    const sectionEntries = Array.from(section.querySelectorAll('[data-manual-entry]'));
                    let visibleInSection = 0;

                    sectionEntries.forEach((entry) => {
                        const matches = query === '' || normalize(entry.dataset.manualSearch).includes(query);
                        entry.hidden = !matches;
                        if (matches) {
                            visibleEntries += 1;
                            visibleInSection += 1;
                            if (query !== '') entry.open = true;
                        }
                    });

                    section.hidden = visibleInSection === 0;
                    const sectionId = section.dataset.manualSection;
                    const tocLink = tocLinks.find((link) => link.dataset.manualToc === sectionId);
                    const tocGroup = tocGroups.find((group) => group.dataset.manualTocGroup === sectionId);
                    if (tocLink) tocLink.hidden = visibleInSection === 0;
                    if (tocGroup) tocGroup.hidden = visibleInSection === 0;
                });

                tocTopicLinks.forEach((link) => {
                    const entry = entries.find((candidate) => candidate.dataset.manualEntry === link.dataset.manualTocTopic);
                    link.hidden = !entry || entry.hidden;
                });

                clearButton.classList.toggle('hidden', query === '');
                clearButton.classList.toggle('flex', query !== '');
                noResults.classList.toggle('hidden', visibleEntries !== 0);
                resultCount.textContent = query === ''
                    ? `Prikazano je svih ${totalEntries} uputa.`
                    : `Pronađeno uputa: ${visibleEntries}.`;
            };

            const setAllOpen = (open) => {
                entries.forEach((entry) => {
                    if (!entry.hidden) entry.open = open;
                });
            };

            const revealHashTarget = () => {
                const rawId = window.location.hash.slice(1);
                if (!rawId) return;

                const target = document.getElementById(decodeURIComponent(rawId));
                if (!target) return;

                if (target.matches('[data-manual-entry]')) target.open = true;

                const targetSection = target.matches('[data-manual-section]')
                    ? target
                    : target.closest('[data-manual-section]');
                if (targetSection) {
                    tocLinks.forEach((link) => link.classList.toggle(
                        'is-active',
                        link.dataset.manualToc === targetSection.dataset.manualSection,
                    ));
                }

                window.requestAnimationFrame(() => target.scrollIntoView({ block: 'start' }));
            };

            search.addEventListener('input', updateResults);
            clearButton.addEventListener('click', clearSearch);
            root.querySelectorAll('[data-manual-clear-search]').forEach((button) => button.addEventListener('click', clearSearch));
            root.querySelector('#admin-manual-expand-all')?.addEventListener('click', () => setAllOpen(true));
            root.querySelector('#admin-manual-collapse-all')?.addEventListener('click', () => setAllOpen(false));
            root.querySelectorAll('[data-manual-expand-all]').forEach((button) => button.addEventListener('click', () => setAllOpen(true)));
            root.querySelectorAll('[data-manual-collapse-all]').forEach((button) => button.addEventListener('click', () => setAllOpen(false)));
            window.addEventListener('hashchange', revealHashTarget);

            if ('IntersectionObserver' in window) {
                const sectionObserver = new IntersectionObserver((observedSections) => {
                    const visibleSection = observedSections.find((observedSection) => observedSection.isIntersecting);
                    if (!visibleSection) return;

                    const activeSectionId = visibleSection.target.dataset.manualSection;
                    tocLinks.forEach((link) => link.classList.toggle('is-active', link.dataset.manualToc === activeSectionId));
                }, {
                    rootMargin: '-96px 0px -68% 0px',
                    threshold: 0,
                });

                sections.forEach((section) => sectionObserver.observe(section));
            }

            updateResults();
            if (!window.location.hash && tocLinks[0]) tocLinks[0].classList.add('is-active');
            revealHashTarget();
        })();
    </script>
</x-admin-layout>
