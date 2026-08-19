# Production Tracker

A client-facing order tracker for Junction Inc Ltd — a job shop that machines
and fabricates parts to customer drawings. It covers the whole run:

**quote request → order → free-issue material in → production → delivery note
out → Clear Books invoice**

Clients sign in to raise parts, place orders against their own purchase orders,
follow progress and ask questions. Junction sees every client's work in one
place, checks material in, records what has been made, issues the paperwork and
pushes the invoice.

Internal tool, not a product — but the data model has handled multiple client
companies from the first migration.

---

## What makes it more than a status field

Two things about job-shop work that most order trackers get wrong:

**Free-issue material.** Clients supply the stock Junction works on. It arrives
late, short, or as the wrong grade — and those are not the same problem. A
shortage is material that has not turned up yet, already covered by the request
that is out; nothing needs doing. A **rejection** is material that turned up and
cannot be used: it goes back on a return note, the same quantity is added to
what the line still needs, and the request that is already out asks for it
again. One outstanding request per line, reissued rather than duplicated,
because two notes asking for overlapping material is how a client sends twice.

How much material an order needs is worked out from a ratio the client sets on
the part: one length of bar might yield eight parts (divide by 8), or a
weldment might take three castings (multiply by 3). Junction can correct it, and
the correction is recorded against whoever made it.

**A line does not have a status.** Its quantity is spread across stages, and the
status you read is written out from that spread:

```
awaiting_free_issue → ready_for_production → in_production → complete → delivered → invoiced
```

So a line reads "12 awaiting free issue, 5 ready for production, 3 in
production" — because that is what is true. Staff move whatever number of parts
they choose, one stage at a time, forwards or back. Nobody sets a status and
there is nowhere one could be set.

Everything ordered is somewhere: the stages sum to the quantity ordered. Parts
that fail are parked in a bucket of their own with the reason and the stage they
failed at, so they keep showing as owed until they are remade rather than
quietly vanishing from the total. Parts that will never be made are cancelled
off, recorded as cancelled, and drop out of what is outstanding.

---

## The rest of it

- **Roles that mean something.** Eight of them, several per person. Pricing is
  omitted from the response entirely for anyone without `view_pricing` — not
  greyed out, not hidden with CSS, absent.
- **Invitations, not passwords.** Nobody but the account holder ever knows
  their password. Inviting somebody emails a single-use link; they choose their
  own. Client administrators invite their own colleagues.
- **Paperwork that works on paper.** Delivery notes and route cards render to
  PDF; every free-issue note carries a QR code that opens the check-in screen
  for that exact order line on a phone in the goods-in bay. Route cards and
  free-issue notes are built at the moment they are asked for, from live data —
  a saved copy of either is out of date as soon as anybody moves anything — and
  a whole order's route cards print in one action, a card to a page.
- **Bars are not parts.** Where a part is machined several to a bar, the stages
  before completion count the bars and everything after counts the parts, so
  "10" and "20" both appear on the same line and both are right. The line says
  which is which, and says it in words as well: *5 received parts will produce
  30 final parts.*
- **Changes of mind, handled.** A client can ask to change a quantity on a line
  that is already running, with the amended purchase order attached; Junction
  applies or declines it. A reduction cannot eat into what has already been
  made, delivered or invoiced, and the screen says where the floor is before
  anybody commits.
- **Editable email.** Every message the tracker sends can be reworded in the
  interface, with documented merge fields and a preview. The editor refuses to
  save a placeholder the sending code does not supply.
- **A report that answers the real question.** Parts on order, totalled per
  part across every purchase order it appears on — so two orders for the same
  component are one number to set up for, not two. Each line says what material
  is on the shelf and what it will make: *5 received, 4 available for
  production — enough for 24 final parts.*
- **Orders from either end.** Clients place their own; Junction types one in
  when it arrives on the phone, using the same form against the client's account.
  Either way it is their order, and they get the confirmation.
- **One page per thing.** A part is one page and an order is one page, shown to
  the client and to Junction with different parts switched on rather than as two
  templates quietly drifting apart. Photos are resized on upload and served as
  thumbnails in the grids.
- **Setup knowledge that stays with the part.** A photo of the finished thing,
  the fixture, the settings sheet, the CNC program — attached to the part, so
  they are in front of whoever runs it next rather than buried in the one order
  they happened to be added on.
- **Light and dark**, sharing a visual language with
  [Kitwell](https://github.com/maeterlinckle/kitwell), Junction's asset
  register. Same family, no shared code.

## Built from

Plain PHP 8.1+ and MariaDB. No framework, no build step, no JavaScript
toolchain. Three runtime packages — PHPMailer, dompdf, endroid/qr-code — and a
few hundred lines of hand-written router, migrator and view layer.

That is a deliberate choice for a tool one person maintains: there is nothing
to upgrade on a schedule, and every layer can be read in an afternoon.

## Install

```bash
git clone https://github.com/maeterlinckle/production-tracker.git
sudo ./production-tracker/install.sh
```

The installer asks what it cannot work out, then installs the packages, creates
the database, writes the configuration, sets permissions, configures Apache or
nginx, runs the migrations and creates the first administrator. Run it with
`--dry-run` first to see the plan.

Afterwards, everything administrative is one command:

```bash
sudo tracker status      # or doctor, backup, update, users, mail-test …
sudo tracker help
```

Full detail, including the Clear Books setup, is in
**[docs/INSTALL.md](docs/INSTALL.md)**. The current state of the codebase —
schema, patterns, what is done and what is not — is in
**[PROJECT_STATE.md](PROJECT_STATE.md)**.

## Licence

Private, for Junction Inc Ltd.
