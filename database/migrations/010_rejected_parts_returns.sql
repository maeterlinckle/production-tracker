-- Parts coming back the other way.
--
-- Until now every note in the system moved something from the client to
-- Junction (free issue in), or from Junction to the client (goods out,
-- material returned). The one movement with no paperwork was the awkward one:
-- finished parts that failed the client's own inspection and are being sent
-- back to be remade.
--
-- It is deliberately a fourth `delivery_notes.type` rather than a table of its
-- own. It needs the same numbering, the same PDF, the same place in the
-- client's list of paperwork and the same appearance on the order page as the
-- other three; a parallel table would have had to grow all of that again.
--
-- Same conventions as 001-009: InnoDB/utf8mb4, uq_/idx_/fk_ prefixes, and
-- IF [NOT] EXISTS wherever MariaDB accepts it. This file is re-runnable.

-- ---------------------------------------------------------------------------
-- The new type
-- ---------------------------------------------------------------------------

ALTER TABLE delivery_notes
    MODIFY COLUMN type ENUM('free_issue_in','goods_out','material_return','parts_return') NOT NULL;

-- Which despatch the parts are coming back from.
--
-- A rejected part is always a part that Junction sent, so the note it is being
-- returned against is known and is what the client picks from. Recording it
-- means "what came back off DN-2026-0009" is a question with an answer, and it
-- is what scopes how much may be returned: you cannot send back more of a part
-- than went out on the note you are naming.
--
-- Nullable and ON DELETE SET NULL because it only applies to this one type, in
-- the same way `invoiced` has only ever applied to goods_out.
ALTER TABLE delivery_notes
    ADD COLUMN IF NOT EXISTS related_note_id INT UNSIGNED NULL
        COMMENT 'parts_return only: the goods_out note the parts went out on'
        AFTER client_id;

-- MariaDB has no IF NOT EXISTS for constraints, so this is guarded by looking
-- the constraint up first. Keeps the file re-runnable.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'delivery_notes'
       AND CONSTRAINT_NAME = 'fk_delivery_notes_related'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE delivery_notes ADD CONSTRAINT fk_delivery_notes_related
       FOREIGN KEY (related_note_id) REFERENCES delivery_notes (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Booking returned parts back in
--
-- The mirror of free_issue_receipts, and for the same reason: what the client
-- says is coming and what turns up at the door are two different facts, and
-- the second one is the one production acts on. Staff confirm a quantity, and
-- that quantity -- not the declared one -- is what moves into the Failed stage.
--
-- Several rows per note are allowed. Half a return arriving on Tuesday and the
-- rest on Thursday is a real thing that happens, and recording it as two
-- receipts is more honest than making somebody wait to book in the lot.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS parts_return_receipts (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_note_id    INT UNSIGNED NOT NULL,
    order_line_id       INT UNSIGNED NOT NULL,
    qty_received        INT UNSIGNED NOT NULL COMMENT 'Final parts, as counted in',
    notes               TEXT NULL,
    received_by         INT UNSIGNED NOT NULL,
    received_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_parts_return_receipts_note (delivery_note_id),
    KEY idx_parts_return_receipts_line (order_line_id),
    CONSTRAINT fk_parts_return_receipts_note FOREIGN KEY (delivery_note_id) REFERENCES delivery_notes (id) ON DELETE CASCADE,
    CONSTRAINT fk_parts_return_receipts_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_parts_return_receipts_user FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
