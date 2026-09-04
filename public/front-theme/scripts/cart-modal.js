(() => {
    if (window.TermolCartModal) {
        return;
    }

    let refs = null;
    let previouslyFocusedElement = null;
    let focusFrame = 0;

    const modalMarkup = [
        '<div class="product-cart-modal-backdrop" data-cart-modal-backdrop></div>',
        '<section class="product-cart-modal-panel" role="dialog" aria-modal="true" tabindex="-1" data-cart-modal-panel>',
        '  <button type="button" class="product-cart-modal-close" data-cart-modal-close>',
        '    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
        '  </button>',
        '  <div class="product-cart-modal-status">',
        '    <span class="product-cart-modal-status-icon" aria-hidden="true">',
        '      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>',
        '    </span>',
        '    <p class="product-cart-modal-status-title" data-cart-modal-title></p>',
        '  </div>',
        '  <div class="product-cart-modal-product">',
        '    <div class="product-cart-modal-image-wrap" data-cart-modal-image-wrap>',
        '      <img src="" alt="" class="product-cart-modal-image" data-cart-modal-image>',
        '    </div>',
        '    <div class="product-cart-modal-info">',
        '      <h3 class="product-cart-modal-name" data-cart-modal-name></h3>',
        '      <div class="product-cart-modal-details">',
        '        <p class="product-cart-modal-detail" data-cart-modal-option-wrap hidden><span class="product-cart-modal-detail-label" data-cart-modal-option-label></span><span aria-hidden="true">:</span> <span data-cart-modal-option></span></p>',
        '        <p class="product-cart-modal-detail"><span class="product-cart-modal-detail-label" data-cart-modal-qty-label></span><span aria-hidden="true">:</span> <span data-cart-modal-qty></span></p>',
        '      </div>',
        '    </div>',
        '  </div>',
        '  <div class="product-cart-modal-actions">',
        '    <button type="button" class="product-cart-modal-action product-cart-modal-action-secondary" data-cart-modal-continue>',
        '      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>',
        '      <span data-cart-modal-continue-label></span>',
        '    </button>',
        '    <a href="#" class="product-cart-modal-action product-cart-modal-action-primary" data-cart-modal-cart>',
        '      <span data-cart-modal-cart-label></span>',
        '      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>',
        '    </a>',
        '  </div>',
        '</section>',
    ].join('');

    const close = function () {
        if (!refs || refs.root.classList.contains('hidden')) {
            return;
        }

        if (focusFrame) {
            window.cancelAnimationFrame(focusFrame);
            focusFrame = 0;
        }

        refs.root.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');

        if (previouslyFocusedElement && typeof previouslyFocusedElement.focus === 'function') {
            previouslyFocusedElement.focus({ preventScroll: true });
        }
        previouslyFocusedElement = null;
    };

    const ensure = function () {
        if (refs) {
            return refs;
        }

        const root = document.createElement('div');
        root.className = 'product-cart-modal hidden';
        root.innerHTML = modalMarkup;
        document.body.appendChild(root);

        refs = {
            root: root,
            backdrop: root.querySelector('[data-cart-modal-backdrop]'),
            panel: root.querySelector('[data-cart-modal-panel]'),
            closeButton: root.querySelector('[data-cart-modal-close]'),
            imageWrap: root.querySelector('[data-cart-modal-image-wrap]'),
            image: root.querySelector('[data-cart-modal-image]'),
            title: root.querySelector('[data-cart-modal-title]'),
            name: root.querySelector('[data-cart-modal-name]'),
            optionWrap: root.querySelector('[data-cart-modal-option-wrap]'),
            optionLabel: root.querySelector('[data-cart-modal-option-label]'),
            optionValue: root.querySelector('[data-cart-modal-option]'),
            quantityLabel: root.querySelector('[data-cart-modal-qty-label]'),
            quantityValue: root.querySelector('[data-cart-modal-qty]'),
            continueButton: root.querySelector('[data-cart-modal-continue]'),
            continueLabel: root.querySelector('[data-cart-modal-continue-label]'),
            cartButton: root.querySelector('[data-cart-modal-cart]'),
            cartLabel: root.querySelector('[data-cart-modal-cart-label]'),
        };

        root.addEventListener('click', function (event) {
            const target = event.target instanceof Element ? event.target : null;
            if (
                target === refs.backdrop
                || target?.closest('[data-cart-modal-close]')
                || target?.closest('[data-cart-modal-continue]')
            ) {
                close();
            }
        });

        root.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });

        return refs;
    };

    const show = function (form, optionText, quantity) {
        const modal = ensure();
        const imageUrl = String(form.dataset.productImage || '').trim();
        const productName = String(form.dataset.productName || '').trim();
        const title = String(form.dataset.modalTitle || 'Added to cart');
        const safeOptionText = String(optionText || '').trim();
        const safeQuantity = Number.isFinite(quantity) ? Math.max(1, quantity) : 1;

        if (imageUrl !== '') {
            modal.image.src = imageUrl;
            modal.imageWrap.hidden = false;
        } else {
            modal.image.removeAttribute('src');
            modal.imageWrap.hidden = true;
        }

        modal.image.alt = productName;
        modal.title.textContent = title;
        modal.panel.setAttribute('aria-label', title);
        modal.closeButton.setAttribute('aria-label', String(form.dataset.modalClose || 'Close'));
        modal.name.textContent = productName;

        modal.optionWrap.hidden = safeOptionText === '';
        if (safeOptionText !== '') {
            modal.optionLabel.textContent = String(form.dataset.modalOption || 'Option');
            modal.optionValue.textContent = safeOptionText;
        }

        modal.quantityLabel.textContent = String(form.dataset.modalQuantity || 'Quantity');
        modal.quantityValue.textContent = String(safeQuantity);
        modal.continueLabel.textContent = String(form.dataset.modalContinue || 'Continue shopping');
        modal.cartLabel.textContent = String(form.dataset.modalGoCart || 'Go to cart');
        modal.cartButton.setAttribute('href', String(form.dataset.cartUrl || '/cart'));

        if (modal.root.classList.contains('hidden')) {
            previouslyFocusedElement = document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        }

        modal.root.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        if (focusFrame) {
            window.cancelAnimationFrame(focusFrame);
        }
        focusFrame = window.requestAnimationFrame(function () {
            focusFrame = 0;
            modal.closeButton.focus({ preventScroll: true });
        });
    };

    window.TermolCartModal = Object.freeze({
        close: close,
        show: show,
    });
})();
