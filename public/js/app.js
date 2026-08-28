/* Junction Production Tracker — small vanilla-JS behaviours. No build step,
   no framework. Everything here is progressive enhancement: the application
   works with JavaScript switched off, this only adds the manners. */
(function () {
    'use strict';

    // --- Theme -------------------------------------------------------------
    // The initial theme is applied by an inline script in <head> so the page
    // never flashes. This handles the toggle and remembers the choice in both
    // localStorage (fast) and a cookie (survives cleared storage on iOS).
    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);

        try {
            localStorage.setItem('theme', theme);
        } catch (e) { /* private mode */ }

        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = 'theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax' + secure;

        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', theme === 'dark' ? '#0b1120' : '#ffffff');
        }

        updateThemeLabels(theme);
    }

    function updateThemeLabels(theme) {
        var label = theme === 'dark' ? 'Light mode' : 'Dark mode';
        document.querySelectorAll('[data-theme-label]').forEach(function (el) {
            el.textContent = label;
        });
        document.querySelectorAll('[data-theme-toggle]').forEach(function (el) {
            el.setAttribute('aria-label', 'Switch to ' + label.toLowerCase());
        });
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-theme-toggle]');
        if (toggle) {
            applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
        }
    });

    updateThemeLabels(currentTheme());

    // --- Mobile navigation -------------------------------------------------
    var navToggle = document.querySelector('[data-nav-toggle]');
    var nav = document.querySelector('[data-nav]');

    if (navToggle && nav) {
        navToggle.addEventListener('click', function () {
            var open = nav.classList.toggle('is-open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // Close the menu when tapping outside it, or on Escape.
        document.addEventListener('click', function (event) {
            if (!nav.classList.contains('is-open')) return;
            if (nav.contains(event.target) || navToggle.contains(event.target)) return;

            nav.classList.remove('is-open');
            navToggle.setAttribute('aria-expanded', 'false');
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                nav.classList.remove('is-open');
                navToggle.setAttribute('aria-expanded', 'false');
                navToggle.focus();
            }
        });
    }

    // --- Dismissable flash messages ----------------------------------------
    document.addEventListener('click', function (event) {
        var dismiss = event.target.closest('[data-dismiss]');
        if (dismiss && dismiss.parentElement) {
            dismiss.parentElement.remove();
        }
    });

    // --- Auto-hiding confirmations -----------------------------------------
    // Only the banners the server marked: confirmations, never warnings or
    // errors. The countdown pauses while the pointer is over the banner or the
    // keyboard is inside it — text that removes itself while it is being read
    // reads as a bug.
    (function () {
        var banners = document.querySelectorAll('[data-flash-autohide]');
        if (!banners.length) return;

        Array.prototype.forEach.call(banners, function (banner) {
            var delay = (parseInt(banner.getAttribute('data-flash-autohide'), 10) || 0) * 1000;
            if (delay <= 0) return;

            var timer = null;

            function hide() {
                banner.classList.add('is-leaving');
                window.setTimeout(function () {
                    if (banner.parentElement) banner.remove();
                }, 250);
            }

            function start() {
                stop();
                timer = window.setTimeout(hide, delay);
            }

            function stop() {
                if (timer) { window.clearTimeout(timer); timer = null; }
            }

            banner.addEventListener('mouseenter', stop);
            banner.addEventListener('mouseleave', start);
            banner.addEventListener('focusin', stop);
            banner.addEventListener('focusout', start);

            start();
        });
    })();

    // --- Show/hide password ------------------------------------------------
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-toggle-password]');
        if (!button) return;

        var input = document.getElementById(button.getAttribute('data-toggle-password'));
        if (!input) return;

        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.textContent = reveal ? 'Hide' : 'Show';
    });

    // --- Copy a read-only field to the clipboard ---------------------------
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        if (!button) return;

        var field = document.querySelector(button.getAttribute('data-copy'));
        if (!field) return;

        var original = button.textContent;
        var done = function () {
            button.textContent = 'Copied';
            window.setTimeout(function () { button.textContent = original; }, 2000);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(field.value).then(done, function () {});
            return;
        }

        field.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* nothing to fall back to */ }
    });

    // --- Free-issue relationship -------------------------------------------
    // The "how many" dropdown only makes sense once a direction is chosen, and
    // there is one of each per direction so the chosen number survives switching
    // back and forth. Without JavaScript both are visible and both submit — the
    // server reads whichever the chosen direction calls for, so the form still
    // works, it is just less tidy.
    document.addEventListener('change', function (event) {
        var select = event.target.closest('[data-fi-select]');
        if (!select) return;

        var divide = document.getElementById(select.getAttribute('data-fi-divide'));
        var multiply = document.getElementById(select.getAttribute('data-fi-multiply'));

        if (divide) divide.hidden = select.value !== 'divide';
        if (multiply) multiply.hidden = select.value !== 'multiply';
    });

    // Checkbox that shows or hides a block of fields — the free-issue toggle on
    // the part forms. Without JavaScript the block is simply always visible and
    // the checkbox still decides what gets saved, so the form keeps working.
    document.addEventListener('change', function (event) {
        var toggle = event.target.closest('[data-toggle-panel]');
        if (!toggle) return;

        var panel = document.getElementById(toggle.getAttribute('data-toggle-panel'));
        if (panel) panel.hidden = !toggle.checked;
    });

    /**
     * The free-issue check-in form.
     *
     * One question decides the shape of it: are the received parts correct?
     * "No" reveals the rejection rows, and the submit button stays out of reach
     * until every one of them has a quantity and a reason and the total does
     * not exceed what arrived.
     *
     * All of this is a courtesy to whoever is filling it in on a phone in the
     * goods-in bay — the controller enforces the same rules, because a disabled
     * button is not a control.
     */
    var checkinForm = document.querySelector('[data-checkin-form]');

    if (checkinForm) {
        var received = checkinForm.querySelector('[data-checkin-received]');
        var rejectionPanel = checkinForm.querySelector('[data-checkin-rejections]');
        var rejectRows = checkinForm.querySelector('[data-reject-rows]');
        var submit = checkinForm.querySelector('[data-checkin-submit]');
        var errorLine = checkinForm.querySelector('[data-checkin-error]');
        var echo = checkinForm.querySelector('[data-checkin-echo]');

        function chosenAnswer() {
            var picked = checkinForm.querySelector('[data-checkin-correct]:checked');
            return picked ? picked.value : '';
        }

        function rejectionRows() {
            return Array.prototype.slice.call(rejectRows.querySelectorAll('.reject-row'));
        }

        function receivedQty() {
            return parseInt(received.value, 10) || 0;
        }

        /** @return {string} the reason this cannot be submitted, or '' if it can. */
        function problem() {
            if (receivedQty() <= 0) return 'Enter how many were received.';

            var answer = chosenAnswer();
            if (answer === '') return '';
            if (answer === 'yes') return '';

            var rows = rejectionRows();
            var total = 0;
            var incomplete = false;

            rows.forEach(function (row) {
                var qty = parseInt(row.querySelector('[data-reject-qty]').value, 10) || 0;
                var reason = row.querySelector('[data-reject-reason]').value.trim();

                if (qty <= 0 || reason === '') {
                    incomplete = true;
                    return;
                }
                total += qty;
            });

            if (incomplete) return 'Every rejected entry needs both a quantity and a reason.';
            if (total === 0) return 'Enter what was rejected.';
            if (total > receivedQty()) {
                return 'You cannot reject ' + total + ' out of ' + receivedQty() + ' received.';
            }

            return '';
        }

        function refresh() {
            var answer = chosenAnswer();
            rejectionPanel.hidden = answer !== 'no';

            if (echo) {
                echo.textContent = receivedQty() > 0 ? String(receivedQty()) : 'of them';
            }

            var reason = problem();
            // No answer chosen yet is not an error to show, just a reason to
            // wait: nothing has gone wrong, the form is simply unfinished.
            var ready = answer !== '' && reason === '';

            submit.disabled = !ready;

            if (errorLine) {
                var showable = answer === 'no' && reason !== '' && receivedQty() > 0;
                errorLine.hidden = !showable;
                errorLine.textContent = showable ? reason : '';
            }
        }

        function syncRemoveButtons() {
            var rows = rejectionRows();
            rows.forEach(function (row) {
                var button = row.querySelector('[data-reject-remove]');
                if (button) button.hidden = rows.length < 2;
            });
        }

        checkinForm.addEventListener('input', refresh);
        checkinForm.addEventListener('change', refresh);

        checkinForm.addEventListener('click', function (event) {
            if (event.target.closest('[data-reject-add]')) {
                var template = rejectionRows()[0];
                var copy = template.cloneNode(true);

                copy.querySelectorAll('input').forEach(function (input) { input.value = ''; });
                rejectRows.appendChild(copy);
                syncRemoveButtons();
                refresh();
                return;
            }

            var remove = event.target.closest('[data-reject-remove]');
            if (remove && rejectionRows().length > 1) {
                remove.closest('.reject-row').remove();
                syncRemoveButtons();
                refresh();
            }
        });

        syncRemoveButtons();
        refresh();
    }

    /**
     * Generic AJAX search combobox. Markup:
     *   <div data-combobox data-url="/search-endpoint">
     *     <input type="text" data-combobox-input placeholder="Search...">
     *     <div class="combobox-results" data-combobox-results></div>
     *   </div>
     * The endpoint must return JSON {results: [{id, cpn, name}, ...]}.
     * Selecting a result fires a 'combobox:select' CustomEvent on the
     * [data-combobox] element with detail = that result object, then clears
     * the input — the page wires up what "select" means (add a row, link a
     * part, and so on).
     */
    var comboboxTimers = new WeakMap();

    function renderComboboxResults(box, results) {
        var container = box.querySelector('[data-combobox-results]');
        container.innerHTML = '';

        if (results.length === 0) {
            container.classList.remove('open');
            return;
        }

        results.forEach(function (item) {
            var row = document.createElement('button');
            row.type = 'button';
            row.className = 'combobox-item';

            var title = document.createElement('strong');
            title.textContent = item.cpn;
            row.appendChild(title);

            var name = document.createElement('span');
            name.className = 'cell-sub';
            name.textContent = item.name;
            row.appendChild(name);

            // Flagged before it is picked as well as after: somebody scanning a
            // list of parts to order should see it without having to add one.
            if (item.price_under_review) {
                var warning = document.createElement('span');
                warning.className = 'cell-sub cell-warn';
                warning.textContent = 'Price under review';
                row.appendChild(warning);
            }

            row.addEventListener('click', function () {
                box.dispatchEvent(new CustomEvent('combobox:select', { detail: item }));
                box.querySelector('[data-combobox-input]').value = '';
                container.innerHTML = '';
                container.classList.remove('open');
            });
            container.appendChild(row);
        });

        container.classList.add('open');
    }

    document.addEventListener('input', function (event) {
        var input = event.target.closest('[data-combobox-input]');
        if (!input) {
            return;
        }

        var box = input.closest('[data-combobox]');
        var url = box.getAttribute('data-url');
        var query = input.value.trim();

        clearTimeout(comboboxTimers.get(box));

        if (query.length < 2) {
            renderComboboxResults(box, []);
            return;
        }

        var timer = setTimeout(function () {
            fetch(url + '?q=' + encodeURIComponent(query), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) { return response.ok ? response.json() : { results: [] }; })
                .then(function (data) { renderComboboxResults(box, data.results || []); })
                .catch(function () { renderComboboxResults(box, []); });
        }, 250);

        comboboxTimers.set(box, timer);
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-combobox]')) {
            return;
        }
        document.querySelectorAll('[data-combobox-results]').forEach(function (el) {
            el.innerHTML = '';
            el.classList.remove('open');
        });
    });
})();

