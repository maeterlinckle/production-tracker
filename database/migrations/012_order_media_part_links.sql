-- Which part is that a photo of?
--
-- Order attachments already knew which order they belonged to, and optionally
-- which line. What they could not say is which part they show — and that is
-- the question asked about them six months later, from the part page rather
-- than the order page. "There was a photo of the burr on this one somewhere"
-- meant remembering which order it was taken on, which is precisely the thing
-- nobody remembers.
--
-- Part rather than line, because the reference is to the part: the same part
-- comes round on a new order and the photo is still the answer. And many
-- rather than one, because a shot of two components fitted together is one
-- photo about two parts, and forcing a choice there loses half the reference.
--
-- The existing order_line_id column stays as it is. It records which line the
-- attachment was filed against on that order, which is a different fact from
-- what the attachment depicts, and nothing that reads it changes here.
--
-- Same conventions as 001-011: InnoDB/utf8mb4, uq_/idx_/fk_ prefixes, and
-- IF [NOT] EXISTS wherever MariaDB accepts it. This file is re-runnable.

CREATE TABLE IF NOT EXISTS order_photo_parts (
    order_photo_id  INT UNSIGNED NOT NULL,
    part_id         INT UNSIGNED NOT NULL,
    PRIMARY KEY (order_photo_id, part_id),
    KEY idx_order_photo_parts_part (part_id),
    CONSTRAINT fk_order_photo_parts_photo FOREIGN KEY (order_photo_id) REFERENCES order_photos (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_photo_parts_part  FOREIGN KEY (part_id)        REFERENCES parts (id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing attachments filed against a line already say which part they are
-- about, so they start out tagged rather than starting out blank. Anything
-- filed against the whole order stays untagged, which is the honest answer:
-- nobody said what it showed.
--
-- INSERT IGNORE rather than a NOT EXISTS clause, so re-running the file over a
-- database where somebody has since untagged one of these does not put it back.
INSERT IGNORE INTO order_photo_parts (order_photo_id, part_id)
SELECT p.id, ol.part_id
  FROM order_photos p
  JOIN order_lines ol ON ol.id = p.order_line_id
 WHERE p.order_line_id IS NOT NULL;
