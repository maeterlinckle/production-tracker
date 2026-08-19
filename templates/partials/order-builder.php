<?php
/**
 * The order form: search for parts, set quantities, attach the purchase order.
 *
 * Shared between the client placing their own order and Junction placing one on
 * their behalf. The two differ only in where the form posts, which parts the
 * search returns, and whose purchase order number is being asked for — so they
 * are parameters, not a second copy of two hundred lines of form.
 *
 * @var string      $action        where the form posts
 * @var string      $searchUrl     AJAX endpoint for the part combobox
 * @var string      $linkedUrlBase base for the "usually ordered with" lookup
 * @var string      $cancelUrl
 * @var array|null  $preselectPart a part to start with, from a "Order this" link
 * @var string      $poNumberHint
 * @var string      $submitLabel
 */
$preselectPart = $preselectPart ?? null;
$poNumberHint = $poNumberHint ?? 'It goes on the invoice, so it is worth getting right.';
$submitLabel = $submitLabel ?? 'Place order';
?>
<form method="post" action="<?= url($action) ?>" enctype="multipart/form-data" id="order-form">
    <?= csrf_field() ?>
    <div id="order-line-inputs"></div>

    <div class="card">
        <h2 class="mt-0">Parts</h2>
        <p class="text-muted">Search by CPN or name to add a part. You can add as many as you need.</p>

        <div class="combobox" data-combobox data-url="<?= url($searchUrl) ?>">
            <input type="text" data-combobox-input placeholder="Search by CPN or name…">
            <div class="combobox-results" data-combobox-results></div>
        </div>

        <div id="link-suggestion" class="flash flash-info" style="display:none; margin-top: var(--space-4)">
            <span id="link-suggestion-text"></span>
        </div>

        <div class="table-wrap" style="margin-top: var(--space-4)">
            <table>
                <thead>
                    <tr><th>CPN</th><th>Name</th><th>Quantity</th><th>Free-issue qty required</th><th></th></tr>
                </thead>
                <tbody id="order-lines-body">
                    <tr id="order-lines-empty"><td colspan="5" class="text-muted">No parts added yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 class="mt-0">Purchase order</h2>
        <div class="field">
            <label for="po_number">Purchase order number</label>
            <input type="text" id="po_number" name="po_number" required maxlength="60">
            <div class="hint"><?= e($poNumberHint) ?></div>
        </div>
        <div class="field">
            <label for="po">Purchase order document</label>
            <input type="file" id="po" name="po" required>
            <div class="hint">PDF, image or Word document, up to 15 MB.</div>
        </div>
        <div class="field">
            <label for="notes">Notes (optional)</label>
            <textarea id="notes" name="notes"></textarea>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary" id="order-submit" disabled><?= e($submitLabel) ?></button>
        <a href="<?= url($cancelUrl) ?>" class="btn">Cancel</a>
    </div>
</form>

