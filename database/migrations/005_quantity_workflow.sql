-- Quantity-driven production workflow, staff-created parts, an explicit
-- free-issue toggle, PO numbers and their document history, client-requested
-- quantity changes, and material return notes.
--
-- The centrepiece is the replacement of order_lines.stage with a distribution:
-- a line's quantity is now spread across stages rather than sitting at one of
-- them, so "12 awaiting free issue, 5 ready, 3 in production" is a state the
-- system can hold instead of a state it has to round to whichever single
-- status is least wrong.
--
-- Same conventions as 001-004: InnoDB/utf8mb4 and uq_/idx_/fk_ prefixes, with
-- IF [NOT] EXISTS wherever MariaDB accepts it. Unlike the earlier files this
-- one is genuinely forward-only: it reads production_status_log and then drops
-- it, so a second run has nothing to read. The migrations table is what stops
-- that happening; do not run this file by hand against a database that has
-- already had it.

-- ---------------------------------------------------------------------------
-- Free-issue material becomes an explicit yes/no on the part (item 2)
--
-- Until now "does this part have free issue?" was inferred from whether anybody
-- had typed a source material into the list. That is a guess dressed as a fact:
-- a part genuinely made from client material but recorded without a reference
-- read as needing none, and every free-issue field on every screen was rendered
-- empty rather than hidden. The toggle says it outright.
-- ---------------------------------------------------------------------------

ALTER TABLE parts
    ADD COLUMN IF NOT EXISTS has_free_issue TINYINT(1) NOT NULL DEFAULT 0 AFTER notes;

-- Existing parts: listing a source material is the best evidence available of
-- what the toggle should have said, and it is what every screen was already
-- treating as the answer.
UPDATE parts p
   SET p.has_free_issue = 1
 WHERE EXISTS (SELECT 1 FROM part_free_issue_materials m WHERE m.part_id = p.id);

-- A part that needs no material cannot carry a ratio for it. Corrected first,
-- then enforced, so the constraint cannot fail on data that predates it.
UPDATE parts
   SET free_issue_relationship = 'none', free_issue_factor = 1
 WHERE has_free_issue = 0;

ALTER TABLE parts
    ADD CONSTRAINT chk_parts_free_issue_toggle CHECK (
        has_free_issue = 1 OR free_issue_relationship = 'none'
    );

-- ---------------------------------------------------------------------------
-- PO number on the order (item 9)
--
-- Distinct from the uploaded PO document: the number is what Clear Books needs
-- as the invoice reference, and what the client quotes on the phone. Existing
-- orders have no such number to backfill from -- the document is all there is --
-- so they keep an empty string rather than a meaningless placeholder. New
-- orders are required to carry one by the form that places them.
-- ---------------------------------------------------------------------------

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS po_number VARCHAR(60) NOT NULL DEFAULT '' AFTER order_number;

ALTER TABLE orders
    ADD KEY IF NOT EXISTS idx_orders_po_number (po_number);

