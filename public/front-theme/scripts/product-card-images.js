(() => {
    const imageSelector = 'img[data-product-card-image]';

    const frameFor = (image) => image instanceof HTMLImageElement && image.matches(imageSelector)
        ? image.closest('[data-product-card-image-frame]')
        : null;

    const reveal = (image) => {
        const frame = frameFor(image);
        if (frame) {
            frame.dataset.imageState = image.naturalWidth > 0 ? 'loaded' : 'error';
        }
    };

    const prepare = (container = document) => {
        container.querySelectorAll(imageSelector).forEach((image) => {
            const frame = frameFor(image);
            if (!frame) {
                return;
            }
            // Cached images stay visible; JS-disabled pages also keep normal images.
            if (image.complete) {
                reveal(image);
            } else {
                frame.dataset.imageState = 'loading';
            }
        });
    };

    // Capture also covers images appended by pagination or cloned by carousels.
    document.addEventListener('load', (event) => {
        const image = event.target;
        if (!frameFor(image)) {
            return;
        }
        if (typeof image.decode === 'function') {
            image.decode().catch(() => {}).then(() => reveal(image));
        } else {
            reveal(image);
        }
    }, true);
    document.addEventListener('error', (event) => reveal(event.target), true);
    document.addEventListener('catalog:items-appended', (event) => {
        prepare(event.detail?.container || document);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => prepare(), { once: true });
    } else {
        prepare();
    }
})();
