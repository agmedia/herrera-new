(() => {
    const init = () => {
        document.querySelectorAll('[data-herrera-subcategory-carousel]').forEach((root) => {
            if (root.dataset.carouselInitialized === '1') return;

            const track = root.querySelector('[data-subcategory-track]');
            const controls = root.querySelector('[data-subcategory-controls]');
            const previous = root.querySelector('[data-subcategory-previous]');
            const next = root.querySelector('[data-subcategory-next]');
            if (!track || !controls || !previous || !next) return;
            root.dataset.carouselInitialized = '1';

            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
            const update = () => {
                const maxScroll = Math.max(0, track.scrollWidth - track.clientWidth);
                controls.hidden = maxScroll < 2;
                previous.disabled = track.scrollLeft < 2;
                next.disabled = track.scrollLeft >= maxScroll - 2;
            };
            const move = (direction) => {
                const first = track.firstElementChild;
                if (!first) return;
                const gap = parseFloat(window.getComputedStyle(track).columnGap) || 0;
                const step = first.getBoundingClientRect().width + gap;
                const page = Math.max(1, Math.floor((track.clientWidth + gap) / step));
                track.scrollBy({ left: direction * step * page, behavior: reducedMotion.matches ? 'instant' : 'smooth' });
            };

            previous.addEventListener('click', () => move(-1));
            next.addEventListener('click', () => move(1));
            track.addEventListener('scroll', update, { passive: true });
            track.addEventListener('keydown', (event) => {
                if (event.target !== track) return;
                if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                    event.preventDefault();
                    move(event.key === 'ArrowLeft' ? -1 : 1);
                } else if (event.key === 'Home' || event.key === 'End') {
                    event.preventDefault();
                    track.scrollTo({ left: event.key === 'Home' ? 0 : track.scrollWidth, behavior: reducedMotion.matches ? 'instant' : 'smooth' });
                }
            });
            if ('ResizeObserver' in window) {
                new ResizeObserver(update).observe(track);
            } else {
                window.addEventListener('resize', update, { passive: true });
            }
            update();
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
