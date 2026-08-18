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
late, short, or as the wrong grade. Every order line tracks what is required
against what has actually been booked in, and each check-in records a
discrepancy — shortfall, excess, wrong item — that **blocks the line from
starting until somebody resolves it**, even if a later delivery makes the
numbers add up. "The quantities match" is not the same as "the material is
right".

How much material an order needs is worked out from a ratio the client sets on
the part: one length of bar might yield eight parts (divide by 8), or a
weldment might take three castings (multiply by 3). Junction can correct it, and
the correction is recorded against whoever made it.

**Partial everything.** Parts come off a machine in batches and go out in
batches. So ordered, made, delivered and invoiced are four independent running
totals on each order line rather than one status:

```
qty_free_issue_required / qty_free_issue_received
qty_ordered / qty_completed / qty_delivered / qty_invoiced
```

A line can be part-made, part-delivered and part-invoiced at once, and the
interface says so — "In production — part complete (12 of 20)" — instead of
rounding it to whichever status is least wrong.

---

## The rest of it

- **Roles that mean something.** Seven of them, several per person. Pricing is
  omitted from the response entirely for anyone without `view_pricing` — not
  greyed out, not hidden with CSS, absent.
- **Invitations, not passwords.** Nobody but the account holder ever knows
  their password. Inviting somebody emails a single-use link; they choose their
  own. Client administrators invite their own colleagues.
- **Paperwork that works on paper.** Delivery notes and route cards render to
  PDF; every free-issue note carries a QR code that opens the check-in screen
  for that exact order line on a phone in the goods-in bay.
- **Editable email.** Every message the tracker sends can be reworded in the
  interface, with documented merge fields and a preview. The editor refuses to
  save a placeholder the sending code does not supply.
- **A report that answers the real question.** Parts on order, totalled per
  part across every purchase order it appears on — so two orders for the same
  component are one number to set up for, not two.
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