/* Menu groups.
 *
 * One <details> per group drives both layouts: below the bar's breakpoint it is
 * an accordion inside the drawer, above it the same element is a drop-down.
 * Only one drop-down at a time on a desktop — two panels open over the page at
 * once is a menu that has lost track of itself. On a phone they stay as a plain
 * accordion, where leaving one open is the helpful behaviour. */
(function () {
    'use strict';

    var groups = document.querySelectorAll('[data-nav-group]');
    if (!groups.length) return;

    // Must match the horizontal bar's breakpoint in app.css.
    var desktop = window.matchMedia('(min-width: 1150px)');

    function closeAll(except) {
        Array.prototype.forEach.call(groups, function (group) {
            if (group !== except) group.open = false;
        });
    }

    Array.prototype.forEach.call(groups, function (group) {
        group.addEventListener('toggle', function () {
            if (group.open && desktop.matches) closeAll(group);
        });
    });

    // A group carrying data-nav-autoopen was opened by the server to show which
    // section the current page belongs to, not because anyone asked for it. On
    // a phone that is an accordion and is exactly what we want. On a desktop the
    // panel floats over the page, so the stylesheet hides it — but the element
    // is still `open`, which would make the first click *close* it. So on
    // desktop, close it properly and drop the attribute.
    function normaliseAutoOpen() {
        if (!desktop.matches) return;

        Array.prototype.forEach.call(groups, function (group) {
            if (!group.hasAttribute('data-nav-autoopen')) return;
            group.open = false;
            group.removeAttribute('data-nav-autoopen');
        });
    }

    normaliseAutoOpen();

    if (desktop.addEventListener) {
        desktop.addEventListener('change', normaliseAutoOpen);
    } else if (desktop.addListener) {
        desktop.addListener(normaliseAutoOpen);   // Safari < 14
    }

    Array.prototype.forEach.call(groups, function (group) {
        group.addEventListener('click', function () {
            group.removeAttribute('data-nav-autoopen');
        });
    });

    // --- Open on hover -----------------------------------------------------
    // Click still opens and closes them; this only adds the manner a desktop
    // drop-down is expected to have. Two delays, both there to stop it feeling
    // twitchy: a short one before opening, so dragging the pointer across the
    // bar does not flash three menus open, and a longer one before closing, so
    // the diagonal from the summary to the item you are aiming at does not shut
    // the panel under your cursor.
    var hoverCapable = window.matchMedia('(hover: hover) and (pointer: fine)');
    var openTimer = null;
    var closeTimer = null;

    // How long after a hover-open a click on the *summary* is ignored: a click
    // aimed at an item inside can land on the summary on the way past, which the
    // browser reads as "close this".
    var HOVER_CLICK_GRACE_MS = 2000;

    function hoverEnabled() {
        return desktop.matches && hoverCapable.matches;
    }

    function cancelTimers() {
        if (openTimer) { window.clearTimeout(openTimer); openTimer = null; }
        if (closeTimer) { window.clearTimeout(closeTimer); closeTimer = null; }
    }

    Array.prototype.forEach.call(groups, function (group) {
        var hoverOpenedAt = 0;

        group.addEventListener('mouseenter', function () {
            if (!hoverEnabled()) return;

            cancelTimers();

            if (group.open) return;

            openTimer = window.setTimeout(function () {
                group.removeAttribute('data-nav-autoopen');
                group.open = true;
                hoverOpenedAt = Date.now();
            }, 140);
        });

        var summary = group.querySelector('summary');

        if (summary) {
            summary.addEventListener('click', function (event) {
                if (!hoverEnabled() || !group.open) return;
                if (event.detail === 0) return;   // Enter/Space on a focused summary
                if (Date.now() - hoverOpenedAt >= HOVER_CLICK_GRACE_MS) return;

                event.preventDefault();
            });
        }

        group.addEventListener('toggle', function () {
            if (!group.open) hoverOpenedAt = 0;
        });

        group.addEventListener('mouseleave', function () {
            if (!hoverEnabled()) return;

            cancelTimers();

            closeTimer = window.setTimeout(function () {
                // Not while the keyboard is inside it.
                if (group.contains(document.activeElement)) return;

                group.open = false;
            }, 260);
        });

        group.addEventListener('click', cancelTimers);
    });

    document.addEventListener('click', function (event) {
        if (!desktop.matches) return;
        if (event.target.closest('[data-nav-group]')) return;
        closeAll(null);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !desktop.matches) return;

        var open = document.querySelector('[data-nav-group][open]');
        if (!open) return;

        open.open = false;
        var summary = open.querySelector('summary');
        if (summary) summary.focus();
    });
})();

