@php
    $analytics = $storeSettings['analytics'] ?? [];
    $cookies = $storeSettings['cookies'] ?? [];
    $analyticsEnabled = (bool) ($analytics['enabled'] ?? false);
    $ga4Id = trim((string) ($analytics['ga4_measurement_id'] ?? ''));
    $cookieConsentEnabled = (bool) ($cookies['enabled'] ?? true);
    $metaPixelId = '2376960792811713';
@endphp

@if ($analyticsEnabled && $ga4Id !== '')
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4Id }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        window.updateGoogleConsentFromCookie = function (analyticsGranted, marketingGranted) {
            gtag('consent', 'update', {
                analytics_storage: analyticsGranted ? 'granted' : 'denied',
                ad_storage: marketingGranted ? 'granted' : 'denied',
                ad_user_data: marketingGranted ? 'granted' : 'denied',
                ad_personalization: marketingGranted ? 'granted' : 'denied',
            });
        };
        @if ($cookieConsentEnabled)
            gtag('consent', 'default', {
                analytics_storage: 'denied',
                ad_storage: 'denied',
                ad_user_data: 'denied',
                ad_personalization: 'denied',
            });
        @else
            gtag('consent', 'default', {
                analytics_storage: 'granted',
                ad_storage: 'granted',
                ad_user_data: 'granted',
                ad_personalization: 'granted',
            });
            window.cookieAnalyticsAllowed = true;
            window.canTrackAnalytics = () => true;
        @endif
        gtag('config', '{{ $ga4Id }}');
    </script>
@endif

@if ($metaPixelId !== '')
    <script>
        window.cookieMarketingAllowed = window.cookieMarketingAllowed === true;

        @if ($cookieConsentEnabled)
            (function () {
                var match = document.cookie.match('(^|;)\\s*cc_cookie\\s*=\\s*([^;]+)');
                if (!match) {
                    return;
                }

                try {
                    var consent = JSON.parse(decodeURIComponent(match.pop()));
                    window.cookieMarketingAllowed = Array.isArray(consent.categories)
                        && consent.categories.indexOf('marketing') !== -1;
                } catch (error) {
                    window.cookieMarketingAllowed = false;
                }
            })();
        @else
            window.cookieMarketingAllowed = true;
        @endif

        window.loadMetaPixel = window.loadMetaPixel || function () {
            if (window.__metaPixelLoaded === true) {
                return;
            }
            window.__metaPixelLoaded = true;

            !function(f,b,e,v,n,t,s)
            {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};
            if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
            n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t,s)}(window,document,'script',
            'https://connect.facebook.net/en_US/fbevents.js');

            fbq('consent', 'grant');
            fbq('init', @json($metaPixelId));
            fbq('track', 'PageView');
        };

        window.updateMetaPixelConsentFromCookie = function (marketingGranted) {
            window.cookieMarketingAllowed = marketingGranted === true;

            if (!marketingGranted) {
                if (typeof window.fbq === 'function') {
                    window.fbq('consent', 'revoke');
                }

                return;
            }

            window.loadMetaPixel();

            if (typeof window.fbq === 'function') {
                window.fbq('consent', 'grant');
            }
        };

        window.ShopMetaPixel = window.ShopMetaPixel || (function () {
            var standardEventMap = {
                view_item: 'ViewContent',
                add_to_cart: 'AddToCart',
                begin_checkout: 'InitiateCheckout',
                add_payment_info: 'AddPaymentInfo',
                purchase: 'Purchase',
            };

            var toNumber = function (value, fallback) {
                var parsed = Number.parseFloat(String(value == null ? '' : value).replace(',', '.'));
                return Number.isFinite(parsed) ? parsed : fallback;
            };

            var compactPayload = function (payload) {
                Object.keys(payload).forEach(function (key) {
                    var value = payload[key];
                    if (
                        value == null
                        || value === ''
                        || (Array.isArray(value) && value.length === 0)
                        || (typeof value === 'number' && !Number.isFinite(value))
                    ) {
                        delete payload[key];
                    }
                });

                return payload;
            };

            var metaEventNameFromGa4 = function (eventName, payload) {
                if (payload && payload.transaction_id) {
                    return 'Purchase';
                }

                return standardEventMap[eventName] || '';
            };

            var metaPayloadFromGa4 = function (payload) {
                payload = payload || {};

                var items = Array.isArray(payload.items) ? payload.items : [];
                var contents = items.map(function (item) {
                    var id = String(item.item_id || item.id || item.item_name || '').trim();
                    var quantity = Math.max(1, parseInt(item.quantity || 1, 10) || 1);
                    var price = toNumber(item.price || item.item_price, 0);

                    return compactPayload({
                        id: id,
                        quantity: quantity,
                        item_price: price,
                    });
                }).filter(function (item) {
                    return item.id;
                });

                var firstItem = items[0] || {};
                var fallbackValue = contents.reduce(function (total, item) {
                    return total + (toNumber(item.item_price, 0) * (parseInt(item.quantity || 1, 10) || 1));
                }, 0);
                var value = toNumber(payload.value, fallbackValue);

                return compactPayload({
                    content_ids: contents.map(function (item) { return item.id; }),
                    content_name: firstItem.item_name || '',
                    content_category: firstItem.item_category || '',
                    content_type: contents.length > 1 ? 'product_group' : 'product',
                    contents: contents,
                    currency: String(payload.currency || 'EUR').trim() || 'EUR',
                    num_items: contents.reduce(function (total, item) {
                        return total + (parseInt(item.quantity || 1, 10) || 1);
                    }, 0),
                    value: value,
                    order_id: payload.transaction_id || '',
                    payment_type: payload.payment_type || '',
                });
            };

            var track = function (eventName, payload) {
                if (!eventName || window.cookieMarketingAllowed !== true) {
                    return false;
                }

                window.loadMetaPixel();

                if (typeof window.fbq !== 'function') {
                    return false;
                }

                window.fbq('track', eventName, payload || {});

                return true;
            };

            return {
                track: track,
                trackFromGa4: function (eventName, payload) {
                    var metaEventName = metaEventNameFromGa4(eventName, payload || {});

                    if (!metaEventName) {
                        return false;
                    }

                    return track(metaEventName, metaPayloadFromGa4(payload));
                },
            };
        })();

        if (window.cookieMarketingAllowed === true) {
            window.loadMetaPixel();
        }
    </script>
    @unless ($cookieConsentEnabled)
        <noscript>
            <img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id={{ $metaPixelId }}&amp;ev=PageView&amp;noscript=1">
        </noscript>
    @endunless
@endif

@if (($analyticsEnabled && $ga4Id !== '') || $metaPixelId !== '')
    <script defer src="{{ asset('front-theme/scripts/shop-analytics.js') }}?v={{ filemtime(public_path('front-theme/scripts/shop-analytics.js')) }}"></script>
@endif
