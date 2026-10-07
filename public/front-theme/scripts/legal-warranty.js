(() => {
    const dialog = document.querySelector('[data-legal-warranty-modal]');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    let trigger = null;
    document.querySelectorAll('[data-legal-warranty-open]').forEach(link => {
        link.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            trigger = link;
            if (!dialog.open) dialog.showModal();
        });
    });
    dialog.querySelectorAll('[data-legal-warranty-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => {
        if (trigger?.isConnected) trigger.focus();
    });
})();
