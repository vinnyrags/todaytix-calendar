/**
 * Ticket calendar — month paging + mobile performance selector.
 *
 * Progressive enhancement. The server renders every month (all but the opening one
 * hidden) with each day's showtimes inline. This script:
 *   1. pages between months via each month's prev/next buttons; and
 *   2. on narrow screens, collapses each day to a number + availability dot and,
 *      when a day is tapped, fills a "performance selector" panel below the calendar
 *      with that day's showtimes (Wicked-style, rows stacked vertically).
 *
 * With JS off — or on desktop — nothing here is required: the inline per-day times
 * remain real links and the calendar is fully usable. The mobile layout only engages
 * once this script adds the `ttx-calendar--enhanced` class, so a no-JS phone still
 * gets the (functional) inline times rather than dead day cells.
 */
(function () {
    'use strict';

    var MOBILE_QUERY = '(max-width: 640px)';
    var instance = 0;

    function initCalendar(root) {
        root.classList.add('ttx-calendar--enhanced');

        var panel = root.querySelector('[data-ttx-selector]');
        var panelId = 'ttx-calendar-selector-' + (++instance);
        if (panel) {
            panel.id = panelId;
        }

        var months = Array.prototype.slice.call(root.querySelectorAll('.ttx-calendar__month'));
        var mq = window.matchMedia(MOBILE_QUERY);

        setupPaging(root, months, panel);
        setupSelector(root, panel, panelId, mq);
    }

    /* --- Month paging ----------------------------------------------------- */

    function setupPaging(root, months, panel) {
        var index = months.findIndex(function (m) { return !m.hasAttribute('hidden'); });
        if (index < 0) { index = 0; }

        function show(target) {
            if (target < 0 || target >= months.length || target === index) { return; }
            months[index].setAttribute('hidden', '');
            months[target].removeAttribute('hidden');
            index = target;

            clearSelection(root, panel); // the selected day belonged to the old month

            var label = months[target].querySelector('.ttx-calendar__month-label');
            if (label && typeof label.focus === 'function') {
                label.focus({ preventScroll: false });
            }
        }

        months.forEach(function (month, i) {
            var prev = month.querySelector('[data-ttx-prev]');
            var next = month.querySelector('[data-ttx-next]');
            if (prev) { prev.addEventListener('click', function () { show(i - 1); }); }
            if (next) { next.addEventListener('click', function () { show(i + 1); }); }
        });
    }

    /* --- Mobile performance selector -------------------------------------- */

    function setupSelector(root, panel, panelId, mq) {
        if (!panel) { return; }

        function applyMode() {
            if (mq.matches) {
                markTriggers(root, panelId, true);
            } else {
                markTriggers(root, panelId, false);
                clearSelection(root, panel); // hide panel + reset when leaving mobile
            }
        }

        // Activate a day: fill + reveal the panel, mark the day selected, move focus.
        function activate(cell) {
            if (!mq.matches || !cell) { return; }

            clearSelection(root, panel);
            cell.setAttribute('aria-expanded', 'true');
            cell.closest('.ttx-calendar__day').classList.add('is-selected');

            renderPanel(panel, cell);
            panel.hidden = false;

            var heading = panel.querySelector('.ttx-calendar__selector-date');
            if (heading && typeof heading.focus === 'function') {
                heading.focus({ preventScroll: false });
            }
        }

        root.addEventListener('click', function (event) {
            var cell = event.target.closest('[data-ttx-day]');
            // Let real inline links (desktop / no-mobile) behave normally.
            if (cell && mq.matches && !event.target.closest('.ttx-calendar__perf-link')) {
                activate(cell);
            }
        });

        root.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ' && event.key !== 'Spacebar') { return; }
            var cell = event.target.closest('[data-ttx-day]');
            if (cell && mq.matches && event.target === cell) {
                event.preventDefault();
                activate(cell);
            }
        });

        addMediaListener(mq, applyMode);
        applyMode();
    }

    // Toggle the day cells between plain divs and keyboard-operable buttons.
    function markTriggers(root, panelId, on) {
        var cells = root.querySelectorAll('[data-ttx-day]');
        Array.prototype.forEach.call(cells, function (cell) {
            if (on) {
                cell.setAttribute('role', 'button');
                cell.setAttribute('tabindex', '0');
                cell.setAttribute('aria-controls', panelId);
                cell.setAttribute('aria-expanded', 'false');
            } else {
                cell.removeAttribute('role');
                cell.removeAttribute('tabindex');
                cell.removeAttribute('aria-controls');
                cell.removeAttribute('aria-expanded');
            }
        });
    }

    function clearSelection(root, panel) {
        Array.prototype.forEach.call(root.querySelectorAll('[data-ttx-day][aria-expanded="true"]'), function (cell) {
            cell.setAttribute('aria-expanded', 'false');
        });
        Array.prototype.forEach.call(root.querySelectorAll('.ttx-calendar__day.is-selected'), function (day) {
            day.classList.remove('is-selected');
        });
        if (panel) {
            panel.hidden = true;
            panel.textContent = '';
        }
    }

    // Build the panel from the tapped cell's per-performance data attributes.
    function renderPanel(panel, cell) {
        panel.textContent = '';

        var heading = document.createElement('h4');
        heading.className = 'ttx-calendar__selector-date';
        heading.setAttribute('tabindex', '-1');
        heading.textContent = cell.getAttribute('data-day-label') || '';
        panel.appendChild(heading);

        var list = document.createElement('ul');
        list.className = 'ttx-calendar__selector-list';

        var perfs = cell.querySelectorAll('.ttx-calendar__perf');
        Array.prototype.forEach.call(perfs, function (perf) {
            list.appendChild(buildRow(perf));
        });

        panel.appendChild(list);
    }

    function buildRow(perf) {
        var state = perf.getAttribute('data-state') || '';
        var stateLabel = perf.getAttribute('data-state-label') || '';
        var time = perf.getAttribute('data-time') || '';
        var price = perf.getAttribute('data-price') || '';
        var buyUrl = perf.getAttribute('data-buy-url') || '';

        var row = document.createElement('li');
        row.className = 'ttx-calendar__selector-row is-' + state;

        var timeEl = document.createElement('span');
        timeEl.className = 'ttx-calendar__selector-time';
        timeEl.textContent = time;
        row.appendChild(timeEl);

        var meta = document.createElement('span');
        meta.className = 'ttx-calendar__selector-meta';
        var parts = [];
        if (stateLabel) { parts.push(stateLabel); }
        if (price) { parts.push('from ' + price); }
        meta.textContent = parts.join(' · ');
        row.appendChild(meta);

        if (buyUrl) {
            var buy = document.createElement('a');
            buy.className = 'ttx-calendar__selector-buy';
            buy.href = buyUrl;
            buy.target = '_blank';
            buy.rel = 'noopener';
            buy.textContent = 'Get tickets';
            buy.setAttribute('aria-label', 'Get tickets for ' + time + ' (opens in a new tab)');
            row.appendChild(buy);
        }

        return row;
    }

    /* --- helpers ---------------------------------------------------------- */

    function addMediaListener(mq, fn) {
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', fn);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(fn); // older Safari
        }
    }

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ttx-calendar]'), initCalendar);
    });
})();
