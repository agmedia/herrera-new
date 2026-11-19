(() => {
    if (window.herreraLocationControl) return;
    window.herreraLocationControl = true;
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-location-button]');
        if (!button) return;
        const field = button.closest('[data-device-location]');
        const status = field.querySelector('[data-location-status]');
        const inputs = field.querySelectorAll('[data-coordinate]');
        const setCoordinates = coords => {
            inputs.forEach(input => {
                input.value = coords ? String(coords[input.dataset.coordinate]) : '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        };
        if (field.dataset.hasLocation === '1') {
            setCoordinates(null);
            delete field.dataset.hasLocation;
            status.textContent = 'Lokacija uklonjena.';
            button.textContent = 'Dodaj lokaciju uređaja';
            return;
        }
        if (!navigator.geolocation) {
            status.textContent = 'Lokacija nije dostupna u ovom pregledniku.';
            return;
        }
        button.disabled = true;
        status.textContent = 'Čekam lokaciju uređaja…';
        navigator.geolocation.getCurrentPosition(position => {
            setCoordinates(position.coords);
            field.dataset.hasLocation = '1';
            button.textContent = 'Ukloni lokaciju';
            status.textContent = 'Lokacija dodana (±' + Math.ceil(position.coords.accuracy) + ' m).';
            button.disabled = false;
        }, () => {
            status.textContent = 'Lokacija nije dobivena. Zapis možete spremiti i bez nje.';
            button.disabled = false;
        }, { enableHighAccuracy: true, maximumAge: 0, timeout: 12000 });
    });
})();
