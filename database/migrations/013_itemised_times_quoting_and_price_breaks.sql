-- Itemised build times, a quoting scratchpad, and price breaks.
--
-- Four fields on a part stop being single numbers somebody typed and become
-- lists that add up. The reason is the same in each case: the number was never
-- the thing anybody actually knew. Nobody knows a part takes 140 minutes; they
-- know it is 40 on the lathe, 60 on the mill and 40 to fettle, and the 140 is
-- what falls out. When the estimate turns out wrong, the single number says
-- nothing about which operation was misjudged.
--
-- Every total here is cached on `parts` and recalculated from its rows after
-- each write, never incremented -- the same rule the order-line totals follow.
--
-- Same conventions as 001-012: InnoDB/utf8mb4, uq_/idx_/fk_ prefixes, and
-- IF [NOT] EXISTS wherever MariaDB accepts it. This file is re-runnable.

-- ---------------------------------------------------------------------------
-- Build time: estimated and actual, both itemised
-- ---------------------------------------------------------------------------

-- `build_time_minutes` was always the estimate; it is only now that there is
-- something to confuse it with. Renamed rather than left alone, because a
-- column called one thing and labelled another is how the next person reads
-- the wrong figure.
--
-- MariaDB has no IF EXISTS for a column rename, so it is guarded by looking
-- the column up first. Keeps the file re-runnable.
SET @old_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'parts'
       AND COLUMN_NAME = 'build_time_minutes'
);
SET @sql := IF(@old_column = 1,
    'ALTER TABLE parts CHANGE COLUMN build_time_minutes estimated_build_time_minutes
       INT UNSIGNED NULL COMMENT ''Sum of the estimated part_time_entries; recalculated, never typed''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE parts
    ADD COLUMN IF NOT EXISTS actual_build_time_minutes INT UNSIGNED NULL
        COMMENT 'Sum of the actual part_time_entries; recalculated, never typed'
        AFTER estimated_build_time_minutes;

-- One table for both kinds rather than one each. An estimated row and an
-- actual row are the same shape and are read side by side -- "40 estimated on
-- the lathe against 55 actual" is the question the pair exists to answer, and
-- two tables would mean two of every query that asks it.
CREATE TABLE IF NOT EXISTS part_time_entries (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id         INT UNSIGNED NOT NULL,
    kind            ENUM('estimated','actual') NOT NULL,
    task            VARCHAR(255) NOT NULL,
    minutes         INT UNSIGNED NOT NULL,
    position        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The order they were entered in, which is usually the order of operations',
    recorded_by     INT UNSIGNED NOT NULL,
    recorded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_part_time_entries_part (part_id, kind, position),
    CONSTRAINT fk_part_time_entries_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_time_entries_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A part already carrying an estimate keeps it, as a single unnamed row rather
-- than as a total with nothing behind it. Anyone opening the editor sees the
-- figure that was there and can break it up; leaving the list empty under a
-- non-zero total would read as data lost.
INSERT INTO part_time_entries (part_id, kind, task, minutes, position, recorded_by)
SELECT p.id, 'estimated', 'Build time (recorded before this was itemised)',
       p.estimated_build_time_minutes, 0, COALESCE(p.updated_by, p.created_by)
  FROM parts p
 WHERE p.estimated_build_time_minutes IS NOT NULL
   AND p.estimated_build_time_minutes > 0
   AND NOT EXISTS (
        SELECT 1 FROM part_time_entries e
         WHERE e.part_id = p.id AND e.kind = 'estimated'
   );

-- ---------------------------------------------------------------------------
-- The quoting scratchpad
-- ---------------------------------------------------------------------------

-- One draft per part. It is a scratchpad, not a history: the question it
-- answers is "what should this part cost?", asked again from scratch whenever
-- anybody wonders. What was thought last year is not evidence about today's
-- material price.
--
-- The rate and the mark-up are NULL when the central setting is to be used,
-- which is different from a rate that happens to equal it: a part deliberately
-- quoted at the standard rate and a part nobody has thought about are the same
-- figure and not the same fact. Change the setting and the second follows;
-- the first does not.
CREATE TABLE IF NOT EXISTS part_quote_drafts (
    part_id                 INT UNSIGNED NOT NULL,
    machine_rate_per_minute DECIMAL(10,4) NULL COMMENT 'NULL means follow the central setting',
    markup_percent          DECIMAL(6,2) NULL COMMENT 'NULL means follow the central setting',
    draft_total             DECIMAL(12,2) NULL COMMENT 'Recalculated from the lines and the rates; never typed',
    notes                   TEXT NULL,
    updated_by              INT UNSIGNED NOT NULL,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (part_id),
    CONSTRAINT fk_part_quote_drafts_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_quote_drafts_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The freeform half: anything the standard sums do not already cover.
-- Subcontract plating, a fixture that has to be made first, carriage on a
-- one-off. Signed, because a discount belongs on the same list as a cost --
-- writing "less 40" as a line is how somebody records that they knocked
-- something off, and a separate discount field would be a second place to look.
CREATE TABLE IF NOT EXISTS part_quote_lines (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id     INT UNSIGNED NOT NULL,
    label       VARCHAR(255) NOT NULL,
    amount      DECIMAL(12,2) NOT NULL COMMENT 'May be negative: a deduction is a line like any other',
    position    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_part_quote_lines_part (part_id, position),
    CONSTRAINT fk_part_quote_lines_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Price breaks
-- ---------------------------------------------------------------------------

-- Freely settable quantity/price pairs rather than fixed tiers, because the
-- quantities that matter are the ones this client actually orders. A part run
-- in 12s and 250s wants breaks at 12 and 250, and a tier table of
-- 1/10/50/100/500 would have neither.
--
-- Both kinds live here: the client's target price and Junction's quote are the
-- same shape of statement made by different people, and the pair is read
-- together.
--
-- `qty` is where the price starts applying, so it is unique per part and kind
-- -- two prices from 100 up is not a price break, it is a contradiction.
CREATE TABLE IF NOT EXISTS part_price_breaks (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id     INT UNSIGNED NOT NULL,
    kind        ENUM('target','quoted') NOT NULL,
    qty         INT UNSIGNED NOT NULL COMMENT 'The quantity from which this price applies',
    price       DECIMAL(10,2) NOT NULL COMMENT 'Price each at that quantity',
    set_by      INT UNSIGNED NOT NULL,
    set_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_part_price_breaks (part_id, kind, qty),
    CONSTRAINT fk_part_price_breaks_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_price_breaks_user FOREIGN KEY (set_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- The central quoting figures
-- ---------------------------------------------------------------------------

-- Defaults, not rules: every draft may override them, and does so by storing
-- its own value rather than by copying this one.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('quoting.machine_rate_per_minute', '1.00'),
    ('quoting.markup_percent', '30.00');
