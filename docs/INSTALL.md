# Production Tracker — install & maintenance notes

Internal notes for Nick (sole admin). The project lives at
<https://github.com/maeterlinckle/production-tracker>, and the installer and
management script both work from that repository.

## 1. Requirements

- A Linux server. `install.sh` handles Debian/Ubuntu, RHEL/Fedora, SUSE and
  Arch, and installs whatever is missing.
- PHP 8.1+ with `pdo_mysql`, `json`, `mbstring`, `fileinfo`, `gd`, `dom`,
  `curl` and `openssl`. `gd` and `dom` are not optional here: dompdf renders
  every delivery note and route card, and the QR code on a free-issue note is
  what the workshop scans.
- MariaDB/MySQL, with a dedicated database and user — the installer creates
  both. Don't reuse Kitwell's database or credentials; this is an independent
  application that happens to be deployed on the same stack.
- Composer. The installer fetches it if the machine has none.
- **One optional cron entry**, for the outstanding-parts reminder digest (§7).
  Nothing else is scheduled: every other background-shaped action (PDF
  generation, emails, Clear Books calls) runs synchronously inside the request
  that triggers it. Without the cron entry everything works — it just never
  sends that one digest.

## 2. Install

Two commands from a bare server to a working site:

```bash
git clone https://github.com/maeterlinckle/production-tracker.git
sudo ./production-tracker/install.sh
```

The installer asks for what it cannot work out — install directory, hostname,
how HTTPS is handled, the database name, and the first administrator — and
does the rest: installs packages, creates the database and its user, writes
`.env` with a generated `APP_KEY` and database password, sets ownership and
modes, configures Apache or nginx, raises PHP's upload limits, runs the
migrations, creates the administrator and checks the result.

Worth knowing before you start it:

- `--dry-run` prints the plan and changes nothing. Do that first.
- Nothing outside the install directory, the web server config, `/etc/php*`
  and the named database is touched.
- Re-running it against an existing install is an **upgrade**: the files are
  refreshed and the migrations re-run, and `.env`, `storage/` and the database
  are left alone. It says so and asks before doing it.
- It links `/usr/local/sbin/tracker` to the installed `manage.sh`, so
  administration is `sudo tracker <command>` from anywhere.

### Unattended installs

Every answer can come from a file instead:

```bash
sudo ./install.sh --answers=/root/tracker.answers --non-interactive
```

`./install.sh --help` lists every key. The file holds a database password and
an administrator password, so create it mode 600 and delete it afterwards —
the installer reminds you at the end.

### Doing it by hand

If you would rather not run the installer, the manual equivalent is: create the
database and user, `composer install --no-dev --optimize-autoloader`, copy
`.env.example` to `.env` and fill it in (§3), `php bin/migrate.php`, then
`php bin/console.php user:create --name=… --email=… --roles=staff.admin
--stdin-password`. Point the document root at `public/` — nothing outside that
directory should be web-reachable — and make `storage/` writable by the
PHP-FPM user.

## 3. What is in `.env`

The installer writes this; these are the keys worth knowing about when
something needs changing later. `manage.sh config KEY [VALUE]` reads and edits
them without an editor, keeping a timestamped backup each time.

- `APP_URL` — the real external URL, no trailing slash. Used to build links in
  emails, QR codes and PDFs, and to compute the base path correctly behind a
  reverse proxy.
- `APP_KEY` — generated at install. It is the AES-256-GCM key for the one
  secret held in the database (the SMTP password). **Changing it makes that
  password unreadable**, so back it up with the database rather than instead
  of it. `php bin/console.php key:generate` prints a fresh one if you ever
  need it.
- `DB_*` — the database, user and generated password.
- `TRUSTED_PROXIES` — the reverse proxy's IP, or `*` when the application is
  never reachable except through it. Without it, HTTPS detection and the
  client IP used for sign-in throttling are both wrong behind Caddy.
- `MAIL_*` — the fallback SMTP details. Normally set from **Settings → Email**
  instead, and the stored values win once they are. If neither is filled in,
  sends fail closed and are logged to `email_log` rather than crashing
  whatever triggered them.
- `CLEARBOOKS_CLIENT_ID` / `_SECRET` / `_REDIRECT_URI` — fallback OAuth client
  credentials; same story as mail. The API and OAuth **endpoints** are not
  configurable: they are published in the Clear Books OpenAPI description and
  live as constants in `App\Services\ClearBooksClient`.

