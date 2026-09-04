@extends('front.desktop.layouts.store')

@section('title', __('ui.wishlist.page_title'))
@section('main_class', 'w-full px-0 py-8')

@section('content')
    @php
        $cols = (int) ($cols ?? 4);

        $gridClass = match ($cols) {
            1 => 'grid grid-cols-1',
            2 => 'grid grid-cols-2',
            3 => 'grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-3',
            5 => 'grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5',
            default => 'grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4',
        };
    @endphp

    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/category-catalog.css') }}?v={{ filemtime(public_path('front-theme/styles/category-catalog.css')) }}">
    @endpush

    <section class="storefront-container px-3 sm:px-4 lg:px-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.wishlist.title') }}</h1>
                <p class="mt-2 text-slate-600">{{ __('ui.wishlist.subtitle') }}</p>
            </div>
            <div class="md:hidden">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.shop.filters.grid') }}</label>
                <div class="flex h-[42px]">
                    @foreach ([1, 2] as $gridCols)
                        <a
                            href="{{ route('wishlist.index', array_merge(request()->query(), ['cols' => $gridCols])) }}"
                            class="inline-flex h-full w-11 items-center justify-center border border-slate-300 {{ $cols === $gridCols ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 hover:bg-slate-100' }}"
                            aria-label="{{ __('ui.shop.filters.grid') }} {{ $gridCols }}"
                        >
                            <span class="flex h-4 items-stretch gap-[2px]">
                                @for ($i = 0; $i < $gridCols; $i++)
                                    <span class="h-4 w-[3px] border border-current/80"></span>
                                @endfor
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
            <div class="hidden md:block">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.shop.filters.grid') }}</label>
                <div class="flex h-[42px]">
                    @foreach ([3, 4, 5] as $gridCols)
                        <a
                            href="{{ route('wishlist.index', array_merge(request()->query(), ['cols' => $gridCols])) }}"
                            class="{{ $gridCols === 5 ? 'hidden 2xl:inline-flex' : 'inline-flex' }} h-full w-11 items-center justify-center border border-slate-300 {{ $cols === $gridCols ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 hover:bg-slate-100' }}"
                            aria-label="{{ __('ui.shop.filters.grid') }} {{ $gridCols }}"
                        >
                            <span class="flex h-4 items-stretch gap-[1px]">
                                @for ($i = 0; $i < $gridCols; $i++)
                                    <span class="h-4 w-[2px] border border-current/80"></span>
                                @endfor
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="storefront-container px-3 py-6 sm:px-4 lg:px-6" data-wishlist-page data-wishlist-empty-text="{{ __('ui.wishlist.empty') }}">
        @if ($products->isEmpty())
            <div class="border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">
                {{ __('ui.wishlist.empty') }}
            </div>
        @else
            <div class="catalog-lined-grid {{ $gridClass }}">
                @foreach ($products as $product)
                    @include('front.desktop.partials.product-card', [
                        'product' => $product,
                        'locale' => $locale,
                        'fallbackLocale' => $fallbackLocale,
                        'flat' => true,
                        'lined' => true,
                        'priorityImage' => $loop->first,
                        'headingLevel' => 2,
                    ])
                @endforeach
            </div>
        @endif
    </section>
@endsection
