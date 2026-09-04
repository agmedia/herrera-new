<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'AG Shop') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        html {
            scroll-behavior: smooth;
        }

        #categories,
        #featured,
        #contact {
            scroll-margin-top: 2rem;
        }

        #site-header,
        #site-header * {
            transition: all 0.3s ease;
        }

        #site-header .header-inner {
            height: 5rem;
        }

        #site-header.is-scrolled .header-inner {
            height: 3.5rem;
        }

        #site-header.is-scrolled {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
@php
    $categories = [
        ['name' => 'Backpacks', 'description' => 'Urban, travel and daily carry options.'],
        ['name' => 'Outerwear', 'description' => 'Layered pieces for weather-ready movement.'],
        ['name' => 'Footwear', 'description' => 'Comfort first with durable construction.'],
        ['name' => 'Accessories', 'description' => 'Compact essentials and utility add-ons.'],
    ];

    $products = [
        ['name' => 'Transit Pro Backpack', 'price' => 'EUR 89', 'tag' => 'Best seller'],
        ['name' => 'Storm Utility Jacket', 'price' => 'EUR 129', 'tag' => 'New drop'],
        ['name' => 'Weekend Duffel 32L', 'price' => 'EUR 109', 'tag' => 'Limited'],
    ];

    $homeBeforeProductsBlocks = app(\App\Services\Content\ContentBlockResolver::class)->forPlacement(
        'home.before_products',
        app()->getLocale(),
        null,
        null,
        'desktop'
    );
    $homeCategoriesBlocks = app(\App\Services\Content\ContentBlockResolver::class)->forPlacement(
        'home.categories',
        app()->getLocale(),
        null,
        null,
        'desktop'
    );

    $homeHeroBlocks = app(\App\Services\Content\ContentBlockResolver::class)->forPlacement(
        'home.hero',
        app()->getLocale(),
        null,
        null,
        'desktop'
    );

    $homeHeroBenefitsBlocks = app(\App\Services\Content\ContentBlockResolver::class)->forPlacement(
        'home.hero_benefits',
        app()->getLocale(),
        null,
        null,
        'desktop'
    );

    $isFrontPreview = false;
    $frontPreviewBlock = null;
    $frontPreviewPlacement = null;

    $viewer = auth()->user();
    $canPreviewBlock = $viewer && ($viewer->isA('superadmin') || $viewer->can('content.blocks'));
    $previewBlockId = $canPreviewBlock ? (int) request()->query('preview_block', 0) : 0;
    $requestedPreviewPlacement = $canPreviewBlock ? (string) request()->query('preview_placement', '') : '';

    if ($previewBlockId > 0) {
        $frontPreviewBlock = \App\Models\Content\ContentBlock::query()
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', [app()->getLocale(), config('app.locale')]),
                'slots' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            ])
            ->find($previewBlockId);

        if ($frontPreviewBlock) {
            $frontPreviewPlacement = $requestedPreviewPlacement !== ''
                ? $requestedPreviewPlacement
                : (string) ($frontPreviewBlock->slots->first()?->placement ?? 'home.hero');

            $frontPreviewTranslation = $frontPreviewBlock->translations->firstWhere('locale', app()->getLocale())
                ?? $frontPreviewBlock->translations->firstWhere('locale', config('app.locale'));
            $frontPreviewSlot = $frontPreviewBlock->slots->firstWhere('placement', $frontPreviewPlacement)
                ?? new \App\Models\Content\ContentBlockSlot(['placement' => $frontPreviewPlacement]);
            $frontPreviewItem = collect([[
                'slot' => $frontPreviewSlot,
                'block' => $frontPreviewBlock,
                'translation' => $frontPreviewTranslation,
            ]]);

            if ($frontPreviewPlacement === 'home.hero') {
                $homeHeroBlocks = $frontPreviewItem;
                $isFrontPreview = true;
            } elseif ($frontPreviewPlacement === 'home.hero_benefits') {
                $homeHeroBenefitsBlocks = $frontPreviewItem;
                $isFrontPreview = true;
            } elseif ($frontPreviewPlacement === 'home.before_products') {
                $homeBeforeProductsBlocks = $frontPreviewItem;
                $isFrontPreview = true;
            } elseif ($frontPreviewPlacement === 'home.categories') {
                $homeCategoriesBlocks = $frontPreviewItem;
                $isFrontPreview = true;
            }
        }
    }