/* ---------------------------------------------------------------------------
 * Is this part number already taken?
 *
 * Asked while somebody types it into the new-part form, because finding out
 * after filling the whole thing in — and losing the drawing they attached — is
 * the version of this that wastes an afternoon. A CPN is unique per client, so
 * the staff form has to say which client it is asking about, and re-ask when
 * that changes.
 *
 * Advisory. The server refuses a duplicate whatever this says; the submit
 * button being disabled is a courtesy to the person typing, not a control.
 * ------------------------------------------------------------------------- */
(function () {
    var input = document.querySelector('[data-cpn-check]');
    if (!input) return;

    var endpoint = input.getAttribute('data-cpn-check');
    var status = document.querySelector('[data-cpn-status]');
    var form = input.form;
    var clientSelect = input.getAttribute('data-cpn-client')
        ? document.querySelector(input.getAttribute('data-cpn-client'))
        : null;

    if (!status || !form) return;

    var submits = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    var timer = null;
    // Every request carries a sequence number and only the newest is allowed to
    // write to the page. Without it a slow "taken" can land after a later
    // "free" and leave the form disabled over a name that is fine.
    var sequence = 0;

    function setSubmitDisabled(disabled) {
        Array.prototype.forEach.call(submits, function (button) {
            button.disabled = disabled;
        });
    }

    function clear() {
        // Bumping the sequence abandons anything already in flight. Without
        // it, emptying the field while a slow "taken" is on its way lets that
        // answer land afterwards and disable the form over a box with nothing
        // in it.
        sequence++;
        status.textContent = '';
        status.className = 'cpn-status';
        setSubmitDisabled(false);
    }

    function showFree() {
        status.className = 'cpn-status cpn-status-ok';
        status.textContent = '\u2713 That part number is free.';
        setSubmitDisabled(false);
    }

    function showTaken(part) {
        status.className = 'cpn-status cpn-status-taken';
        status.textContent = '';

        var text = document.createElement('span');
        text.textContent = '\u26A0 ' + part.cpn + ' is already used by '
            + part.name + (part.archived ? ' (archived)' : '') + '. ';
        status.appendChild(text);

        var link = document.createElement('a');
        link.href = part.url;
        link.textContent = 'Open that part';
        status.appendChild(link);

        var note = document.createElement('span');
        note.textContent = ' \u2014 or change the number above to carry on.';
        status.appendChild(note);

        setSubmitDisabled(true);
    }

    function check() {
        var cpn = input.value.trim();

        if (cpn === '') {
            clear();
            return;
        }

        var url = endpoint + '?cpn=' + encodeURIComponent(cpn);

        if (clientSelect) {
            if (!clientSelect.value) {
                clear();
                return;
            }
            url += '&client_id=' + encodeURIComponent(clientSelect.value);
        }

        var mine = ++sequence;

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (mine !== sequence || !data) return;
                if (data.available === true) showFree();
                else if (data.available === false && data.part) showTaken(data.part);
                else clear();
            })
            .catch(function () {
                // A check that cannot run must not block the form. The server
                // still refuses a duplicate.
                if (mine === sequence) clear();
            });
    }

    function schedule() {
        window.clearTimeout(timer);
        timer = window.setTimeout(check, 350);
    }

    input.addEventListener('input', schedule);
    input.addEventListener('blur', check);
    if (clientSelect) clientSelect.addEventListener('change', check);

    // A value can already be here: the form comes back populated when a save
    // was rejected, and that is exactly when the number is likely to be taken.
    if (input.value.trim() !== '') check();
})();

