-- Thumbnails for uploaded photos.
--
-- The grids on the part and order pages were drawing 160-pixel tiles out of
-- whatever came off a phone — four to twelve megabytes each — so a part with a
-- dozen setup photos sent fifty megabytes to somebody on a workshop 4G
-- connection. Uploads are now shrunk to a sane maximum on the way in and a
-- thumbnail is written beside the original for the grids to use.
--
-- Nullable, because a thumbnail is a nicety that must never cost somebody the
-- photo they just took: where GD is missing, or a format cannot be read, or the
-- disk is full, the column stays NULL and the full image is served instead.

ALTER TABLE part_media
    ADD COLUMN IF NOT EXISTS thumb_path VARCHAR(500) NULL AFTER file_path;

ALTER TABLE order_photos
    ADD COLUMN IF NOT EXISTS thumb_path VARCHAR(500) NULL AFTER file_path;
