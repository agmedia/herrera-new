(function () {
    'use strict';

    function noun(count, one, few, many) {
        var lastTwo = count % 100;
        var last = count % 10;

        if (lastTwo >= 11 && lastTwo <= 14) {
            return many;
        }

        if (last === 1) {
            return one;
        }

        if (last >= 2 && last <= 4) {
            return few;
        }

        return many;
    }

    function initializeDirectory(root) {
        var letterLinks = Array.prototype.slice.call(root.querySelectorAll('[data-brand-letter]'));
        var groups = Array.prototype.slice.call(root.querySelectorAll('[data-brand-group]'));
        var status = root.querySelector('[data-brand-status]');

        root.querySelectorAll('[data-brand-logo]').forEach(function (logo) {
            var fallback = logo.parentElement.querySelector('[data-brand-logo-fallback]');

            function showFallback() {
                logo.hidden = true;

                if (fallback) {
                    fallback.hidden = false;
                }
            }

            logo.addEventListener('error', showFallback, { once: true });

            if (logo.complete && logo.naturalWidth === 0) {
                showFallback();
            }
        });

        if (!letterLinks.length || !groups.length) {
            return;
        }

        function applyFilter(letter, updateHash) {
            var selectedLetter = letter || '*';
            var selectedGroup = null;
            var selectedCount = 0;

            groups.forEach(function (group) {
                var matches = selectedLetter === '*' || group.dataset.brandGroup === selectedLetter;
                group.hidden = !matches;

                if (matches && selectedLetter !== '*') {
                    selectedGroup = group;
                    selectedCount = group.querySelectorAll('.brand-directory__card').length;
                }
            });

            letterLinks.forEach(function (link) {
                var isCurrent = link.dataset.brandLetter === selectedLetter;

                if (isCurrent) {
                    link.setAttribute('aria-current', 'true');
                } else {
                    link.removeAttribute('aria-current');
                }
            });

            if (status) {
                status.textContent = selectedLetter === '*'
                    ? 'Prikazani su svi brendovi.'
                    : 'Prikazano je ' + selectedCount + ' ' + noun(selectedCount, 'brend', 'brenda', 'brendova') + ' na slovo ' + selectedLetter + '.';
            }

            if (updateHash && window.history && window.history.replaceState) {
                var nextHash = selectedLetter === '*'
                    ? '#svi-brendovi'
                    : '#slovo-' + encodeURIComponent(selectedLetter);
                window.history.replaceState(null, '', nextHash);
            }

            if (selectedGroup) {
                selectedGroup.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        letterLinks.forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                applyFilter(link.dataset.brandLetter, true);
            });
        });

        var hashMatch = window.location.hash.match(/^#slovo-(.+)$/);
        var hashLetter = hashMatch ? decodeURIComponent(hashMatch[1]) : '*';
        var hasHashLetter = letterLinks.some(function (link) {
            return link.dataset.brandLetter === hashLetter;
        });

        applyFilter(hasHashLetter ? hashLetter : '*', false);
    }

    function initialize() {
        document.querySelectorAll('[data-brand-directory]').forEach(initializeDirectory);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
