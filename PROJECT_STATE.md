# Production Tracker — project state

Where the codebase actually is, written from the code and the live schema
rather than from memory. Read this before picking the work up again.

**Last verified:** 18 August 2026, against `production_tracker_dev` on the
local MariaDB 12.3 instance and a full browser/HTTP pass across all three role
levels.

For *how to install and run it*, see [`docs/INSTALL.md`](docs/INSTALL.md).
This file is about what exists and why, not how to deploy it.

---

## 1. What this is

A self-hosted PHP 8.1+/MariaDB order tracker for Junction Inc Ltd, covering
quote request → order → workshop production → delivery note → Clear Books
invoice. Internal tool, never resold, but the data model supports multiple
client companies from day one.

Two workflow complications drive most of the design:

- **Free-issue material.** Clients supply stock for Junction to work on. It
  arrives partially, late, or wrong, and that has to be tracked per order line.
- **Partial and batched delivery.** Finished parts go out in whatever
  quantities are ready, so ordered/made/delivered/invoiced are four independent
  running totals rather than one status.

### Relationship to Kitwell

Kitwell (the sibling asset tracker, `github.com/maeterlinckle/kitwell`) is
**not a dependency** — no shared code, database, includes or paths. Patterns
were studied and re-implemented natively against this application's own
classes. The visual language is deliberately the same family: `public/css/app.css`
is Kitwell's stylesheet with the asset-register-specific sections removed and
this application's components appended, so the two look like siblings without
sharing a file.

Deliberate divergences from Kitwell, each for a reason:

| Area | Kitwell | Here | Why |
|---|---|---|---|
| Permissions | Admin-editable permission grid | Fixed in-code `Capabilities::MATRIX` | The seven roles are specified and fixed; a grid would be configuration nobody needs |
| QR codes | Hand-rolled Reed–Solomon | `endroid/qr-code` | These go on physical paperwork; correctness beats dependency-avoidance |
| PDF | None to borrow | `dompdf` | Kitwell has no PDF generation |
| Scheduling | Several reminder types | One digest | There is one thing worth chasing here |

---

## 2. Architecture

Plain PHP, no framework, no build step. PSR-4 `App\` → `src/`.

```
bin/            CLI entry points (migrate, create-admin, console, reminders)
install.sh      prompt-driven installer
manage.sh       the single administration entry point
config/         config.php — one array, everything from .env via App\Core\Env
database/
  migrations/   numbered .sql, forward-only, tracked in a migrations table