## 4. Database

```bash
sudo tracker migrate --status   # see what is pending
sudo tracker migrate            # apply it
```

Migrations are plain numbered `.sql` files in `database/migrations/`, tracked
in a `migrations` table. Forward-only, no rollback — to undo something, write a
new migration. Safe to re-run.

If a migration stops with **"command denied"**, the database user was created
without the rights the schema changes need. `sudo tracker db-grant` re-applies
the grant (it asks for the MariaDB root account), then run `migrate` again.

## 5. Users, roles and permissions

The installer creates the first administrator. If you ever need another, or
everybody is locked out:

```bash
sudo tracker create-admin
```

It prompts for name, email and password, never echoes the password, and pipes
it to the application rather than passing it as an argument — an argument would
be visible in `ps` and would land in root's shell history.

That is the *only* place a password is typed in by somebody other than its
owner, and it exists because the first account has nobody to invite it. Every
account after that is created by invitation (§5a).

`bin/create-admin.php` is still there and does the same job directly, for a
machine where `manage.sh` has not been installed.

**Roles** (multi-role per user; seeded by migration `002`):

| Side | Role | Can |
|---|---|---|
| Client | `client.admin` | Everything below, plus manage users/roles for their own company (**Team** in their nav) |
| Client | `client.purchaser` | Create/edit parts, place orders |
| Client | `client.production` | View orders, raise/answer queries, return rejected parts — no pricing, no ordering |
| Staff | `staff.admin` | Everything, including Settings |
| Staff | `staff.invoicing` | Raise Clear Books invoices, set a client's posting details, and see prices |
| Staff | `staff.quoting` | Set part pricing, raise parts for a client, decide quantity changes |
| Staff | `staff.raise_orders` | Place an order on a client's behalf, and amend one when the PO changes |
| Staff | `staff.production` | Check in free issue and returned parts, update production status, generate paperwork |

`staff.raise_orders` is separate from `staff.quoting` on purpose: deciding a
price and committing a client to buy at it are different jobs, and one person
holding both should be somebody's decision rather than a side effect of the
role list.

A part page and an order page are each one page shown to both sides: `/parts/{id}`
and `/staff/parts/{id}` render the same template, as do `/orders/{id}` and
`/staff/orders/{id}`. What a person sees is decided by their role, not by which
address they arrived at — so a client and a member of staff looking at the same
order are looking at the same thing, with different parts of it switched on.

Pricing (quoted prices, invoice amounts) is only ever sent to the browser or
an email for users holding `view_pricing` (`client.purchaser`/`client.admin`
on the client side, `staff.quoting`/`staff.invoicing`/`staff.admin` on the
staff side) — it's omitted from the response entirely for everyone else, not
just hidden by CSS.

### 5a. Invitations

Nobody but the account holder ever knows their password. Inviting somebody
creates the account inactive, with a hash of random bytes in the password
column, and emails them a single-use link; they choose a password from that
link, which activates the account and signs them in.

Three places send one:

| Who | Where | Creates |
|---|---|---|
| `staff.admin` | **Settings → Users** | Junction staff accounts |
| `staff.admin` | **Settings → Clients → a client → Invite somebody** | That client's login users |
| `client.admin` | Their own **Team** page | Users in their own company only |

`client.admin` can only ever grant `client.*` roles to users in their own
company; staff-side roles and other companies are never reachable from there.

Links last **7 days** and work once. Issuing a fresh invitation expires any
outstanding one for that account, so there is never more than one live link per
person. A pending account shows as **Invited** in the relevant list with a
**Re-send invitation** button next to it.

**If email is not working**, the invitation is not silently lost: the screen
says so and gives you the link to pass on by hand. The `user_invite` template
is the one message that cannot be switched off (§6b), precisely so the
interface can never promise an email it did not send.

## 6. Settings (staff.admin only)

Everything below lives under **Settings** in the staff nav, backed by a
generic `settings` key/value table so it's editable without a redeploy. The
Settings index is a set of cards, each showing enough of its own state
("Connected", "3 edited", "Off") to answer *is anything wrong here?* without
opening every screen underneath it.

### 6a. Email connection

