# Project state — a factual snapshot

What exists in this application right now: tables and columns actually in use,
every route, the conventions to follow, the quantity state machine, the shared
components, the permission model, and the navigation.

**This is a snapshot, not a history.** Nothing here says what changed or when.
For the reasoning behind a decision — why a page is at two URLs, why the route
card reads figures rather than computing them, what the Clear Books verification
found — see [`../PROJECT_STATE.md`](../PROJECT_STATE.md) in the repository root,
which is the narrative record.

Regenerate the mechanical parts of this file from the running application rather
than editing them by hand where you can: the schema from `information_schema`,
the routes from the router, the capabilities from `Capabilities::MATRIX`.

---

## 1. Stack and layout

Plain PHP 8.1+ and MariaDB. No framework, no build step, no JavaScript
toolchain, no CSS preprocessor. Three runtime packages: PHPMailer, dompdf,
endroid/qr-code.

```
bin/          console.php (all DB-touching admin), migrate.php, create-admin.php
config/       config.php — the only place .env is read
database/     migrations/ — numbered .sql, forward-only, tracked in `migrations`
public/       index.php (entry point), css/app.css, js/app.js, favicon.svg
routes/       web.php — every route, in two middleware groups
src/Core/     Router, Auth, Capabilities, Database, View, Csrf, Session,
              Request, Response, Upload, Image, Validator, Config, Env, Flash,
              Crypto, LoginThrottle, Migrator, NotificationTypes
src/Models/   one per table-ish; static methods, no ORM
src/Services/ multi-model work and anything talking to the outside world
templates/    plain PHP views; partials/ for shared fragments
storage/      logs/ and uploads/ (subdirectories are created on demand)
```