docs/           INSTALL.md
public/         document root — index.php, css/app.css, js/app.js
routes/web.php  the whole route table, one file
src/
  Controllers/  Client/*, Staff/*, and the shared few at the top level
  Core/         Router, Auth, Capabilities, Database, View, Csrf, Session,
                Upload, Validator, Config, Env, Flash, Request, Response,
                Crypto, Migrator, LoginThrottle, NotificationTypes
  Mail/         Mailer, EmailTemplate, Merge, Layout
  Middleware/   auth, guest, staff, csrf + MiddlewareRunner
  Models/       one class per table-ish concept, static methods, no ORM
  Services/     Branding, ClearBooksClient, FreeIssueNoteService, Invitations,
                Notifications, PartsOnOrder, PdfService, QrCodeService,
                Reminders, ReferenceNumber
storage/        uploads/ and logs/ — outside public/, never web-reachable
templates/      plain PHP views; layouts/, partials/, one directory per area
```

84 PHP classes, 49 templates, ~1,800 lines of CSS, ~420 of JS.

### Established patterns

**Config and environment.** `.env` → `App\Core\Env` → `config/config.php`
returns one nested array → `Config::get('app.url')`. Nothing reads `$_ENV`
directly. Runtime-editable settings live in the `settings` key/value table
(`App\Models\Setting`) and *win over* the `.env` fallback, so an operator can
change SMTP without a redeploy.

**Database.** PDO with `ATTR_EMULATE_PREPARES => false`. Every query goes
through `App\Core\Database` (`one`, `all`, `scalar`, `run`, `insert`,
`transaction`) with **named placeholders** — no string interpolation of user
input anywhere. `LIMIT` is the one place an integer is cast and inlined, because
it cannot be bound.

**Models** are final classes of static methods returning plain arrays. No
identity map, no lazy loading. Multi-step writes go through
`Database::transaction()` and take `PDO $pdo` so they compose (see
`ReferenceNumber::next()`, which accepts an existing connection precisely so it
can be called inside an open transaction).

**Auth and RBAC.** `users.side` (`staff`|`client`) is the fixed top-level split
and is enforced by a CHECK constraint tying it to `client_id` nullability.
Granular permission is `roles`/`user_roles` many-to-many plus the in-code
`Capabilities::MATRIX`. A generic superset rule in `Capabilities::allows()`
gives `staff.admin` any capability listing a `staff.*` role, and `client.admin`
any listing a `client.*` role, rather than repeating them per row.
`Auth::authorize()` 403s and exits, JSON-aware.

**Pricing visibility** is a hard rule, not styling: anything carrying a price
is gated on `view_pricing` at the point the data is assembled — controllers omit
it from the view payload, `Notifications` refuses to send price-bearing emails
to recipients without it, and the preferences screen does not even offer those
notification types to a user who could never receive them.

**Templating.** `View::render($template, $data, $layout)`; templates are plain
PHP with `<?= e($x) ?>`. `partial()` for fragments. `View::renderFile()` names
its own locals `$__template`/`$__data`/`$__path` because `extract(..., EXTR_SKIP)`
silently drops a view variable that collides with one — a real bug once.

**CSRF** on every state-changing route via the `csrf` middleware and
`csrf_field()`. Token rotates on login.

**Uploads.** Disk under `storage/uploads/<area>/`, randomised filenames
(`Ymd-His-<hex>.ext`), path-traversal blocked by realpath containment, DB stores
the relative path only. Extension allow-list plus finfo magic-number checks for
formats that have one — deliberately skipped for CAD, which does not.

**File responses** go through `Response::file()`, which resolves a real content
type (a stored `application/octet-stream` plus `nosniff` is what forces a
download), sends `Content-Disposition: inline`, and replaces the page CSP with a
narrow one that does *not* sandbox — sandboxing is what makes a browser's PDF
viewer give up and offer a download instead.

**Email.** `Mailer::sendTemplate($key, $to, $name, $fields)` → `EmailTemplate`
(defaults in code, overrides in DB) → `Merge` (escaped `{{placeholders}}`) →
`Layout` (fixed table-based HTML shell, logo embedded by CID) → PHPMailer.
Never throws; every attempt lands in `email_log` either way.

**Routing.** One `Router` with `{name:regex}` placeholders compiled by a
hand-written scanner that counts brace depth, so a quantifier inside a
constraint (`{token:[a-f0-9]{64}}`) works.

---

## 3. Database schema

35 tables. Every table InnoDB/utf8mb4; `uq_`/`idx_`/`fk_`/`chk_` naming
throughout.

### Identity and access

| Table | Notes |
|---|---|
| `clients` | Client companies. `clearbooks_entity_id` links to accounting. |
| `users` | `side` enum + nullable `client_id`, tied by `chk_users_client_side`. `password_set_at` NULL = invited, never signed in. |
| `roles`, `user_roles` | Seven seeded roles, many-to-many. |
| `user_invites` | SHA-256 of the token only, `expires_at`, `accepted_at`. Rows kept after acceptance as the audit trail of how an account came to exist. |
| `login_attempts` | Throttling, per email+IP. |
| `notification_preferences` | Opt-in only; a missing row means "do not send". |

### Parts and quoting

| Table | Notes |
|---|---|
| `parts` | Client-visible fields plus Junction-only workshop fields on one row. `status` draft→quoted, `is_archived` for reversible hiding. |
| `part_alternate_numbers` | Drawing numbers and the like. |
| `part_free_issue_materials` | What material the client supplies. |
| `part_files`, `part_photos` | Drawings and client photos, disk + reference. |
| `part_links` | Symmetric "usually ordered with", one row per unordered pair enforced by `chk_part_links_order (part_id < linked_part_id)`. |

`parts.has_free_issue` is the explicit yes/no, and `chk_parts_free_issue_toggle`
stops a part that has none carrying a ratio for it. Everything that shows, asks
for or chases free-issue material reads `Part::hasFreeIssue()` and hides itself
when the answer is no, rather than rendering empty fields.

Alongside it: `free_issue_relationship` (`none`/`divide`/`multiply`) +
`free_issue_factor` (2–10, enforced by `chk_parts_free_issue_factor`) +
`free_issue_updated_by`/`_at`. **Set by the client** from the quote stage,
overridable by staff, with the override attributed.

### Orders and production

| Table | Notes |
|---|---|
| `orders` | `order_number` unique, `po_number` required at placement, PO document required too. |
| `order_po_documents` | The running history of PO paperwork — amended and additional POs are added, never substituted. |
| `order_lines` | The heart of it — see below. |
| `order_line_quantities` | Where a line's quantity actually sits: one row per stage. |
| `order_line_stage_moves` | Every movement of quantity, including the ones that create and destroy it. |
| `order_line_change_requests` | Client-requested quantity changes, `pending`/`applied`/`declined`. |
| `free_issue_receipts` | One row per check-in event, with `discrepancy_type` (`none`/`shortfall`/`excess`/`wrong_item`), notes, and `resolved_at`/`resolved_by`. |
| `free_issue_rejections` | Material that arrived and could not be used, with the return note out and the replacement request in. |
| `delivery_notes`, `delivery_note_lines` | `type` = `free_issue_in`, `goods_out` or `material_return`. |
| `invoices` | Clear Books reference + amount, per delivery note. |
| `order_notes`, `order_queries`, `order_query_replies` | Timestamped log and threaded queries. |
| `order_photos` | Staff-only progress/setup photos. |

**A line does not have a status.** Its quantity is distributed across stages, and
the status a person reads is written out from that distribution. Nobody sets it,
and there is nowhere it could be set — `order_lines.stage` was dropped in
migration `005`.

The stages are the flow —

```
awaiting_free_issue → ready_for_production → in_production → complete → delivered → invoiced
```

— plus two places quantity ends up when it does not finish: `failed` and
`cancelled`.

**The invariant everything rests on:** the `order_line_quantities` rows for a
line sum to `order_lines.qty_ordered`. Every part ordered is somewhere. Quantity
is created only by placing an order or increasing one, and destroyed only by
reducing one — both of which move `qty_ordered` by the same amount in the same
transaction.

`failed` and `cancelled` are buckets rather than counters for a reason. A failed
part is not a smaller order, it is a part that has to be made again; parking it
somewhere visible is what stops it being quietly forgotten, and returning it to
the flow once replacement material arrives is an ordinary backward move.

The one place the free-issue and no-free-issue paths differ is where quantity
enters: `awaiting_free_issue` for a part built from client material,
`ready_for_production` for one that is not. Everything downstream is the same
code — a line with no material to wait for simply never has anything in the
first bucket.

`qty_completed` / `qty_delivered` / `qty_invoiced` / `qty_failed` /
`qty_cancelled` are still on `order_lines`, and are still what the reports, the
despatch screen and the ageing queries read, but nothing writes to them by hand:
`recalculateTotals()` derives all five from the distribution after every move.
They are a maintained projection, not a second source of truth.

`qty_free_issue_required` joined them in migration `006`. It is worked out, not
accumulated:

```
enough for what is still on the order  (qty_ordered - qty_cancelled)
+ enough to remake what has failed     (qty_failed)
```

rounded up separately, because a single failed part still needs a whole bar and
the spare that leaves is the honest answer. Rejected material needs no term of
its own — it counts as received and is subtracted again when the outstanding
figure is worked out, so rejecting three puts exactly three back on to what is
owed. Deriving it is what makes the replacement figure move with the shortfall:
adding a top-up each time something failed stacked requests that stopped
matching reality the moment anything else went wrong.

### Two units of measure

Before a part is machined, the thing on the floor is a piece of material; after
it, a finished part. For anything but a 1:1 ratio those are different counts, and
counting bars as parts is how somebody goes looking for twenty of something when
there are ten.

- `awaiting_free_issue`, `ready_for_production`, `in_production` are **read and
  entered in material units**.
- `complete`, `delivered`, `invoiced`, `failed`, `cancelled` are **final parts**.

Storage does not change: everything is held in final parts, because that is the
only quantity the whole order agrees on and it keeps the distribution summing to
`qty_ordered`. The conversion happens where a person reads or types a number —
`OrderLine::displayQty()` on the way out, `storedQtyFromEntered()` on the way in
— and the rule for input is that you type in the unit of the stage you are
taking from, because that is the pile in front of you.

Ten bars at divide-by-2 therefore read as 10 at every stage up to production and
become 20 the moment they are complete. On a 1:1 part every conversion is the
identity, so the model costs those lines nothing and shows them nothing: the
"counted in" column and the unit words only appear where the two counts differ.

Checking free-issue material in is the one and only way quantity leaves
`awaiting_free_issue`, and the check-in screen is the only place it happens. The
order page shows where a line has got to and links there; it has no material
inputs of its own, and the move endpoint refuses that transition, because two
ways of recording the same arrival is how two different answers end up on one
line.

What is accepted at check-in goes straight to ready for production. Ten bars of
which three are cracked is seven bars of work that can start today, not a
delivery to be argued about before anything moves.

### Configuration and operations

| Table | Notes |
|---|---|
| `settings` | Key/value, runtime-editable. SMTP password AES-256-GCM encrypted. |
| `email_templates` | **Overrides only** — a row exists iff somebody edited that message. |
| `email_log` | Every send attempt, sent or failed, with the error verbatim. |
| `reminder_runs` | One row per digest actually sent; also the interval guard. |
| `clearbooks_tokens` | OAuth token pair, auto-refreshed. |
| `reference_sequences` | Per-year counters behind ORD-/DN-/RC- numbers. |
| `migrations` | Applied migration filenames. |

### Migrations

| File | Contents |
|---|---|
| `001_schema.sql` | Original 18 tables. |
| `002_feature_expansion.sql` | Roles/permissions, settings, part links, free-issue ratio, photos, notes/queries, discrepancies, notification preferences. Renamed `users.role` → `users.side`. |
| `003_invites_templates_reminders.sql` | `email_templates`, `user_invites`, `reminder_runs`, `users.password_set_at`, free-issue attribution columns and the 2–10 factor CHECK. |
| `004_backfill_completed_quantities.sql` | Data repair: `qty_completed` on lines marked complete before `setStage()` kept them in step. |
| `005_quantity_workflow.sql` | The quantity distribution and its movement log; `parts.has_free_issue`; `orders.po_number` and `order_po_documents`; `order_line_change_requests`; `free_issue_rejections` and the `material_return` note type; close-down columns. Backfills the distribution from the old columns, migrates `production_status_log` into the movement log and drops it, drops `order_lines.stage` and drops `route_cards`. |

| `006_derive_free_issue_requirement.sql` | Data repair: recomputes `qty_free_issue_required` on every line under the new derivation, so rows carrying accumulated one-off top-ups come on to the calculated footing. |

`005` is the one migration that is genuinely forward-only: it reads
`production_status_log` and then drops it, so a second run has nothing to read.
The `migrations` table is what stops that happening.

---

## 4. Feature status

### Original brief

| Deliverable | Status |
|---|---|
| Multi-client data model | Done |
| Quote request → quoted price | Done |
| Order placement with PO upload | Done |
| Free-issue tracking at line level, partial receipt | Done |
| Partial/batched delivery at line level | Done |
| Delivery notes (in and out) with PDF | Done |
| Route cards with QR | Done |
| Clear Books invoicing | OAuth done; **payload unverified** — see §5 |
| Light/dark theme | Done |
| Install/maintenance notes | Done — `docs/INSTALL.md` |

### 14-item change request

| # | Item | Status |
|---|---|---|
| 1 | Light/dark toggle matching Kitwell exactly | Done — same markup, same CSS, crescent/sun verified in both themes |
| 2 | Symmetric "usually ordered with" part linking | Done — surfaced on the part page and as a suggestion while ordering |
| 3 | Client-editable parts, archive, delete-if-unused | Done |
| 4 | Free-issue divisor/multiplier on a part | Done — now client-set, 2–10 either way |
| 5 | AJAX part search on the order form, auto free-issue qty | Done — and recalculated server-side |
| 6 | Free-issue delivery notes with QR check-in, discrepancies | Done — a flagged discrepancy is shown on the line and on the report until resolved |
| 7 | Cumulative parts-completed per line | Superseded by the quantity distribution |
| 8 | Order notes and threaded queries | Done |
| 9 | Delivery notes grouped on the order page | Done |
| 10 | Seven roles, multi-role, pricing hidden entirely | Done |
| 11 | Logo upload | Done — nav, sign-in, PDFs, embedded in email |
| 12 | Email config + opt-in notification preferences | Done |
| 13 | Settings menu, staff.admin only | Done |
| 14 | Client part photos, staff order photos | Done |

### Follow-up round (17 August 2026)

| Item | Status |
|---|---|
| Pull full styling from Kitwell | Done — `app.css` rebuilt from Kitwell's |
| Menu bar with dropdowns matching Kitwell | Done — `<details>` groups, drawer below 1150px |
| Menu must not wrap | Done — verified 1400/1280/1150/1100px, header stays 61px |
| Split sections for menu-level access | Done — Parts ▸ New part, Orders ▸ Place an order, Settings ▸ 7 entries |
| Move Clients to Settings | Done |
| Invite-based user creation | Done — three entry points, single-use 7-day links |
| Free-issue ratio client-set, range to 10 | Done — with staff override and attribution |
| PDFs view rather than download | Done — `Response::file()` |
| Kitwell email template system | Done — 12 editable templates, merge fields, preview |
| Parts-on-order report with cross-PO totals | Done — grouped per part, CSV export |
| Cron email reminders for outstanding parts | Done — `bin/reminders.php` + Settings → Reminders |

---

### Deployment round (18 August 2026)

| Item | Status |
|---|---|
| `install.sh` — prompt-driven installer | Done — same shape as Kitwell's: OS/package detection, answers file, `--dry-run`, `--non-interactive`, upgrade-in-place |
| `manage.sh` — single admin entry point | Done — 28 commands; linked to `/usr/local/sbin/tracker` |
| `bin/console.php` extended | Done — 19 commands; everything touching the database goes through it |
| `/health` endpoint | Done — unauthenticated, deliberately uninformative |
| Clear Books verified against the spec | Done — see below; the previous implementation was wrong in every guessed part |
| GitHub repository | <https://github.com/maeterlinckle/production-tracker> |

---

### Quantity workflow round (18 August 2026)

| # | Item | Status |
|---|---|---|
| 1 | Staff can raise a part on a client's behalf | Done — `staff.quoting`, same fields, indistinguishable afterwards |
| 2 | Explicit free-issue toggle on the part forms | Done — hidden, not blank, everywhere free issue would otherwise appear |
| 3 | Route cards built on request, no regenerate button | Done — nothing stored; `route_cards` dropped |
| 4 | Order page delivery notes listed by CPN, not type | Done — free-issue, returns and goods-out are three lists, each with the CPN |
| 5 | Free-issue note: "Quantity Required" + blank "Actual Quantity Sent" | Done |
| 6 | Quantity-driven production workflow | Done — see §3; includes failures with reasons, rejection vs shortage, and close-down |
| 7 | Free-issue notes ask for what is still outstanding | Done — living document, rendered fresh, never stored |
| 8 | Client-requested quantity changes with PO history | Done — staff apply or decline; a decrease cannot eat into what is made |
| 9 | PO number on the order, through to Clear Books | Done — the invoice `reference` field |

### Check-in and unit-accuracy round (19 August 2026)

| # | Item | Status |
|---|---|---|
| 1 | Check-in moved out of the production status table | Done — that row links out; the move endpoint refuses the transition too |
| 2 | Production status table redesigned | Done — a real table, data left, actions right, one colour language with the bar |
| 3 | Check-in as a single conditional form | Done — Yes/No with no default, rejection rows revealed by No, submit gated on both |
| 4 | Check-in submission logic | Done — accepted quantity straight to ready for production, return note linked afterwards, replacement asks for exactly what was rejected |
| 5 | Quantities in physical units before completion | Done — see "Two units of measure"; replacement material derived from the live shortfall |
| 6 | Inputs aligned with their buttons | Done — one `.action-row` rule across the page |
| 7 | All route cards for an order in one action | Done — one card to a page, built live like the single one |

**The two decisions left open on this round, and how they were taken.**

*The table's design.* A table rather than a stack of bordered rows: stage,
quantity, unit, action, with a rule separating the data from the controls so the
quantities can be read straight down without threading between input boxes. Each
row carries the same colour as its segment of the bar above it, so a colour means
one thing on the page.

*Where "request additional material" lives.* Inline, in the failed section of the
line it belongs to, with the figure and its arithmetic spelled out beside the
button — "2 more pieces, which yields 12 and leaves 5 spare". It takes no
quantity input at all, because there is nothing to choose: the figure is the
current shortfall converted by the part's own ratio, and typing one in would
invite it to disagree.

**The earlier decisions from the quantity round, and how they were taken.**

*Stage names.* The Prompt 1 vocabulary was kept where it fitted, and the two
stages the old model handled with columns rather than states — `delivered` and
`invoiced` — became stages of their own, so that a quantity's whole life is one
sequence. `closed` was dropped: it meant "nothing left to do", which the
distribution now says by itself.

**One earlier behaviour was deliberately reversed.** Under the old model an
unresolved free-issue discrepancy *blocked* a line from advancing, because
advancement was automatic and a gate was the only way to stop it. Advancement is
now a decision somebody makes, one quantity at a time, so a hard block would
mean refusing a staff instruction — and the model exists precisely to let staff
push forward what they can while something else is unresolved. The discrepancy
is instead shown on the line, on the check-in screen and on the parts-on-order
report until it is cleared, and a rejection now has real machinery behind it
rather than only a flag.

*Where a rejection's replacement request goes.* It reissues the free-issue note
that is already out for that line rather than raising a second one. There is at
most one outstanding request per line by design — two notes asking for
overlapping material is the state in which somebody sends twice, or sends
nothing because they assume the other note covers it. If no note is open, one is
created. Only failed or rejected quantity triggers this; an ordinary shortage is
already covered by the note that is out, and does nothing.

---

## 5. Clear Books — what the verification found

Checked against <https://api.clearbooks.co.uk/spec/v1.yaml> (OpenAPI 3.1,
v1.0.0) and <https://api-docs.clearbooks.co.uk/>. The OAuth *mechanism* had
been identified correctly in an earlier round; every concrete value below it
had been guessed, and every guess was wrong.

| | Before (guessed) | Per the spec |
|---|---|---|
| Authorise URL | `oauth-helper.clearbooks.co.uk/oauth2/authorize` | `secure.clearbooks.co.uk/account/action/oauth/` |
| Token URL | `oauth-helper.clearbooks.co.uk/oauth2/token` | `api.clearbooks.co.uk/oauth/token` |
| API base | `api.clearbooks.co.uk` | `api.clearbooks.co.uk/v1` |
| Invoice endpoint | `POST /v2/invoices` | `POST /accounting/sales/invoices` |
| Scopes | none requested | six required, named |
| PKCE | absent | supported and recommended — now used |
| Business selection | absent | `X-Business-ID` header |
| Invoice fields | `entity_id`, `lines[]`, `unit_price` | `customerId`, `vatTreatment`, `lineItems[]`, `unitPrice` |
| Line fields | description, quantity, unit price | + `accountCode` and `vatRateKey`, both required |
| Response | `invoice_number`, `total` | `formattedDocumentNumber`, `gross` |
| Rate limiting | unhandled | 429 with exponential backoff on reads |
| Errors | status code only | their `errorCode`/`errorMessage` repeated verbatim |

Consequences worth remembering:

- **The client "entity reference" was never an entity reference.** It is the
  numeric ID of a Clear Books *customer*. The field is relabelled and validated;
  a non-numeric value is refused with an explanation rather than sent.
- **Three new settings are mandatory** before an invoice can be raised: sales
  account code, VAT treatment and VAT rate. There is no sensible default for
  any of them — they are properties of Junction's own chart of accounts — so
  the settings page reads the live lists from the API and offers them.
- **The endpoints are no longer configurable.** They were in `.env`, which
  meant the wrong values were installable. They are constants now.
- Refresh tokens are single use and there is one access token per user per
  application, so reconnecting revokes the current token. Both are handled and
  both are documented on the settings page.

Still not done: **no invoice has been raised against a live Clear Books
account from this code.** The request is built from the published spec rather
than guessed, which is a different thing from proven. Raise one real invoice
against a low-value delivery note before trusting it.

---

## 6. Known gaps and things to watch

**No automated test suite.** Matches Kitwell's level of rigour, as specified.
Verification is a full HTTP sweep across four role levels plus browser testing
of the workflows. `tests/` exists but is near-empty.

**No password reset.** Not requested. Invitations cover onboarding, and
`tracker reset-password EMAIL` covers a forgotten one. If self-service reset is
wanted later, `user_invites` and `InviteController` are the shape to copy.

**Orders have no due date.** Nothing in the schema records when a customer
wants parts. "Overdue" in the reminder digest and the report therefore means
*open longer than the configured ageing threshold*, counted from the order
date. If real promised dates matter, that is a column on `orders` or
`order_lines` and a change to `PartsOnOrder`.

**The installer has not been run end to end on a real server.** Its syntax,
argument handling, guards and help are verified; the package installation, web
server configuration and SELinux paths cannot be exercised from a Windows
development box. It is closely derived from Kitwell's, which is in service, and
the risky steps are all behind `--dry-run`.

**Local development note.** The PHP on this Windows box has no `php.ini`, so
extensions load only when one is supplied:

```bash
PHPRC=<scratchpad> php bin/console.php doctor
php -c <scratchpad>/php-test.ini -S 127.0.0.1:8321 -t public
```

Without it, `bin/*.php` dies at bootstrap on a missing `mb_internal_encoding`.
Not a code problem — a machine setup one — but it will waste ten minutes if
somebody hits it cold.

---

## 7. Immediate next steps

1. **Raise one real Clear Books invoice** against a low-value delivery note.
   The only part of the system never exercised against the live service.
2. **Configure real SMTP** and re-run the invitation and reminder flows. Both
   are proven end to end locally, but every send so far has failed at the
   connection, by design of the test.
3. **Run `install.sh --dry-run` on the target server**, then the real thing.
