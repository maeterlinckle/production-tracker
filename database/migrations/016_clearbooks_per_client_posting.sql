-- Clear Books posting details, per client rather than once for everybody.
--
-- Same conventions as 001-015: InnoDB/utf8mb4, uq_/idx_/fk_ prefixes, and
-- IF [NOT] EXISTS wherever MariaDB accepts it. This file is re-runnable: every
-- backfill below is guarded on the column still being unset, so running it a
-- second time cannot undo a change somebody has since made on a client page.

-- ---------------------------------------------------------------------------
-- What moves out of Settings
-- ---------------------------------------------------------------------------
--
-- The API connection stays global -- there is one Clear Books account and one
-- OAuth token pair, and those belong in Settings. What was underneath them on
-- that page did not: which business to post to, which nominal code, which VAT
-- treatment and rate, and how long the client has to pay are all facts about a
-- customer relationship, and Junction does not have one customer.
--
-- Nullable throughout, with NULL meaning "nobody has chosen yet" rather than a
-- guessed default. There is no sensible default for a nominal code or a VAT
-- treatment; inventing one is how an invoice ends up posted to the wrong place
-- and nobody notices for a quarter.

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS clearbooks_business_id INT UNSIGNED NULL AFTER clearbooks_entity_id;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS clearbooks_account_code INT UNSIGNED NULL AFTER clearbooks_business_id;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS clearbooks_vat_treatment VARCHAR(60) NULL AFTER clearbooks_account_code;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS clearbooks_vat_rate_key VARCHAR(60) NULL AFTER clearbooks_vat_treatment;

-- Nullable rather than NOT NULL DEFAULT 30, so that the backfill below can tell
-- "never set" from "deliberately thirty days". The application reads a NULL as
-- thirty; the difference only matters for the one-time copy out of settings.
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS clearbooks_payment_terms_days SMALLINT UNSIGNED NULL AFTER clearbooks_vat_rate_key;

-- Whether to send a due date at all.
--
-- The due-date rules available in the Clear Books interface are richer than
-- anything the API exposes -- end of month following, and the like. Where the
-- API cannot reproduce what a client's terms actually are, sending a date this
-- application worked out is worse than sending none: leave dateDue off the
-- payload entirely and Clear Books applies that contact's own default, which is
-- where the real rule already lives.
--
-- Defaults to 1 because that is what every existing invoice did, and a schema
-- change should not quietly alter the documents anybody raises tomorrow.
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS clearbooks_send_due_date TINYINT(1) NOT NULL DEFAULT 1 AFTER clearbooks_payment_terms_days;

-- The invoice "description" field, which the Clear Books interface labels
-- Summary. A template rather than a fixed string: it is written once per client
-- and rendered per invoice, so {po_number} and the rest turn one saved line
-- into the sentence accounts payable at that client actually needs to see.
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS clearbooks_invoice_summary VARCHAR(255) NULL AFTER clearbooks_send_due_date;

-- ---------------------------------------------------------------------------
-- Carry the old global values onto every client that exists today
-- ---------------------------------------------------------------------------
--
-- Without this, moving the settings would silently stop invoicing working for
-- everybody until somebody visited every client page. Each statement writes
-- only where the client's own column is still NULL, so this is safe to re-run
-- and cannot overwrite a per-client choice made after the migration.

UPDATE clients
   SET clearbooks_business_id = (
           SELECT NULLIF(s.setting_value, '') FROM settings s WHERE s.setting_key = 'clearbooks_business_id'
       )
 WHERE clearbooks_business_id IS NULL;

UPDATE clients
   SET clearbooks_account_code = (
           SELECT NULLIF(s.setting_value, '') FROM settings s WHERE s.setting_key = 'clearbooks_account_code'
       )
 WHERE clearbooks_account_code IS NULL;

UPDATE clients
   SET clearbooks_vat_treatment = (
           SELECT NULLIF(s.setting_value, '') FROM settings s WHERE s.setting_key = 'clearbooks_vat_treatment'
       )
 WHERE clearbooks_vat_treatment IS NULL;

UPDATE clients
   SET clearbooks_vat_rate_key = (
           SELECT NULLIF(s.setting_value, '') FROM settings s WHERE s.setting_key = 'clearbooks_vat_rate_key'
       )
 WHERE clearbooks_vat_rate_key IS NULL;

UPDATE clients
   SET clearbooks_payment_terms_days = (
           SELECT NULLIF(s.setting_value, '') FROM settings s WHERE s.setting_key = 'clearbooks_payment_terms_days'
       )
 WHERE clearbooks_payment_terms_days IS NULL;

-- The globals are gone from the settings page, so leaving the rows behind would
-- leave a copy of the truth that nothing reads and nobody can edit. Deleted
-- after the backfill above, never before.
DELETE FROM settings
 WHERE setting_key IN (
    'clearbooks_business_id',
    'clearbooks_account_code',
    'clearbooks_vat_treatment',
    'clearbooks_vat_rate_key',
    'clearbooks_payment_terms_days'
 );