-- ---------------------------------------------------------------------------
-- Purchase order documents become a history rather than one slot (item 8)
--
-- A quantity change usually arrives with an amended or additional PO, and the
-- original still has to be readable afterwards -- it is what the original price
-- was agreed against. orders.po_file_path is kept, and keeps pointing at the
-- first document, so nothing that already reads it has to change.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS order_po_documents (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id            INT UNSIGNED NOT NULL,
    po_number           VARCHAR(60) NOT NULL DEFAULT '',
    file_path           VARCHAR(500) NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    mime_type           VARCHAR(120) NULL,
    file_size           INT UNSIGNED NULL,
    is_original         TINYINT(1) NOT NULL DEFAULT 0,
    note                VARCHAR(255) NULL,
    uploaded_by         INT UNSIGNED NOT NULL,
    uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_po_documents_order (order_id, uploaded_at),
    CONSTRAINT fk_order_po_documents_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_po_documents_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO order_po_documents (order_id, po_number, file_path, original_filename, is_original, uploaded_by, uploaded_at)
SELECT o.id, o.po_number, o.po_file_path, o.po_original_filename, 1, o.placed_by, o.placed_at
  FROM orders o
 WHERE NOT EXISTS (SELECT 1 FROM order_po_documents d WHERE d.order_id = o.id);

-- ---------------------------------------------------------------------------
-- The quantity distribution (item 6)
--
-- One row per stage a line has quantity at. The invariant the model rests on is
-- that the rows for a line sum to order_lines.qty_ordered: every part ordered is
-- somewhere, including the ones that were scrapped or cancelled off.
--
-- 'failed' and 'cancelled' are buckets rather than counters for exactly that
-- reason. A failed part is not a smaller order, it is a part that has to be made
-- again, and parking it somewhere visible is what stops it being quietly
-- forgotten; returning it to the flow is an ordinary backward move once
-- replacement material is in.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS order_line_quantities (
    order_line_id   INT UNSIGNED NOT NULL,
    stage           ENUM(
                        'awaiting_free_issue','ready_for_production','in_production',
                        'complete','delivered','invoiced','failed','cancelled'
                    ) NOT NULL,
    qty             INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_line_id, stage),
    KEY idx_order_line_quantities_stage (stage),
    CONSTRAINT fk_order_line_quantities_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every movement of quantity, including the ones that create and destroy it.
--
-- from_stage NULL means quantity entered the line (the order was placed, or its
-- quantity was increased); to_stage NULL means quantity left it (the quantity
-- was reduced). Failing parts is a move to 'failed' whose from_stage records
-- where they failed, so no separate table is needed to answer "what stage do we
-- lose parts at".
CREATE TABLE IF NOT EXISTS order_line_stage_moves (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_line_id   INT UNSIGNED NOT NULL,
    from_stage      ENUM(
                        'awaiting_free_issue','ready_for_production','in_production',
                        'complete','delivered','invoiced','failed','cancelled'
                    ) NULL COMMENT 'NULL = quantity entered the line',
    to_stage        ENUM(
                        'awaiting_free_issue','ready_for_production','in_production',
                        'complete','delivered','invoiced','failed','cancelled'
                    ) NULL COMMENT 'NULL = quantity left the line',
    qty             INT UNSIGNED NOT NULL,
    reason          VARCHAR(255) NULL,
    moved_by        INT UNSIGNED NOT NULL,
    moved_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_line_stage_moves_line (order_line_id, moved_at),
    CONSTRAINT fk_order_line_stage_moves_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_line_stage_moves_user FOREIGN KEY (moved_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Backfill the distribution from the columns that used to hold it
--
-- Order matters: the furthest-on quantities are placed first, and whatever is
-- left over goes to the stage the line was sitting at. Migration 004 already
-- made qty_completed agree with the old stage, so a line that read as complete
-- has no remainder to place.
-- ---------------------------------------------------------------------------

INSERT INTO order_line_quantities (order_line_id, stage, qty)
SELECT id, 'invoiced', qty_invoiced FROM order_lines WHERE qty_invoiced > 0
ON DUPLICATE KEY UPDATE qty = VALUES(qty);

INSERT INTO order_line_quantities (order_line_id, stage, qty)
SELECT id, 'delivered', qty_delivered - qty_invoiced FROM order_lines WHERE qty_delivered > qty_invoiced
ON DUPLICATE KEY UPDATE qty = VALUES(qty);

INSERT INTO order_line_quantities (order_line_id, stage, qty)
SELECT id, 'complete', qty_completed - qty_delivered FROM order_lines WHERE qty_completed > qty_delivered
ON DUPLICATE KEY UPDATE qty = VALUES(qty);

INSERT INTO order_line_quantities (order_line_id, stage, qty)
SELECT id,
       CASE WHEN stage IN ('complete', 'closed') THEN 'complete' ELSE stage END,
       qty_ordered - qty_completed
  FROM order_lines
 WHERE qty_ordered > qty_completed
ON DUPLICATE KEY UPDATE qty = order_line_quantities.qty + VALUES(qty);

-- Provenance for the rows above, so the movement history of a migrated line
-- does not simply begin in the middle of the story.
INSERT INTO order_line_stage_moves (order_line_id, from_stage, to_stage, qty, reason, moved_by, moved_at)
SELECT q.order_line_id, NULL, q.stage, q.qty,
       'Migrated from the single-status model', o.placed_by, o.placed_at
  FROM order_line_quantities q
  JOIN order_lines ol ON ol.id = q.order_line_id
  JOIN orders o ON o.id = ol.order_id
 WHERE q.qty > 0;

-- The old status log is the same history at line granularity. Under that model
-- a status change moved the whole line, so the whole ordered quantity is the
-- honest reading of how much moved.
INSERT INTO order_line_stage_moves (order_line_id, from_stage, to_stage, qty, reason, moved_by, moved_at)
SELECT l.order_line_id,
       CASE WHEN l.from_stage = 'closed' THEN 'complete' ELSE l.from_stage END,
       CASE WHEN l.to_stage = 'closed' THEN 'complete' ELSE l.to_stage END,
       ol.qty_ordered,
       CONCAT('Whole line: ', COALESCE(l.notes, 'status changed')),
       l.changed_by, l.changed_at
  FROM production_status_log l
  JOIN order_lines ol ON ol.id = l.order_line_id;

DROP TABLE IF EXISTS production_status_log;

-- ---------------------------------------------------------------------------
-- Order line: derived totals, rejection tracking, and close-down
--
-- qty_completed / qty_delivered / qty_invoiced stay, and are joined by
-- qty_failed and qty_cancelled. They are no longer written to directly: every
-- one of them is recalculated from the distribution after a move. Keeping them
-- is what lets the reports, the despatch screen and the ageing queries stay
-- single indexable statements rather than a join and a sum apiece.
-- ---------------------------------------------------------------------------

ALTER TABLE order_lines
    ADD COLUMN IF NOT EXISTS qty_failed INT UNSIGNED NOT NULL DEFAULT 0 AFTER qty_invoiced,
    ADD COLUMN IF NOT EXISTS qty_cancelled INT UNSIGNED NOT NULL DEFAULT 0 AFTER qty_failed,
    ADD COLUMN IF NOT EXISTS qty_free_issue_rejected INT UNSIGNED NOT NULL DEFAULT 0 AFTER qty_free_issue_received,
    ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS closed_by INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS close_reason VARCHAR(255) NULL;

ALTER TABLE order_lines
    ADD CONSTRAINT fk_order_lines_closed_by FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL;

-- The single status is gone. Everything that read it now reads the
-- distribution, or one of the derived totals above.
ALTER TABLE order_lines DROP KEY IF EXISTS idx_order_lines_stage;

ALTER TABLE order_lines DROP COLUMN IF EXISTS stage;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS closed_by INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS close_reason VARCHAR(255) NULL;

ALTER TABLE orders
    ADD CONSTRAINT fk_orders_closed_by FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Rejected free-issue material (item 6)
--
-- Different from a shortage in every way that matters. A shortage is material
-- that has not arrived yet and is already covered by the outstanding request; a
-- rejection is material that arrived, cannot be used, has to go back, and has to
-- be sent again. Hence a return note out and a replacement request in, both
-- recorded here against the rejection that caused them.
--
-- Quantities in this table are material units, matching
-- order_lines.qty_free_issue_required rather than qty_ordered.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS free_issue_rejections (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_line_id       INT UNSIGNED NOT NULL,
    qty_rejected        INT UNSIGNED NOT NULL COMMENT 'Material units',
    reason              VARCHAR(255) NOT NULL,
    return_note_id      INT UNSIGNED NULL COMMENT 'Paperwork for the material going back',
    replacement_note_id INT UNSIGNED NULL COMMENT 'Request for the material coming again',
    rejected_by         INT UNSIGNED NOT NULL,
    rejected_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_free_issue_rejections_line (order_line_id, rejected_at),
    CONSTRAINT fk_free_issue_rejections_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE CASCADE,
    CONSTRAINT fk_free_issue_rejections_return FOREIGN KEY (return_note_id) REFERENCES delivery_notes (id) ON DELETE SET NULL,
    CONSTRAINT fk_free_issue_rejections_replacement FOREIGN KEY (replacement_note_id) REFERENCES delivery_notes (id) ON DELETE SET NULL,
    CONSTRAINT fk_free_issue_rejections_user FOREIGN KEY (rejected_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Return notes are delivery notes going the other way, so they are the same
-- record with a third type rather than a parallel table with its own numbering,
-- its own PDF and its own listing.
ALTER TABLE delivery_notes
    MODIFY COLUMN type ENUM('free_issue_in','goods_out','material_return') NOT NULL;

-- ---------------------------------------------------------------------------
-- Client-requested quantity changes (item 8)
--
-- The request is a record, not an edit: qty_at_request freezes what the line
-- said when it was asked for, so staff reviewing it a week later can see
-- whether the ground moved underneath it.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS order_line_change_requests (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_line_id   INT UNSIGNED NOT NULL,
    qty_at_request  INT UNSIGNED NOT NULL,
    qty_requested   INT UNSIGNED NOT NULL,
    reason          TEXT NULL,
    status          ENUM('pending','applied','declined') NOT NULL DEFAULT 'pending',
    requested_by    INT UNSIGNED NOT NULL,
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by     INT UNSIGNED NULL,
    reviewed_at     DATETIME NULL,
    review_notes    VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_order_line_change_requests_line (order_line_id, status),
    KEY idx_order_line_change_requests_open (status, requested_at),
    CONSTRAINT fk_order_line_change_requests_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_line_change_requests_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_line_change_requests_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Route cards are no longer stored (item 3)
--
-- They are built from the order line every time they are asked for, so a stored
-- copy could only ever be a stale one -- and the "regenerate" button existed
-- purely to deal with that staleness. The reference is now derived from the
-- order number and the line number, which is stable without being stored.
-- ---------------------------------------------------------------------------

DROP TABLE IF EXISTS route_cards;
