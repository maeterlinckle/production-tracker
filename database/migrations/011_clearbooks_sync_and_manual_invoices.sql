-- Pulling a client's details from Clear Books, and raising an invoice without
-- Clear Books at all.
--
-- Two unrelated-looking changes that share a cause: the Clear Books connection
-- is the one part of this application that depends on somebody else's service
-- being reachable and correctly configured, and the tracker had no way to carry
-- on when it is not.
--
-- Same conventions as 001-010: InnoDB/utf8mb4, uq_/idx_/fk_ prefixes, and
-- IF [NOT] EXISTS wherever MariaDB accepts it. This file is re-runnable.

-- ---------------------------------------------------------------------------
-- Somewhere to put what Clear Books actually returns
--
-- Their Contact record (the schema behind a customer) carries a county, a VAT
-- number and a company number, and this table had nowhere for any of them. A
-- sync that silently dropped a third of the record would be worse than no sync:
-- it would look like the local copy was complete.
--
-- Verified against the Customer/Contact and Address schemas in
-- https://api.clearbooks.co.uk/spec/v1.yaml rather than guessed.
-- ---------------------------------------------------------------------------

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS address_county VARCHAR(100) NULL AFTER address_city;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS vat_number VARCHAR(40) NULL AFTER billing_email;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS company_number VARCHAR(40) NULL AFTER vat_number;

-- When the local copy was last pulled, and by whom. The question anybody asks
-- of a synced record is "how old is this?", and without these the honest answer
-- is that nobody knows. Same housekeeping pattern as parts.updated_by.
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS clearbooks_synced_at DATETIME NULL AFTER company_number;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS clearbooks_synced_by INT UNSIGNED NULL AFTER clearbooks_synced_at;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'clients'
       AND CONSTRAINT_NAME = 'fk_clients_synced_by'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE clients ADD CONSTRAINT fk_clients_synced_by
       FOREIGN KEY (clearbooks_synced_by) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Invoices raised outside the API
--
-- An invoice typed into Clear Books' own interface, or written on a pad, is
-- still an invoice: the delivery note is no longer waiting to be invoiced and
-- must stop appearing as though it is. What it does not have is a Clear Books
-- internal id, because nothing here created it.
--
-- So `clearbooks_invoice_id` becomes nullable rather than being filled with an
-- empty string. A blank id that looks like data is how somebody later writes a
-- lookup against it and gets nothing back with no idea why.
-- ---------------------------------------------------------------------------

ALTER TABLE invoices
    MODIFY COLUMN clearbooks_invoice_id VARCHAR(64) NULL
        COMMENT 'Clear Books internal ID. NULL when the invoice was raised outside this application.';

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS source ENUM('clearbooks','manual') NOT NULL DEFAULT 'clearbooks'
        COMMENT 'clearbooks = raised through the API by this application; manual = raised elsewhere and recorded here'
        AFTER delivery_note_id;

-- Everything already on file went through the API, which is the default, so
-- there is nothing to backfill. `raised_by` and `raised_at` already record who
-- and when for both kinds.
