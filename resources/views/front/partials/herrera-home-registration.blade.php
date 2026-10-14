@guest
    @once('herrera-home-registration')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-registration.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-registration.css')) }}">
        @endpush

        <section class="herrera-home-registration" aria-labelledby="herrera-home-registration-title" data-herrera-home-registration>
            <div class="herrera-home-registration-copy">
                <p class="herrera-home-registration-eyebrow">{{ __('herrera.registration.eyebrow') }}</p>
                <h2 id="herrera-home-registration-title">{{ __('herrera.registration.title') }}</h2>
                <p class="herrera-home-registration-description">{{ __('herrera.registration.description') }}</p>
                <div class="herrera-home-registration-actions">
                    <a class="herrera-home-registration-primary" href="{{ route('front.auth.b2b-register') }}">
                        {{ __('herrera.registration.action') }} <x-fa-icon name="arrow-right" />
                    </a>
                    <a class="herrera-home-registration-login" href="{{ route('front.auth.login') }}">{{ __('herrera.registration.login') }}</a>
                </div>
                <p class="herrera-home-registration-note">{{ __('herrera.registration.note') }}</p>
            </div>
            <ul class="herrera-home-registration-benefits">
                @foreach (['prices' => 'user-check', 'order' => 'list-check', 'history' => 'rotate-right'] as $benefit => $icon)
                    <li>
                        <span class="herrera-home-registration-icon"><x-fa-icon :name="$icon" /></span>
                        <div>
                            <h3>{{ __('herrera.benefit_'.$benefit) }}</h3>
                            <p>{{ __('herrera.registration.benefits.'.$benefit) }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endonce
@endguest
