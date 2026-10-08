(() => {
    const initStickyHeader = () => {
        const header = document.querySelector('.site-main-header');
        if (!(header instanceof HTMLElement) || header.dataset.stickyHeaderInit === '1') {
            return;
        }

        const spacer = document.querySelector('[data-site-main-header-spacer]');
        const preservesHeaderFlow = spacer instanceof HTMLElement;
        const nativeStickyViewport = document.body.classList.contains('herrera-storefront')
            ? window.matchMedia('(max-width: 1023px)')
            : null;
        const usesNativeStickyHeader = () => nativeStickyViewport?.matches === true;
        let expandedHeaderHeight = 24;
        let sticky = header.classList.contains('is-sticky');

        const syncExpandedHeaderHeight = (remeasureSticky = false) => {
            if (!preservesHeaderFlow || (sticky && !remeasureSticky)) {
                return;
            }

            // Measure the expanded layout even after resizing a compact header.
            // Both class changes happen before the next paint.
            if (sticky) {
                header.classList.remove('is-sticky');
            }
            expandedHeaderHeight = Math.ceil(header.getBoundingClientRect().height);
            if (sticky) {
                header.classList.add('is-sticky');
            }
            spacer.style.setProperty('--site-main-header-expanded-height', `${expandedHeaderHeight}px`);
        };

        const updateHeaderState = (remeasureSticky = false) => {
            // Herrera's mobile header keeps its full height with CSS sticky.
            // Avoid relayout when iOS scrolls or resizes its browser controls.
            if (usesNativeStickyHeader()) {
                if (sticky) {
                    sticky = false;
                    header.classList.remove('is-sticky');
                }
                return;
            }

            syncExpandedHeaderHeight(remeasureSticky);
            const stickAt = preservesHeaderFlow ? expandedHeaderHeight : 24;
            const releaseAt = preservesHeaderFlow ? Math.max(24, expandedHeaderHeight - 16) : 24;
            const shouldStick = sticky
                ? window.scrollY > releaseAt
                : window.scrollY > stickAt;

            if (shouldStick === sticky) {
                return;
            }

            sticky = shouldStick;
            header.classList.toggle('is-sticky', sticky);
        };

        header.dataset.stickyHeaderInit = '1';
        updateHeaderState(true);
        let frameRequested = false;
        let remeasureRequested = false;

        const scheduleUpdate = (remeasureSticky = false) => {
            if (usesNativeStickyHeader() && !sticky) {
                return;
            }

            remeasureRequested = remeasureRequested || remeasureSticky;
            if (frameRequested) {
                return;
            }

            frameRequested = true;
            window.requestAnimationFrame(() => {
                const remeasure = remeasureRequested;
                frameRequested = false;
                remeasureRequested = false;
                updateHeaderState(remeasure);
            });
        };

        window.addEventListener('scroll', () => scheduleUpdate(), { passive: true });
        window.addEventListener('resize', () => scheduleUpdate(true), { passive: true });
        window.addEventListener('pageshow', () => scheduleUpdate(true));
        window.addEventListener('load', () => scheduleUpdate(true), { once: true });
        document.fonts?.ready.then(() => scheduleUpdate(true));
        if (typeof nativeStickyViewport?.addEventListener === 'function') {
            nativeStickyViewport.addEventListener('change', () => scheduleUpdate(true));
        } else {
            nativeStickyViewport?.addListener(() => scheduleUpdate(true));
        }
    };

    const mountDeferredCatalogMegaMenus = () => {
        document.querySelectorAll('template[data-catalog-mega-template]').forEach((template) => {
            if (!(template instanceof HTMLTemplateElement)) {
                return;
            }

            const trigger = Array.from(document.querySelectorAll('[data-catalog-mega-trigger]'))
                .find((element) => element.getAttribute('aria-controls') === template.dataset.catalogMegaTemplate);
            const navGroup = trigger?.closest('.group\\/nav');
            if (!(navGroup instanceof HTMLElement)) {
                return;
            }

            // Keep the large hidden tree after visible content in the response,
            // then restore its group for the existing hover and keyboard behavior.
            navGroup.append(template.content);
            template.remove();
        });
    };

    const initCatalogMegaMenus = () => {
        document.querySelectorAll('[data-catalog-mega]').forEach((megaMenu) => {
            if (!(megaMenu instanceof HTMLElement) || megaMenu.dataset.catalogMegaInit === '1') {
                return;
            }

            const treeSource = megaMenu.querySelector('[data-catalog-mega-tree]');
            const columns = Array.from(megaMenu.querySelectorAll('[data-catalog-mega-column]'));
            const navGroup = megaMenu.closest('.group\\/nav');
            const trigger = navGroup?.querySelector(':scope > [data-catalog-mega-trigger]');
            const rootLabel = megaMenu.dataset.catalogMegaLabel || 'Proizvodi';
            const rootUrl = megaMenu.dataset.catalogMegaUrl || '#';
            const rootTitle = megaMenu.dataset.catalogMegaRootTitle || 'Kategorije';
            const maxColumns = Math.max(1, Math.min(5, Number.parseInt(megaMenu.dataset.catalogMegaMaxColumns || '1', 10) || 1));
            let tree = [];

            try {
                tree = JSON.parse(treeSource?.textContent || '[]');
            } catch {
                tree = [];
            }

            if (!Array.isArray(tree) || tree.length === 0 || columns.length === 0) {
                return;
            }

            megaMenu.dataset.catalogMegaInit = '1';

            const nodesByDepth = [];
            const activeIndexByDepth = [];
            const hasChildren = (node) => Array.isArray(node?.children) && node.children.length > 0;

            const setExpanded = (expanded) => {
                if (trigger instanceof HTMLElement) {
                    trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                }
            };

            const focusItem = (depth, index) => {
                const target = columns[depth]?.querySelector(`[data-catalog-mega-item-index="${index}"]`);
                if (target instanceof HTMLElement) {
                    target.focus();
                }
            };

            const hideColumnsAfter = (depth) => {
                columns.forEach((column, columnIndex) => {
                    if (columnIndex <= depth) {
                        return;
                    }

                    column.hidden = true;
                    column.querySelector('[data-catalog-mega-list]')?.replaceChildren();
                });
                nodesByDepth.splice(depth + 1);
                activeIndexByDepth.splice(depth + 1);
            };

            const syncActiveItems = (depth) => {
                const column = columns[depth];
                if (!column) {
                    return;
                }

                column.querySelectorAll('[data-catalog-mega-item]').forEach((item, itemIndex) => {
                    const isActive = itemIndex === activeIndexByDepth[depth];
                    item.classList.toggle('is-active', isActive);
                    if (item.hasAttribute('aria-haspopup')) {
                        item.setAttribute('aria-expanded', isActive ? 'true' : 'false');
                    }
                });
            };

            const activateItem = (depth, index) => {
                const nodes = nodesByDepth[depth] || [];
                const selectedNode = nodes[index];
                if (!selectedNode || activeIndexByDepth[depth] === index) {
                    return;
                }

                activeIndexByDepth[depth] = index;
                activeIndexByDepth.splice(depth + 1);
                syncActiveItems(depth);
                hideColumnsAfter(depth);

                const nestedNodes = hasChildren(selectedNode) ? selectedNode.children : [];
                const nextDepth = depth + 1;

                if (Array.isArray(nestedNodes) && nestedNodes.length > 0 && nextDepth < maxColumns) {
                    renderColumn(nextDepth, nestedNodes, selectedNode);
                    activeIndexByDepth[nextDepth] = -1;
                    syncActiveItems(nextDepth);
                }
            };

            const resetCascade = () => {
                activeIndexByDepth[0] = -1;
                activeIndexByDepth.splice(1);
                syncActiveItems(0);
                hideColumnsAfter(0);
            };

            const handleItemKeydown = (event, depth, index, node) => {
                const nodes = nodesByDepth[depth] || [];

                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    const direction = event.key === 'ArrowDown' ? 1 : -1;
                    const nextIndex = (index + direction + nodes.length) % nodes.length;
                    activateItem(depth, nextIndex);
                    focusItem(depth, nextIndex);
                    return;
                }

                if (event.key === 'ArrowRight' && hasChildren(node) && depth + 1 < maxColumns) {
                    event.preventDefault();
                    activateItem(depth, index);
                    focusItem(depth + 1, Math.max(0, activeIndexByDepth[depth + 1] ?? 0));
                    return;
                }

                if (event.key === 'ArrowLeft' && depth > 0) {
                    event.preventDefault();
                    focusItem(depth - 1, Math.max(0, activeIndexByDepth[depth - 1] ?? 0));
                    return;
                }

                if (event.key === 'Home' || event.key === 'End') {
                    event.preventDefault();
                    const nextIndex = event.key === 'Home' ? 0 : nodes.length - 1;
                    activateItem(depth, nextIndex);
                    focusItem(depth, nextIndex);
                    return;
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    setExpanded(false);
                    resetCascade();
                    if (document.activeElement instanceof HTMLElement) {
                        document.activeElement.blur();
                    }
                }
            };

            const renderChevron = () => {
                const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                icon.setAttribute('viewBox', '0 0 20 20');
                icon.setAttribute('fill', 'none');
                icon.setAttribute('stroke', 'currentColor');
                icon.setAttribute('stroke-width', '1.8');
                icon.setAttribute('stroke-linecap', 'round');
                icon.setAttribute('stroke-linejoin', 'round');
                icon.setAttribute('aria-hidden', 'true');

                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', 'm7 4 6 6-6 6');
                icon.append(path);

                return icon;
            };

            function renderColumn(depth, nodes, parentNode, selectedIndex = -1) {
                const column = columns[depth];
                if (!column || !Array.isArray(nodes)) {
                    return;
                }

                const title = column.querySelector('[data-catalog-mega-column-title]');
                const viewAll = column.querySelector('[data-catalog-mega-column-link]');
                const list = column.querySelector('[data-catalog-mega-list]');
                if (!(list instanceof HTMLElement)) {
                    return;
                }

                nodesByDepth[depth] = nodes;
                column.hidden = false;

                if (title instanceof HTMLElement) {
                    title.textContent = depth === 0 ? rootTitle : (parentNode?.label || '');
                }

                if (viewAll instanceof HTMLAnchorElement) {
                    const viewAllUrl = depth === 0 ? rootUrl : (parentNode?.url || '');
                    viewAll.href = viewAllUrl || '#';
                    viewAll.hidden = viewAllUrl === '';
                }

                const fragment = document.createDocumentFragment();
                nodes.forEach((node, index) => {
                    const item = document.createElement('li');
                    const link = document.createElement('a');
                    const itemMain = document.createElement('span');
                    const label = document.createElement('span');
                    const nodeHasChildren = hasChildren(node) && depth + 1 < maxColumns;
                    const imageUrl = typeof node?.image_url === 'string' ? node.image_url.trim() : '';

                    link.href = typeof node?.url === 'string' && node.url !== '' ? node.url : '#';
                    link.className = 'catalog-mega-item';
                    link.dataset.catalogMegaItem = '';
                    link.dataset.catalogMegaItemIndex = String(index);
                    if (nodeHasChildren) {
                        link.setAttribute('aria-haspopup', 'true');
                        link.setAttribute('aria-expanded', index === selectedIndex ? 'true' : 'false');
                    }

                    itemMain.className = 'catalog-mega-item-main';
                    if (imageUrl !== '') {
                        const thumbnail = document.createElement('span');
                        const image = document.createElement('img');

                        link.classList.add('has-image');
                        thumbnail.className = 'catalog-mega-item-thumb';
                        thumbnail.setAttribute('aria-hidden', 'true');
                        image.src = imageUrl;
                        image.alt = '';
                        image.loading = 'lazy';
                        image.decoding = 'async';
                        image.addEventListener('error', () => {
                            thumbnail.remove();
                            link.classList.remove('has-image');
                        }, { once: true });
                        thumbnail.append(image);
                        itemMain.append(thumbnail);
                    }

                    label.className = 'catalog-mega-item-label';
                    label.textContent = typeof node?.label === 'string' ? node.label : '';
                    itemMain.append(label);
                    link.append(itemMain);
                    if (nodeHasChildren) {
                        link.append(renderChevron());
                    }

                    link.addEventListener('pointerenter', () => activateItem(depth, index));
                    link.addEventListener('focus', () => activateItem(depth, index));
                    link.addEventListener('keydown', (event) => handleItemKeydown(event, depth, index, node));
                    item.append(link);
                    fragment.append(item);
                });

                list.replaceChildren(fragment);
            }

            renderColumn(0, tree, { label: rootLabel, url: rootUrl });
            resetCascade();

            navGroup?.addEventListener('pointerenter', () => setExpanded(true));
            navGroup?.addEventListener('pointerleave', () => {
                if (!navGroup.contains(document.activeElement)) {
                    setExpanded(false);
                    resetCascade();
                }
            });
            navGroup?.addEventListener('focusin', () => setExpanded(true));
            navGroup?.addEventListener('focusout', () => {
                window.setTimeout(() => {
                    if (!navGroup.contains(document.activeElement)) {
                        setExpanded(false);
                        resetCascade();
                    }
                }, 0);
            });
        });
    };

    const init = () => {
        mountDeferredCatalogMegaMenus();
        initStickyHeader();
        initCatalogMegaMenus();

        const root = document.querySelector('[data-mobile-menu-root]');
        if (!root) {
            document.body.classList.remove('overflow-hidden');
            document.body.classList.remove('desktop-mobile-menu-open');
            return;
        }

        const panel = root.querySelector('[data-mobile-menu-panel]');
        const overlay = root.querySelector('[data-mobile-menu-close]');
        const openButtons = document.querySelectorAll('[data-mobile-menu-open]');
        const closeButtons = root.querySelectorAll('[data-mobile-menu-close]');
        const accordionSections = root.querySelectorAll('[data-mobile-menu-accordion]');
        const accordionToggleButtons = root.querySelectorAll('[data-mobile-menu-toggle]');
        const menuLinks = Array.from(root.querySelectorAll('a[href]'));
        const resetSectionStates = new WeakMap();
        let lastSyncedPath;
        let menuOpener = null;

        const forceClosedState = () => {
            root.classList.add('pointer-events-none');
            root.dataset.menuOpen = '0';
            root.inert = true;
            openButtons.forEach((button) => button.setAttribute('aria-expanded', 'false'));
            overlay?.classList.remove('opacity-100');
            overlay?.classList.add('opacity-0');
            panel?.classList.add('-translate-x-full');
            panel?.classList.remove('translate-x-0');
            document.body.classList.remove('overflow-hidden');
            document.body.classList.remove('desktop-mobile-menu-open');
        };

        forceClosedState();

        if (root.dataset.menuInit === '1') {
            return;
        }
        root.dataset.menuInit = '1';

        const normalizePath = (href) => {
            try {
                const url = new URL(href, window.location.origin);
                if (url.origin !== window.location.origin) {
                    return null;
                }

                const pathname = url.pathname.replace(/\/+$/, '');
                return pathname === '' ? '/' : pathname;
            } catch {
                return null;
            }
        };

        const getSectionDepth = (link) => {
            let depth = 0;
            let currentSection = link.closest('details');

            while (currentSection) {
                depth += 1;
                currentSection = currentSection.parentElement?.closest('details') ?? null;
            }

            return depth;
        };

        const collapseSection = (section) => {
            section.querySelectorAll('[data-mobile-menu-accordion][open]').forEach((nestedSection) => {
                nestedSection.open = false;
            });
            section.open = false;
        };

        const getSiblingSections = (section) => {
            const directParent = section.parentElement;
            if (!directParent) {
                return [];
            }

            const container = directParent.tagName === 'LI' ? directParent.parentElement : directParent;
            if (!container) {
                return [];
            }

            return Array.from(container.children).flatMap((child) => {
                if (child.matches('[data-mobile-menu-accordion]')) {
                    return [child];
                }

                if (child.tagName === 'LI') {
                    const nestedSection = child.querySelector(':scope > [data-mobile-menu-accordion]');
                    return nestedSection ? [nestedSection] : [];
                }

                return [];
            });
        };

        const resetSectionOpen = (section, open) => {
            if (section.open === open) {
                return;
            }

            // Ignore the queued native toggle caused by resetting the menu.
            resetSectionStates.set(section, open);
            section.open = open;
        };

        const resetAccordionSections = () => {
            accordionSections.forEach((section) => resetSectionOpen(section, false));
        };

        const findBestLinkForPath = (path) => menuLinks
            .filter((link) => normalizePath(link.href) === path)
            .sort((firstLink, secondLink) => getSectionDepth(secondLink) - getSectionDepth(firstLink))[0] ?? null;

        const clearActiveState = () => {
            root.querySelectorAll('.desktop-mobile-menu-row-active').forEach((row) => {
                row.classList.remove('desktop-mobile-menu-row-active');
            });

            root.querySelectorAll('.desktop-mobile-menu-link-active').forEach((link) => {
                link.classList.remove('desktop-mobile-menu-link-active');
                if (link.getAttribute('aria-current') === 'page') {
                    link.removeAttribute('aria-current');
                }
            });
        };

        const highlightCurrentLink = (link) => {
            if (!link) {
                return;
            }

            clearActiveState();
            link.classList.add('desktop-mobile-menu-link-active');
            link.setAttribute('aria-current', 'page');
            link.closest('.desktop-mobile-menu-row')?.classList.add('desktop-mobile-menu-row-active');
        };

        const syncMenuState = () => {
            const currentPath = normalizePath(window.location.href);
            if (currentPath === lastSyncedPath) {
                return;
            }
            lastSyncedPath = currentPath;
            const currentLink = currentPath ? findBestLinkForPath(currentPath) : null;

            clearActiveState();
            highlightCurrentLink(currentLink);
        };

        const closeMenu = (restoreFocus = true) => {
            forceClosedState();
            if (restoreFocus && menuOpener instanceof HTMLElement) {
                menuOpener.focus({ preventScroll: true });
            }
        };

        const openMenu = (event) => {
            if (event) {
                event.preventDefault();
            }
            menuOpener = event?.currentTarget instanceof HTMLElement
                ? event.currentTarget
                : document.activeElement;
            root.classList.remove('pointer-events-none');
            root.dataset.menuOpen = '1';
            root.inert = false;
            openButtons.forEach((button) => button.setAttribute('aria-expanded', 'true'));
            resetAccordionSections();
            syncMenuState();
            if (event?.currentTarget?.hasAttribute('data-mobile-menu-open-categories')) {
                const catalogSection = root.querySelector('[data-mobile-menu-catalog]');
                if (catalogSection instanceof HTMLDetailsElement) {
                    resetSectionOpen(catalogSection, true);
                }
            }
            overlay?.classList.remove('opacity-0');
            overlay?.classList.add('opacity-100');
            panel?.classList.remove('-translate-x-full');
            panel?.classList.add('translate-x-0');
            document.body.classList.add('overflow-hidden');
            document.body.classList.add('desktop-mobile-menu-open');
            panel?.querySelector('[data-mobile-menu-close]')?.focus({ preventScroll: true });
        };

        document.addEventListener('keydown', (event) => {
            if (root.dataset.menuOpen !== '1') {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                closeMenu();
                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusable = Array.from(panel?.querySelectorAll('a[href], button, summary, [tabindex="0"]') || [])
                .filter((element) => !element.hasAttribute('disabled') && element.getClientRects().length > 0);
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (!panel?.contains(document.activeElement)) {
                event.preventDefault();
                (event.shiftKey ? last : first)?.focus();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        });

        openButtons.forEach((button) => {
            button.setAttribute('aria-controls', panel?.id || 'mobile-navigation');
            button.addEventListener('click', openMenu);
            button.addEventListener('touchend', openMenu, { passive: false });
        });
        closeButtons.forEach((button) => button.addEventListener('click', () => closeMenu()));
        accordionSections.forEach((section) => {
            section.addEventListener('toggle', () => {
                if (resetSectionStates.has(section)) {
                    const resetOpen = resetSectionStates.get(section);
                    resetSectionStates.delete(section);
                    if (section.open === resetOpen) {
                        return;
                    }
                }

                if (!section.open) {
                    return;
                }

                getSiblingSections(section).forEach((otherSection) => {
                    if (otherSection !== section) {
                        collapseSection(otherSection);
                    }
                });
            });
        });
        accordionToggleButtons.forEach((button) => {
            let touchStart = null;
            let lastTouchToggleAt = 0;

            const toggleSection = (event) => {
                event.preventDefault();
                event.stopPropagation();

                const section = button.closest('[data-mobile-menu-accordion]');
                if (section instanceof HTMLDetailsElement) {
                    const hadFocus = document.activeElement === button;
                    section.open = !section.open;
                    if (hadFocus) {
                        const visibleToggle = section.querySelector(section.open
                            ? ':scope > summary > [data-mobile-menu-toggle-close]'
                            : ':scope > summary > [data-mobile-menu-toggle-open]');
                        visibleToggle?.focus({ preventScroll: true });
                    }
                }
            };

            button.addEventListener('touchstart', (event) => {
                const touch = event.touches[0];
                touchStart = touch
                    ? { x: touch.clientX, y: touch.clientY }
                    : null;
            }, { passive: true });

            button.addEventListener('touchend', (event) => {
                const touch = event.changedTouches[0];
                if (!touchStart || !touch) {
                    touchStart = null;
                    return;
                }

                const movedX = Math.abs(touch.clientX - touchStart.x);
                const movedY = Math.abs(touch.clientY - touchStart.y);
                touchStart = null;

                if (movedX > 12 || movedY > 12) {
                    return;
                }

                lastTouchToggleAt = Date.now();
                toggleSection(event);
            }, { passive: false });

            button.addEventListener('touchcancel', () => {
                touchStart = null;
            }, { passive: true });

            button.addEventListener('click', (event) => {
                if (Date.now() - lastTouchToggleAt < 700) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }

                toggleSection(event);
            });
        });
        root.querySelectorAll('summary [data-mobile-nav-link]').forEach((link) => {
            link.addEventListener('click', (event) => {
                event.stopPropagation();
            });
        });
        const leavesCurrentDocument = (event, link) => {
            if (event.defaultPrevented || (event.button !== undefined && event.button !== 0)
                || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
                || link.hasAttribute('download')) {
                return false;
            }

            const target = (link.getAttribute('target') || '').trim().toLowerCase();
            if (target && target !== '_self') {
                return false;
            }

            try {
                const destination = new URL(link.href, window.location.href);
                const current = new URL(window.location.href);
                if (!['http:', 'https:'].includes(destination.protocol)) {
                    return false;
                }

                const sameDocument = destination.origin === current.origin
                    && destination.pathname === current.pathname && destination.search === current.search;
                const hasFragment = destination.href.includes('#');
                return !sameDocument || !hasFragment;
            } catch {
                return false;
            }
        };
        menuLinks.forEach((link) => {
            link.addEventListener('click', (event) => {
                // Keep the outgoing page still while a new document is loading.
                if (!leavesCurrentDocument(event, link)) {
                    closeMenu(false);
                }
            });
        });

        resetAccordionSections();
        syncMenuState();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            init();
        }
    });
})();