/* ---------------------------------------------------------------------------
 * Order lines are disclosures, so anything pointing at one has to open it.
 *
 * The QR code printed on a route card links to `/staff/orders/12#line-34`.
 * Somebody scans it at the machine and lands on the order — and would land on a
 * closed card, having asked for that specific line. So the fragment opens the
 * card it names and scrolls to it, on load and on every later hash change.
 *
 * Printing gets the same treatment for the same reason: a closed <details>
 * prints as its summary, and a printed order page that is nothing but eight
 * headings is not the page anybody meant to print. Cards opened for the
 * printer are closed again afterwards, so the screen is as it was left.
 * ------------------------------------------------------------------------- */
(function () {
    function openFromHash() {
        if (!window.location.hash) return;

        var target;
        try {
            target = document.querySelector(window.location.hash);
        } catch (e) {
            // A hash that is not a usable selector — nothing to open.
            return;
        }

        var card = target && target.closest ? target.closest('details') : null;
        while (card) {
            card.open = true;
            card = card.parentElement ? card.parentElement.closest('details') : null;
        }

        if (target && target.scrollIntoView) target.scrollIntoView();
    }

    openFromHash();
    window.addEventListener('hashchange', openFromHash);

    var openedForPrint = [];

    window.addEventListener('beforeprint', function () {
        openedForPrint = [];
        document.querySelectorAll('[data-line-card]:not([open])').forEach(function (card) {
            openedForPrint.push(card);
            card.open = true;
        });
    });

    window.addEventListener('afterprint', function () {
        openedForPrint.forEach(function (card) { card.open = false; });
        openedForPrint = [];
    });
})();