@endphp

<header id="site-header" class="sticky top-0 z-50">
    <div class="absolute inset-0 bg-gradient-to-r from-blue-700 via-indigo-700 to-sky-600"></div>
    <div class="absolute inset-0 bg-black/10 backdrop-blur-md"></div>

    <div class="relative mx-auto max-w-7xl px-6">
        <div class="header-inner flex items-center justify-between text-white">
            <a href="/" class="flex items-center gap-4 text-2xl font-extrabold tracking-tight hover:opacity-90">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/15">AG</span>
                AGSHOP
            </a>

            <nav class="hidden items-center gap-10 text-base font-medium md:flex">
                <a href="{{ route('categories.index') }}" class="text-white/80 hover:text-white">Categories</a>
                <a href="{{ route('shop.index') }}" class="text-white/80 hover:text-white">Featured</a>
                <a href="{{ route('contact.create') }}" class="text-white/80 hover:text-white">Contact</a>

                @auth
                    <a href="{{ route('admin.dashboard') }}" class="ml-4 inline-flex items-center justify-center rounded-xl bg-white px-5 py-2.5 font-semibold text-blue-700 hover:bg-slate-100">
                        Admin
                    </a>
                @else
                    <a href="{{ route('login') }}" class="text-white/80 hover:text-white">Login</a>
                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-xl bg-white px-5 py-2.5 font-semibold text-blue-700 hover:bg-slate-100">
                        Register
                    </a>
                @endauth
            </nav>
        </div>
    </div>
</header>

