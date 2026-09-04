@php
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $materialPayload = data_get($translationPayload, 'material_craftsmanship', []);
    if (! is_array($materialPayload)) {
        $materialPayload = [];
    }

    $materialText = static function (string $path) use ($materialPayload): string {
        $value = data_get($materialPayload, $path);

        return is_scalar($value) ? trim((string) $value) : '';
    };

    $title = trim((string) ($translation?->title ?? ''));
    $subtitle = trim((string) ($translation?->subtitle ?? ''));
    $expandLabel = $materialText('expand_label');

    $materials = [
        [
            'key' => 'micromodal',
            'tone' => 'dark',
            'icon' => asset('front-theme/images/MIKROMODAL.svg'),
            'eyebrow' => $materialText('materials.micromodal.eyebrow'),
            'title' => $materialText('materials.micromodal.title'),
            'intro' => $materialText('materials.micromodal.intro'),
            'body_1' => $materialText('materials.micromodal.body_1'),
            'body_2' => $materialText('materials.micromodal.body_2'),
            'bullets' => [
                ['icon' => asset('front-theme/images/SVILENKASTI_DODIR.svg'), 'text' => $materialText('materials.micromodal.bullets.0')],
                ['icon' => asset('front-theme/images/ELASTICNOST.svg'), 'text' => $materialText('materials.micromodal.bullets.1')],
                ['icon' => asset('front-theme/images/HIPOALERGEN.svg'), 'text' => $materialText('materials.micromodal.bullets.2')],
            ],
        ],
        [
            'key' => 'giza',
            'tone' => 'light',
            'icon' => asset('front-theme/images/GIZA_PAMUK.svg'),
            'eyebrow' => $materialText('materials.giza.eyebrow'),
            'title' => $materialText('materials.giza.title'),
            'intro' => $materialText('materials.giza.intro'),
            'body_1' => $materialText('materials.giza.body_1'),
            'body_2' => $materialText('materials.giza.body_2'),
            'bullets' => [
                ['icon' => asset('front-theme/images/PROZRACAN.svg'), 'text' => $materialText('materials.giza.bullets.0')],
                ['icon' => asset('front-theme/images/UPOJNOST.svg'), 'text' => $materialText('materials.giza.bullets.1')],
                ['icon' => asset('front-theme/images/DUGOTRAJAN.svg'), 'text' => $materialText('materials.giza.bullets.2')],
            ],
        ],
    ];
@endphp

