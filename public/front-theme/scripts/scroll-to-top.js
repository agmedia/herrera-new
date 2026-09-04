(() => {
    const scrollTopButton = document.querySelector('[data-scroll-to-top]');
    if (!scrollTopButton) {
        return;
    }

    let ticking = false;

    const syncVisibility = () => {
        scrollTopButton.classList.toggle('is-visible', window.scrollY > 360);
        ticking = false;
    };

    const onScroll = () => {
        if (ticking) {
            return;
        }

        ticking = true;
        window.requestAnimationFrame(syncVisibility);
    };

    scrollTopButton.addEventListener('click', () => {
        window.scrollTo({
            top: 0,
            behavior: 'smooth',
        });
    });

    syncVisibility();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    window.addEventListener('pageshow', syncVisibility);
})();