PSR-4: `App\` → `src/`.

---

## 2. Conventions

**Database.** PDO with `ATTR_EMULATE_PREPARES => false`; named placeholders
everywhere. `Database::query()` converts PHP booleans to 0/1 before binding —
`execute()` binds everything as a string and a bound `false` reaches MariaDB as
`''`, which strict mode rejects. Direct `$pdo->prepare()` inside a
`Database::transaction()` closure does **not** get that conversion, so write
`? 1 : 0` there.

`Database::transaction()` is re-entrant: it joins an open transaction rather
than nesting, because PDO cannot nest. A model method that opens its own is safe
to call from inside another.

**Migrations** are forward-only, numbered, and re-runnable where MariaDB allows
it (`IF [NOT] EXISTS`, and constraint existence checked via `information_schema`
before `ALTER`). `005` is the one exception and says so in its header.

**Derived columns are recalculated, never incremented.** `OrderLine::recalculateTotals()`
rewrites every cached total from the distribution after each write.

**Views** are plain PHP. `e()` escapes, `url()` builds paths, `partial()`
includes a fragment, `csrf_field()` emits the token. `View::render($template,
$data, $layout)`; `View::capture()` returns a string.
`View::capture($template, $data, null)` renders with no layout at all, which is
how an AJAX request is answered with the same partial the full page uses.

**Permissions are enforced twice on purpose:** the template hides what somebody
cannot change, and the service or controller refuses it. A hidden field is not a
locked one.

**Pricing is omitted, not disabled.** Anything carrying a price is gated on
`view_pricing` where the payload is assembled, so it is absent from the response
rather than hidden with CSS. `Notifications` refuses to send price-bearing email
to a recipient without it.

**Comments explain why, not what**, in plain British English.

**Kitwell** (the sibling asset register) is never a dependency, submodule or
shared include. Patterns may be copied; code may not.

---

## 3. Schema

43 tables, migrations `001`–`015` applied. Primary keys in bold.

| Table | Columns |
|---|---|
| `clearbooks_tokens` | **id**, access_token, refresh_token, expires_at, updated_at |
| `clients` | **id**, name, clearbooks_entity_id, address_line1, address_line2, address_city, address_county, address_postcode, address_country, main_contact_name, main_contact_email, main_contact_phone, billing_email, vat_number, company_number, clearbooks_synced_at, clearbooks_synced_by, notes, is_active, deactivated_at, deactivated_by, deactivated_reason, created_at, updated_at |
| `delivery_notes` | **id**, type, client_id, related_note_id, reference, pdf_file_path, issued_by, issued_at, invoiced, invoiced_at, notes, created_at |
| `delivery_note_lines` | **id**, delivery_note_id, order_line_id, qty |
| `email_log` | **id**, to_email, subject, template_key, related_type, related_id, status, error, sent_at |
| `email_templates` | **id**, template_key, subject, body, is_html, is_active, updated_by, updated_at |
| `free_issue_receipts` | **id**, order_line_id, qty_received, discrepancy_type, discrepancy_notes, resolved_at, resolved_by, received_at, received_by, notes, created_at |
| `free_issue_rejections` | **id**, order_line_id, qty_rejected, reason, return_note_id, replacement_note_id, rejected_by, rejected_at |
| `invoices` | **id**, delivery_note_id, source, clearbooks_invoice_id, clearbooks_invoice_number, amount, raised_by, raised_at, notes |
| `login_attempts` | **id**, email, ip_address, succeeded, attempted_at |
| `migrations` | **id**, migration, batch, executed_at |
| `notification_preferences` | **user_id**, **notification_type** |
| `orders` | **id**, client_id, order_number, po_number, po_file_path, po_original_filename, placed_by, placed_at, notes, created_at, updated_at, closed_at, closed_by, close_reason |
| `order_lines` | **id**, order_id, part_id, line_no, qty_ordered, unit_price, qty_free_issue_required, qty_free_issue_received, qty_free_issue_rejected, qty_completed, qty_delivered, qty_invoiced, qty_failed, qty_cancelled, notes, created_at, updated_at, closed_at, closed_by, close_reason |
| `order_line_change_requests` | **id**, order_line_id, initiated_by, qty_at_request, qty_requested, reason, status, requested_by, requested_at, reviewed_by, reviewed_at, review_notes |
| `order_line_due_dates` | **id**, order_line_id, qty, due_date, note, set_by, set_at |
| `order_line_quantities` | **order_line_id**, **stage**, qty, updated_at |
| `order_line_stage_moves` | **id**, order_line_id, from_stage, to_stage, qty, reason, moved_by, moved_at |
| `order_notes` | **id**, order_id, user_id, body, created_at |
| `order_photos` | **id**, order_id, order_line_id, file_path, thumb_path, original_filename, mime_type, file_size, caption, uploaded_by, uploaded_at |
| `order_photo_parts` | **order_photo_id**, **part_id** |
| `order_po_documents` | **id**, order_id, po_number, file_path, original_filename, mime_type, file_size, is_original, note, uploaded_by, uploaded_at |
| `order_queries` | **id**, order_id, raised_by, subject, body, status, created_at, updated_at |
| `order_query_replies` | **id**, order_query_id, user_id, body, created_at |
| `parts` | **id**, client_id, cpn, name, description, usual_order_multiple, expected_next_order_qty, last_order_value, last_order_qty, last_order_date, target_price, notes, has_free_issue, free_issue_relationship, free_issue_factor, free_issue_updated_by, free_issue_updated_at, status, is_archived, internal_notes, estimated_build_time_minutes, actual_build_time_minutes, quoted_price, quoted_price_set_by, quoted_price_set_at, price_under_review, base_material, material_source, material_cost, created_by, updated_by, created_at, updated_at |
| `part_drawings` | **id**, part_id, name, position, created_by, created_at |
| `part_price_breaks` | **id**, part_id, kind, qty, price, set_by, set_at |
| `part_quote_drafts` | **part_id**, machine_rate_per_minute, markup_percent, draft_total, notes, updated_by, updated_at |
| `part_quote_lines` | **id**, part_id, label, amount, position |
| `part_time_entries` | **id**, part_id, kind, task, minutes, position, recorded_by, recorded_at |
| `parts_return_receipts` | **id**, delivery_note_id, order_line_id, qty_received, notes, received_by, received_at |
| `part_alternate_numbers` | **id**, part_id, number, label |
| `part_files` | **id**, part_id, drawing_id, file_path, original_filename, mime_type, file_size, version_no, is_current, uploaded_by, uploaded_at |
| `part_free_issue_materials` | **id**, part_id, reference, notes |
| `part_links` | **id**, part_id, linked_part_id, created_by, created_at |
| `part_media` | **id**, part_id, kind, is_main, caption, file_path, thumb_path, original_filename, mime_type, file_size, uploaded_by, uploaded_at |
| `reference_sequences` | **sequence_key**, next_number |
| `reminder_runs` | **id**, kind, ran_at, recipients, items, sent, failed, triggered_by, notes |
| `roles` | **id**, slug, name, side |
| `settings` | **setting_key**, setting_value, updated_at |
| `users` | **id**, client_id, side, name, email, password_hash, password_set_at, is_active, last_login_at, created_at, updated_at |
| `user_invites` | **id**, user_id, token_hash, invited_by, created_at, expires_at, accepted_at |
| `user_roles` | **user_id**, **role_id**, granted_at |

### The tables that carry the workflow

`order_lines` is the centre. A line does **not** have a status column — its
quantity is spread across stages in `order_line_quantities`, one row per
occupied stage, and the rows for a line always sum to `order_lines.qty_ordered`.
Every movement is written to `order_line_stage_moves` (`from_stage` NULL means
quantity entered the line, `to_stage` NULL means it left).

Cached totals on `order_lines` — `qty_completed`, `qty_delivered`,
`qty_invoiced`, `qty_failed`, `qty_cancelled`, `qty_free_issue_required` — are
rewritten from the distribution after every write.

`qty_free_issue_required` is **derived**, not accumulated: enough for what is
still on the order, plus enough to remake what has failed, each rounded up
separately.

---

## 4. The quantity state machine

```
awaiting_free_issue → ready_for_production → in_production → complete → delivered → invoiced
```

plus two terminal buckets: **`failed`** and **`cancelled`**.

No status is stored anywhere. `OrderLine::statusLabel()` writes the distribution
out ("12 awaiting free issue, 5 ready for production"), and
`OrderLine::headlineStage()` picks the least-advanced non-empty flow stage for
the badge.

**Where staff may move quantity by hand** (`OrderLine::MANUAL_DESTINATIONS`):

| From | To |
|---|---|
| `awaiting_free_issue` | ready_for_production, failed, cancelled |
| `ready_for_production` | in_production, awaiting_free_issue, failed, cancelled |
| `in_production` | complete, ready_for_production, failed, cancelled |
| `complete` | in_production, failed, cancelled |
| `delivered` | *(none)* |
| `invoiced` | *(none)* |
| `failed` | awaiting_free_issue, ready_for_production, cancelled |
| `cancelled` | awaiting_free_issue, ready_for_production |

`delivered` and `invoiced` are unreachable by hand on purpose: quantity becomes
delivered by appearing on a goods-out note and invoiced by an invoice being
raised. A second, quieter way to say the same thing would disagree within a week.

**Two units of measure.** `OrderLine::UNIT_STAGES` — `awaiting_free_issue`,
`ready_for_production`, `in_production` — are read and entered in **material
units**; everything from `complete` onward is **final parts**. Storage is always
final parts. Conversion happens only at the display/input boundary
(`OrderLine::displayQty()` out, `OrderLine::storedQtyFromEntered()` in), gated on
`Part::convertsQuantity()`, so a 1:1 part sees no conversion and no unit words.
**When typing a quantity, you type in the unit of the stage you are taking from.**

**Free-issue figures** come from one place, `OrderLine::freeIssueFigures()`,
which returns `required`, `received`, `rejected`, `accepted`, `outstanding` and
`surplus`. They always reconcile: `accepted + outstanding = required + surplus`,
and only one of outstanding/surplus is ever non-zero. Nothing should compute
these itself.

**Rejections vs shortages vs failures** are three different things:

- a **shortage** is material that has not arrived; the standing note already
  asks for it and nothing else happens;
- a **rejection** is material that arrived and cannot be used; it goes back on a
  return note and the same quantity is added to what the line still needs;
- a **failure** is a part that was made and is not acceptable; it sits in
  `failed`, still counts as owed, and raises the material requirement.

---

## 5. Roles and permissions

`users.side` (`staff`|`client`) is the fixed top-level split, enforced by a CHECK
tying it to `client_id` nullability. Granular permission is
`roles`/`user_roles` many-to-many plus the in-code `Capabilities::MATRIX`.

A generic superset rule in `Capabilities::allows()` gives `staff.admin` any
capability listing at least one `staff.*` role, and `client.admin` any listing a
`client.*` role — so those two are never written into a row.

**Nine roles:**

| Side | Slug | Name |
|---|---|---|
| staff | `staff.admin` | Staff admin |
| staff | `staff.invoicing` | Invoicing |
| staff | `staff.production` | Production |
| staff | `staff.quoting` | Quoting |
| staff | `staff.raise_orders` | Raise orders |
| client | `client.admin` | Client admin |
| client | `client.production` | Production viewer |
| client | `client.production_manager` | Production manager |
| client | `client.purchaser` | Purchaser |

**Capabilities**, as written (admin supersets not repeated):

| Capability | Roles |
|---|---|
| `view_pricing` | client.purchaser, client.admin, staff.quoting, staff.admin |
| `manage_parts` | client.purchaser, client.admin |
| `place_orders` | client.purchaser, client.admin |
| `manage_client_users` | client.admin |
| `request_quantity_change` | client.purchaser, client.admin |
| `return_rejected_parts` | client.production, client.production_manager, client.purchaser, client.admin |
| `set_due_dates` | client.production_manager, client.admin |
| `view_orders` | all four client roles + staff.production, staff.quoting, staff.invoicing, staff.admin |
| `raise_queries` | all four client roles + staff.production, staff.quoting, staff.invoicing, staff.admin |
| `manage_clients` | staff.admin |
| `set_pricing` | staff.quoting, staff.admin |
| `edit_workshop_fields` | staff.quoting, staff.production, staff.admin |
| `production_control` | staff.production, staff.admin |
| `issue_delivery_notes` | staff.production, staff.admin |
| `create_client_parts` | staff.quoting, staff.admin |
| `raise_orders` | staff.raise_orders, staff.admin |
| `approve_quantity_changes` | staff.quoting, staff.raise_orders, staff.admin |
| `close_orders` | staff.quoting, staff.admin |
| `push_invoices` | staff.invoicing, staff.admin |
| `manage_settings` | staff.admin |

`Auth::authorize('x')` 403s and exits (JSON-aware). `Auth::can('x')` returns a
bool for templates.

---

## 6. Routes

155 routes in two middleware groups (62 GET, 93 POST). `auth` is the client area (staff may also
reach it, scoped to their own client); `staff` is Junction's. `csrf` is on every
state-changing POST.

The full list is best read from the router itself:

```php
$router = require 'routes/web.php';
// $routes is a flat list of ['method','path','handler','middleware','regex']
```

**The shape to know:** the part page and the order page are each **one template
rendered at two URLs**.

| Page | Template | Payload | URLs |
|---|---|---|---|
| Part view | `templates/parts/show.php` | `PartView::payload()` | `/parts/{id}`, `/staff/parts/{id}` |
| Order view | `templates/orders/show.php` | `OrderView::payload()` | `/orders/{id}`, `/staff/orders/{id}` |
| Part edit | `templates/parts/edit.php` | `PartForm` | `/parts/{id}/edit`, `/staff/parts/{id}/edit` |

Two URLs rather than one because Junction's own actions must sit behind the
`staff` middleware group, and every link into the staff area already points
there. One template, one payload builder, and the staff copies are deleted
rather than kept alongside.

Inside such a template the pattern is:

```php
$isStaff       = Auth::isStaff();
$canProduce    = $isStaff && Auth::can('production_control');
$staffBase     = '/staff/orders/' . $order['id'];
$clientBase    = '/orders/' . $order['id'];
$base          = $isStaff ? $staffBase : $clientBase;
```

Staff-only forms post to `$staffBase`, client actions to `$clientBase`. **Both
halves need routes** — a form rendered for staff whose staff route was never
registered renders happily and 404s on submit.

There is no route-audit script in the repository, but the check is worth running
by hand after adding routes: pull every `url(...)` out of the templates and test
it against the router's own compiled `regex` values (not a second matcher of your
own). A staff form whose route was never registered rendered for weeks before
anybody pressed the button.

When writing the audit, note that many hrefs end `?>#fragment"` rather than
`?>"`. A pattern anchored on `?>"` runs past them into the next expression and
reports URLs that were never in the template.

