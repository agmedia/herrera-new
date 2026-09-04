@extends('front.desktop.layouts.store')

@section('title', 'Stranica nije pronađena')
@section('robots', 'noindex,follow')
@section('body_class', 'error-page-404')
@section('main_class', 'w-full px-0 py-0')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/error-404.css') }}?v={{ filemtime(public_path('front-theme/styles/error-404.css')) }}">
@endpush

@section('content')
    <section class="error-404" aria-labelledby="not-found-title">
        <div class="storefront-container error-404__container">
            <div class="error-404__hero">
                <div class="error-404__code" aria-hidden="true">
                    404
                    <span class="error-404__degree"></span>
                </div>

                <p class="error-404__eyebrow">
                    <span class="error-404__eyebrow-line" aria-hidden="true"></span>
                    Ups, pogrešna adresa
                </p>

                <h1 id="not-found-title" class="error-404__title">Stranica nije pronađena</h1>
                <p class="error-404__lead">
                    Izgleda da je ova adresa promijenjena ili više nije dostupna.
                    Potražite proizvod ili odaberite jedan od brzih puteva natrag.
                </p>

                <div class="error-404__search-block">
                    <p class="error-404__search-label">Što tražite?</p>
                    <form action="{{ route('shop.index') }}" method="GET" class="error-404__search" role="search">
                        <label class="sr-only" for="error-404-search">Pretražite proizvode</label>
                        <input
                            id="error-404-search"
                            type="search"
                            name="q"
                            class="error-404__search-input"
                            placeholder="Upišite naziv proizvoda, brend ili šifru"
                            autocomplete="off"
                        >
                        <button type="submit" class="error-404__search-button">
                            <x-fa-icon name="magnifying-glass" class="error-404__search-icon" />
                            <span>Pretraži</span>
                        </button>
                    </form>
                    <p class="error-404__requested-path">
                        Tražena adresa:
                        <span>{{ '/'.ltrim((string) request()->path(), '/') }}</span>
                    </p>
                </div>
            </div>

            <nav class="error-404__quick-links" aria-label="Brzi povratak">
                <a href="{{ route('home') }}" class="error-404__quick-link">
                    <span class="error-404__quick-icon" aria-hidden="true">
                        <x-fa-icon name="arrow-right" />
                    </span>
                    <span>
                        <strong>Početna stranica</strong>
                        <small>Vratite se na početak</small>
                    </span>
                </a>

                <a href="{{ route('shop.index') }}" class="error-404__quick-link">
                    <span class="error-404__quick-icon" aria-hidden="true">
                        <x-fa-icon name="bag-shopping" />
                    </span>
                    <span>
                        <strong>Web trgovina</strong>
                        <small>Pregled cijele ponude</small>
                    </span>
                </a>

                <a href="{{ route('categories.index') }}" class="error-404__quick-link">
                    <span class="error-404__quick-icon" aria-hidden="true">
                        <x-fa-icon name="table-cells-large" />
                    </span>
                    <span>
                        <strong>Kategorije</strong>
                        <small>Pronađite vrstu proizvoda</small>
                    </span>
                </a>

                <a href="{{ route('contact.create') }}" class="error-404__quick-link">
                    <span class="error-404__quick-icon" aria-hidden="true">
                        <x-fa-icon name="circle-info" />
                    </span>
                    <span>
                        <strong>Trebate pomoć?</strong>
                        <small>Javite nam što tražite</small>
                    </span>
                </a>
            </nav>
        </div>
    </section>
@endsection
