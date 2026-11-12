@if (auth()->guest() || (isset($herreraWidgetBlock) && (int) ($previewBlockId ?? 0) === (int) $herreraWidgetBlock->id))
    @php
        $registrationPayload = \App\Support\HerreraHomeWidgets::payload($herreraWidgetBlock ?? null, $herreraWidgetTranslation ?? null);
        $registrationFields = $registrationPayload['widget_fields'] ?? [];
        $registrationHeadingId = 'herrera-home-registration-title'.(isset($herreraWidgetBlock) ? '-'.$herreraWidgetBlock->id : '');
        $registrationEyebrow = $registrationPayload['eyebrow'] ?? __('herrera.registration.eyebrow');
        $registrationDescription = $herreraWidgetTranslation?->subtitle ?? __('herrera.registration.description');
        $registrationUrl = \App\Support\HerreraHomeWidgets::safeUrl($herreraWidgetTranslation?->cta_url ?? '', route('front.auth.b2b-register'));
        $registrationLoginUrl = \App\Support\HerreraHomeWidgets::safeUrl($registrationFields['login_url'] ?? '', route('front.auth.login'));
    @endphp
    @once('herrera-home-registration-style')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-registration.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-registration.css')) }}">
        @endpush
    @endonce

    @once('herrera-home-registration-'.$registrationHeadingId)
        <section class="herrera-home-registration" aria-labelledby="{{ $registrationHeadingId }}" data-herrera-home-registration>
            <div class="herrera-home-registration-copy">
                @if ($registrationEyebrow !== '')<p class="herrera-home-registration-eyebrow">{{ $registrationEyebrow }}</p>@endif
                <h2 id="{{ $registrationHeadingId }}">{{ $herreraWidgetTranslation?->title ?? __('herrera.registration.title') }}</h2>
                @if ($registrationDescription !== '')<p class="herrera-home-registration-description">{{ $registrationDescription }}</p>@endif
                <div class="herrera-home-registration-actions">
                    <a class="herrera-home-registration-primary" href="{{ $registrationUrl }}">
                        {{ $herreraWidgetTranslation?->cta_label ?? __('herrera.registration.action') }} <x-fa-icon name="arrow-right" />
                    </a>
                    <a class="herrera-home-registration-login" href="{{ $registrationLoginUrl }}">{{ $registrationFields['login_label'] ?? __('herrera.registration.login') }}</a>
                </div>
                <p class="herrera-home-registration-note">{{ $registrationFields['note'] ?? __('herrera.registration.note') }}</p>
            </div>
            <ul class="herrera-home-registration-benefits">
                @foreach (['prices' => ['prices', 'user-check'], 'order' => ['quick_order', 'list-check'], 'history' => ['orders', 'rotate-right']] as $benefit => [$field, $icon])
                    <li>
                        <span class="herrera-home-registration-icon"><x-fa-icon :name="$icon" /></span>
                        <div>
                            <h3>{{ $registrationFields[$field.'_label'] ?? __('herrera.benefit_'.$benefit) }}</h3>
                            <p>{{ $registrationFields[$field.'_description'] ?? __('herrera.registration.benefits.'.$benefit) }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endonce
@endif