<section class="relative left-1/2 w-screen max-w-[100vw] -translate-x-1/2 overflow-x-hidden py-10 sm:py-12" id="material-comparison-{{ $block->id }}">
    <style>
        #material-comparison-{{ $block->id }} .materials-heading {
            max-width: 760px;
            margin: 0 auto;
            text-align: center;
        }

        #material-comparison-{{ $block->id }} .materials-subtitle {
            max-width: 640px;
            margin: 0.5rem auto 0;
            color: #64748b;
            font-size: 0.875rem;
            line-height: 1.7;
        }

        #material-comparison-{{ $block->id }} .materials-grid {
            display: grid;
            gap: 1.5rem;
            margin-top: 2.75rem;
        }

        #material-comparison-{{ $block->id }} .material-card {
            height: 100%;
            background: #ffffff;
            border: 1px solid #dbe4ee;
            padding: 1.5rem 1.15rem 1.4rem;
        }

        #material-comparison-{{ $block->id }} .material-top {
            display: flex;
            align-items: flex-start;
            gap: 0.875rem;
        }

        #material-comparison-{{ $block->id }} .material-hero-icon {
            width: 52px;
            height: 52px;
            border-radius: 999px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        #material-comparison-{{ $block->id }} .material-hero-icon-img {
            display: block;
            width: 52px;
            height: 52px;
            object-fit: contain;
        }

        #material-comparison-{{ $block->id }} .material-kicker {
            margin: 0 0 0.35rem;
            font-size: 9px;
            line-height: 1.4;
            font-weight: 600;
            letter-spacing: 0.24em;
            text-transform: uppercase;
        }

        #material-comparison-{{ $block->id }} .material-name {
            margin: 0;
            color: #0f172a;
            font-size: 1.15rem;
            line-height: 1.15;
            letter-spacing: -0.045em;
            font-weight: 600;
        }

        #material-comparison-{{ $block->id }} .material-intro {
            margin: 1rem 0 0;
            color: #526072;
            font-size: 0.92rem;
            line-height: 1.75;
        }

        #material-comparison-{{ $block->id }} summary::-webkit-details-marker {
            display: none;
        }

        #material-comparison-{{ $block->id }} details[open] .material-expand-chevron {
            transform: rotate(180deg);
        }

        #material-comparison-{{ $block->id }} .material-expand-chevron {
            transition: transform .28s ease;
        }

        #material-comparison-{{ $block->id }} .material-summary {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 0.95rem;
            cursor: pointer;
            color: #0f172a;
            font-size: 11px;
            line-height: 1.2;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            list-style: none;
        }

        #material-comparison-{{ $block->id }} .material-summary:hover {
            opacity: 0.8;
        }

        #material-comparison-{{ $block->id }} .material-body-wrap {
            display: grid;
            grid-template-rows: 0fr;
            margin-top: 0;
            opacity: 0;
            transition: grid-template-rows .3s ease, margin-top .3s ease, opacity .22s ease;
        }

        #material-comparison-{{ $block->id }} details[open] .material-body-wrap {
            grid-template-rows: 1fr;
            margin-top: 0.8rem;
            opacity: 1;
        }

        #material-comparison-{{ $block->id }} .material-body-inner {
            overflow: hidden;
        }

        #material-comparison-{{ $block->id }} .material-body {
            color: #526072;
            font-size: 14px;
            line-height: 1.7;
            transform: translateY(-8px);
            transition: transform .3s ease;
        }

        #material-comparison-{{ $block->id }} details[open] .material-body {
            transform: translateY(0);
        }

        #material-comparison-{{ $block->id }} .material-body p {
            margin: 0;
        }

        #material-comparison-{{ $block->id }} .material-body p + p {
            margin-top: 0.8rem;
        }

        #material-comparison-{{ $block->id }} .material-benefits {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.5rem;
            margin-top: 1.35rem;
            padding-top: 1rem;
            border-top: 1px solid #edf2f7;
        }

        #material-comparison-{{ $block->id }} .material-benefit {
            min-width: 0;
            text-align: center;
        }

        #material-comparison-{{ $block->id }} .material-benefit-icon {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        #material-comparison-{{ $block->id }} .material-benefit-icon-img {
            display: block;
            width: 42px;
            height: 42px;
            object-fit: contain;
        }

        #material-comparison-{{ $block->id }} .material-benefit-label {
            margin: 0.55rem 0 0;
            color: #0f172a;
            font-size: 9px;
            line-height: 1.45;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        @media (min-width: 640px) {
            #material-comparison-{{ $block->id }} .materials-subtitle {
                font-size: 1rem;
            }

            #material-comparison-{{ $block->id }} .material-card {
                padding: 2.25rem 2rem 2rem;
            }

            #material-comparison-{{ $block->id }} .material-hero-icon {
                width: 62px;
                height: 62px;
            }

            #material-comparison-{{ $block->id }} .material-hero-icon-img {
                width: 62px;
                height: 62px;
            }

            #material-comparison-{{ $block->id }} .material-kicker {
                margin: 4px 0 8px;
                font-size: 10px;
                letter-spacing: 0.28em;
            }

            #material-comparison-{{ $block->id }} .material-name {
                font-size: 1.65rem;
                line-height: 1.06;
            }

            #material-comparison-{{ $block->id }} .material-intro {
                margin-top: 1.35rem;
                font-size: 1rem;
            }

            #material-comparison-{{ $block->id }} .material-summary {
                margin-top: 1.1rem;
                font-size: 12px;
                letter-spacing: 0.18em;
            }

            #material-comparison-{{ $block->id }} .material-body {
                font-size: 15px;
                line-height: 1.8;
            }

            #material-comparison-{{ $block->id }} .material-benefits {
                gap: 1rem;
                margin-top: 1.9rem;
                padding-top: 1.35rem;
            }

            #material-comparison-{{ $block->id }} .material-benefit-icon {
                width: 58px;
                height: 58px;
            }

            #material-comparison-{{ $block->id }} .material-benefit-icon-img {
                width: 58px;
                height: 58px;
            }

            #material-comparison-{{ $block->id }} .material-benefit-label {
                margin-top: 0.8rem;
                font-size: 11px;
                line-height: 1.55;
                letter-spacing: 0.15em;
            }
        }

        @media (min-width: 1024px) {
            #material-comparison-{{ $block->id }} .materials-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            #material-comparison-{{ $block->id }} .material-name {
                font-size: 1.7rem;
                line-height: 1.08;
            }
        }
    </style>

    <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
        @if ($title !== '' || $subtitle !== '')
            <div class="max-[540px]:mb-5 mb-8 text-center">
                @if ($title !== '')
                    <div class="mx-auto flex max-w-3xl items-center gap-4 md:gap-6">
                        @include('front.partials.section-heading-line', ['side' => 'left'])
                        <h2 class="max-[540px]:text-[1.18rem] max-[540px]:leading-[1.65rem] text-[1.35rem] leading-[1.95rem] sm:text-[1.7rem] sm:leading-[2.5rem] font-semibold text-slate-900">{{ $title }}</h2>
                        @include('front.partials.section-heading-line', ['side' => 'right'])
                    </div>
                @endif

                @if ($subtitle !== '')
                    <p class="materials-subtitle">{{ $subtitle }}</p>
                @endif
            </div>
        @endif

        <div class="materials-grid">
            @foreach ($materials as $material)
                @php
                    $hasBody = $material['body_1'] !== '' || $material['body_2'] !== '';
                    $visibleBullets = array_values(array_filter($material['bullets'], static fn (array $bullet): bool => $bullet['text'] !== ''));
                    $hasMaterialContent = $material['eyebrow'] !== ''
                        || $material['title'] !== ''
                        || $material['intro'] !== ''
                        || $hasBody
                        || $visibleBullets !== [];
                    $eyebrowStyle = $material['tone'] === 'dark' ? 'color:#5b6f8d;' : 'color:#8a6f58;';
                    $heroIconStyle = $material['tone'] === 'dark'
                        ? 'background:#eef2ff;color:#23344d;'
                        : 'background:#f7f1ea;color:#6c5948;';
                    $benefitIconStyle = $material['tone'] === 'dark'
                        ? 'background:#f3f6fb;color:#23344d;'
                        : 'background:#faf5ef;color:#6c5948;';
                @endphp
                @continue(! $hasMaterialContent)

                <article class="material-card">
                    <div class="material-top">
                        <span class="material-hero-icon" style="{{ $heroIconStyle }}">
                            <img src="{{ $material['icon'] }}" alt="" class="material-hero-icon-img" loading="lazy" decoding="async" aria-hidden="true">
                        </span>

                        <div>
                            @if ($material['eyebrow'] !== '')
                                <p class="material-kicker" style="{{ $eyebrowStyle }}">{{ $material['eyebrow'] }}</p>
                            @endif
                            @if ($material['title'] !== '')
                                <h3 class="material-name">{{ $material['title'] }}</h3>
                            @endif
                        </div>
                    </div>

                    @if ($material['intro'] !== '')
                        <p class="material-intro">{{ $material['intro'] }}</p>
                    @endif

                    @if ($expandLabel !== '' && $hasBody)
                        <details>
                            <summary class="material-summary">
                                <span>{{ $expandLabel }}</span>
                                <svg class="material-expand-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="m6 9 6 6 6-6"></path>
                                </svg>
                            </summary>

                            <div class="material-body-wrap">
                                <div class="material-body-inner">
                                    <div class="material-body">
                                        @if ($material['body_1'] !== '')
                                            <p>{{ $material['body_1'] }}</p>
                                        @endif
                                        @if ($material['body_2'] !== '')
                                            <p>{{ $material['body_2'] }}</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </details>
                    @endif

                    @if ($visibleBullets !== [])
                        <div class="material-benefits">
                            @foreach ($visibleBullets as $bullet)
                                    <div class="material-benefit">
                                        <span class="material-benefit-icon" style="{{ $benefitIconStyle }}">
                                            <img src="{{ $bullet['icon'] }}" alt="" class="material-benefit-icon-img" loading="lazy" decoding="async" aria-hidden="true">
                                        </span>
                                        <p class="material-benefit-label">{{ $bullet['text'] }}</p>
                                    </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</section>