<script>
(function () {
    'use strict';

    var linkedUrlBase = <?= json_encode(url($linkedUrlBase)) ?>;

    var lines = {};
    var body = document.getElementById('order-lines-body');
    var emptyRow = document.getElementById('order-lines-empty');
    var inputsHost = document.getElementById('order-line-inputs');
    var submitBtn = document.getElementById('order-submit');
    var suggestionBox = document.getElementById('link-suggestion');
    var suggestionText = document.getElementById('link-suggestion-text');

    function freeIssueQty(part, orderQty) {
        var factor = Math.max(1, part.free_issue_factor || 1);
        if (part.free_issue_relationship === 'divide') {
            return Math.ceil(orderQty / factor);
        }
        if (part.free_issue_relationship === 'multiply') {
            return orderQty * factor;
        }
        return orderQty;
    }

    function renderInputs() {
        inputsHost.innerHTML = '';
        Object.keys(lines).forEach(function (id) {
            var line = lines[id];
            ['part_id', 'qty', 'free_issue_qty'].forEach(function (field) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = field + '[]';
                input.value = field === 'part_id' ? line.part.id : (field === 'qty' ? line.qty : line.freeIssueQty);
                inputsHost.appendChild(input);
            });
        });
        submitBtn.disabled = Object.keys(lines).length === 0;
    }

    function renderRow(id) {
        var line = lines[id];
        var part = line.part;
        var row = document.getElementById('row-' + id);
        if (!row) {
            row = document.createElement('tr');
            row.id = 'row-' + id;
            body.appendChild(row);
        }

        // Worked out from the part's own ratio, and shown rather than asked for.
        // It can be raised — sending extra material is a real thing to do — but
        // not lowered: the server recalculates and takes whichever is larger, so
        // a smaller number here would be quietly ignored, and a field that
        // discards what you type into it is worse than one you cannot.
        var fiCell = '';
        if (part.has_free_issue) {
            fiCell = '<input type="number" min="' + line.freeIssueQty + '" class="fi-input" style="width:90px"'
                + ' value="' + line.freeIssueQty + '" data-fi-minimum="' + line.freeIssueQty + '">';
        } else {
            fiCell = '<span class="text-muted">—</span>';
        }

        // The price warning goes here, beside the quantity box, because this is
        // the last moment before somebody commits to a number. On the part page
        // it is a note; here it is the thing they are about to act on.
        var priceNote = part.price_under_review
            ? '<div class="cell-sub cell-warn">Price under review — may change on this order</div>'
            : '';

        row.innerHTML =
            '<td>' + escapeHtml(part.cpn) + priceNote + '</td>' +
            '<td class="wrap">' + escapeHtml(part.name) + '</td>' +
            '<td><input type="number" min="1" class="qty-input" style="width:90px" value="' + line.qty + '"></td>' +
            '<td>' + fiCell + '</td>' +
            '<td><button type="button" class="btn btn-sm" data-remove>Remove</button></td>';

        row.querySelector('.qty-input').addEventListener('input', function () {
            var qty = Math.max(1, parseInt(this.value, 10) || 1);
            line.qty = qty;
            if (part.has_free_issue) {
                line.freeIssueQty = freeIssueQty(part, qty);
                var fiInput = row.querySelector('.fi-input');
                if (fiInput) {
                    fiInput.value = line.freeIssueQty;
                    fiInput.min = line.freeIssueQty;
                    fiInput.setAttribute('data-fi-minimum', line.freeIssueQty);
                }
            }
            renderInputs();
        });

        var fiInput = row.querySelector('.fi-input');
        if (fiInput) {
            fiInput.addEventListener('input', function () {
                var minimum = parseInt(this.getAttribute('data-fi-minimum'), 10) || 0;
                line.freeIssueQty = Math.max(minimum, parseInt(this.value, 10) || 0);
                renderInputs();
            });
        }

        row.querySelector('[data-remove]').addEventListener('click', function () {
            delete lines[id];
            row.remove();
            if (Object.keys(lines).length === 0) {
                emptyRow.style.display = '';
            }
            renderInputs();
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function addPart(part, qty) {
        qty = qty || 1;
        if (lines[part.id]) {
            return;
        }
        emptyRow.style.display = 'none';
        lines[part.id] = { part: part, qty: qty, freeIssueQty: part.has_free_issue ? freeIssueQty(part, qty) : 0 };
        renderRow(part.id);
        renderInputs();
        showLinkedSuggestions(part.id);
    }

    function showLinkedSuggestions(partId) {
        fetch(linkedUrlBase + '/' + partId + '/linked-summary', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : { results: [] }; })
            .then(function (data) {
                var candidates = (data.results || []).filter(function (p) { return !lines[p.id]; });
                if (candidates.length === 0) {
                    suggestionBox.style.display = 'none';
                    return;
                }
                suggestionText.textContent = 'Usually ordered with: ';
                candidates.forEach(function (p) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-sm';
                    btn.style.marginLeft = '6px';
                    btn.textContent = 'Add ' + p.cpn;
                    btn.addEventListener('click', function () {
                        addPart(p, 1);
                        btn.disabled = true;
                    });
                    suggestionText.appendChild(btn);
                });
                suggestionBox.style.display = 'flex';
            })
            .catch(function () { suggestionBox.style.display = 'none'; });
    }

    document.querySelector('[data-combobox]').addEventListener('combobox:select', function (e) {
        addPart(e.detail, 1);
    });

    <?php if ($preselectPart !== null): ?>
    addPart(<?= json_encode($preselectPart) ?>, 1);
    <?php endif; ?>
})();
</script>
