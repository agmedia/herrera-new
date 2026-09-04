document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('[data-product-detail], [data-mobile-product-gallery], [data-product-detail-form]');
    if (!root) {
        return;
    }

    const galleryThumbs = Array.from(document.querySelectorAll('[data-gallery-thumb]'));
    const galleryMain = Array.from(document.querySelectorAll('[data-gallery-main]'));
    const gallerySecondary = Array.from(document.querySelectorAll('[data-gallery-secondary]'));
    const galleryOpenButtons = Array.from(document.querySelectorAll('[data-gallery-open]'));
    const productSplide = document.querySelector('[data-product-splide]');
    const galleryThumbnailStrip = document.querySelector('[data-product-gallery-thumbnails]');

    if (productSplide && typeof window.Splide === 'function') {
        const galleryNavigation = Array.from(document.querySelectorAll('[data-gallery-nav]'));
        const gallerySlideCount = productSplide.querySelectorAll('.splide__slide').length;
        const slider = new window.Splide(productSplide, {
            type: 'slide',
            perPage: 1,
            perMove: 1,
            arrows: gallerySlideCount > 1,
            pagination: gallerySlideCount > 1,
            drag: gallerySlideCount > 1,
            speed: 450,
            rewind: gallerySlideCount > 1,
            lazyLoad: 'nearby',
            preloadPages: 0,
        });

        const revealGalleryNavigation = function (button) {
            if (!galleryThumbnailStrip || !button) {
                return;
            }

            const stripRect = galleryThumbnailStrip.getBoundingClientRect();
            const buttonRect = button.getBoundingClientRect();
            const isOutsideView = buttonRect.left < stripRect.left || buttonRect.right > stripRect.right;

            if (!isOutsideView) {
                return;
            }

            const targetLeft = galleryThumbnailStrip.scrollLeft
                + buttonRect.left
                - stripRect.left
                - ((stripRect.width - buttonRect.width) / 2);
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            galleryThumbnailStrip.scrollTo({
                left: Math.max(0, targetLeft),
                behavior: reducedMotion ? 'auto' : 'smooth',
            });
        };

        const syncGalleryNavigation = function (index) {
            galleryNavigation.forEach(function (button, buttonIndex) {
                const isActive = buttonIndex === index;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-current', isActive ? 'true' : 'false');
            });

            revealGalleryNavigation(galleryNavigation[index] || null);
        };

        slider.on('mounted', function () {
            syncGalleryNavigation(0);
        });
        slider.on('move', syncGalleryNavigation);
        slider.mount();

        galleryNavigation.forEach(function (button) {
            button.addEventListener('click', function () {
                const index = Number.parseInt(String(button.dataset.galleryNav || '0'), 10);
                slider.go(Number.isNaN(index) ? 0 : index);
            });
        });
    }

    const normalizeGalleryUrl = function (value) {
        const raw = String(value || '').trim();
        if (!raw) {
            return '';
        }

        if (/^https?:\/\//i.test(raw)) {
            if (window.location.protocol === 'https:' && raw.startsWith('http://')) {
                return 'https://' + raw.slice('http://'.length);
            }
            return raw;
        }

        if (raw.startsWith('//')) {
            return window.location.protocol + raw;
        }

        if (raw.startsWith('/')) {
            return window.location.origin + raw;
        }

        return raw;
    };

    const galleryItemsFromThumbs = galleryThumbs.map(function (button) {
        return {
            full: normalizeGalleryUrl(button.dataset.full || ''),
            alt: String(button.dataset.alt || '').trim(),
        };
    });

    const galleryItemsFromButtons = galleryOpenButtons.map(function (button) {
        const image = button.querySelector('img');
        return {
            full: normalizeGalleryUrl((image && (image.getAttribute('src') || image.dataset.splideLazy)) || ''),
            alt: String((image && image.getAttribute('alt')) || button.getAttribute('aria-label') || '').trim(),
        };
    });

    const galleryItemsRaw = (galleryItemsFromThumbs.some(function (item) { return item.full !== ''; })
        ? galleryItemsFromThumbs
        : galleryItemsFromButtons
    ).filter(function (item) {
        return item.full !== '';
    });
    const seenGallery = new Set();
    const galleryItems = galleryItemsRaw.filter(function (item) {
        if (seenGallery.has(item.full)) {
            return false;
        }
        seenGallery.add(item.full);
        return true;
    });

    const isDesktopPreview = function () {
        return window.matchMedia('(min-width: 769px)').matches;
    };

    const setGalleryPreview = function (index) {
        if (!galleryItems.length || !galleryMain.length || !isDesktopPreview()) {
            return;
        }

        const normalized = ((index % galleryItems.length) + galleryItems.length) % galleryItems.length;
        const next = (normalized + 1) % galleryItems.length;
        const primary = galleryItems[normalized];
        const secondary = galleryItems[next] || primary;

        galleryMain.forEach(function (imageEl) {
            imageEl.src = primary.full;
            imageEl.alt = primary.alt;
        });

        gallerySecondary.forEach(function (imageEl) {
            imageEl.src = secondary.full;
            imageEl.alt = secondary.alt;
        });
    };

    galleryThumbs.forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            if (thumb.hasAttribute('data-gallery-open')) {
                return;
            }

            if (!isDesktopPreview()) {
                return;
            }
            const index = Number.parseInt(String(thumb.dataset.index || '0'), 10);
            setGalleryPreview(Number.isNaN(index) ? 0 : index);
        });
    });

    const LIGHTGALLERY_MIN_SCALE = 1;
    const LIGHTGALLERY_MAX_SCALE = 4;
    const LIGHTGALLERY_SCALE_STEP = 0.25;

    const ensureLightGalleryStyles = function () {};

    const clampLightGalleryScale = function (value) {
        return Math.min(LIGHTGALLERY_MAX_SCALE, Math.max(LIGHTGALLERY_MIN_SCALE, value));
    };

    const getCurrentLightGalleryImage = function (container) {
        if (!container) {
            return null;
        }

        const selectors = [
            '.lg-item.lg-current .lg-object',
            '.lg-item.lg-current .lg-image',
            '.lg-item.lg-current img',
            '.lg-current .lg-object',
            '.lg-current .lg-image',
            '.lg-current img',
        ];

        for (let index = 0; index < selectors.length; index += 1) {
            const image = container.querySelector(selectors[index]);
            if (image) {
                return image;
            }
        }

        return null;
    };

    const getLightGalleryImageScale = function (image) {
        if (!image) {
            return LIGHTGALLERY_MIN_SCALE;
        }

        const raw = Number.parseFloat(String(image.dataset.productLightgalleryScale || LIGHTGALLERY_MIN_SCALE));
        if (!Number.isFinite(raw)) {
            return LIGHTGALLERY_MIN_SCALE;
        }

        return clampLightGalleryScale(raw);
    };

    const applyLightGalleryScale = function (image, scale) {
        if (!image) {
            return;
        }

        const normalized = clampLightGalleryScale(scale);
        image.dataset.productLightgalleryScale = String(normalized);
        image.style.transform = normalized > 1 ? 'scale(' + normalized + ')' : 'scale(1)';
        image.style.cursor = normalized > 1 ? 'zoom-out' : '';
    };

    const syncLightGalleryZoomState = function (container) {
        if (!container) {
            return;
        }

        const currentImage = getCurrentLightGalleryImage(container);
        const imageNodes = Array.from(container.querySelectorAll('.lg-item img, .lg-item .lg-object, .lg-item .lg-image'));

        imageNodes.forEach(function (image) {
            if (image !== currentImage) {
                image.dataset.productLightgalleryScale = String(LIGHTGALLERY_MIN_SCALE);
                image.style.transform = 'scale(1)';
                image.style.cursor = '';
            }
        });

        if (currentImage) {
            applyLightGalleryScale(currentImage, getLightGalleryImageScale(currentImage));
        }

        const zoomInButton = container.querySelector('[data-gallery-zoom-in]');
        const zoomOutButton = container.querySelector('[data-gallery-zoom-out]');
        const currentScale = currentImage ? getLightGalleryImageScale(currentImage) : LIGHTGALLERY_MIN_SCALE;

        if (zoomInButton) {
            zoomInButton.disabled = currentScale >= LIGHTGALLERY_MAX_SCALE;
        }

        if (zoomOutButton) {
            zoomOutButton.disabled = currentScale <= LIGHTGALLERY_MIN_SCALE;
        }
    };

    const adjustCurrentLightGalleryScale = function (container, delta) {
        const currentImage = getCurrentLightGalleryImage(container);
        if (!currentImage) {
            return;
        }

        const currentScale = getLightGalleryImageScale(currentImage);
        applyLightGalleryScale(currentImage, currentScale + delta);
        syncLightGalleryZoomState(container);
    };

    const ensureLightGalleryZoomControls = function (container) {
        if (!container || container.querySelector('[data-product-lightgallery-zoom="1"]')) {
            return;
        }

        const controls = document.createElement('div');
        controls.className = 'product-lightgallery-zoom';
        controls.setAttribute('data-product-lightgallery-zoom', '1');
        controls.innerHTML = [
            '<button type="button" class="product-lightgallery-zoom__btn" data-gallery-zoom-in aria-label="Uvećaj sliku" title="Uvećaj sliku"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="M20 20l-4.2-4.2"></path><path d="M11 8.4v5.2"></path><path d="M8.4 11h5.2"></path></svg></button>',
            '<button type="button" class="product-lightgallery-zoom__btn" data-gallery-zoom-out aria-label="Smanji sliku" title="Smanji sliku"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="M20 20l-4.2-4.2"></path><path d="M8.4 11h5.2"></path></svg></button>',
        ].join('');

        const zoomInButton = controls.querySelector('[data-gallery-zoom-in]');
        const zoomOutButton = controls.querySelector('[data-gallery-zoom-out]');

        zoomInButton.addEventListener('click', function () {
            adjustCurrentLightGalleryScale(container, LIGHTGALLERY_SCALE_STEP);
        });

        zoomOutButton.addEventListener('click', function () {
            adjustCurrentLightGalleryScale(container, -LIGHTGALLERY_SCALE_STEP);
        });

        container.appendChild(controls);
    };

    const attachLightGalleryEnhancements = function () {
        const container = document.querySelector('.lg-container') || document.querySelector('.lg-outer');
        if (!container) {
            return;
        }

        ensureLightGalleryStyles();
        ensureLightGalleryZoomControls(container);
        syncLightGalleryZoomState(container);

        if (container.__productLightgalleryObserverAttached === true) {
            return;
        }

        const observerTarget = container.querySelector('.lg-inner') || container;
        const observer = new MutationObserver(function () {
            syncLightGalleryZoomState(container);
        });

        observer.observe(observerTarget, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['class'],
        });

        container.__productLightgalleryObserverAttached = true;
        container.__productLightgalleryObserver = observer;
    };

    const scheduleLightGalleryEnhancements = function () {
        window.setTimeout(attachLightGalleryEnhancements, 80);
    };

    let galleryBox = null;
    if (galleryItems.length && typeof window.lightGallery === 'function') {
        const previousGalleryRoot = document.querySelector('[data-product-lightgallery="1"]');
        if (previousGalleryRoot) {
            previousGalleryRoot.remove();
        }

        const galleryRoot = document.createElement('div');
        galleryRoot.setAttribute('data-product-lightgallery', '1');
        document.body.appendChild(galleryRoot);

        ensureLightGalleryStyles();

        galleryBox = window.lightGallery(galleryRoot, {
            dynamic: true,
            dynamicEl: galleryItems.map(function (item) {
                return {
                    src: encodeURI(item.full),
                    thumb: encodeURI(item.full),
                    subHtml: item.alt,
                };
            }),
            download: false,
            counter: true,
            closable: true,
            swipeThreshold: 30,
            mobileSettings: {
                controls: true,
                showCloseIcon: true,
                download: false,
            },
        });

        galleryRoot.addEventListener('lgAfterOpen', scheduleLightGalleryEnhancements);
        galleryRoot.addEventListener('lgAfterSlide', scheduleLightGalleryEnhancements);
        galleryRoot.addEventListener('lgBeforeClose', function () {
            const container = document.querySelector('.lg-container') || document.querySelector('.lg-outer');
            if (!container) {
                return;
            }

            const images = container.querySelectorAll('.lg-item img, .lg-item .lg-object, .lg-item .lg-image');
            images.forEach(function (image) {
                image.dataset.productLightgalleryScale = String(LIGHTGALLERY_MIN_SCALE);
                image.style.transform = 'scale(1)';
                image.style.cursor = '';
            });
        });
    }

    const openFallbackImage = function (index) {
        if (!galleryItems.length) {
            return;
        }

        const normalized = ((index % galleryItems.length) + galleryItems.length) % galleryItems.length;
        const target = galleryItems[normalized];
        if (!target || !target.full) {
            return;
        }

        // iOS-safe fallback when lightbox plugin is unavailable or throws.
        window.location.href = target.full;
    };

    galleryOpenButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (!galleryItems.length) {
                return;
            }

            const index = Number.parseInt(String(button.dataset.galleryOpen || '0'), 10);
            const normalized = Number.isNaN(index) ? 0 : index;

            if (!galleryBox) {
                openFallbackImage(normalized);
                return;
            }

            try {
                galleryBox.openGallery(normalized);
                scheduleLightGalleryEnhancements();
            } catch (error) {
                openFallbackImage(normalized);
            }
        });
    });

    const mobileTrack = document.querySelector('[data-mobile-gallery-track]');
    if (mobileTrack) {
        const dots = Array.from(document.querySelectorAll('[data-mobile-gallery-dot]'));
        const updateDots = function () {
            if (!dots.length) {
                return;
            }

            const width = mobileTrack.clientWidth || 1;
            const index = Math.round(mobileTrack.scrollLeft / width);
            dots.forEach(function (dot, dotIndex) {
                if (dotIndex === index) {
                    dot.classList.add('bg-black');
                    dot.classList.remove('bg-slate-300', 'bg-white/70');
                    return;
                }

                dot.classList.remove('bg-black');
                if (!dot.classList.contains('bg-slate-300')) {
                    dot.classList.add('bg-slate-300');
                }
            });
        };

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                const index = Number.parseInt(String(dot.dataset.mobileGalleryDot || '0'), 10);
                const target = (Number.isNaN(index) ? 0 : index) * (mobileTrack.clientWidth || 0);
                mobileTrack.scrollTo({ left: target, behavior: 'smooth' });
            });
        });

        mobileTrack.addEventListener('scroll', updateDots, { passive: true });
        updateDots();
    }

    const qtyControls = document.querySelectorAll('[data-product-detail-form] [data-qty-control]');
    qtyControls.forEach(function (control) {
        const input = control.querySelector('[data-qty-input]');
        const dec = control.querySelector('[data-qty-dec]');
        const inc = control.querySelector('[data-qty-inc]');

        if (!input || !dec || !inc) {
            return;
        }

        const setValue = function (value) {
            const numeric = Number.parseInt(String(value), 10);
            const clamped = Number.isNaN(numeric) ? 1 : Math.min(99, Math.max(1, numeric));
            input.value = String(clamped);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };

        dec.addEventListener('click', function () {
            setValue((Number.parseInt(String(input.value), 10) || 1) - 1);
        });

        inc.addEventListener('click', function () {
            setValue((Number.parseInt(String(input.value), 10) || 1) + 1);
        });
    });

    const forms = document.querySelectorAll('[data-product-detail-form]');
    if (!forms.length) {
        return;
    }

    const floatingCart = document.querySelector('[data-product-floating-cart]');
    if (floatingCart) {
        const floatingForm = document.getElementById(String(floatingCart.dataset.cartFormId || ''));
        const sourceQtyInput = floatingForm ? floatingForm.querySelector('input[name="quantity"]') : null;
        const floatingQtyInput = floatingCart.querySelector('[data-product-floating-qty-input]');
        const floatingQtyDec = floatingCart.querySelector('[data-product-floating-qty-dec]');
        const floatingQtyInc = floatingCart.querySelector('[data-product-floating-qty-inc]');
        const floatingTrigger = document.querySelector('#product-description')
            || document.querySelector('#product-specifications')
            || document.querySelector('[data-product-detail-lower]');

        const normalizedQuantity = function (value) {
            const numeric = Number.parseInt(String(value), 10);
            return Number.isNaN(numeric) ? 1 : Math.min(99, Math.max(1, numeric));
        };

        const syncFloatingQuantity = function () {
            if (!sourceQtyInput || !floatingQtyInput) {
                return;
            }

            floatingQtyInput.value = String(normalizedQuantity(sourceQtyInput.value));
        };

        const setSourceQuantity = function (value) {
            if (!sourceQtyInput) {
                return;
            }

            sourceQtyInput.value = String(normalizedQuantity(value));
            sourceQtyInput.dispatchEvent(new Event('input', { bubbles: true }));
            sourceQtyInput.dispatchEvent(new Event('change', { bubbles: true }));
        };

        if (sourceQtyInput && floatingQtyInput) {
            sourceQtyInput.addEventListener('input', syncFloatingQuantity);
            sourceQtyInput.addEventListener('change', syncFloatingQuantity);
            syncFloatingQuantity();
        }

        if (floatingQtyDec) {
            floatingQtyDec.addEventListener('click', function () {
                setSourceQuantity((Number.parseInt(String(sourceQtyInput ? sourceQtyInput.value : '1'), 10) || 1) - 1);
            });
        }

        if (floatingQtyInc) {
            floatingQtyInc.addEventListener('click', function () {
                setSourceQuantity((Number.parseInt(String(sourceQtyInput ? sourceQtyInput.value : '1'), 10) || 1) + 1);
            });
        }

        let floatingScrollFrame = null;
        const updateFloatingCart = function () {
            floatingScrollFrame = null;

            if (!floatingTrigger || !floatingForm) {
                floatingCart.classList.remove('is-visible');
                floatingCart.setAttribute('aria-hidden', 'true');
                return;
            }

            const triggerRect = floatingTrigger.getBoundingClientRect();
            const formRect = floatingForm.getBoundingClientRect();
            const revealLine = Math.max(120, window.innerHeight * 0.78);
            const sourceControlsVisible = formRect.bottom > 0 && formRect.top < window.innerHeight;
            const shouldShow = triggerRect.bottom <= revealLine && !sourceControlsVisible;

            floatingCart.classList.toggle('is-visible', shouldShow);
            floatingCart.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
        };

        const scheduleFloatingCartUpdate = function () {
            if (floatingScrollFrame !== null) {
                return;
            }

            floatingScrollFrame = window.requestAnimationFrame(updateFloatingCart);
        };

        window.addEventListener('scroll', scheduleFloatingCartUpdate, { passive: true });
        window.addEventListener('resize', scheduleFloatingCartUpdate);
        window.addEventListener('load', scheduleFloatingCartUpdate, { once: true });
        scheduleFloatingCartUpdate();
    }

    const initLinkedOptionSelectors = function (form) {
        const primary = form.querySelector('[data-linked-option-primary]');
        const secondary = form.querySelector('[data-linked-option-secondary]');

        if (!primary || !secondary) {
            return;
        }

        const options = Array.from(secondary.querySelectorAll('option[data-parent-id]'));
        const update = function () {
            const selectedParentId = String(primary.value || '').trim();
            const hasParent = selectedParentId !== '';

            secondary.disabled = !hasParent;
            options.forEach(function (option) {
                const matches = hasParent && String(option.dataset.parentId || '') === selectedParentId;
                option.hidden = !matches;
                option.disabled = !matches;
            });

            const selectedOption = secondary.options[secondary.selectedIndex];
            if (selectedOption && selectedOption.disabled) {
                secondary.value = '';
            }
            secondary.dispatchEvent(new Event('change', { bubbles: true }));
        };

        primary.addEventListener('change', update);
        update();
    };

    const selectedOptionSku = function (form) {
        const radioChecked = form.querySelector('input[name="product_option_value_id"]:checked');
        if (radioChecked) {
            return String(radioChecked.dataset.optionSku || '').trim();
        }

        const select = form.querySelector('select[name="product_option_value_id"]');
        if (!select || select.disabled || !select.value) {
            return '';
        }

        const option = select.options[select.selectedIndex];
        return String(option ? (option.dataset.optionSku || '') : '').trim();
    };

    const syncSkuDisplay = function (form) {
        const optionSku = selectedOptionSku(form);
        const baseSku = String(form.dataset.productBaseSku || '').trim();
        const resolvedSku = optionSku || baseSku || 'n/a';
        const ga4ItemId = resolvedSku !== 'n/a'
            ? resolvedSku
            : (String(form.dataset.productFallbackId || '').trim() || '');

        document.querySelectorAll('[data-product-sku-value]').forEach(function (node) {
            node.textContent = resolvedSku;
        });

        if (ga4ItemId !== '') {
            form.dataset.ga4ItemId = ga4ItemId;
        }
    };

    const defaultPriceData = function (form) {
        return {
            current: String(form.dataset.productDefaultPriceCurrent || '').trim(),
            currentValue: String(form.dataset.productDefaultPriceCurrentValue || '').trim(),
            net: String(form.dataset.productDefaultPriceNet || '').trim(),
            old: String(form.dataset.productDefaultPriceOld || '').trim(),
            discount: String(form.dataset.productDefaultPriceDiscount || '').trim(),
            lowest: String(form.dataset.productDefaultPriceLowest || '').trim(),
            isB2B: String(form.dataset.productDefaultPriceB2b || '').trim(),
        };
    };

    const datasetValue = function (dataset, key, fallback) {
        if (!dataset || !Object.prototype.hasOwnProperty.call(dataset, key)) {
            return fallback;
        }

        return String(dataset[key] || '').trim();
    };

    const priceDataFromOptionNode = function (node, fallback) {
        if (!node) {
            return fallback;
        }

        return {
            current: datasetValue(node.dataset, 'optionPriceCurrent', fallback.current),
            currentValue: datasetValue(node.dataset, 'optionPriceCurrentValue', fallback.currentValue),
            net: datasetValue(node.dataset, 'optionPriceNet', fallback.net),
            old: datasetValue(node.dataset, 'optionPriceOld', fallback.old),
            discount: datasetValue(node.dataset, 'optionPriceDiscount', fallback.discount),
            lowest: datasetValue(node.dataset, 'optionPriceLowest', fallback.lowest),
            isB2B: datasetValue(node.dataset, 'optionPriceB2b', fallback.isB2B),
        };
    };

    const selectedOptionPriceData = function (form) {
        const fallback = defaultPriceData(form);
        const radioChecked = form.querySelector('input[name="product_option_value_id"]:checked');
        if (radioChecked) {
            return priceDataFromOptionNode(radioChecked, fallback);
        }

        const select = form.querySelector('select[name="product_option_value_id"]');
        if (!select || select.disabled || !select.value) {
            return fallback;
        }

        return priceDataFromOptionNode(select.options[select.selectedIndex], fallback);
    };

    const setNodeVisibility = function (node, visible, displayClass) {
        if (!node) {
            return;
        }

        if (displayClass) {
            node.classList.toggle(displayClass, visible);
        }

        node.classList.toggle('hidden', !visible);
        node.classList.toggle('d-none', !visible);
    };

    const syncPriceDisplay = function (form) {
        const priceData = selectedOptionPriceData(form);

        if (priceData.current !== '') {
            document.querySelectorAll('[data-product-price-current]').forEach(function (node) {
                node.textContent = priceData.current;
            });
        }

        if (priceData.net !== '') {
            document.querySelectorAll('[data-product-price-net]').forEach(function (node) {
                node.textContent = priceData.net;
            });
        }

        document.querySelectorAll('[data-product-price-old]').forEach(function (node) {
            const hasOldPrice = priceData.old !== '';
            if (hasOldPrice) {
                node.textContent = priceData.old;
            }
            setNodeVisibility(node, hasOldPrice);
        });

        document.querySelectorAll('[data-product-price-lowest]').forEach(function (node) {
            const hasLowestPrice = priceData.lowest !== '';
            if (hasLowestPrice) {
                node.textContent = priceData.lowest;
            }
            setNodeVisibility(node, hasLowestPrice);
        });

        document.querySelectorAll('[data-product-price-discount]').forEach(function (node) {
            const discount = priceData.discount.replace(/^-/, '').replace(/%$/, '');
            const hasDiscount = discount !== '' && Number.parseFloat(discount) > 0;
            if (hasDiscount) {
                node.textContent = '-' + discount + '%';
            }
            setNodeVisibility(node, hasDiscount, 'inline-flex');
        });

        document.querySelectorAll('[data-product-price-b2b]').forEach(function (node) {
            setNodeVisibility(node, priceData.isB2B === '1');
        });

        if (priceData.currentValue !== '') {
            form.dataset.ga4ItemPrice = priceData.currentValue;
        }
    };

    const cartCountNodes = document.querySelectorAll('[data-cart-count]');

    const setCartCount = function (count) {
        const safeCount = Number.isFinite(count) ? Math.max(0, count) : 0;
        cartCountNodes.forEach(function (node) {
            node.textContent = String(safeCount);
        });
    };

    const selectedOptionLabel = function (form) {
        const linkedPrimary = form.querySelector('[data-linked-option-primary]');
        const linkedSecondary = form.querySelector('[data-linked-option-secondary]');
        if (linkedPrimary && linkedSecondary && String(linkedSecondary.value || '').trim() !== '') {
            const primaryOption = linkedPrimary.options[linkedPrimary.selectedIndex];
            const secondaryOption = linkedSecondary.options[linkedSecondary.selectedIndex];
            const primaryLabelEl = linkedPrimary.closest('div') ? linkedPrimary.closest('div').querySelector('label') : null;
            const secondaryLabelEl = linkedSecondary.closest('div') ? linkedSecondary.closest('div').querySelector('label') : null;
            const primaryLabel = String(primaryLabelEl ? primaryLabelEl.textContent : '').trim();
            const secondaryLabel = String(secondaryLabelEl ? secondaryLabelEl.textContent : '').trim();
            const primaryValue = String(primaryOption ? primaryOption.textContent : '').trim();
            const secondaryValue = String(secondaryOption ? secondaryOption.textContent : '').trim();
            const parts = [];

            if (primaryLabel !== '' && primaryValue !== '') {
                parts.push(primaryLabel + ': ' + primaryValue);
            }
            if (secondaryLabel !== '' && secondaryValue !== '') {
                parts.push(secondaryLabel + ': ' + secondaryValue);
            }

            if (parts.length) {
                return parts.join(' / ');
            }
        }

        const radioChecked = form.querySelector('input[name="product_option_value_id"]:checked');
        if (radioChecked) {
            const label = form.querySelector('label[for="' + radioChecked.id + '"] span');
            return label ? String(label.textContent || '').trim() : '';
        }

        const select = form.querySelector('select[name="product_option_value_id"]');
        if (!select || !select.value) {
            return '';
        }

        const option = select.options[select.selectedIndex];
        return option ? String(option.textContent || '').trim() : '';
    };

    const hasOptions = function (form) {
        return !!(
            form.querySelector('input[name="product_option_value_id"]')
            || form.querySelector('select[name="product_option_value_id"]')
        );
    };

    const optionSelected = function (form) {
        const radioChecked = form.querySelector('input[name="product_option_value_id"]:checked');
        if (radioChecked) {
            return true;
        }

        const select = form.querySelector('select[name="product_option_value_id"]');
        return !!(select && !select.disabled && String(select.value || '').trim() !== '');
    };

    const currentQty = function (form) {
        const input = form.querySelector('input[name="quantity"]');
        const value = Number.parseInt(String(input ? input.value : '1'), 10);
        if (Number.isNaN(value)) {
            return 1;
        }

        return Math.min(99, Math.max(1, value));
    };

    const showModal = function (form) {
        if (window.TermolCartModal) {
            window.TermolCartModal.show(form, selectedOptionLabel(form), currentQty(form));
        }
    };

    forms.forEach(function (form) {
        initLinkedOptionSelectors(form);
        syncSkuDisplay(form);
        syncPriceDisplay(form);

        form.querySelectorAll('input[name="product_option_value_id"], select[name="product_option_value_id"], [data-linked-option-primary]').forEach(function (field) {
            field.addEventListener('change', function () {
                syncSkuDisplay(form);
                syncPriceDisplay(form);
            });
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            const optionError = form.querySelector('[data-option-error]');
            if (hasOptions(form) && !optionSelected(form)) {
                if (optionError) {
                    optionError.textContent = String(form.dataset.optionErrorRequired || optionError.textContent || '');
                    optionError.classList.remove('hidden', 'd-none');
                }
                if (event.submitter && event.submitter.hasAttribute('data-product-floating-submit')) {
                    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    form.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
                }
                return;
            }

            if (optionError) {
                optionError.classList.add('hidden');
            }

            const formData = new FormData(form);

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    body: formData,
                });

                const payload = await response.json().catch(function () { return null; });
                if (!response.ok || !payload.ok) {
                    if (optionError) {
                        const optionErrorMessage = payload && payload.errors && payload.errors.product_option_value_id
                            ? String(payload.errors.product_option_value_id[0] || '')
                            : String((payload && payload.message) || form.dataset.optionErrorUnavailable || optionError.textContent || '');
                        if (optionErrorMessage !== '') {
                            optionError.textContent = optionErrorMessage;
                        }
                        optionError.classList.remove('hidden', 'd-none');
                    }
                    return;
                }

                if (payload.summary && typeof payload.summary.item_qty === 'number') {
                    setCartCount(payload.summary.item_qty);
                }

                document.dispatchEvent(new CustomEvent('cart:updated', {
                    detail: { summary: payload.summary || null },
                }));

                if (window.ShopAnalytics && typeof window.ShopAnalytics.trackAddToCartFromForm === 'function') {
                    window.ShopAnalytics.trackAddToCartFromForm(form, currentQty(form), selectedOptionLabel(form));
                }

                showModal(form);
            } catch (error) {
                // Keep UI stable on network errors.
            }
        });
    });
});