<main>
    @if ($isFrontPreview)
        <section class="border-b border-amber-200 bg-amber-50">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-6 py-3">
                <p class="text-sm font-medium text-amber-900">
                    Front preview mode:
                    <span class="font-semibold">{{ $frontPreviewBlock?->name }}</span>
                    <span class="text-amber-700">({{ $frontPreviewPlacement }})</span>
                </p>
                <a href="{{ route('home') }}" class="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                    Exit Preview
                </a>
            </div>
        </section>
    @endif

    <section class="relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-blue-700 via-indigo-700 to-sky-600"></div>
        <div class="absolute -top-24 -right-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>
        <div class="absolute -bottom-24 -left-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>

        <div class="relative mx-auto max-w-7xl px-6 pb-14 pt-14 lg:pt-16">
            @if ($homeHeroBlocks->isNotEmpty())
                @include('components.content-placement', ['items' => $homeHeroBlocks])
            @else
                <div class="max-w-3xl text-white">
                    <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm">
                        <span class="h-2 w-2 rounded-full bg-emerald-300"></span>
                        New season collection live now
                    </p>

                    <h1 class="mt-6 text-4xl font-extrabold leading-tight tracking-tight lg:text-6xl">
                        Modern essentials,
                        <br class="hidden sm:block">
                        built for everyday carry.
                    </h1>

                    <p class="mt-6 max-w-xl text-lg text-white/90">
                        AGShop combines durable materials, clean silhouettes and practical storage to keep your daily setup lightweight and ready.
                    </p>

                    <div class="mt-10 flex flex-wrap items-center gap-4">
                        <a href="{{ route('shop.index') }}" class="rounded-xl bg-white px-6 py-3 font-semibold text-blue-700 hover:bg-slate-100">Shop featured</a>
                        <a href="{{ route('categories.index') }}" class="rounded-xl border border-white/30 px-6 py-3 text-white hover:bg-white/10">Browse categories</a>
                    </div>
                </div>
            @endif
        </div>

        <div class="relative border-t border-white/10">
            <div class="absolute inset-0 bg-white/5 backdrop-blur-sm"></div>
            <div class="relative w-full px-6 py-10">
                @if ($homeHeroBenefitsBlocks->isNotEmpty())
                    @include('components.content-placement', ['items' => $homeHeroBenefitsBlocks])
                @else
                    <div class="mx-auto grid max-w-7xl gap-8 text-white md:grid-cols-3">
                        <div class="flex items-center gap-4">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                                <span class="text-xl font-bold">+</span>
                            </div>
                            <div>
                                <div class="text-2xl font-bold leading-none">Fast Dispatch</div>
                                <div class="mt-1 text-sm leading-tight text-white/80">Orders before 14:00 ship same day.</div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                                <span class="text-xl font-bold">+</span>
                            </div>
                            <div>
                                <div class="text-2xl font-bold leading-none">Easy Returns</div>
                                <div class="mt-1 text-sm leading-tight text-white/80">30-day return flow with no paperwork.</div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                                <span class="text-xl font-bold">+</span>
                            </div>
                            <div>
                                <div class="text-2xl font-bold leading-none">Secure Checkout</div>
                                <div class="mt-1 text-sm leading-tight text-white/80">Card, Apple Pay, and Google Pay support.</div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

    @if ($homeBeforeProductsBlocks->isNotEmpty())
        <section class="bg-slate-50 py-16">
            <div class="mx-auto max-w-7xl px-6">
                @include('components.content-placement', ['items' => $homeBeforeProductsBlocks])
            </div>
        </section>
    @endif

    @if ($homeCategoriesBlocks->isNotEmpty())
        @include('components.content-placement', ['items' => $homeCategoriesBlocks])
    @else
        <section id="categories" class="border-y border-slate-200/60 bg-slate-100/70 py-24">
            <div class="mx-auto max-w-7xl px-6">
                <div class="mb-10">
                    <h2 class="text-4xl font-extrabold tracking-tight text-slate-900">Shop by category</h2>
                    <p class="mt-4 max-w-2xl text-lg text-slate-600">Locker-like layout rhythm with a cleaner ecommerce direction for AGShop.</p>
                </div>

                <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($categories as $category)
                        <article class="rounded-3xl border border-slate-200/60 bg-white p-7 shadow-sm transition hover:shadow-md">
                            <div class="h-32 rounded-2xl bg-gradient-to-br from-slate-200 to-blue-100"></div>
                            <h3 class="mt-5 text-xl font-semibold text-slate-900">{{ $category['name'] }}</h3>
                            <p class="mt-3 text-slate-600">{{ $category['description'] }}</p>
                            <a href="#" class="mt-4 inline-flex text-sm font-semibold text-blue-700 hover:text-blue-800">Explore collection</a>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section id="featured" class="bg-slate-50 py-24">
        <div class="mx-auto max-w-7xl px-6">
            <div class="mb-10 flex items-end justify-between gap-6">
                <div>
                    <h2 class="text-4xl font-extrabold tracking-tight text-slate-900">Featured products</h2>
                    <p class="mt-4 max-w-2xl text-lg text-slate-600">Weekly curated products ready for your real catalog binding.</p>
                </div>
                <a href="{{ route('shop.index') }}" class="text-base font-semibold text-blue-700 hover:text-blue-800">View all</a>
            </div>

            <div class="grid gap-8 lg:grid-cols-3">
                @foreach ($products as $product)
                    <article class="overflow-hidden rounded-3xl border border-slate-200/60 bg-white shadow-sm transition hover:shadow-md">
                        <div class="h-52 bg-gradient-to-br from-slate-300 via-slate-200 to-blue-100"></div>
                        <div class="p-6">
                            <span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-blue-700">{{ $product['tag'] }}</span>
                            <h3 class="mt-3 text-xl font-semibold text-slate-900">{{ $product['name'] }}</h3>
                            <div class="mt-4 flex items-center justify-between">
                                <p class="text-lg font-bold text-slate-900">{{ $product['price'] }}</p>
                                <button class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Add</button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section id="contact" class="bg-white py-24">
        <div class="mx-auto max-w-7xl px-6">
            <div class="relative overflow-hidden rounded-3xl border border-slate-200/60">
                <div class="absolute inset-0 bg-gradient-to-br from-blue-700 via-indigo-700 to-sky-600"></div>
                <div class="absolute -top-24 -right-24 h-64 w-64 rounded-full bg-white/10 blur-3xl"></div>
                <div class="absolute -bottom-24 -left-24 h-64 w-64 rounded-full bg-white/10 blur-3xl"></div>

                <div class="relative grid items-center gap-10 p-12 text-white lg:grid-cols-12 lg:p-16">
                    <div class="lg:col-span-8">
                        <h2 class="text-4xl font-extrabold tracking-tight">Ready to launch the new front?</h2>
                        <p class="mt-5 max-w-2xl text-lg text-white/90">Desktop now follows the locker-inspired visual direction while staying optimized for AGShop ecommerce content.</p>
                    </div>

                    <div class="flex flex-col gap-4 lg:col-span-4">
                        <a href="{{ route('shop.index') }}" class="inline-flex items-center justify-center rounded-2xl bg-white px-7 py-3.5 text-base font-semibold text-blue-700 hover:bg-slate-100">Browse products</a>
                        @auth
                            <a href="{{ route('admin.dashboard') }}" class="inline-flex items-center justify-center rounded-2xl border border-white/25 px-7 py-3.5 text-base font-semibold hover:bg-white/10">Open admin</a>
                        @else
                            <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-2xl border border-white/25 px-7 py-3.5 text-base font-semibold hover:bg-white/10">Login</a>
                        @endauth
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="relative bg-slate-950 text-slate-300">
    <div class="absolute inset-x-0 top-0 h-px bg-white/10"></div>
    <div class="absolute -top-24 left-1/2 h-48 w-[42rem] -translate-x-1/2 rounded-full bg-blue-500/10 blur-3xl"></div>

    <div class="relative mx-auto max-w-7xl px-6 py-16">
        <div class="grid gap-14 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <a href="/" class="inline-flex items-center gap-4 text-white hover:opacity-90">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/10">AG</span>
                    <span class="text-2xl font-extrabold tracking-tight">AGSHOP</span>
                </a>

                <p class="mt-5 max-w-sm text-base leading-normal text-slate-400">
                    Product-focused storefront baseline inspired by the locker front style and adapted for AGShop commerce flow.
                </p>

                <div class="mt-7 flex flex-wrap gap-4">
                    <a href="{{ route('shop.index') }}" class="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-base font-semibold text-blue-700 hover:bg-slate-100">Shop now</a>
                    <a href="{{ route('contact.create') }}" class="inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/10 px-6 py-3 text-base font-semibold text-white hover:bg-white/15">Contact</a>
                </div>
            </div>

            <div class="grid gap-12 sm:grid-cols-2 lg:col-span-5">
                <div>
                    <div class="mb-5 text-lg font-semibold text-white">Shop</div>
                    <ul class="space-y-4 text-base text-slate-400">
                        <li><a href="{{ route('categories.index') }}" class="transition hover:text-white">Categories</a></li>
                        <li><a href="{{ route('shop.index') }}" class="transition hover:text-white">Featured</a></li>
                        <li><a href="#" class="transition hover:text-white">New arrivals</a></li>
                        <li><a href="#" class="transition hover:text-white">Best sellers</a></li>
                    </ul>
                </div>

                <div>
                    <div class="mb-5 text-lg font-semibold text-white">Support</div>
                    <ul class="space-y-4 text-base text-slate-400">
                        <li><a href="#" class="transition hover:text-white">Shipping</a></li>
                        <li><a href="#" class="transition hover:text-white">Returns</a></li>
                        <li><a href="#" class="transition hover:text-white">Privacy</a></li>
                        <li><a href="#" class="transition hover:text-white">Terms</a></li>
                    </ul>
                </div>
            </div>

            <div class="lg:col-span-3">
                <div class="mb-5 text-lg font-semibold text-white">Payments</div>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <div class="text-base font-semibold text-slate-300">Accepted methods</div>
                    <p class="mt-3 text-base leading-normal text-slate-400">Card and modern wallets enabled for fast checkout.</p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <span class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-300">Visa</span>
                        <span class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-300">Mastercard</span>
                        <span class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-300">Apple Pay</span>
                        <span class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-300">Google Pay</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-16 flex flex-col gap-4 border-t border-white/10 pt-8 text-sm text-slate-500 md:flex-row md:items-center md:justify-between">
            <p>© {{ date('Y') }} {{ config('app.name', 'AG Shop') }}. All rights reserved.</p>
            <div class="flex flex-wrap gap-6">
                <a href="#" class="transition hover:text-slate-300">Legal</a>
                <a href="#" class="transition hover:text-slate-300">Payments</a>
                <a href="#" class="transition hover:text-slate-300">Privacy</a>
            </div>
        </div>
    </div>
</footer>

<script>
    const header = document.getElementById('site-header');

    window.addEventListener('scroll', () => {
        if (window.scrollY > 40) {
            header.classList.add('is-scrolled');
        } else {
            header.classList.remove('is-scrolled');
        }
    });
</script>
</body>
</html>
