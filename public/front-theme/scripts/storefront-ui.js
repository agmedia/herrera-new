(() => {
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        const dismissButton = target.closest('[data-flash-dismiss]');
        if (dismissButton) {
            dismissButton.closest('[data-flash-message]')?.remove();
        }
    });
})();