/* ---------------------------------------------------------------------------
 * Searching and paging the parts list.
 *
 * The form is an ordinary GET form and the page links are ordinary links, so
 * the list works with the script switched off — it just reloads the page. With
 * the script, both ask for the results region on its own and swap it in.
 *
 * The server renders that region from the same partial either way, so there is
 * no second copy of the table living in JavaScript.
 * ------------------------------------------------------------------------- */
(function () {
    var form = document.querySelector('[data-parts-search]');
    var results = document.querySelector('[data-parts-results]');
    if (!form || !results) return;

    var input = form.querySelector('[data-parts-query]');
    var filters = form.querySelectorAll('[data-parts-filter]');
    var submit = form.querySelector('[data-parts-submit]');
    var timer = null;
    // As with the part-number check: only the newest answer may write to the
    // page, or a slow early keystroke lands on top of a later one.
    var sequence = 0;

    // The button is for people without JavaScript. With it, the list is already
    // keeping up with the typing and a button to press is a button that looks
    // like it needs pressing.
    if (submit) submit.hidden = true;

    function currentUrl() {
        var params = new URLSearchParams();
        new FormData(form).forEach(function (value, key) {
            if (String(value).trim() !== '') params.append(key, value);
        });
        var query = params.toString();

        return form.getAttribute('action') + (query ? '?' + query : '');
    }

    function load(url, push) {
        var mine = ++sequence;
        results.setAttribute('aria-busy', 'true');

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.ok ? response.text() : null; })
            .then(function (html) {
                if (mine !== sequence) return;
                results.removeAttribute('aria-busy');
                if (html === null) return;

                results.innerHTML = html;
                // Typing replaces the entry rather than adding one, so Back
                // leaves the list rather than walking the keystrokes. Paging is
                // a step somebody may well want to come back from.
                if (push) window.history.pushState({ parts: true }, '', url);
                else window.history.replaceState({ parts: true }, '', url);
            })
            .catch(function () {
                // Leave whatever is on screen: a stale list of parts is more
                // use than an empty one, and the form still submits.
                if (mine === sequence) results.removeAttribute('aria-busy');
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        window.clearTimeout(timer);
        load(currentUrl(), false);
    });

    if (input) {
        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { load(currentUrl(), false); }, 300);
        });
    }

    Array.prototype.forEach.call(filters, function (filter) {
        filter.addEventListener('change', function () {
            window.clearTimeout(timer);
            load(currentUrl(), false);
        });
    });

    // The page links are inside the region that gets replaced, so this is
    // delegated from the container rather than bound to the links themselves.
    results.addEventListener('click', function (event) {
        var link = event.target.closest('.pagination a');
        if (!link || link.getAttribute('aria-disabled') === 'true') return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;

        event.preventDefault();
        load(link.getAttribute('href'), true);
        results.scrollIntoView({ block: 'start' });
    });

    window.addEventListener('popstate', function (event) {
        if (!event.state || !event.state.parts) return;
        load(window.location.pathname + window.location.search, false);
    });
})();

