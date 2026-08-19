-- Who last changed a part, and the flag that says its price is about to move.
--
-- Same conventions as 001-007: InnoDB/utf8mb4, uq_/idx_/fk_/chk_ prefixes, and
-- IF [NOT] EXISTS wherever MariaDB accepts it.

-- ---------------------------------------------------------------------------
-- Last modified by (item 2)
--
-- `parts` recorded who created a row and when, and that it had been updated,
-- but not by whom — so "who changed this" could be answered for the first
-- version of a part and no other. Three people can edit a part now (the client,
-- the quoting desk, the workshop) and the answer matters most when they
-- disagree.
--
-- Nullable, because every row that predates this genuinely has no answer and a
-- guess would be worse than a dash on the screen.
-- ---------------------------------------------------------------------------

ALTER TABLE parts
    ADD COLUMN IF NOT EXISTS updated_by INT UNSIGNED NULL AFTER created_by;

ALTER TABLE parts
    ADD CONSTRAINT fk_parts_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- "Update price on next order" (item 9)
--
-- A warning, not a change. Junction knows the price is going to move — material
-- has gone up, the quantity break no longer holds — but the current price is
-- still the current price until somebody sets a new one. The flag says so
-- everywhere the price appears on the client's side, including at the moment
-- they are choosing a quantity, which is the last point at which knowing it is
-- any use.
--
-- Deliberately not a second price column. A "provisional new price" nobody has
-- committed to is a number that gets quoted back at you.
-- ---------------------------------------------------------------------------

ALTER TABLE parts
    ADD COLUMN IF NOT EXISTS price_under_review TINYINT(1) NOT NULL DEFAULT 0 AFTER quoted_price_set_at;
