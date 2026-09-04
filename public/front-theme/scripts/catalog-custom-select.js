(() => {
    const DESKTOP_MEDIA = '(min-width: 1025px)';

    const closeAll = () => {
        document.querySelectorAll('[data-custom-select].is-open').forEach((node) => {
            node.classList.remove('is-open');
            const button = node.querySelector('[data-custom-select-button]');
            if (button) {
                button.setAttribute('aria-expanded', 'false');
            }
        });
    };

    const closePricePanels = () => {
        document.querySelectorAll('[data-price-filter-root].is-open').forEach((node) => {
            node.classList.remove('is-open');
            const button = node.querySelector('[data-price-filter-toggle]');
            if (button) {
                button.setAttribute('aria-expanded', 'false');
            }
        });
    };

    const setLabel = (select, button) => {
        const selected = select.options[select.selectedIndex];
        const label = selected ? selected.textContent || '' : '';
        button.textContent = label;
        button.title = label;
        const isPlaceholder = !selected || String(selected.value || '').trim() === '';
        button.classList.toggle('is-placeholder', isPlaceholder);
    };

    const normalizeSwatchKey = (value) => String(value || '')
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');

    const swatchFromLabel = (label) => {
        const key = normalizeSwatchKey(label);

        const map = new Map([
            [['crna', 'black'], 'background:#050505;'],
            [['bijela', 'white'], 'background:#fcfcfc;'],
            [['crvena', 'red'], 'background:#d62828;'],
            [['siva', 'gray', 'grey'], 'background:#8f96a3;'],
            [['tamno plava', 'tamno-plava', 'dark blue', 'navy'], 'background:#1e3a8a;'],
            [['boja koze', 'boja-koze', 'nude', 'skin'], 'background:#d2a181;'],
            [['kokos'], 'background:linear-gradient(135deg, #ceb08f 0%, #b98d62 48%, #7b5637 100%);'],
            [['geometric'], 'background:linear-gradient(135deg, #101828 0 28%, #e11d48 28% 50%, #f8fafc 50% 62%, #101828 62% 100%);'],
            [['squares'], 'background:linear-gradient(45deg, #111827 25%, #f8fafc 25% 50%, #111827 50% 75%, #f8fafc 75% 100%);background-size:12px 12px;'],
            [['web'], 'background:radial-gradient(circle at center, transparent 0 11px, rgba(15,23,42,.18) 11px 12px, transparent 12px), repeating-linear-gradient(45deg, #111827 0 2px, transparent 2px 8px), #f8fafc;'],
            [['red flowers'], 'background:radial-gradient(circle at 30% 35%, #fca5a5 0 18%, transparent 19%), radial-gradient(circle at 72% 68%, #ef4444 0 16%, transparent 17%), linear-gradient(135deg, #fff1f2 0%, #fecdd3 100%);'],
            [['black roses'], 'background:radial-gradient(circle at 30% 35%, #fbcfe8 0 18%, transparent 19%), radial-gradient(circle at 72% 68%, #111827 0 16%, transparent 17%), linear-gradient(135deg, #111827 0%, #475569 100%);'],
            [['butterfly'], 'background:linear-gradient(135deg, #fb7185 0%, #fecdd3 35%, #111827 35% 45%, #fecdd3 45% 65%, #e11d48 65% 100%);'],
            [['footprint'], 'background:radial-gradient(circle at 30% 30%, #111827 0 13%, transparent 14%), radial-gradient(circle at 52% 52%, #111827 0 10%, transparent 11%), radial-gradient(circle at 70% 28%, #111827 0 7%, transparent 8%), linear-gradient(135deg, #f1e3d3 0%, #d4b08b 100%);'],
            [['stars'], 'background:radial-gradient(circle at 22% 28%, #fef08a 0 6%, transparent 7%), radial-gradient(circle at 68% 36%, #fff7ae 0 5%, transparent 6%), radial-gradient(circle at 48% 70%, #fef08a 0 6%, transparent 7%), #1d4ed8;'],
        ]);

        for (const [aliases, style] of map.entries()) {
            if (aliases.includes(key)) {
                return style;
            }
        }

        if (key.includes('karirano') && key.includes('crvena')) {
            return 'background:repeating-linear-gradient(45deg, rgba(17,24,39,.45) 0 2px, transparent 2px 8px), repeating-linear-gradient(-45deg, rgba(17,24,39,.45) 0 2px, transparent 2px 8px), #dc2626;background-size:12px 12px;';
        }

        if (key.includes('karirano') && (key.includes('crna') || key.includes('black'))) {
            return 'background:repeating-linear-gradient(45deg, rgba(248,250,252,.45) 0 2px, transparent 2px 8px), repeating-linear-gradient(-45deg, rgba(248,250,252,.45) 0 2px, transparent 2px 8px), #111827;background-size:12px 12px;';
        }

        const palette = [
            ['#111827', '#475569'],
            ['#7c2d12', '#fb923c'],
            ['#1d4ed8', '#38bdf8'],
            ['#6d28d9', '#c084fc'],
            ['#be123c', '#fda4af'],
            ['#166534', '#86efac'],
        ];
        let hash = 0;
        for (let index = 0; index < key.length; index += 1) {
            hash = ((hash << 5) - hash) + key.charCodeAt(index);
            hash |= 0;
        }
        const [start, end] = palette[Math.abs(hash) % palette.length];

        return `background:linear-gradient(135deg, ${start} 0%, ${end} 100%);`;
    };

    const applySwatchStyle = (swatch, option) => {
        const imageUrl = String(option.dataset.filterSwatch || '').trim();

        if (imageUrl !== '') {
            swatch.style.cssText = '';
            swatch.style.backgroundImage = `url("${imageUrl.replace(/"/g, '\\"')}")`;
            swatch.style.backgroundSize = 'cover';
            swatch.style.backgroundPosition = 'center';
            swatch.style.backgroundRepeat = 'no-repeat';
            swatch.style.backgroundColor = 'transparent';
            return;
        }

        swatch.style.cssText = swatchFromLabel(option.textContent || '');
    };

    const buildCustomSelect = (select) => {
        if (select.dataset.customSelectReady === '1') {
            return;
        }

        const container = select.parentElement;
        if (!container) {
            return;
        }

        const custom = document.createElement('div');
        custom.className = 'catalog-filter-custom';
        custom.setAttribute('data-custom-select', '');
        if (select.classList.contains('catalog-filter-sort')) {
            custom.classList.add('is-sort');
        }
        if (select.classList.contains('catalog-filter-inline-select')) {
            custom.classList.add('is-inline-label');
        }
        if (select.dataset.filterKind === 'composition') {
            custom.classList.add('is-composition');
        }
        if (select.dataset.filterKind === 'color') {
            custom.classList.add('is-color');
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'catalog-filter-custom-button';
        button.setAttribute('data-custom-select-button', '');
        button.setAttribute('aria-expanded', 'false');

        const list = document.createElement('div');
        list.className = 'catalog-filter-custom-list';
        list.setAttribute('data-custom-select-list', '');

        Array.from(select.options).forEach((option) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'catalog-filter-custom-item';
            item.dataset.value = option.value;
            if (String(option.value || '').trim() === '') {
                item.classList.add('is-placeholder');
            }
            if (option.disabled) {
                item.disabled = true;
            }
            if (option.selected) {
                item.classList.add('is-selected');
            }

            if (select.dataset.filterKind === 'color' && String(option.value || '').trim() !== '') {
                const row = document.createElement('span');
                row.className = 'catalog-filter-color-item-content';

                const swatch = document.createElement('span');
                swatch.className = 'catalog-filter-color-swatch';
                swatch.setAttribute('aria-hidden', 'true');
                applySwatchStyle(swatch, option);
                row.appendChild(swatch);

                const label = document.createElement('span');
                label.className = 'catalog-filter-color-label';
                label.textContent = option.textContent || '';
                row.appendChild(label);

                item.appendChild(row);

                const countRaw = Number(option.dataset.filterCount || 0);
                if (countRaw > 0) {
                    const badge = document.createElement('span');
                    badge.className = 'catalog-filter-color-count';
                    badge.textContent = String(countRaw);
                    item.appendChild(badge);
                }
            } else {
                item.textContent = option.textContent || '';
            }

            item.addEventListener('click', () => {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));

                list.querySelectorAll('.catalog-filter-custom-item.is-selected').forEach((row) => {
                    row.classList.remove('is-selected');
                });
                item.classList.add('is-selected');

                setLabel(select, button);
                custom.classList.remove('is-open');
                button.setAttribute('aria-expanded', 'false');
            });

            list.appendChild(item);
        });

        setLabel(select, button);

        button.addEventListener('click', (event) => {
            event.preventDefault();
            const opening = !custom.classList.contains('is-open');
            closeAll();
            if (opening) {
                closePricePanels();
            }
            custom.classList.toggle('is-open', opening);
            button.setAttribute('aria-expanded', opening ? 'true' : 'false');
        });

        select.addEventListener('change', () => {
            setLabel(select, button);
            list.querySelectorAll('.catalog-filter-custom-item').forEach((row) => {
                row.classList.toggle('is-selected', row.dataset.value === select.value);
            });
        });

        container.appendChild(custom);
        custom.appendChild(button);
        custom.appendChild(list);

        select.classList.add('catalog-filter-native-hidden');
        select.dataset.customSelectReady = '1';
    };

    const destroyCustomSelect = (select) => {
        const container = select.parentElement;
        if (!container) {
            return;
        }

        const custom = container.querySelector('[data-custom-select]');
        if (custom) {
            custom.remove();
        }

        select.classList.remove('catalog-filter-native-hidden');
        select.dataset.customSelectReady = '0';
    };

    const applyMode = () => {
        const desktop = window.matchMedia(DESKTOP_MEDIA).matches;
        document.querySelectorAll('[data-desktop-filter-form] .catalog-filter-select').forEach((select) => {
            if (desktop) {
                buildCustomSelect(select);
            } else {
                destroyCustomSelect(select);
            }
        });
        if (!desktop) {
            closeAll();
        }
    };

    const init = () => {
        if (document.documentElement.dataset.catalogCustomSelectInit === '1') {
            applyMode();
            return;
        }

        document.documentElement.dataset.catalogCustomSelectInit = '1';
        applyMode();

        window.addEventListener('resize', applyMode);
        document.addEventListener('click', (event) => {
            if (!event.target.closest('[data-custom-select]')) {
                closeAll();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAll();
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
    window.addEventListener('pageshow', init);
})();
