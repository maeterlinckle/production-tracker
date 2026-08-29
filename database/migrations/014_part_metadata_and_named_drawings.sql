-- Part reference fields, and more than one drawing at a time.
--
-- Two unrelated things, in one file because they are one change to the part.
--
-- Same conventions as 001-013: InnoDB/utf8mb4, uq_/idx_/fk_ prefixes, and
-- IF [NOT] EXISTS wherever MariaDB accepts it. This file is re-runnable.

-- ---------------------------------------------------------------------------
-- What the client expects to order, and what they last ordered
-- ---------------------------------------------------------------------------

-- `usual_order_qty` was never a quantity. It is the size of the batch this part
-- is ordered in -- a part bought 500 at a time in multiples of 100 has a usual
-- multiple of 100 and no usual quantity at all -- and the two readings put
-- different numbers in the box depending on who filled it in. Renamed to say
-- what it is.
--
-- MariaDB has no IF EXISTS for a column rename, so it is guarded by looking the
-- column up first. Keeps the file re-runnable.
SET @old_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'parts'
       AND COLUMN_NAME = 'usual_order_qty'
);
SET @sql := IF(@old_column = 1,
    'ALTER TABLE parts CHANGE COLUMN usual_order_qty usual_order_multiple
       INT UNSIGNED NULL COMMENT ''The batch size this part is ordered in''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- What is coming, so Junction can see it coming. Separate from the multiple:
-- one is how this part is always ordered, the other is what is expected next
-- and changes every time.
ALTER TABLE parts
    ADD COLUMN IF NOT EXISTS expected_next_order_qty INT UNSIGNED NULL
        COMMENT 'What the client expects to order next'
        AFTER usual_order_multiple;

-- The last order, as the client remembers it.
--
-- Recorded by hand and deliberately not derived from the order history in this
-- system, which only knows about orders placed through it. A part machined for
-- ten years before any of this existed has a last order, and it is not in
-- `orders`. The two live side by side on the part page and are labelled so
-- nobody reads one as the other.
--
-- The value is a price and is gated on view_pricing like every other price.
ALTER TABLE parts
    ADD COLUMN IF NOT EXISTS last_order_value DECIMAL(10,2) NULL
        COMMENT 'Recorded by hand; not derived from `orders`'
        AFTER expected_next_order_qty,
    ADD COLUMN IF NOT EXISTS last_order_qty INT UNSIGNED NULL
        COMMENT 'Recorded by hand; not derived from `orders`'
        AFTER last_order_value,
    ADD COLUMN IF NOT EXISTS last_order_date DATE NULL
        COMMENT 'Recorded by hand; not derived from `orders`'
        AFTER last_order_qty;

-- ---------------------------------------------------------------------------
-- More than one current drawing
-- ---------------------------------------------------------------------------

-- A part had one drawing lineage: upload a file and it became the current
-- revision of the only drawing there was. That is wrong for most real parts.
-- A fabrication has a general arrangement and a detail per sub-component; a
-- machined part often has a separate drawing for a second operation. Uploading
-- the second one superseded the first, and the first quietly became history.
--
-- So a drawing becomes a thing with a name, and the files become its
-- revisions. The name lives here rather than on each file because it belongs
-- to the drawing and not to any one revision of it -- renaming a drawing
-- should not mean rewriting its history.
CREATE TABLE IF NOT EXISTS part_drawings (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL COMMENT 'Short description: what this drawing is of',
    position    INT UNSIGNED NOT NULL DEFAULT 0,
    created_by  INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Two drawings on one part with the same name would defeat the point of
    -- having named them.
    UNIQUE KEY uq_part_drawings_name (part_id, name),
    CONSTRAINT fk_part_drawings_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_drawings_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE part_files
    ADD COLUMN IF NOT EXISTS drawing_id INT UNSIGNED NULL
        COMMENT 'Which named drawing this file is a revision of'
        AFTER part_id;

-- Everything already uploaded is one lineage per part, so it becomes one
-- drawing per part. Named rather than left blank: "Main drawing" is what it
-- was, and a required name that is empty on every existing row would be a
-- required name in name only.
--
-- created_by is whoever uploaded the earliest revision, which is as close to
-- "who started this drawing" as the data goes.
INSERT INTO part_drawings (part_id, name, position, created_by)
SELECT f.part_id, 'Main drawing', 0,
       (SELECT f2.uploaded_by FROM part_files f2
         WHERE f2.part_id = f.part_id
         ORDER BY f2.version_no, f2.id LIMIT 1)
  FROM part_files f
 WHERE NOT EXISTS (
        SELECT 1 FROM part_drawings d WHERE d.part_id = f.part_id AND d.name = 'Main drawing'
   )
 GROUP BY f.part_id;

UPDATE part_files f
  JOIN part_drawings d ON d.part_id = f.part_id AND d.name = 'Main drawing'
   SET f.drawing_id = d.id
 WHERE f.drawing_id IS NULL;

-- Now that every row has one. Guarded so a re-run does not trip over a column
-- that is already NOT NULL with a constraint on it.
SET @nullable := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'part_files'
       AND COLUMN_NAME = 'drawing_id'
       AND IS_NULLABLE = 'YES'
);
SET @sql := IF(@nullable = 1,
    'ALTER TABLE part_files MODIFY COLUMN drawing_id INT UNSIGNED NOT NULL
       COMMENT ''Which named drawing this file is a revision of''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'part_files'
       AND CONSTRAINT_NAME = 'fk_part_files_drawing'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE part_files ADD CONSTRAINT fk_part_files_drawing
       FOREIGN KEY (drawing_id) REFERENCES part_drawings (id) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Version numbers and "is this the current one" are now per drawing rather
-- than per part. The unique key is what stops two revisions claiming to be
-- v3 of the same drawing.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'part_files'
       AND INDEX_NAME = 'uq_part_files_version'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE part_files ADD UNIQUE KEY uq_part_files_version (drawing_id, version_no)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'part_files'
       AND INDEX_NAME = 'idx_part_files_current'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE part_files ADD KEY idx_part_files_current (drawing_id, is_current)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
