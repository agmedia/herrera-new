@if ((bool) ($storeSettings['cookies']['enabled'] ?? true))
    @php
        $cookieTitle = trim((string) ($storeSettings['cookies']['title'] ?? 'Koristimo kolačiće'));
        $cookieMessage = trim((string) ($storeSettings['cookies']['message'] ?? 'Koristimo kolačiće za ispravan rad sajta i bolje korisničko iskustvo.'));
        $cookieAcceptLabel = trim((string) ($storeSettings['cookies']['accept_label'] ?? 'U redu'));
        $cookiePolicyLabel = trim((string) ($storeSettings['cookies']['policy_label'] ?? 'Politika kolačića'));
        $cookiePolicyUrl = trim((string) ($storeSettings['cookies']['policy_url'] ?? ''));
        $cookiePreferencesTitle = trim((string) ($storeSettings['cookies']['preferences_title'] ?? 'Postavke kolačića'));
        $cookiePreferencesAcceptAll = trim((string) ($storeSettings['cookies']['preferences_accept_all_label'] ?? 'Prihvati sve'));
        $cookiePreferencesAcceptNecessary = trim((string) ($storeSettings['cookies']['preferences_accept_necessary_label'] ?? 'Samo nužni'));
        $cookiePreferencesSave = trim((string) ($storeSettings['cookies']['preferences_save_label'] ?? 'Spremi odabir'));
        $cookieNecessaryTitle = trim((string) ($storeSettings['cookies']['necessary_title'] ?? 'Nužni kolačići'));
        $cookieNecessaryDescription = trim((string) ($storeSettings['cookies']['necessary_description'] ?? 'Neki kolačići na ovoj internetskoj stranici neophodni su za pravilno funkcioniranje stranice stoga ih nije moguće onemogućiti.'));
        $cookieAnalyticsTitle = trim((string) ($storeSettings['cookies']['analytics_title'] ?? 'Analitički kolačići'));
        $cookieAnalyticsDescription = trim((string) ($storeSettings['cookies']['analytics_description'] ?? 'Analitički kolačići nam pomažu kako bismo poboljšali našu internetsku stranicu sakupljajući i analizirajući podatke o njenoj posjećenosti.'));
        $cookieMarketingTitle = trim((string) ($storeSettings['cookies']['marketing_title'] ?? 'Marketinški kolačići'));
        $cookieMarketingDescription = trim((string) ($storeSettings['cookies']['marketing_description'] ?? 'Marketinški kolačići služe za praćenje posjetitelja u korištenju internet stranice u svrhu omogućavanja prikazivanja relevantnih oglasa oglašivača trećih strana.'));
        $cookieLocale = app()->getLocale();
        $showCookieFloatingButton = (bool) ($showCookieFloatingButton ?? true);
        $cookieDescription = $cookieMessage;
        if ($cookiePolicyUrl !== '') {
            $cookieDescription .= ' <a href="'.e($cookiePolicyUrl).'">'.e($cookiePolicyLabel).'</a>';
        }
    @endphp
    @if ($showCookieFloatingButton)
        <button
            type="button"
            id="cookie-consent-floating-button"
            class="cookie-consent-floating-button"
            aria-label="Cookie postavke"
        >
            <x-fa-icon name="cookie-bite" class="h-6 w-6" />
        </button>
    @endif

    <script>
        const syncGoogleConsent = () => {
            if (!window.CookieConsent) {
                return;
            }

            const analyticsGranted = window.CookieConsent.acceptedCategory('analytics');
            const marketingGranted = window.CookieConsent.acceptedCategory('marketing');

            window.cookieAnalyticsAllowed = analyticsGranted;
            window.cookieMarketingAllowed = marketingGranted;
            window.canTrackAnalytics = () => window.cookieAnalyticsAllowed === true;

            if (typeof window.updateGoogleConsentFromCookie === 'function') {
                window.updateGoogleConsentFromCookie(analyticsGranted, marketingGranted);
            }

            if (typeof window.updateMetaPixelConsentFromCookie === 'function') {
                window.updateMetaPixelConsentFromCookie(marketingGranted);
            }
        };

        const cookieConsentConfig = {
            disablePageInteraction: true,
            guiOptions: {
                consentModal: {
                    layout: 'box',
                    position: 'middle center',
                    equalWeightButtons: true,
                    flipButtons: false
                },
                preferencesModal: {
                    layout: 'box',
                    position: 'middle center'
                }
            },
            categories: {
                necessary: {
                    enabled: true,
                    readOnly: true
                },
                analytics: {
                    enabled: false,
                    readOnly: false
                },
                marketing: {
                    enabled: false,
                    readOnly: false
                }
            },
            onFirstConsent: () => syncGoogleConsent(),
            onConsent: () => syncGoogleConsent(),
            onChange: () => syncGoogleConsent(),
            language: {
                default: @json($cookieLocale),
                translations: {
                    @json($cookieLocale): {
                        consentModal: {
                            title: @json($cookieTitle),
                            description: @json($cookieDescription),
                            acceptAllBtn: @json($cookieAcceptLabel),
                            acceptNecessaryBtn: @json($cookiePreferencesAcceptNecessary),
                            showPreferencesBtn: 'Postavke'
                        },
                        preferencesModal: {
                            title: @json($cookiePreferencesTitle),
                            acceptAllBtn: @json($cookiePreferencesAcceptAll),
                            acceptNecessaryBtn: @json($cookiePreferencesAcceptNecessary),
                            savePreferencesBtn: @json($cookiePreferencesSave),
                            sections: [
                                {
                                    title: @json($cookieNecessaryTitle),
                                    description: @json($cookieNecessaryDescription),
                                    linkedCategory: 'necessary'
                                },
                                {
                                    title: @json($cookieAnalyticsTitle),
                                    description: @json($cookieAnalyticsDescription),
                                    linkedCategory: 'analytics'
                                },
                                {
                                    title: @json($cookieMarketingTitle),
                                    description: @json($cookieMarketingDescription),
                                    linkedCategory: 'marketing'
                                }
                            ]
                        }
                    }
                }
            }
        };

        const ensureCookieConsentAssets = (() => {
            let loadingPromise = null;

            return () => {
                if (loadingPromise) {
                    return loadingPromise;
                }

                loadingPromise = new Promise((resolve, reject) => {
                    const localCssHref = @json(asset('front-theme/vendors/cookieconsent/cookieconsent.css'));
                    const cdnCssHref = 'https://cdn.jsdelivr.net/npm/vanilla-cookieconsent@3/dist/cookieconsent.css';
                    const localScriptSrc = @json(asset('front-theme/vendors/cookieconsent/cookieconsent.umd.js'));
                    const cdnScriptSrc = 'https://cdn.jsdelivr.net/npm/vanilla-cookieconsent@3/dist/cookieconsent.umd.js';

                    if (!document.querySelector('link[data-cookie-consent-css="1"]')) {
                        const css = document.createElement('link');
                        css.rel = 'stylesheet';
                        css.href = localCssHref;
                        css.setAttribute('data-cookie-consent-css', '1');
                        css.onerror = () => {
                            css.onerror = null;
                            css.href = cdnCssHref;
                        };
                        document.head.appendChild(css);
                    }

                    if (typeof window.CookieConsent?.run === 'function') {
                        resolve();
                        return;
                    }

                    const script = document.createElement('script');
                    script.src = localScriptSrc;
                    script.async = true;
                    script.onload = () => resolve();
                    script.onerror = () => {
                        script.onerror = () => reject(new Error('Failed to load cookie consent script.'));
                        script.src = cdnScriptSrc;
                    };
                    document.head.appendChild(script);
                });

                return loadingPromise;
            };
        })();

        const runCookieConsent = () => {
            if (typeof window.CookieConsent?.run !== 'function') {
                return;
            }

            if (window.__cookieConsentInitialized === true) {
                return;
            }
            window.__cookieConsentInitialized = true;

            window.CookieConsent.run(cookieConsentConfig);
            syncGoogleConsent();
            if (!window.CookieConsent.validConsent()) {
                window.CookieConsent.show();
            }
        };

        const bootCookieConsent = () => {
            ensureCookieConsentAssets()
                .then(runCookieConsent)
                .catch(() => {
                    window.__cookieConsentInitialized = false;
                });
        };

        const cookieFloatingButton = document.getElementById('cookie-consent-floating-button');
        if (cookieFloatingButton) {
            cookieFloatingButton.addEventListener('click', () => {
                ensureCookieConsentAssets().then(() => {
                    runCookieConsent();
                    if (typeof window.CookieConsent?.showPreferences === 'function') {
                        window.CookieConsent.showPreferences();
                    }
                });
            });
        }

        const hasStoredCookieConsent = () => document.cookie.split(';').some((entry) => entry.trim().startsWith('cc_cookie='));

        const scheduleCookieConsentBoot = () => {
            if (hasStoredCookieConsent()) {
                bootCookieConsent();
                return;
            }

            let booted = false;
            const runBootOnce = () => {
                if (booted) {
                    return;
                }
                booted = true;
                bootCookieConsent();
            };

            const interactionEvents = ['pointerdown', 'keydown', 'touchstart', 'scroll'];
            interactionEvents.forEach((eventName) => {
                window.addEventListener(eventName, runBootOnce, { once: true, passive: true });
            });

            window.setTimeout(runBootOnce, 6000);
        };

        if (document.readyState === 'complete') {
            scheduleCookieConsentBoot();
        } else {
            window.addEventListener('load', scheduleCookieConsentBoot, { once: true });
        }
    </script>
@endif
