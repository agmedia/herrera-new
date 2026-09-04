(() => {
    const ready = function (callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }

        callback();
    };

    const bindSwatches = function () {
        document.querySelectorAll('[data-color-variant-swatch]').forEach(function (swatch) {
            const imageUrl = String(swatch.dataset.swatchImage || '').trim();
            const cssText = String(swatch.dataset.swatchStyle || '').trim();

            if (imageUrl !== '') {
                swatch.style.backgroundImage = 'url("' + imageUrl.replace(/"/g, '\\"') + '")';
                return;
            }

            if (cssText !== '') {
                swatch.style.cssText += ';' + cssText;
            }
        });
    };

    const bindDetailNavigation = function () {
        const tabs = document.querySelector('[data-product-detail-tabs]');
        if (!tabs || tabs.dataset.productDetailTabsInit === '1') {
            return;
        }

        const lower = tabs.closest('[data-product-detail-lower]');
        const header = document.querySelector('.site-main-header');
        const links = Array.from(tabs.querySelectorAll('[data-product-detail-tab]'));
        const items = links.map(function (link) {
            const hash = String(link.getAttribute('href') || '');
            const targetId = hash.startsWith('#') ? hash.slice(1) : '';
            const target = targetId ? document.getElementById(targetId) : null;

            return target ? { hash: hash, id: targetId, link: link, target: target } : null;
        }).filter(Boolean);

        if (!lower || items.length === 0) {
            return;
        }

        tabs.dataset.productDetailTabsInit = '1';
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let activeId = '';
        let frameRequested = false;

        const stickyHeaderOffset = function () {
            if (!header) {
                return 0;
            }

            const position = window.getComputedStyle(header).position;
            if (position !== 'fixed' && position !== 'sticky') {
                return 0;
            }

            const rect = header.getBoundingClientRect();
            if (rect.bottom <= 0 || rect.top > 1) {
                return 0;
            }

            return Math.max(0, Math.ceil(rect.bottom));
        };

        const syncOffsets = function () {
            const headerOffset = stickyHeaderOffset();
            const tabsHeight = Math.ceil(tabs.getBoundingClientRect().height);
            tabs.style.setProperty('--product-detail-tabs-sticky-top', headerOffset + 'px');
            lower.style.setProperty('--product-detail-scroll-offset', (headerOffset + tabsHeight + 12) + 'px');

            return {
                header: headerOffset,
                tabs: tabsHeight,
            };
        };

        const revealActiveTab = function (link) {
            if (tabs.scrollWidth <= tabs.clientWidth) {
                return;
            }

            const left = link.offsetLeft - ((tabs.clientWidth - link.offsetWidth) / 2);
            tabs.scrollTo({
                left: Math.max(0, left),
                behavior: reducedMotion ? 'auto' : 'smooth',
            });
        };

        const setActive = function (id) {
            if (!id || id === activeId) {
                return;
            }

            activeId = id;
            items.forEach(function (item) {
                const isActive = item.id === id;
                item.link.classList.toggle('is-active', isActive);
                if (isActive) {
                    item.link.setAttribute('aria-current', 'true');
                    revealActiveTab(item.link);
                } else {
                    item.link.removeAttribute('aria-current');
                }
            });
        };

        const updateNavigation = function () {
            const offsets = syncOffsets();
            const activationLine = offsets.header + offsets.tabs + 24;
            let activeItem = items[0];

            items.forEach(function (item) {
                if (item.target.getBoundingClientRect().top <= activationLine) {
                    activeItem = item;
                }
            });

            setActive(activeItem.id);
            frameRequested = false;
        };

        const scheduleNavigationUpdate = function () {
            if (frameRequested) {
                return;
            }

            frameRequested = true;
            window.requestAnimationFrame(updateNavigation);
        };

        const scrollToItem = function (item, updateHistory) {
            setActive(item.id);

            if (updateHistory && window.location.hash !== item.hash) {
                window.history.pushState(null, '', item.hash);
            }

            const performScroll = function () {
                const offsets = syncOffsets();
                const targetTop = window.scrollY
                    + item.target.getBoundingClientRect().top
                    - offsets.header
                    - offsets.tabs;

                window.scrollTo({
                    top: Math.max(0, targetTop),
                    behavior: reducedMotion ? 'auto' : 'smooth',
                });
            };

            const targetDocumentTop = window.scrollY + item.target.getBoundingClientRect().top;
            if (
                header
                && !header.classList.contains('is-sticky')
                && targetDocumentTop > header.getBoundingClientRect().height
            ) {
                header.classList.add('is-sticky');
                window.requestAnimationFrame(performScroll);
                return;
            }

            performScroll();
        };

        items.forEach(function (item) {
            item.link.addEventListener('click', function (event) {
                if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }

                event.preventDefault();
                scrollToItem(item, true);
            });
        });

        window.addEventListener('scroll', scheduleNavigationUpdate, { passive: true });
        window.addEventListener('resize', scheduleNavigationUpdate, { passive: true });
        window.addEventListener('hashchange', function () {
            const item = items.find(function (candidate) {
                return candidate.hash === window.location.hash;
            });
            if (item) {
                window.setTimeout(function () {
                    scrollToItem(item, false);
                }, 0);
            }
        });

        if (header && typeof window.MutationObserver === 'function') {
            const headerObserver = new window.MutationObserver(scheduleNavigationUpdate);
            headerObserver.observe(header, {
                attributes: true,
                attributeFilter: ['class'],
            });
        }

        if (typeof window.ResizeObserver === 'function') {
            const resizeObserver = new window.ResizeObserver(scheduleNavigationUpdate);
            resizeObserver.observe(tabs);
            if (header) {
                resizeObserver.observe(header);
            }
        }

        const initialItem = items.find(function (item) {
            return item.hash === window.location.hash;
        });
        if (initialItem) {
            window.setTimeout(function () {
                scrollToItem(initialItem, false);
            }, 60);
        } else {
            scheduleNavigationUpdate();
        }
    };

    const bindComments = function () {
        const toggle = document.querySelector('[data-comment-form-toggle]');
        const panel = document.querySelector('[data-comment-form-panel]');
        if (!toggle || !panel) {
            return;
        }

        toggle.addEventListener('click', function () {
            const isClosed = panel.classList.contains('hidden') || panel.classList.contains('d-none');
            panel.classList.toggle('hidden', !isClosed);
            panel.classList.toggle('d-none', !isClosed);
            const isOpen = isClosed;
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    };

    const bindSizeGuide = function () {
        const modal = document.querySelector('[data-size-guide-modal]');
        if (!modal) {
            return;
        }

        const openButtons = document.querySelectorAll('[data-size-guide-open]');
        const closeButtons = modal.querySelectorAll('[data-size-guide-close]');

        const openModal = function () {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('overflow-hidden');
        };

        const closeModal = function () {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
        };

        openButtons.forEach(function (button) {
            button.addEventListener('click', openModal);
        });

        closeButtons.forEach(function (button) {
            button.addEventListener('click', closeModal);
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') {
                closeModal();
            }
        });
    };

    const initProductCarousels = function () {
        if (typeof window.Splide !== 'function') {
            return false;
        }

        document.querySelectorAll('[data-related-products-splide]').forEach(function (element) {
            if (element.dataset.splideReady === '1') {
                return;
            }

            const count = element.querySelectorAll('.splide__slide').length;
            if (count === 0) {
                return;
            }

            element.dataset.splideReady = '1';
            const desktopPerPage = Math.max(
                1,
                Number.parseInt(element.dataset.desktopCols || '5', 10) || 5
            );
            const mobilePerPage = Math.max(
                1,
                Number.parseInt(element.dataset.mobileCols || '2', 10) || 2
            );

            new window.Splide(element, {
                type: count > desktopPerPage ? 'loop' : 'slide',
                perPage: desktopPerPage,
                perMove: 1,
                gap: '0rem',
                drag: count > 1,
                snap: true,
                pagination: false,
                arrows: count > desktopPerPage,
                updateOnMove: true,
                speed: 520,
                breakpoints: {
                    1280: {
                        perPage: 4,
                        arrows: count > 4,
                    },
                    1024: {
                        perPage: 3,
                        arrows: count > 3,
                    },
                    860: {
                        perPage: 2,
                        arrows: count > 2,
                    },
                    640: {
                        perPage: mobilePerPage,
                        arrows: false,
                        pagination: count > mobilePerPage,
                    },
                },
            }).mount();
        });

        return true;
    };

    ready(function () {
        bindSwatches();
        bindDetailNavigation();
        bindComments();
        bindSizeGuide();
        initProductCarousels();
    });
})();