**`#line-{id}` is a contract.** Each order line is a collapsed `<details>` with
that id, the QR code on every printed route card points at it, and app.js opens
whatever a fragment names. The staff actions that act on one line redirect back
to it — `move`, `replacement-material`, `close`, `reopen`, and both change-request
decisions — so the card is open on the line just worked on. Only their success
paths carry the fragment: a failure redirects to the top of the page, where its
error message is.

---

## 7. Reusable components

**Partials** (`templates/partials/`):

| Partial | What it is |
|---|---|
| `back-link.php` | The return link above a page heading. `href`, `label`. |
| `brand.php` | Logo or fallback mark plus wordmark. |
| `delivery-note-table.php` | One delivery-note table, any of the four kinds. Declares the shared six columns. |
| `flash.php` | The flash stack. |
| `footer.php` | Page footer. |
| `free-issue-fields.php` | The has-free-issue toggle, relationship and factor. |
| `free-issue-relationship.php` | The relationship wording. |
| `nav.php` | Primary navigation, permission-filtered (see §9). |
| `order-builder.php` | The line builder on the order forms. |
| `order-notes-queries.php` | Notes and queries, both audiences. Posts to `/staff/orders/...` or `/orders/...` by viewer. |
| `part-media.php` | Part photo/document/tooling grid, plus the order attachments tagged with this part. |
| `parts-results.php` | The parts listing region: count, table, pages. Both audiences, and what the AJAX search asks for on its own. |
| `quote-standard-inputs.php` | The rate and mark-up boxes inside the draft-quote editor. Empty means "follow Settings". |
| `order-reference-fields.php` | Usual multiple, expected next quantity and the last order. Shared by all three part forms. |
| `row-editor.php` | The popup holding a list of rows that add up. Four uses on the part page — see §7.1. |
| `qty-bar.php` | A done-of-total progress bar. `label`, `done`, `total`. |
| `stage-moves.php` | The production-status table and its move controls. |
| `stepper.php` | The proportional stage bar. Built from spans, so it is valid inside a `<summary>`. |
| `theme-init.php` | Sets `data-theme` before first paint. |

