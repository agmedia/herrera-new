@php
    $brandCount = $manufacturerGroups->flatten(1)->count();

    $croatianNoun = static function (int $count, string $one, string $few, string $many): string {
        $lastTwo = $count % 100;
        $last = $count % 10;

        if ($lastTwo >= 11 && $lastTwo <= 14) {
            return $many;
        }

        return match ($last) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    };
@endphp

<div class="brand-directory" id="svi-brendovi" data-brand-directory>
    @if ($brandCount === 0)
        <div class="brand-directory__empty">
            Trenutačno nema aktivnih brendova.
        </div>
    @else
        <nav class="brand-directory__alphabet" aria-label="Filtriranje brendova prema početnom slovu">
            <a
                href="#svi-brendovi"
                class="brand-directory__letter brand-directory__letter--all"
                data-brand-letter="*"
                aria-current="true"
            >
                Svi
            </a>

            @foreach ($manufacturerAlphabet as $letter)
                @if (in_array($letter, $availableManufacturerLetters, true))
                    <a
                        href="#slovo-{{ urlencode($letter) }}"
                        class="brand-directory__letter"
                        data-brand-letter="{{ $letter }}"
                        aria-label="Prikaži brendove na slovo {{ $letter }}"
                    >
                        {{ $letter }}
                    </a>
                @else
                    <span
                        class="brand-directory__letter is-disabled"
                        aria-disabled="true"
                        title="Nema brendova na slovo {{ $letter }}"
                    >
                        {{ $letter }}
                    </span>
                @endif
            @endforeach
        </nav>

        <p class="brand-directory__status sr-only" data-brand-status aria-live="polite">
            Prikazani su svi brendovi.
        </p>

        <div class="brand-directory__groups">
            @foreach ($manufacturerGroups as $letter => $items)
                <section
                    class="brand-directory__group"
                    id="slovo-{{ urlencode($letter) }}"
                    data-brand-group="{{ $letter }}"
                    aria-labelledby="naslov-slovo-{{ urlencode($letter) }}"
                >
                    <div class="brand-directory__group-heading">
                        <h2 id="naslov-slovo-{{ urlencode($letter) }}">{{ $letter }}</h2>
                        <span>
                            {{ $items->count() }}
                            {{ $croatianNoun($items->count(), 'brend', 'brenda', 'brendova') }}
                        </span>
                    </div>

                    <div class="brand-directory__grid">
                        @foreach ($items as $item)
                            <a
                                href="{{ route('manufacturers.show', ['slug' => $item['slug']]) }}"
                                class="brand-directory__card"
                                aria-label="{{ $item['name'] }} – pregledaj proizvode"
                            >
                                <span class="brand-directory__logo">
                                    @if ($item['logo_url'])
                                        <img
                                            src="{{ $item['logo_url'] }}"
                                            alt="Logotip brenda {{ $item['name'] }}"
                                            width="180"
                                            height="72"
                                            loading="lazy"
                                            decoding="async"
                                            referrerpolicy="no-referrer"
                                            data-brand-logo
                                        >
                                        <span class="brand-directory__initials" aria-hidden="true" data-brand-logo-fallback hidden>{{ $item['initials'] }}</span>
                                    @else
                                        <span class="brand-directory__initials" aria-hidden="true">{{ $item['initials'] }}</span>
                                    @endif
                                </span>

                                <span class="brand-directory__card-content">
                                    <strong>{{ $item['name'] }}</strong>
                                    <span>
                                        {{ $item['products_count'] }}
                                        {{ $croatianNoun($item['products_count'], 'proizvod', 'proizvoda', 'proizvoda') }}
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
