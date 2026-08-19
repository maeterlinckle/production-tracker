-- A staff role for raising orders, a part-level media library, and the record
-- of who asked for a quantity change.
--
-- Same conventions as 001-006: InnoDB/utf8mb4, uq_/idx_/fk_/chk_ prefixes, and
-- IF [NOT] EXISTS wherever MariaDB accepts it. Forward-only: the part_photos
-- table is read into part_media and then dropped.

-- ---------------------------------------------------------------------------
-- "Raise orders" (item 1)
--
-- Junction takes orders by phone and by email, and typing one in for the client
-- is faster than talking somebody through the form. It is its own role rather
-- than part of quoting: deciding a price and committing a client to buy are
-- different jobs, and one person having both is a choice somebody should make
-- rather than a side effect. staff.admin gets it by the usual superset rule in
-- Capabilities, with no row needed here.
-- ---------------------------------------------------------------------------

INSERT INTO roles (slug, name, side) VALUES ('staff.raise_orders', 'Raise orders', 'staff')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------------------
-- Part media library (item 6)
--
-- Setup photos, machine settings, a picture of the finished part, the tooling
-- files for the job: all of it describes the *part*, and all of it is wanted
-- again the next time that part is ordered. It lived on the order, where it was
-- invisible to the person setting the same part up six months later.
--
-- One table with a `kind` rather than a table per sort of file. A tooling file
-- is a file attached to a part, the same as a PDF of the machine settings is;
-- giving it its own system would mean two upload paths, two listings and two
-- places to look.
--
-- is_main is 1 or NULL, never 0, so the unique key below can enforce "one main
-- photo per part" -- NULLs do not collide in a unique index, so every other row
-- is free. The alternative is enforcing it in application code and finding out
-- it drifted.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS part_media (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id             INT UNSIGNED NOT NULL,
    kind                ENUM('photo','document','tooling') NOT NULL DEFAULT 'photo',
    is_main             TINYINT(1) NULL COMMENT '1 for the part''s representative photo, NULL otherwise',
    caption             VARCHAR(255) NULL,
    file_path           VARCHAR(500) NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    mime_type           VARCHAR(120) NULL,
    file_size           INT UNSIGNED NULL,
    uploaded_by         INT UNSIGNED NOT NULL,
    uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_part_media_main (part_id, is_main),
    KEY idx_part_media_part (part_id, kind),
    CONSTRAINT fk_part_media_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_media_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_part_media_is_main CHECK (is_main IS NULL OR is_main = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Everything already uploaded is a photo. The oldest becomes the main one,
-- because that is the row the old code was already showing as the thumbnail.
INSERT INTO part_media (part_id, kind, is_main, file_path, original_filename, mime_type, file_size, uploaded_by, uploaded_at)
SELECT p.part_id, 'photo',
       CASE WHEN p.id = (SELECT MIN(p2.id) FROM part_photos p2 WHERE p2.part_id = p.part_id) THEN 1 ELSE NULL END,
       p.file_path, p.original_filename, p.mime_type, p.file_size, p.uploaded_by, p.uploaded_at
  FROM part_photos p;

DROP TABLE IF EXISTS part_photos;

-- Tooling and setup files are engineering formats the drawing uploader already
-- knows, plus the ones a machine controller writes. Held in the same allow-list
-- style as every other upload -- see config/config.php.

-- ---------------------------------------------------------------------------
-- Who asked for a quantity change (item 7)
--
-- Staff can now change a line's quantity themselves when an amended purchase
-- order comes in. That is the same event as an approved client request and it
-- belongs in the same table, so there is one audit trail of quantity changes
-- rather than two half-answers -- but "the client asked and we agreed" and "we
-- changed it when their new PO arrived" are different stories, so the record
-- says which.
--
-- Everything already in the table came from a client asking.
-- ---------------------------------------------------------------------------

ALTER TABLE order_line_change_requests
    ADD COLUMN IF NOT EXISTS initiated_by ENUM('client','staff') NOT NULL DEFAULT 'client' AFTER order_line_id;