**Services** (`src/Services/`): `Branding`, `ClearBooksClient`,
`ClearBooksCustomerSync`, `ClientPurge`, `ClientUsers`, `DrawingUpload`,
`FreeIssueNoteService`, `Invitations`, `Notifications`, `OrderPlacement`,
`OrderView`, `PartForm`, `PartView`, `PartsOnOrder`, `PartsReturnService`,
`PdfService`, `QrCodeService`, `ReferenceNumber`, `Reminders`,
`RouteCardService`.

**Table classes in `app.css`**, each with a `<colgroup>` and declared widths so
tables showing the same thing line up between pages: `.dn-table` (the four
delivery-note tables), `.stage-table`, `.failed-table`, `.table-orders`,
`.table-parts`, `.table-people`, `.table-receipts`. All use
`table-layout: fixed` with one unsized column to absorb the remainder, and fall
back to `auto` under 900px.

**JS behaviours** are `data-` attribute driven in `public/js/app.js`, no build
step: `data-theme-toggle`, `data-nav`, `data-dismiss`, `data-flash-autohide`,
`data-toggle-password`, `data-copy`, `data-checkin-*`, `data-reject-*`,
`data-cpn-check` / `data-cpn-status` / `data-cpn-client`, `data-line-card`,
`data-parts-search` / `data-parts-query` / `data-parts-filter` /
`data-parts-results` / `data-parts-submit`, `data-row-editor` /
`data-row-editor-open` / `data-row-editor-close` / `data-rows` / `data-row` /
`data-row-add` / `data-row-remove` / `data-row-amount` / `data-row-total`.

**Progressive enhancement is the rule for all of them.** The parts search is a
real GET form with a real submit button (hidden by the script, not by CSS) and
the page links are real links; the order line cards are `<details>`, which need
no script at all. Everything works with JavaScript off, more slowly.

**Disclosures all use the same `.caret`** and the same
`details[open] > summary .caret` rotation, so a triangle means one thing
everywhere: the navigation drop-downs, the order line cards (`.line-card`), the
order page's paperwork cards (`.panel-card`), the drawing histories and the
caption editors. Notes and queries is the one card on the order page that does
*not* fold — it is a conversation, and a message behind a heading is a message
nobody answers.

### 7.1 The row editor

`partials/row-editor.php` plus `[data-row-editor]` in app.js. One popup shape
for every list on a part that adds up, so "add a row" behaves identically in
all of them:

| Editor | Rows | Total |
|---|---|---|
| Estimated Build Time | task + minutes | minutes |
| Actual Build Time | task + minutes | minutes |
| Draft quote lines | label + amount | money |
| Price breaks (either kind) | quantity + price | *(none — pairs, not a sum)* |

The caller passes `columns` (name, label, type, and optionally placeholder,
step, min, `width` and `total`), the existing `rows`, and where to post. The
column marked `total` is what the live figure adds up, formatted by
`data-row-total-format`.

**Every one of them works with the script off.** A `<dialog>` with no `open`
attribute is hidden by the user agent and only a script can open one, so the
layout carries a `<noscript>` rule that puts them back into the page flow, and
the trigger buttons render `hidden` and are un-hidden by app.js. The server
always renders one spare blank row, which is how a row is added without the
script; with it, "Add a row" focuses that spare rather than stranding it in the
middle of the list.

**Every list is replace-in-full.** The editor shows the whole list, so what
comes back *is* the list and a row somebody cleared has to actually go. Blank
rows are dropped rather than rejected — the spare is not an error.

