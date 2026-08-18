-- Feature expansion: roles/permissions, settings/branding/email config,
-- part linking, free-issue ratio, part/order photos, order-line completion
-- and free-issue discrepancy tracking, order notes/queries, notification
-- preferences.
--
-- Same conventions as 001: InnoDB/utf8mb4, uq_/idx_/fk_ prefixes,
-- IF NOT EXISTS everywhere so this is safe to re-run.

-- ---------------------------------------------------------------------------
-- Roles & permissions (item 10)
-- ---------------------------------------------------------------------------

-- `role` renamed to `side`: still the fixed top-level client/staff split (drives
-- the client_id nullability rule below); granular capability now comes from
-- roles/user_roles instead of this single column.
ALTER TABLE users DROP CONSTRAINT chk_users_client_role;
ALTER TABLE users CHANGE COLUMN role side ENUM('staff','client') NOT NULL;
ALTER TABLE users ADD CONSTRAINT chk_users_client_side CHECK (
    (side = 'client' AND client_id IS NOT NULL) OR
    (side = 'staff' AND client_id IS NULL)
);

CREATE TABLE IF NOT EXISTS roles (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug    VARCHAR(30) NOT NULL COMMENT 'e.g. client.purchaser, staff.admin',
    name    VARCHAR(60) NOT NULL,
    side    ENUM('staff','client') NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id     INT UNSIGNED NOT NULL,
    role_id     INT UNSIGNED NOT NULL,
    granted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    KEY idx_user_roles_role (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO roles (slug, name, side) VALUES
    ('client.admin', 'Client admin', 'client'),
    ('client.purchaser', 'Purchaser', 'client'),
    ('client.production', 'Production viewer', 'client'),
    ('staff.admin', 'Staff admin', 'staff'),
    ('staff.invoicing', 'Invoicing', 'staff'),
    ('staff.quoting', 'Quoting', 'staff'),
    ('staff.production', 'Production', 'staff')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Existing accounts keep full capability on their side so nothing that
-- worked before this migration stops working after it.
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u JOIN roles r ON r.slug = 'staff.admin' WHERE u.side = 'staff'
ON DUPLICATE KEY UPDATE user_id = user_id;

INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u JOIN roles r ON r.slug = 'client.admin' WHERE u.side = 'client'
ON DUPLICATE KEY UPDATE user_id = user_id;

-- ---------------------------------------------------------------------------
-- Settings (items 11-13): generic key/value store, same pattern as Kitwell's
-- Setting model. Holds SMTP config, logo paths, and the Clear Books OAuth
-- client credentials -- everything an admin should be able to change without
-- a redeploy.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS settings (
    setting_key     VARCHAR(80) NOT NULL,
    setting_value   TEXT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Notification preferences (item 12) -- opt-in, so presence of a row is the
-- subscription; nobody has any row by default.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id             INT UNSIGNED NOT NULL,
    notification_type   VARCHAR(60) NOT NULL,
    PRIMARY KEY (user_id, notification_type),
    CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Part links (item 2) -- symmetric many-to-many. One row per unordered pair
-- (part_id always the smaller id) so "A links to B" and "B links to A" are
-- the same row; queried from either side with `part_id = ? OR linked_part_id = ?`.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS part_links (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id         INT UNSIGNED NOT NULL,
    linked_part_id  INT UNSIGNED NOT NULL,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_part_links_pair (part_id, linked_part_id),
    KEY idx_part_links_linked (linked_part_id),
    CONSTRAINT fk_part_links_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_links_linked_part FOREIGN KEY (linked_part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_links_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_part_links_order CHECK (part_id < linked_part_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Free-issue ratio (item 4) -- one relationship for the whole part, matching
-- the single-dropdown UI. 'none' = 1:1 (order qty == free-issue qty).
-- ---------------------------------------------------------------------------

ALTER TABLE parts
    ADD COLUMN free_issue_relationship ENUM('none','divide','multiply') NOT NULL DEFAULT 'none' AFTER notes,
    ADD COLUMN free_issue_factor TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER free_issue_relationship;

-- ---------------------------------------------------------------------------
-- Part photos (item 14) -- client-uploaded, shown wherever the part appears.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS part_photos (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id             INT UNSIGNED NOT NULL,
    file_path           VARCHAR(500) NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    mime_type           VARCHAR(120) NULL,
    file_size           INT UNSIGNED NULL,
    uploaded_by         INT UNSIGNED NOT NULL,
    uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_part_photos_part (part_id),
    CONSTRAINT fk_part_photos_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_photos_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Order photos (item 14) -- staff-only progress/setup photos, per order or
-- per line.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS order_photos (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id            INT UNSIGNED NOT NULL,
    order_line_id       INT UNSIGNED NULL,
    file_path           VARCHAR(500) NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    mime_type           VARCHAR(120) NULL,
    file_size           INT UNSIGNED NULL,
    caption             VARCHAR(255) NULL,
    uploaded_by         INT UNSIGNED NOT NULL,
    uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_photos_order (order_id),
    KEY idx_order_photos_line (order_line_id),
    CONSTRAINT fk_order_photos_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_photos_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_photos_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Order-line partial completion (item 7)
-- ---------------------------------------------------------------------------

ALTER TABLE order_lines
    ADD COLUMN qty_completed INT UNSIGNED NOT NULL DEFAULT 0 AFTER qty_free_issue_received;

-- ---------------------------------------------------------------------------
-- Free-issue check-in discrepancy (item 6) -- flag on the receipt event, not
-- a new order_line stage: a line can have several receipt events over time,
-- and a discrepancy on one shouldn't permanently overload the stage enum.
-- ---------------------------------------------------------------------------

ALTER TABLE free_issue_receipts
    ADD COLUMN discrepancy_type ENUM('none','shortfall','excess','wrong_item') NOT NULL DEFAULT 'none' AFTER qty_received,
    ADD COLUMN discrepancy_notes VARCHAR(255) NULL AFTER discrepancy_type,
    ADD COLUMN resolved_at DATETIME NULL AFTER discrepancy_notes,
    ADD COLUMN resolved_by INT UNSIGNED NULL AFTER resolved_at,
    ADD CONSTRAINT fk_free_issue_receipts_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Order notes and queries (item 8)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS order_notes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    body        TEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_notes_order (order_id, created_at),
    CONSTRAINT fk_order_notes_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_notes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_queries (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED NOT NULL,
    raised_by   INT UNSIGNED NOT NULL,
    subject     VARCHAR(190) NOT NULL,
    body        TEXT NOT NULL,
    status      ENUM('open','answered') NOT NULL DEFAULT 'open',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_queries_order (order_id, status),
    CONSTRAINT fk_order_queries_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_queries_raised_by FOREIGN KEY (raised_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_query_replies (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_query_id  INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    body            TEXT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_query_replies_query (order_query_id, created_at),
    CONSTRAINT fk_order_query_replies_query FOREIGN KEY (order_query_id) REFERENCES order_queries (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_query_replies_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