**Settings → Email**: SMTP host/port/encryption/username/password/from
address, a "send a test message" action, and the last 10 send attempts. The
password is AES-256-GCM encrypted at rest using `APP_KEY` (§3) — leaving the
password field blank on save keeps the existing one; there's a separate
"clear the stored password" checkbox so an unrelated settings change can't
silently wipe it.

### 6b. Email templates

**Settings → Email templates**: the wording of every message the tracker
sends, editable in place.

The shipped text lives in `App\Mail\EmailTemplate::DEFAULTS` and the
`email_templates` table holds **overrides only** — a row exists for a message
precisely when somebody has edited it. So a fresh install sends properly
worded mail from an empty table, and "reset to the built-in wording" is a
`DELETE` rather than a re-seed that could go stale.

Each template documents its own merge fields (`{{order_number}}` and the
like), and the editor **refuses to save** a placeholder the sending code does
not supply — the alternative is a message that goes out with a blank where a
number should be. Values are escaped when they are merged, so an apostrophe in
a company name cannot break the markup.

Most messages can be switched off individually. `user_invite` cannot: it
carries a link somebody is waiting for, and silencing it would leave the
interface saying "we have emailed them" about a message that never went.

### 6c. Reminders

**Settings → Reminders**: the scheduled digest of parts still outstanding.
See §7 for the cron side of it.

- **Off by default.** Nothing is sent until it is switched on.
- **Opt-in per person**, like every other notification: each staff member ticks
  *"The scheduled digest of parts still outstanding"* on their own **Email
  notifications** page. It is offered to staff only.
- **Nothing outstanding, nothing sent.** An empty digest teaches people the
  message is not worth opening.
- The interval is a floor, not a schedule — cron decides when to call, this
  decides whether a call actually sends, so an extra cron entry or a re-run
  after a failure cannot double up.
- **Send it now** runs exactly what cron runs, ignoring only the interval.

### 6d. Logo

**Settings → Logo**: upload separate light-mode and dark-mode variants (PNG/
JPEG/WEBP only). If only one is set, it's used for both themes. Appears in
the top nav, the sign-in page, PDF paperwork headers, and embedded in outbound
email — and, once one is set, replaces the "PT" text mark everywhere.

### 6e. Clear Books connection

