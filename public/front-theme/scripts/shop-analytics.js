(function () {
    'use strict';

    var storagePrefix = 'shop-ga4-once:';

    var toNumber = function (value, fallback) {
        var parsed = Number.parseFloat(String(value == null ? '' : value).replace(',', '.'));
        return Number.isFinite(parsed) ? parsed : (fallback || 0);
    };

    var toInt = function (value, fallback) {
        var parsed = Number.parseInt(String(value == null ? '' : value), 10);
        return Number.isFinite(parsed) ? parsed : (fallback || 0);
    };

    var hasGtag = function () {
        return typeof window.gtag === 'function';
    };

    var canTrackGa4 = function () {
        return typeof window.canTrackAnalytics !== 'function' || window.canTrackAnalytics() === true;
    };

    var trackGa4 = function (eventName, payload) {
        if (!hasGtag() || !canTrackGa4() || !eventName) {
            return false;
        }

        window.gtag('event', eventName, payload || {});

        return true;
    };

    var trackMeta = function (eventName, payload) {
        if (!window.ShopMetaPixel || typeof window.ShopMetaPixel.trackFromGa4 !== 'function') {
            return false;
        }

        return window.ShopMetaPixel.trackFromGa4(eventName, payload || {});
    };

    var track = function (eventName, payload) {
        if (!eventName) {
            return false;
        }

        var ga4Tracked = trackGa4(eventName, payload);
        var metaTracked = trackMeta(eventName, payload);

        return ga4Tracked || metaTracked;
    };

    var trackWithStorage = function (storageKey, eventName, payload, tracker) {
        if (window.sessionStorage.getItem(storageKey) === '1') {
            return false;
        }

        if (tracker(eventName, payload)) {
            window.sessionStorage.setItem(storageKey, '1');
            return true;
        }

        return false;
    };

    var trackOnce = function (key, eventName, payload) {
        if (!key) {
            track(eventName, payload);
            return;
        }

        try {
            trackWithStorage(storagePrefix + 'ga4:' + key, eventName, payload, trackGa4);
            trackWithStorage(storagePrefix + 'meta:' + key, eventName, payload, trackMeta);
        } catch (error) {
            track(eventName, payload);
        }
    };

    var trackMetaOnce = function (key, eventName, payload) {
        if (!key) {
            trackMeta(eventName, payload);
            return;
        }

        try {
            trackWithStorage(storagePrefix + 'meta:' + key, eventName, payload, trackMeta);
        } catch (error) {
            trackMeta(eventName, payload);
        }
    };

    var parseJson = function (raw, fallback) {
        if (!raw) {
            return fallback;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return fallback;
        }
    };

    var normalizedItemFromDataset = function (node, quantity, variant) {
        if (!node || !node.dataset) {
            return null;
        }

        var itemId = String(node.dataset.ga4ItemId || '').trim();
        var itemName = String(node.dataset.ga4ItemName || '').trim();
        if (!itemId && !itemName) {
            return null;
        }

        var item = {
            item_id: itemId || itemName,
            item_name: itemName || itemId,
            price: toNumber(node.dataset.ga4ItemPrice, 0),
            quantity: Math.max(1, toInt(quantity, 1)),
        };

        var brand = String(node.dataset.ga4ItemBrand || '').trim();
        if (brand !== '') {
            item.item_brand = brand;
        }

        var category = String(node.dataset.ga4ItemCategory || '').trim();
        if (category !== '') {
            item.item_category = category;
        }

        var variantText = String(variant || '').trim();
        if (variantText !== '') {
            item.item_variant = variantText;
        }

        return item;
    };

    var trackAddToCartFromForm = function (formNode, quantity, variant) {
        var item = normalizedItemFromDataset(formNode, quantity, variant);
        if (!item) {
            return;
        }

        var currency = String(formNode.dataset.ga4Currency || 'EUR').trim() || 'EUR';
        track('add_to_cart', {
            currency: currency,
            value: Number((item.price * item.quantity).toFixed(2)),
            items: [item],
        });
    };

    var bindAddToCartSubmit = function () {
        var forms = document.querySelectorAll('[data-ga4-add-to-cart-form]');
        forms.forEach(function (formNode) {
            if (formNode.dataset.ga4SubmitBound === '1') {
                return;
            }

            // AJAX forms are tracked after successful response.
            if (formNode.hasAttribute('data-product-card-form') || formNode.hasAttribute('data-product-detail-form')) {
                return;
            }

            formNode.dataset.ga4SubmitBound = '1';
            formNode.addEventListener('submit', function () {
                var qtyInput = formNode.querySelector('input[name=\"quantity\"]');
                var quantity = toInt(qtyInput ? qtyInput.value : 1, 1);
                var variant = '';
                var selectedOption = formNode.querySelector('input[name=\"product_option_value_id\"]:checked');
                if (selectedOption && selectedOption.id) {
                    var optionLabel = formNode.querySelector('label[for=\"' + selectedOption.id + '\"]');
                    variant = optionLabel ? String(optionLabel.textContent || '').trim() : '';
                } else {
                    var selectOption = formNode.querySelector('select[name=\"product_option_value_id\"]');
                    if (selectOption && selectOption.value) {
                        var selected = selectOption.options[selectOption.selectedIndex];
                        variant = String(selected ? selected.textContent : '').trim();
                    }
                }

                trackAddToCartFromForm(formNode, quantity, variant);
            });
        });
    };

    var bindRemoveFromCart = function () {
        var forms = document.querySelectorAll('[data-ga4-remove-from-cart-form]');
        forms.forEach(function (formNode) {
            if (formNode.dataset.ga4Bound === '1') {
                return;
            }

            formNode.dataset.ga4Bound = '1';
            formNode.addEventListener('submit', function () {
                var quantity = toInt(formNode.dataset.ga4Quantity, 1);
                var item = normalizedItemFromDataset(formNode, quantity);
                if (!item) {
                    return;
                }

                var currency = String(formNode.dataset.ga4Currency || 'EUR').trim() || 'EUR';
                track('remove_from_cart', {
                    currency: currency,
                    value: Number((item.price * item.quantity).toFixed(2)),
                    items: [item],
                });
            });
        });
    };

    var bindCheckout = function () {
        var formNode = document.querySelector('[data-ga4-checkout-form]');
        if (!formNode || formNode.dataset.ga4CheckoutBound === '1') {
            return;
        }

        formNode.dataset.ga4CheckoutBound = '1';

        var currency = String(formNode.dataset.ga4Currency || 'EUR').trim() || 'EUR';
        var value = toNumber(formNode.dataset.ga4Value, 0);
        var items = parseJson(formNode.dataset.ga4Items, []);

        trackOnce('begin-checkout:' + window.location.pathname, 'begin_checkout', {
            currency: currency,
            value: value,
            items: Array.isArray(items) ? items : [],
        });

        var shippingInputs = formNode.querySelectorAll('input[name="shipping_method_code"]');
        var paymentInputs = formNode.querySelectorAll('input[name="payment_method_code"]');

        var trackShippingSelection = function () {
            var selected = formNode.querySelector('input[name="shipping_method_code"]:checked');
            if (!selected) {
                return;
            }

            var tier = String(selected.value || '').trim();
            if (tier === '') {
                return;
            }

            trackOnce('add-shipping-info:' + window.location.pathname + ':' + tier, 'add_shipping_info', {
                currency: currency,
                value: value,
                shipping_tier: tier,
                items: Array.isArray(items) ? items : [],
            });
        };

        var trackPaymentSelection = function () {
            var selected = formNode.querySelector('input[name="payment_method_code"]:checked');
            if (!selected) {
                return;
            }

            var paymentType = String(selected.value || '').trim();
            if (paymentType === '') {
                return;
            }

            trackOnce('add-payment-info:' + window.location.pathname + ':' + paymentType, 'add_payment_info', {
                currency: currency,
                value: value,
                payment_type: paymentType,
                items: Array.isArray(items) ? items : [],
            });
        };

        shippingInputs.forEach(function (input) {
            input.addEventListener('change', trackShippingSelection);
        });

        paymentInputs.forEach(function (input) {
            input.addEventListener('change', trackPaymentSelection);
        });

        formNode.addEventListener('submit', function () {
            trackShippingSelection();
            trackPaymentSelection();
        });
    };

    window.ShopAnalytics = {
        track: track,
        trackOnce: trackOnce,
        trackMetaOnce: trackMetaOnce,
        trackAddToCartFromForm: trackAddToCartFromForm,
    };

    document.addEventListener('DOMContentLoaded', function () {
        bindAddToCartSubmit();
        bindRemoveFromCart();
        bindCheckout();
    });
})();