Posting is parallel arrays (`task[]` beside `minutes[]`), zipped back up by
`StaffPartController::pairedRows()`. That survives a row being added or removed
without renumbering anything.

### Searching a listing

`Part::search()` is the one implementation, for both the client's list and
Junction's — the same question at a different scope. It takes `term`,
`client_id`, `archived`, `only_unquoted`, `include_internal`, `page`,
`per_page`, and returns `rows`, `total`, `page`, `pages`, `per_page`.
`Part::PER_PAGE` is 25.

Three things about it are load-bearing:

- **`include_internal` is a permission, not a convenience.** With it, the search
  also reads `internal_notes`, `base_material` and `material_source`. Those are
  never shown to a client, and a search that matched on them would hand back
  their contents a guess at a time. Only the staff controller passes it.
- **`archived` distinguishes `null` from `false`.** `null` means both, `false`
  means active only — so it is read with `array_key_exists`, not `??`, which
  would collapse an explicit null back to the default. Junction's search spans
  archived parts (there is no archived tab on that side, and the rows carry a
  badge); the client's stays inside the Active/Archived tab they chose.
- **`%` and `_` in the term are escaped** with `addcslashes`. Somebody typing a
  part number containing one means the character, not a wildcard.

Relevance is ordered, not scored: exact part number, then part number starting
with the term, then name starting with it, then anything containing it.
Alternate numbers are matched through an `EXISTS` subquery — the number a client
knows a part by is often not the one it is filed under, which is why the table
exists at all.

The controller renders `partials/parts-results` alone when `Request::isAjax()`,
and the whole page otherwise. Same partial both ways, so the typed-into list and
the loaded list cannot drift.

---

## 8. Documents and paperwork

**Four delivery-note types**, one vocabulary for both audiences
(`DeliveryNote::TYPE_LABELS`):

| Type | Label | Direction |
|---|---|---|
| `free_issue_in` | Free-Issue Sent | client → Junction (a standing request) |
| `goods_out` | Completed Parts Sent | Junction → client |
| `material_return` | Rejected Free-Issue Returned | Junction → client |
| `parts_return` | Rejected Parts Returned | client → Junction |

Reference prefixes: `FIDN-`, `DN-`, `RTN-`, `RPN-`, plus `ORD-` and route cards
derived as `RC-{order}-{line}`. All per-year sequences from
`reference_sequences` via `ReferenceNumber::next()`.

**Free-issue notes and route cards are rendered live and never stored** — what
they ask for changes as material arrives, so a saved copy would be wrong. Notes
that record a movement (goods out, both returns) keep their PDF.

**One outstanding free-issue request per line**, reissued rather than
duplicated. Anything needing more material points at the standing note.

### Drawings: named, several per part, versioned each

A part has **one or more named drawings** (`part_drawings`), and each is a
lineage of revisions (`part_files.drawing_id`). A fabrication has a general
arrangement and a detail per sub-component; before this, uploading the second
superseded the first and nothing on the page said the two were different
drawings rather than two versions of one.

- **The name lives on the drawing, not on the file.** Renaming does not touch
  the history. Unique per part (`uq_part_drawings_name`), so the names actually
  distinguish.
- **Version numbers are per drawing** (`uq_part_files_version` on
  `drawing_id, version_no`), so a part with three drawings has three v1s. So is
  `is_current`: exactly one per drawing.
- **`PartFile::create()` does the numbering and the demotion in one
  transaction**, with `SELECT … FOR UPDATE`, so two uploads at once cannot both
  come out as v3 or both end up current.
- **Nothing is ever replaced.** A superseded revision keeps its file and stays
  viewable, because parts already made were made to it.

`App\Services\DrawingUpload` is the one place that reads an upload, for all
three forms that accept one. A submission names its drawing with either
`drawing_id` (an existing one, checked against this part) or `drawing_name` (a
new one). A name already in use is **not** an error — it folds into that
drawing as its next revision, because two people adding "Op 20 detail" minutes
apart mean the same drawing.

Deleting is all-or-nothing: a whole drawing and every revision of it, staff
only. A single revision cannot be removed from a history, because a version
sequence with a hole in it is a record nothing can explain.

### Attachments: two kinds, deliberately

| | `part_media` | `order_photos` |
|---|---|---|
| Belongs to | the part | one order |
| Says | what this part is, every time it runs | how this batch went |
| Who sees it | both audiences, read-only for the client | Junction only |
| Kinds | `photo`, `document`, `tooling` | anything in `uploads.order_document` |
| Managed by | `edit_workshop_fields` | `production_control` |

`order_photos` is any file attached to an order, not only pictures; the table
name is historic and `mime_type` decides how it renders. It carries two
different facts about which part it concerns:

- **`order_line_id`** — which line it was filed against. Optional, unchanged.
- **`order_photo_parts`** — which part or parts it *shows*. Many-to-many,
  because one photograph of two components fitted together is about both.

The tag is what puts an order's attachment on a part page, under *From orders*,
marked as being about one batch rather than about the part. Posted part ids are
filtered against the order's own lines before being written
(`StaffOrderController::postedPartIds()`) — an unchecked id would surface a
photo on a part belonging to a different client.

**Captions are editable after upload**, on both tables. They are written
mid-upload, before anybody has seen the file in context, so first attempts are
usually wrong. An empty box clears the caption rather than storing a blank one,
and the tile falls back to the filename.

---

## 9. Navigation (`templates/partials/nav.php`)

Groups with children; a link is shown only if its `permission` passes, and a
group disappears when nothing under it does.

**Staff:**