Written against the published Clear Books OpenAPI description
(<https://api.clearbooks.co.uk/spec/v1.yaml>, v1.0.0) and the reference at
<https://api-docs.clearbooks.co.uk/>. The endpoints, scopes, invoice path and
every payload field come from that spec.

Authentication is **OAuth 2, authorization-code grant, confidential client,
with PKCE**. There is no static API key. Three things have to happen once:

1. **Register an OAuth client.** Request API access at
   <https://www.clearbooks.co.uk/support/api/>. You get a
   `client_id`/`client_secret`, and you have to register a redirect URI — use
   `https://<your-domain>/staff/settings/clearbooks/callback`. It must match
   character for character.
2. **Connect.** Enter the client ID, secret and redirect URI at
   **Settings → Clear Books** (or in `.env` — see §3), then click **Connect
   Clear Books**. That runs the consent flow and stores the token pair in
   `clearbooks_tokens`, refreshed automatically from then on.
3. **Choose the posting details, per client.** These are *not* on the settings
   page — they are on each client's own page, at **Clients → a client → Clear
   Books invoicing**, and they need the `staff.invoicing` role. Junction's
   clients do not agree with each other about any of them, so one global set
   would be right for at most one of them.

   | Setting | Why it is needed |
   |---|---|
   | Clear Books customer ID | The numeric ID of that company's *customer* record. Nothing can be raised without it. |
   | Business | Sent as `X-Business-ID`. Only required when the login has more than one business. The account codes and VAT rates offered below belong to it, so save a change here first. |
   | Sales account code | The nominal every invoice line for this client posts to. Required on each line; only codes flagged as sales codes are offered. |
   | VAT treatment | Required on the document itself. |
   | VAT rate | Required on each line. The list is the rates valid for the chosen treatment, so save the treatment first. |
   | Due date | Whether to send one at all, and how many days from the invoice date if so. See below. |
   | Invoice summary | Optional. Written into the invoice's Summary field, with placeholders filled in. See below. |

   `sudo tracker clearbooks-status` prints the connection *and* a per-client
   table of who is ready and what each one is still missing. So does the
   settings page.

**The customer mapping is a number.** It is the number in the Clear Books URL
when you open that customer — not their name, not an account code. Raising an
invoice for a client without one is refused with a message saying so.

**Leaving the due date unset.** The due-date rules available in the Clear Books
interface — end of the month following, and the like — are richer than the
single date the API accepts. For a client whose real terms are one of those,
untick **Send a due date on the invoice**: nothing is sent, and Clear Books
applies that contact's own default, which is where the correct rule already
lives. Ticked, the invoice carries the invoice date plus the payment terms.

**The invoice summary** is a template written once per client and rendered for
each invoice into the field the Clear Books interface labels Summary. These
placeholders are substituted; the same list is shown under the field:

| Placeholder | Becomes |
|---|---|
| `{po_number}` | The client's PO number(s) on the note — all of them where it covers several orders |
| `{order_number}` | The Junction order number(s) |
| `{delivery_note}` | The delivery note reference |
| `{client_name}` | The client company name |
| `{invoice_date}` | The date raised, dd/mm/yyyy |

Anything else in curly brackets is left on the invoice exactly as typed, so a
misspelt placeholder is visible rather than silently blank. Leave the field
empty and no summary is sent at all.

**The purchase orders go up with the invoice.** Every PO document on every order
the delivery note covers is attached to the Clear Books invoice as a sales
attachment, amendments included, named after the order it belongs to. Nothing
needs configuring. If a file cannot be attached — missing from disk, or refused
by Clear Books — the invoice still stands and a warning names what did not go
up, to be attached in Clear Books by hand.

**Upgrading from a version before this.** Migration `016` copies the old global
posting settings onto every client that existed when it ran, so nothing changes
for them. Clients added afterwards start with nothing set and have to be
configured before they can be invoiced.

Two behaviours of theirs the application works around, worth knowing if
something looks odd:

- **Refresh tokens are single use.** Each refresh returns a new pair and
  invalidates the old one. Handled automatically; it only matters if you are
  reading the `clearbooks_tokens` table and wondering why it keeps changing.
- **One access token per user per application.** Completing the consent flow a
  second time silently revokes the first token. **Reconnect** on the settings
  page therefore ends the connection it is replacing — fine, since it issues a
  fresh pair immediately, but there is no reason to do it unless something is
  wrong.

Rate limiting starts above roughly 5 requests a second and answers HTTP 429.
Reads back off and retry; the invoice POST deliberately does not, because a
retried create is a duplicate invoice.

Every goods-out delivery note that has not been invoiced yet shows up on the
staff dashboard and at **Delivery notes → Not yet invoiced** — check that view
periodically so nothing gets missed.

**Still worth doing once:** raise a single real invoice against a low-value
delivery note and check it lands correctly in Clear Books. The request is built
from their published spec rather than guessed, but no invoice has yet been
raised against a live account from this code.

## 7. The one cron entry

The outstanding-parts digest (§6c) is the only thing in the application that
has to be called from outside a request:

```
0 7 * * * php /path/to/production-tracker/bin/reminders.php
```

The exact line, with the right path already in it, is printed on the
**Settings → Reminders** screen.

Calling it daily is safe whatever interval is configured — the interval is
enforced inside the script. It is deliberately **silent when there is nothing
to do**: cron mails the owner anything a job prints, and a script that says
"nothing to send" every morning is a script that gets filtered, taking the one
genuinely failed run with it. Output means something happened or something is
wrong. Exit status is 0 for "ran, or correctly did nothing" and 1 for a real
problem, so a monitoring wrapper can tell the two apart.

Two flags for checking it by hand:

```bash
php bin/reminders.php --dry-run   # who would get it, and what is outstanding
php bin/reminders.php --force     # send now, ignoring the interval
```

## 8. Reports

**Reports → Parts on order** answers the question the orders list cannot:
*how many of this part do we owe, across everything?* The same part often sits
on several purchase orders at once, and setting up twice for two lines of the
same component is the waste this exists to prevent. Rows are per part, with the
orders making up each total underneath, and lines held waiting for free-issue
material are called out separately from lines simply waiting their turn.

"Outstanding" means ordered minus made — parts that still have to come off a
machine. Completed-but-undelivered is shown separately, because that is a
despatch job rather than a production one.

**Export CSV** exports one row per order line rather than the per-part
totals: a spreadsheet can group for itself, but it cannot ungroup. Whatever
client filter is applied on screen applies to the export too.

## 9. Free issue, and where the ratio lives

Free-issue material — stock the client supplies for Junction to work on — is
tracked at **order-line level**, and how much of it an order needs is worked
out from a ratio held on the **part**:

- **1:1** — one piece of material per part (the default).
- **Divide by N** — one piece yields N parts. An order for 20 at divide-by-4
  needs 5 pieces. Division always rounds **up**: you cannot send a fraction of
  a bar.
- **Multiply by N** — each part is built from N pieces. An order for 20 at
  multiply-by-3 needs 60.

N runs from 2 to 10 either way.

All of this only applies to a part with **"This part is made from free-issue
material" ticked**. With the box clear there is no material to work out, and
every free-issue field, column and reminder is absent for that part rather than
shown empty — on the part page, the order form, the order page, the check-in
screen and the route card alike.

**The client sets it**, on the part, from the quote stage — they know how many
parts come out of a length of their own bar. Junction can correct it from
**Parts → a part → Junction-only workshop details**; whoever last changed it
and when is recorded and shown, so an override is visible rather than silent.

The figure shown while an order is being built is **calculated, not asked
for**. The server recalculates it on submission and takes whichever is larger,
so a client can send *more* material than the ratio calls for — a real thing to
do — but cannot post a smaller number and quietly produce a line that waits for
no material at all.

Receipt is not a yes/no. Each check-in records what actually arrived, and a
shortfall, an excess or a wrong item is flagged as a discrepancy, shown on the
line and on the parts-on-order report until somebody clears it — because "the
numbers match" is not the same as "the material is right".

Checking material in happens on **one screen**, reached from the "Awaiting free
issue" row of the production status table or by scanning the QR code on the
note. It is the only place material is booked in, and the only place anything
wrong with it is recorded — the order page has no material inputs of its own.

The form asks three things: how many arrived, any notes, and **are all the
received parts correct?** Answering *yes* puts the whole delivery into ready for
production. Answering *no* opens a set of rejection rows — a quantity and a
reason each, as many as you need — and what is left after them goes into
production instead. Five bars turning up of which one is cracked is four bars'
worth of work that can start today.

The submit button stays out of reach until the form makes sense: a quantity, an
answer, and for a rejection every row filled in with a total that does not exceed
what arrived. The same rules are enforced on the server, because a disabled
button is a courtesy, not a control.

**Rejecting** material is not the same as a shortage:

| | What it means | What happens |
|---|---|---|
| Shortage | It has not arrived yet | Nothing. The free-issue note that is already out still asks for it. |
| Rejection | It arrived and cannot be used | A return note is raised for what goes back — linked from the screen as soon as it is created — and exactly that quantity goes back on to what the line still needs, so the note already out asks for it again. |

The free-issue note is a **standing request, not a shipment record**. It is
rendered fresh every time it is opened and shows what is outstanding *today* —
"8 of 20 already received — 12 still required" — so reprinting it is always
right and reusing an old printout never is. The printable note has a blank
**Actual Quantity Sent** column for the client to fill in by hand when packing.

### The quantity workflow

An order line's quantity is spread across stages rather than sitting at one:

```
awaiting free issue → ready for production → in production → complete → delivered → invoiced
```

Staff move any number of parts one stage at a time, forwards or back, from the
production status table on the order page. A line reads "12 awaiting free issue,
5 ready for production, 3 in production" because that is what is true; there is
no status field anywhere. The one move the table will not do is the first —
material comes in through check-in.

**The first three stages count material, the rest count parts.** For anything but
a 1:1 part those are different numbers: ten bars at divide-by-2 are ten things in
the rack and twenty parts once they are through the machine. So awaiting free
issue, ready for production and in production all read *10*, and completed reads
*20*. The table says which unit each row is in, and the line carries a note —
"5 received parts will produce 30 final parts" — so nobody has to do the
arithmetic. On a 1:1 part none of this appears, because there is nothing to say.

Type quantities in the unit of the row you are moving *from*: that is the pile in
front of you.

Two more places quantity can end up:

- **Failed** — with a reason, and a record of which stage it failed at. Failed
  parts are still owed, so they stay in the outstanding figure. Where the part is
  free-issue, the failed section offers to ask the client for replacement
  material. The quantity is not typed in: it is the current shortfall in parts
  turned back into material by the part's own ratio, rounded up — one failed part
  at divide-by-2 still needs a whole bar, and the screen says so, spare and all.
  Fail two more tomorrow and the same figure moves; it does not stack a second
  request beside the first.
- **Cancelled** — **Close the line down** (or the whole order) cancels off
  everything still to be issued, received or made. It is recorded with a reason,
  not deleted, and stops counting as outstanding from that point. Parts already
  made still have to go out and still have to be invoiced.

Parts become *delivered* by appearing on a delivery note and *invoiced* by
appearing on an invoice — never by anybody typing the quantity in. A second way
of saying the same thing would disagree with the first within a week.

### Quantity changes

A client can ask for a different quantity on a line that is already running,
from the order page, attaching an amended or additional purchase order if they
have one. The request changes nothing by itself: staff apply or decline it, and
applying it is what moves the order.

An increase drops the extra parts in at whichever stage the line starts at. A
decrease comes out of the least advanced stages first and **cannot go below what
has already been made, delivered or invoiced** — the review screen says where
that floor is before anybody commits, and the attempt is refused if it would
cross it.

Purchase order documents are a history. An amended PO is added alongside the
original, never in place of it, because the original is what the price was
agreed against.

## 10. Backups

```bash
sudo tracker backup            # to /var/backups/production-tracker
sudo tracker backup /mnt/nas   # or wherever
```

Three files per run, and **all three are needed for a working restore**:

- the database dump;
- `storage/uploads/` — drawings, purchase orders, and the delivery-note PDFs
  that record a movement (goods out, material returned). The database only holds
  relative paths into this directory, so a dump without it restores a site full
  of broken links. Route cards and free-issue notes are not in there and do not
  need to be: both are built from live data whenever they are opened;
- `.env` — because it carries `APP_KEY`, and without that the stored SMTP
  password will not decrypt.

The last 14 sets are kept (`BACKUP_KEEP` changes that). Everything is written
mode 600 in a mode 700 directory, and the database password goes to
`mariadb-dump` through a defaults file rather than the command line, so it
never appears in the process list. Copy the files off the machine — a backup on
the same disk is not a backup.

To restore:

```bash
sudo tracker restore /var/backups/production-tracker/production_tracker-20260818-021500.sql.gz \
                     /var/backups/production-tracker/uploads-20260818-021500.tar.gz
```

It warns, asks, restores, re-applies permissions and runs `doctor`. If the
`APP_KEY` in the current `.env` is not the one the database was written with,
it says so — re-enter the SMTP password in Settings and everything else is fine.

`storage/logs/app.log` is a rolling error log, not required for recovery.

## 11. Day-to-day admin — `manage.sh`

One entry point for everything administrative. The installer links it to
`/usr/local/sbin/tracker`, so from anywhere on the machine:

```bash
sudo tracker help
```

The rule about what belongs here: anything admin-only that is awkward or risky
without a proper flow — migrations, credential resets, backups, health checks,
config. Orders, parts, pricing and delivery notes are day-to-day work with a
perfectly good interface, and deliberately have no command.

### Checking

```bash
sudo tracker status              # services, versions, disk, migrations, counts
sudo tracker doctor              # PHP, config, storage, database, integrations
sudo tracker health              # GET the site's own /health endpoint
sudo tracker stats               # row counts
sudo tracker logs -f             # follow the application log
```

`doctor` is the one to run first when something is wrong: it checks the PHP
version and extensions, `APP_KEY`, the database connection, whether the
migrations are up to date, whether there is still an active staff
administrator, storage writability, the Composer packages, and whether email,
Clear Books and the reminders are ready — with the actual reason for each thing
that is not.

### Users

```bash
sudo tracker users                                    # everybody, with roles and status
sudo tracker create-admin                             # a Junction staff administrator
sudo tracker reset-password someone@example.com       # new password, lockout cleared
sudo tracker invite-link someone@example.com          # a fresh link, printed not emailed
sudo tracker unlock [EMAIL]                           # clear sign-in lockouts
sudo tracker activate|deactivate someone@example.com
sudo tracker set-roles someone@example.com staff.quoting,staff.production
sudo tracker roles                                    # what each role can actually do
```

`invite-link` is the one to remember: it prints an invitation link instead of
emailing it, which is exactly what you need when email is the thing that is
broken.

Deactivating the last active staff administrator is refused — that is an easy
mistake to make and impossible to undo from the interface.

### Application

```bash
sudo tracker settings                        # the settings table (secrets shown as "set")
sudo tracker set-setting KEY VALUE
sudo tracker config APP_URL                  # read one .env value
sudo tracker config APP_URL https://new.url  # change it, keeping a backup
sudo tracker migrate [--status]
sudo tracker db-grant                        # fix "command denied" during a migration
sudo tracker reset-database                  # empty it and rebuild the schema
```

`reset-database` is the one destructive command here. It drops every table,
re-runs the migrations and leaves the database empty — no clients, no orders,
no accounts — so the next step is `create-admin`, exactly as on a fresh
install. There is no undo.

It asks twice: a yes/no, and then the word `RESET` typed in full. Anything
else at either prompt exits without touching the database. `--yes` does not
satisfy either of them, and it refuses to run at all unless a person is at
the terminal — a reset that could be triggered by a stray pipe or a line in a
script is a reset waiting to happen.

Uploaded files are left alone. The database only ever held their paths, so
afterwards `storage/uploads` is still there and nothing points at it. Clearing
those is `reset-uploads`, below. Take a `backup` first either way.

`reset-uploads` is the other half: it deletes every uploaded and generated
file — drawings, purchase orders, part media, and the delivery notes and
route cards the application wrote — and leaves `storage/uploads` empty and
writable. None of it is in a database dump, which only holds the paths, so
the `uploads-*.tar.gz` from `backup` is the only way back.

It asks the same two questions, ignores `--yes` in the same way, and refuses
without a terminal for the same reason. Afterwards the records point at files
that are not there, so a drawing opened from the tracker will 404 until
`reset-database` is run too — the two together are a fresh install, either one
alone is deliberate.

No subdirectories are recreated. The application makes them as files arrive,
which is also why the installer no longer pre-creates a list of them.

### Email and integrations

```bash
sudo tracker mail-status                     # config, readiness and the recent send log
sudo tracker mail-test you@example.com
sudo tracker send-reminders [--force]        # the outstanding-parts digest, now
sudo tracker clearbooks-status               # connection and posting settings
sudo tracker composer-install                # (re)install the PHP packages
```

### Server

```bash
sudo tracker backup [DIR]                    # database + uploads + .env
sudo tracker restore DUMP.sql.gz [UPLOADS.tar.gz]
sudo tracker update                          # pull the latest and migrate
sudo tracker update /path/to/source          # or from a directory you already have
sudo tracker permissions                     # re-apply ownership and modes
sudo tracker package [FILE]                  # a distributable archive of this install
sudo tracker cron-install | cron-remove
sudo tracker restart
```

`update` with no argument clones
<https://github.com/maeterlinckle/production-tracker> into a temp directory,
copies it over the install, reinstalls the Composer packages, re-applies
permissions, runs the migrations and reloads the web server. `.env`, `storage/`
and the database are left alone. Back up first — it says so and waits.

`--quiet` suppresses everything but warnings and errors, which is what the cron
entries use. `--yes` skips the confirmations.

## 12. Upgrading

```bash
sudo tracker backup
sudo tracker update
```

`update` clones <https://github.com/maeterlinckle/production-tracker> into a
temporary directory, copies it over the install, reinstalls the Composer
packages, re-applies permissions, runs the migrations, runs `doctor` and
reloads the web server. `.env`, `storage/` and the database are left alone.

`sudo tracker update /path/to/source` uses a directory you already have
instead of cloning.

Re-running `install.sh` against an existing directory does the same thing and
recognises it as an upgrade. Either is fine; `update` asks fewer questions.

There is no cache to clear and no build step.

## 13. Local development

The app runs fine on PHP's built-in server for local testing:

```bash
php -S 127.0.0.1:8321 -t public
```

Set `APP_DEBUG=true` in a local `.env` to get real stack traces in the
browser instead of the generic 500 page. Don't run with `APP_DEBUG=true` in
production — it prints file paths and query context on error.
