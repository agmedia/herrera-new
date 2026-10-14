(() => {
    const root = document.querySelector('[data-reference-gallery-root]');
    if (!root || root.dataset.referenceGalleryBound === '1') return;
    const dialog = root.querySelector('[data-reference-lightbox]');
    const data = root.querySelector('[data-reference-gallery-items]');
    if (!dialog || !data || typeof dialog.showModal !== 'function') return;

    let items;
    try {
        items = JSON.parse(data.textContent || '[]');
    } catch (_) {
        return;
    }
    if (!Array.isArray(items) || !items.length || items.some((item) => !item || typeof item.src !== 'string')) return;

    const image = dialog.querySelector('[data-reference-lightbox-image]');
    const caption = dialog.querySelector('[data-reference-lightbox-caption]');
    const counter = dialog.querySelector('[data-reference-lightbox-count]');
    const closeButton = dialog.querySelector('[data-reference-lightbox-close]');
    const previousButton = dialog.querySelector('[data-reference-lightbox-prev]');
    const nextButton = dialog.querySelector('[data-reference-lightbox-next]');
    const stage = dialog.querySelector('[data-reference-lightbox-stage]');
    const thumbnails = Array.from(dialog.querySelectorAll('[data-reference-lightbox-thumb]'));
    if (!image || !caption || !counter || !closeButton || !previousButton || !nextButton || !stage) return;
    root.dataset.referenceGalleryBound = '1';

    let current = 0;
    let opener = null;
    let pageState = null;
    let touchStart = null;
    let ignoreBackdropUntil = 0;

    const show = (index) => {
        current = ((index % items.length) + items.length) % items.length;
        const item = items[current];
        image.src = item.src;
        image.alt = String(item.alt || item.caption || '');
        caption.textContent = String(item.caption || '');
        counter.textContent = (dialog.dataset.countTemplate || ':current / :total')
            .replace(':current', String(current + 1)).replace(':total', String(items.length));
        previousButton.hidden = items.length < 2;
        nextButton.hidden = items.length < 2;
        thumbnails.forEach((thumbnail, index) => {
            thumbnail.setAttribute('aria-current', String(index === current));
        });
        const activeThumbnail = thumbnails[current];
        if (activeThumbnail && typeof activeThumbnail.scrollIntoView === 'function') {
            activeThumbnail.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'instant' });
        }
    };

    const lockPage = () => {
        const body = document.body;
        const properties = ['overflow', 'position', 'top', 'left', 'width', 'paddingRight'];
        pageState = { x: window.scrollX, y: window.scrollY, styles: {} };
        properties.forEach((property) => { pageState.styles[property] = body.style[property]; });
        const scrollbar = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
        const padding = Number.parseFloat(window.getComputedStyle(body).paddingRight) || 0;
        body.style.overflow = 'hidden';
        body.style.position = 'fixed';
        body.style.top = `${-pageState.y}px`;
        body.style.left = `${-pageState.x}px`;
        body.style.width = '100%';
        if (scrollbar > 0) body.style.paddingRight = `${padding + scrollbar}px`;
    };

    const restorePage = () => {
        if (!pageState) return;
        const state = pageState;
        pageState = null;
        Object.entries(state.styles).forEach(([property, value]) => { document.body.style[property] = value; });
        const scrollBehavior = document.documentElement.style.scrollBehavior;
        document.documentElement.style.scrollBehavior = 'auto';
        window.scrollTo(state.x, state.y);
        document.documentElement.style.scrollBehavior = scrollBehavior;
        if (opener && opener.isConnected && typeof opener.focus === 'function') opener.focus({ preventScroll: true });
        opener = null;
    };

    const close = () => {
        if (dialog.open) dialog.close();
        restorePage();
    };

    root.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-reference-open]');
        if (!trigger || !root.contains(trigger) || event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const index = Number.parseInt(trigger.dataset.referenceOpen || '0', 10);
        if (!Number.isFinite(index)) return;
        opener = trigger;
        show(index);
        if (!dialog.open) {
            try { dialog.showModal(); } catch (_) { opener = null; return; }
            lockPage();
        }
        closeButton.focus({ preventScroll: true });
        event.preventDefault();
    });

    closeButton.addEventListener('click', close);
    previousButton.addEventListener('click', () => show(current - 1));
    nextButton.addEventListener('click', () => show(current + 1));
    thumbnails.forEach((thumbnail) => {
        thumbnail.addEventListener('click', () => {
            const index = Number.parseInt(thumbnail.dataset.referenceLightboxThumb || '0', 10);
            if (Number.isFinite(index)) show(index);
        });
    });
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    dialog.addEventListener('close', restorePage);
    dialog.addEventListener('click', (event) => {
        if (Date.now() < ignoreBackdropUntil) return;
        if (event.target === dialog || event.target === stage) close();
    });
    dialog.addEventListener('keydown', (event) => {
        if (!dialog.open || event.altKey || event.ctrlKey || event.metaKey) return;
        if (event.key === 'Escape') { event.preventDefault(); close(); return; }
        if (event.key === 'ArrowLeft') { event.preventDefault(); show(current - 1); return; }
        if (event.key === 'ArrowRight') { event.preventDefault(); show(current + 1); return; }
        if (event.key === 'Home') { event.preventDefault(); show(0); return; }
        if (event.key === 'End') { event.preventDefault(); show(items.length - 1); return; }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(dialog.querySelectorAll('button, a[href], [tabindex]'))
            .filter((element) => !element.hidden && !element.disabled && element.getAttribute('tabindex') !== '-1');
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    });
    stage.addEventListener('touchstart', (event) => {
        if (event.touches.length !== 1 || event.target.closest('button')) { touchStart = null; return; }
        touchStart = { x: event.touches[0].clientX, y: event.touches[0].clientY };
    }, { passive: true });
    stage.addEventListener('touchend', (event) => {
        if (!touchStart || !event.changedTouches.length) return;
        const dx = event.changedTouches[0].clientX - touchStart.x;
        const dy = event.changedTouches[0].clientY - touchStart.y;
        touchStart = null;
        if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy) * 1.5) {
            show(current + (dx < 0 ? 1 : -1));
            ignoreBackdropUntil = Date.now() + 350;
        }
    }, { passive: true });
    stage.addEventListener('touchcancel', () => { touchStart = null; }, { passive: true });
})();