| Group | Children (permission) |
|---|---|
| Orders | Place an order (`raise_orders`), All orders (`view_orders`), Delivery notes (`view_orders`) |
| Parts | New part (`create_client_parts`), All parts (—) |
| Reports | *(top-level, `view_orders`)* |
| Settings | Clients (`manage_clients`), Users, Logo, Email, Email templates, Quoting, Reminders, Clear Books (all `manage_settings`) |

**Client:**

| Group | Children (permission) |
|---|---|
| Parts | New part (`manage_parts`), All parts (—) |
| Orders | Place an order (`place_orders`), All orders (`view_orders`) |
| Team | *(top-level, `manage_client_users`)* |

Every top-level list is one click away from anywhere, which is why a page's
"back" link points at the specific order/client/part it came from rather than at
a list the sidebar already offers.

---

## 10. Notifications

Opt-in per person; everybody starts subscribed to nothing. Registry in
`Core/NotificationTypes.php`; wording in `Mail/EmailTemplate.php` (defaults are
the source of truth, the `email_templates` table holds overrides only).

| Type | Side | Capability | Group |
|---|---|---|---|
| `part_quoted` | both | `view_pricing` | Orders |
| `order_confirmed` | both | — | Orders |
| `order_in_production` | both | — | Orders |
| `free_issue_note_issued` | both | — | Free-issue material |
| `free_issue_checked_in` | both | — | Free-issue material |
| `material_rejected` | both | — | Free-issue material |
| `delivery_note_issued` | both | — | Despatch, returns and invoicing |
| `parts_returned` | staff | — | Despatch, returns and invoicing |
| `invoice_raised` | both | `view_pricing` | Despatch, returns and invoicing |
| `query_raised` | both | — | Questions and changes |
| `query_answered` | both | — | Questions and changes |
| `quantity_change_requested` | staff | — | Questions and changes |
| `quantity_change_decided` | both | — | Questions and changes |
| `parts_outstanding` | staff | — | Junction workload |

**Merge values are escaped, with one narrow exception.** `Merge::render()`
escapes every substituted value in an HTML body — they are data, and some of
them are text a client typed. A template may declare `raw_fields`, and only
those are passed through: today that is `items` on `parts_outstanding`, whose
markup `Reminders::itemsHtml()` builds itself and whose every interpolated value
it escapes. The list lives beside the template's wording so adding to it is a
visible decision.

**The parts-outstanding digest is grouped by part, not listed by line.** It is
read to decide what to set up next, and that decision is made a part at a time:
a part wanted on three orders is one machine setup, and a flat list scattered
those three down the page. Built from `PartsOnOrder::groupByPart()` — the same
grouping the parts-on-order report uses — and cut down to the part, what is
owed, and anything blocking it. Inline-styled tables, because that is what
survives an email client; `Merge::htmlToText()` separates cells so the text
alternative stays readable. Capped at 25 parts, then it points at the report.

A template's merge fields are declared beside its wording, so the editor offers
exactly what the sending code supplies.

---

## 11. Uploads

| Kind | Max | Extensions |
|---|---|---|
| `drawing` | 25 MB | pdf, dwg, dxf, step, stp, iges, igs, png, jpg, jpeg, doc, docx, xls, xlsx |
| `po` | 15 MB | pdf, png, jpg, jpeg, doc, docx |
| `photo` | 10 MB | png, jpg, jpeg, webp |
| `part_document` | 25 MB | pdf, png, jpg, jpeg, webp, doc, docx, xls, xlsx, txt |
| `order_document` | 25 MB | pdf, png, jpg, jpeg, webp, doc, docx, xls, xlsx, txt |
| `part_tooling` | 50 MB | CNC/CAM and archive formats, plus doc, docx, xls, xlsx |
| `logo` | 2 MB | png, jpg, jpeg, webp |

**The extension list is not the whole check.** `Upload::KNOWN_MIMES` maps an
extension to what its contents are allowed to look like, and an extension with
no entry there is taken purely on the strength of its name. The office formats
need several answers each because libmagic differs by version: a legacy .doc or
.xls is an OLE2 compound file, reported on this machine as
`application/x-ole-storage` and elsewhere as `application/CDFV2` or the
specific Office type. The modern formats are ZIP containers and report
`application/zip`, so a renamed .zip passes — telling them apart properly means
reading `[Content_Types].xml` out of the archive, which needs an extension that
is not always installed.

**PHP's `post_max_size` must exceed the largest of these**, or PHP discards the
request body and the missing CSRF token is reported as an expired session.
`install.sh` derives `upload_max_filesize`, `post_max_size` and nginx's
`client_max_body_size` from the application's own config; `tracker doctor`
checks the two PHP limits against it and fails when they disagree.

`Upload::store()`, `Image::process()` and `PdfService` all create their
directories on demand, so the per-kind directories under `storage/uploads` are
not pre-created anywhere. The font cache below is the single exception, and for
the opposite reason: what matters is not that it exists but *who owns it*.

**PDFs and the font cache.** Dompdf parses DejaVu's metrics and caches the
parsed result; with a cache it can reuse a render is roughly twenty times
quicker (58 ms against 1052 ms on an empty document, measured). By default that
cache lives in `vendor/dompdf/dompdf/lib/fonts` — and `manage.sh permissions`
makes the whole application root `root:www-data` at 750, so the web user can
read it and never write it. Every PDF therefore re-parsed every font, on every
request.

`PdfService` points `fontCache` at `storage/uploads/cache/dompdf-fonts`
instead — `storage/` is the one tree the web user owns. The fonts themselves are
still read from dompdf's own directory, which only needs to be readable, so
nothing breaks when composer replaces `vendor/`.