/* ---------------------------------------------------------------------------
 * The row editor: a popup holding a list of rows that add up.
 *
 * Four things on the part page share it — the two build times, the draft quote
 * lines, and the price breaks — so "add a row" behaves identically in all of
 * them. See templates/partials/row-editor.php for the markup.
 *
 * Everything here is an improvement on a form that already works. Without the
 * script the dialog is shown in the flow by a <noscript> rule, the spare row
 * on the end is how a row gets added, and the server does the arithmetic. So
 * nothing below is allowed to be the only way to do anything.
 * ------------------------------------------------------------------------- */
(function () {
    var editors = document.querySelectorAll('[data-row-editor]');
    if (editors.length === 0) return;

    // The trigger buttons are useless without this script, and the dialogs are
    // only hidden by the user agent because it is here. Both facts are settled
    // on load rather than guessed at in CSS.
    document.querySelectorAll('[data-row-editor-open]').forEach(function (button) {
        button.hidden = false;
    });

    function rowsOf(editor) {
        return Array.prototype.slice.call(editor.querySelectorAll('[data-row]'));
    }

    function formatMinutes(total) {
        if (total <= 0) return '—';
        if (total < 60) return total + ' min';
        var hours = Math.floor(total / 60);
        var rest = total % 60;
        return rest === 0 ? hours + ' h' : hours + ' h ' + rest + ' min';
    }

    function formatMoney(total) {
        // Matches format_money() on the server: the same figure should not
        // change shape when the page reloads.
        return '£' + total.toFixed(2);
    }

    function refresh(editor) {
        var output = editor.querySelector('[data-row-total]');
        var rows = rowsOf(editor);

        // A single row has nothing to be removed down to, so its remove button
        // would be a button that does nothing.
        rows.forEach(function (row) {
            var remove = row.querySelector('[data-row-remove]');
            if (remove) remove.hidden = rows.length < 2;
        });

        if (!output) return;

        var total = 0;
        editor.querySelectorAll('[data-row-amount]').forEach(function (input) {
            var value = parseFloat(input.value);
            if (!isNaN(value)) total += value;
        });

        var format = editor.getAttribute('data-row-total-format');
        output.textContent = format === 'minutes'
            ? formatMinutes(Math.round(total))
            : (format === 'money' ? formatMoney(total) : String(total));
    }

    function isEmpty(row) {
        return Array.prototype.every.call(row.querySelectorAll('input'), function (input) {
            return input.value.trim() === '';
        });
    }

    function addRow(editor) {
        var rows = rowsOf(editor);
        var last = rows[rows.length - 1];

        // The server always renders one spare row on the end, so the first
        // press of "Add a row" would otherwise leave that spare stranded in
        // the middle of the list with a new empty row under it. There is
        // already somewhere to type; go there.
        if (isEmpty(last)) {
            var existing = last.querySelector('input');
            if (existing) existing.focus();
            return;
        }

        var copy = last.cloneNode(true);

        copy.querySelectorAll('input').forEach(function (input) { input.value = ''; });
        // The cloned labels and ids would be duplicates, and a label pointing
        // at the wrong box is worse than no label. Uniqueness only has to hold
        // within the page, so the clock is enough.
        var stamp = Date.now() + '_' + rows.length;
        copy.querySelectorAll('input').forEach(function (input) {
            var old = input.id;
            input.id = old + '_' + stamp;
            var label = copy.querySelector('label[for="' + old + '"]');
            if (label) label.setAttribute('for', input.id);
        });

        editor.querySelector('[data-rows]').appendChild(copy);
        refresh(editor);

        var first = copy.querySelector('input');
        if (first) first.focus();
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-row-editor-open]');
        if (opener) {
            var target = document.getElementById(opener.getAttribute('data-row-editor-open'));
            if (target) {
                refresh(target);
                if (typeof target.showModal === 'function') target.showModal();
                else target.setAttribute('open', '');
            }
            return;
        }

        var closer = event.target.closest('[data-row-editor-close]');
        if (closer) {
            var dialog = closer.closest('[data-row-editor]');
            // Closing is abandoning: nothing was posted, so the page behind is
            // still right and reopening re-reads it.
            if (dialog) {
                if (typeof dialog.close === 'function') dialog.close();
                else dialog.removeAttribute('open');
            }
            return;
        }

        var add = event.target.closest('[data-row-add]');
        if (add) {
            addRow(add.closest('[data-row-editor]'));
            return;
        }

        var remove = event.target.closest('[data-row-remove]');
        if (remove) {
            var editor = remove.closest('[data-row-editor]');
            if (rowsOf(editor).length > 1) {
                remove.closest('[data-row]').remove();
                refresh(editor);
            }
        }
    });

    document.addEventListener('input', function (event) {
        var editor = event.target.closest('[data-row-editor]');
        if (editor) refresh(editor);
    });

    editors.forEach(function (editor) { refresh(editor); });
})();
