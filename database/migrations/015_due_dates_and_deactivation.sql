-- When a client needs their parts, and turning an account off without losing it.
--
-- Same conventions as 001-014: InnoDB/utf8mb4, uq_/idx_/fk_ prefixes, and
-- IF [NOT] EXISTS wherever MariaDB accepts it. This file is re-runnable.

-- ---------------------------------------------------------------------------
-- Required by
-- ---------------------------------------------------------------------------

-- A new client role. Deciding when parts are needed is neither buying them nor
-- merely watching them: a production manager schedules the line and knows what
-- has to land in March, without necessarily being the person who signs the
-- purchase order.
INSERT INTO roles (slug, name, side)
SELECT 'client.production_manager', 'Production manager', 'client'
 WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'client.production_manager');

-- What is wanted, and by when.
--
-- A quantity and a date rather than one date for the line, because that is how
-- the requirement actually arrives: "50 by the end of March and the rest by
-- June" is one order line and two dates, and forcing it into one loses the
-- half that is urgent. A line with a single requirement is simply one row.
--
-- Junction reads these; the client sets them. Nothing here changes what is
-- owed -- the quantity on the order is still the quantity on the order -- so
-- these are a statement of need, not a second ledger.
CREATE TABLE IF NOT EXISTS order_line_due_dates (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_line_id   INT UNSIGNED NOT NULL,
    qty             INT UNSIGNED NOT NULL COMMENT 'Final parts wanted by this date',
    due_date        DATE NOT NULL,
    note            VARCHAR(255) NULL,
    set_by          INT UNSIGNED NOT NULL,
    set_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Two different quantities wanted by the same date on the same line is a
    -- contradiction rather than a schedule.
    UNIQUE KEY uq_order_line_due_dates (order_line_id, due_date),
    KEY idx_order_line_due_dates_due (due_date),
    CONSTRAINT fk_order_line_due_dates_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_line_due_dates_user FOREIGN KEY (set_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Turning a client off
-- ---------------------------------------------------------------------------

-- `clients.is_active` has existed since 001 and meant nothing: it was written
-- and never read. It now means the account is switched off -- nobody under it
-- can sign in, its work is out of the day-to-day lists, and its orders stop
-- moving.
--
-- Recorded rather than merely flipped, because "when did we stop working for
-- them, and who decided that" is asked months later and a boolean cannot
-- answer it.
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS deactivated_at DATETIME NULL
        COMMENT 'When the account was switched off; NULL while it is active'
        AFTER is_active,
    ADD COLUMN IF NOT EXISTS deactivated_by INT UNSIGNED NULL
        COMMENT 'Who switched it off'
        AFTER deactivated_at,
    ADD COLUMN IF NOT EXISTS deactivated_reason VARCHAR(255) NULL
        COMMENT 'Why -- shown wherever the account is listed as inactive'
        AFTER deactivated_by;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'clients'
       AND CONSTRAINT_NAME = 'fk_clients_deactivated_by'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE clients ADD CONSTRAINT fk_clients_deactivated_by
       FOREIGN KEY (deactivated_by) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Deliberately no per-order or per-user "frozen" flag.
--
-- Freezing is derived from the client being inactive, everywhere it is asked
-- about. A second stored state would have to be set on every order at
-- deactivation and unset on every order at reactivation, and the first one
-- missed by a new code path is an order that stays frozen forever with nothing
-- to explain why. The users under a client keep their own is_active exactly as
-- they had it, so reactivating the client restores who could sign in rather
-- than switching everybody on.