The directory is created by `install.sh` and by `tracker permissions`, before
the ownership pass that hands `storage/` to the web user. That is deliberate:
left to appear on its own it is created by whoever renders the first PDF, and a
console command run directly as root leaves a root-owned directory the web
server cannot write — the original problem in a new place. Creating it up front
means it is always web-owned, and `tracker permissions` repairs one that is not.

`tracker pdf-warm` builds the cache, and runs from `install.sh`, `manage.sh
update` and `manage.sh restore` — a restore replaces `storage/uploads` wholesale,
so the cache goes with it. Backups exclude `uploads/cache`, being the one thing
under there that can be regenerated. The cache is per *face*, not per family, so
the warm document uses the faces the PDF templates use — DejaVu Sans regular and
bold, no italic — or it warms files nothing reads and leaves the real ones cold.

`doctor` reports four states: warm, writable but empty, missing, and not
writable. "Not writable" depends on who is asking: `tracker` runs the console as
the web user, but run it by hand as yourself and a healthy directory reads as
unwritable because it belongs to the web server. That case is a warning naming
the owner and suggesting `sudo tracker doctor`, not a failure.

Photos are normalised to a 2400px longest edge with a 480px thumbnail beside
them; `thumb_path` is nullable and the file controller falls back to the full
image.

---

## 12. Administration

`sudo tracker <command>` (`manage.sh`). Anything touching the database goes
through `bin/console.php` so it uses the application's own models and
validation; anything needing root is done in the shell script.

Notable: `doctor` (the first thing to run when something is wrong), `backup`
(database + uploads + `.env`, all three needed for a restore), `reset-database`
and `reset-uploads` (each asks twice, requires `RESET` typed in full, ignores
`--yes`, and refuses without a terminal).

**The application's database user is deliberately narrow** — `install.sh` grants
`SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES` on its
own schema and nothing else: no GRANT OPTION, CREATE USER, FILE, SUPER, PROCESS,
EVENT, TRIGGER or LOCK TABLES.

Anything run as that user has to live within it. `backup` therefore dumps with
`--single-transaction --skip-events --skip-routines`: `--events` needs the EVENT
privilege and failed the whole backup, and `--single-transaction` is what avoids
needing LOCK TABLES. Nothing is lost — the schema has no events, routines,
triggers or views, and the same grant that will not dump a stored program will
not create one either.

Temp files holding the database password are removed by an **EXIT** trap, not a
`RETURN` one: `set -e` exits the shell rather than returning from a function, so
a RETURN trap misses the failure case it exists for.

---

## 12.1 Accounts: switching people and companies off

**Nothing is ever deleted.** A user who has raised orders, asked questions and
written notes is attached to all of it; removing the row would take that history
with it or leave it pointing at nothing. Deactivation everywhere, as with
archived parts.

**One user** — `users.is_active`. Set from the client's own team page
(`manage_client_users`) or from Junction's client page (`manage_clients`), both
through `App\Services\ClientUsers`, which is also where name/email edits and
their uniqueness check live. `ClientUsers::isLastActiveAdmin()` refuses to
deactivate a company's only remaining `client.admin`: they would then be unable
to invite anybody, fix their own roles, or reactivate the person who could.

**A whole client** — `clients.is_active`, plus `deactivated_at`,
`deactivated_by` and `deactivated_reason`, set only by
`StaffClientController::setActive()` (a reason is required). It does three
things, and **all three are derived from that one flag** rather than written
across their records:

| | How |
|---|---|
| Nobody can sign in | `Auth::attempt()` refuses, and `User::findActive()` joins `clients` so an open session dies on its next request |
| Their work is out of the lists | `Order::all($includeInactiveClients)` and `Part::search(['active_clients_only' => …])`, with a toggle on each staff list |
| Their orders freeze | every mutating staff action asks `Client::isActive()` — `StaffOrderController::findLine()` covers the line actions, `refuseIfFrozen()` the order-level ones |

There is deliberately **no stored frozen flag**. One would have to be set on
every order at deactivation and unset at reactivation, and the first one missed
by a new code path is an order frozen for ever with nothing to explain it.

Reactivation is therefore complete and needs no repair: the orders unfreeze at
the stage they stopped at, the lists fill back in, and **a user deactivated
individually stays deactivated** — their own `is_active` was never touched.

**Deleting a client for good** is the one exception to archive-over-delete, in
`App\Services\ClientPurge`. Three things must be true: `manage_clients`, an
account already switched off, and the client's name typed out in full. It is
offered only on a switched-off account, because deciding to stop working with
somebody and deciding to erase them are different decisions.

Two things make it more than `DELETE FROM clients`. The foreign keys are mostly
RESTRICT — which is right, it is what stops an ordinary mistake taking an
order's history with it — so the rows come out in dependency order by hand:
invoices, delivery notes, orders, parts, users, client. And the file paths are
collected **before** the rows go, because a path only exists in the row pointing
at it. Rows are removed in one transaction; the files afterwards, since a
rollback can restore a row and nothing can restore a file.

---

## 12.2 Required by

`order_line_due_dates`: a quantity and a date, several rows per line, set by the
client under `set_due_dates` (`client.production_manager` or `client.admin`).

They change nothing about what is owed — the quantity on the order is still the
quantity on the order. They are a statement of need that Junction reads to
decide what to run next, which is why the model is mostly about surfacing the
*next* one:

- `OrderLineDueDate::next()` walks the schedule against `qty_completed` and
  returns the earliest requirement not yet covered. Measured against
  **completed**, not delivered: a part that is made and waiting for a van is not
  one anybody needs chasing about, and a date that stays red until the courier
  has been is a date people learn to ignore.
- `urgency()` returns `overdue` / `soon` / `ok` rather than a number of days,
  because the page uses it to pick a colour and the colour is the message.
  A requirement already covered renders as `met`.

Surfaced on the **collapsed** order line card, since that is where an order is
scanned, and listed in full inside it.

---

## 13. How a part is ordered

Four reference fields on `parts`, all set by the client and by staff on their
behalf, all optional, and all read and validated in one place —
`Part::readOrderReferenceInput()` and `Part::validateOrderReference()` — so the
three forms that offer them cannot drift. The boxes themselves are
`partials/order-reference-fields.php`.

| Column | Means |
|---|---|
| `usual_order_multiple` | The batch size this part is ordered in |
| `expected_next_order_qty` | What is likely to be ordered next |
| `last_order_value` / `last_order_qty` / `last_order_date` | The last order, as recorded by hand |

**The last order here is not the order history.** `orders` only knows about
orders placed through this system; a part machined for ten years before any of
it existed has a last order that is not in there. The two are shown in separate
panels on the part page and labelled, so neither is read as the other.

`last_order_value` is a price and follows the rule every price follows: absent
from the form and refused by the reader for anybody without `view_pricing`. The
key is *omitted* rather than nulled in that case, so a caller can tell "not
allowed to set this" from "set it to nothing" and preserve what is stored —
the same shape `material_cost` uses. Every role that can edit these fields today
also holds `view_pricing`, so that branch is defensive rather than exercised.

---

## 14. What a part costs, and how long it takes

Four lists on the part page, all edited through the same row editor (§7.1).

**Build time is itemised, in two kinds.** `part_time_entries` holds
`estimated` and `actual` rows — a task and its minutes — and
`parts.estimated_build_time_minutes` / `parts.actual_build_time_minutes` are
the sums, rewritten by `PartTimeEntry::replace()` after every change. Neither
total is ever typed: `Part::updateStaffFields()` no longer touches them, and
the workshop form has no box for one. An empty list stores NULL rather than 0,
because "nobody has estimated this" and "this takes no time" are different
statements. `PartTimeEntry::variance()` is the comparison the pair exists for.

Gated on `edit_workshop_fields` — quoting, production and admin — because both
kinds are workshop facts.

**The quoting scratchpad** is `part_quote_drafts` (one row per part) plus
`part_quote_lines`. It is Junction's working towards a price and **sets
nothing**; the quoted price is still its own deliberate, client-visible act.
Gated on `set_pricing`, which is exactly staff.quoting and staff.admin.

`PartQuote::calculate()` returns the whole breakdown, not a number:

```
machine time  = parts.estimated_build_time_minutes × rate
              + parts.material_cost
              + sum of part_quote_lines.amount   (may be negative)
              = subtotal
              + subtotal × markup%
              = Draft Part Quote
```

Machine time uses the **estimated** build time, never the actual: a quote is
made before the work, and pricing a repeat order off how long it happened to
take would charge the client for a bad day.

The rate and mark-up are **NULL when the part follows the house figures**, which
is not the same as storing a number equal to them. Read with a null check, never
`??`. Change the figures in Settings → Quoting and every follower moves;
`PartQuote::recalculateFollowers()` rewrites their cached totals and the
controller says how many. A part with its own rate does not move, which is the
whole point of having stored one.

House figures live in `settings` as `quoting.machine_rate_per_minute` and
`quoting.markup_percent`, at `/staff/settings/quoting` under `manage_settings`.

**Price breaks** are `part_price_breaks`: freely settable quantity/price pairs,
two kinds. A break's `qty` is where its price *starts* applying, unique per part
and kind. The part's own `target_price` / `quoted_price` still governs below the
first break.

| Kind | Whose | Set by |
|---|---|---|
| `target` | the client's hoped-for price | client `manage_parts`; staff `create_client_parts` |
| `quoted` | Junction's answer | `set_pricing` |

**Quoted breaks price an order.** `OrderPlacement` prices each line with
`PartPriceBreak::priceAt()` at the quantity actually ordered — a break set at 10
applies when 12 are ordered — worked out server-side rather than taken from the
form, for the same reason the free-issue quantity is. A part with no breaks
prices at `quoted_price` exactly as it always did. Target breaks price nothing;
they are the client's statement of what they hope to pay.

**A price is entered as a ladder, not a box.** One editor per kind, rows of
"from this quantity, this price each". `PartPriceBreak::splitLadder()` puts the
lowest row into the part's own price column (`quoted_price` / `target_price`)
and the rest into `part_price_breaks`; `ladderRows()` is the inverse, so
reopening the editor shows what was saved. A ladder of one row is exactly the
single figure this replaced, stored where it always was — which is why
everything that reads one price still works.

On the create forms the same editor is rendered `inline` (see §7.1): a part that
does not exist yet has no URL to post rows to, and a form cannot nest inside a
form.

---

## 15. Known state

- **No automated test suite.** Verification is a full HTTP sweep across four
  role levels plus browser measurement of layout.
- **No invoice has been raised against a live Clear Books account**, and the
  customer read behind the client sync is equally unproven against live data.
  Both are built from the published spec rather than guessed.
- **SMTP is not configured** in any environment used so far, so every send has
  failed at the connection by design.
- `exif_read_data` is unavailable on the Windows development machine, so EXIF
  auto-rotation degrades silently there.
- **CSS `:has()` is relied on in one place**: opening a caption editor gives its
  tile the full row, because the editor does not fit a 130px thumbnail. Without
  `:has()` the editor is cramped rather than broken, and the form still submits.
