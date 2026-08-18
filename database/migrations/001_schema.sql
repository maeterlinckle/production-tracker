-- Core schema: clients, users, parts, orders, free issue, delivery notes,
-- invoicing links, route cards and supporting tables.
--
-- Status model: order_lines.stage is a coarse 5-state pipeline used for the
-- stepper UI. Free-issue receipt, delivery and invoicing are each tracked as
-- qty-vs-qty pairs (plus per-event log tables) rather than folded into the
-- enum, because a line can be e.g. 50/100 delivered and 30/100 invoiced at
-- the same time -- that's two independent fractions, not one state.

CREATE TABLE IF NOT EXISTS clients (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                VARCHAR(150) NOT NULL,
    clearbooks_entity_id VARCHAR(64) NULL,
    address_line1       VARCHAR(150) NULL,
    address_line2       VARCHAR(150) NULL,
    address_city        VARCHAR(100) NULL,
    address_postcode    VARCHAR(20) NULL,
    address_country     VARCHAR(100) NULL DEFAULT 'United Kingdom',
    main_contact_name   VARCHAR(150) NULL,
    main_contact_email  VARCHAR(190) NULL,
    main_contact_phone  VARCHAR(40) NULL,
    billing_email       VARCHAR(190) NULL,
    notes               TEXT NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id       INT UNSIGNED NULL,
    role            ENUM('staff','client') NOT NULL,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_client (client_id),
    CONSTRAINT fk_users_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT chk_users_client_role CHECK (
        (role = 'client' AND client_id IS NOT NULL) OR
        (role = 'staff' AND client_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email           VARCHAR(190) NOT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    succeeded       TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_lookup (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Atomic per-year reference number generation for orders, delivery notes,
-- route cards etc. Incremented inside a transaction with SELECT ... FOR
-- UPDATE so two staff generating paperwork at once never collide.
CREATE TABLE IF NOT EXISTS reference_sequences (
    sequence_key    VARCHAR(60) NOT NULL,
    next_number     INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (sequence_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Single-row store for the Clear Books OAuth 2 token pair (authorization
-- code grant — there is no static API key). Populated by the one-time
-- staff-only /staff/clearbooks/connect flow, refreshed automatically
-- thereafter. id is always 1.
CREATE TABLE IF NOT EXISTS clearbooks_tokens (
    id              TINYINT UNSIGNED NOT NULL,
    access_token    TEXT NOT NULL,
    refresh_token   TEXT NOT NULL,
    expires_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS parts (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id               INT UNSIGNED NOT NULL,
    cpn                     VARCHAR(80) NOT NULL,
    name                    VARCHAR(190) NOT NULL,
    description             TEXT NULL,
    usual_order_qty         INT UNSIGNED NULL,
    target_price            DECIMAL(10,2) NULL COMMENT 'Client-visible previous/target pricing, informational only',
    notes                   TEXT NULL COMMENT 'Client-visible notes',
    status                  ENUM('draft','quoted') NOT NULL DEFAULT 'draft',
    is_archived             TINYINT(1) NOT NULL DEFAULT 0,
    -- Junction-only fields, never rendered in client-facing views:
    internal_notes          TEXT NULL,
    build_time_minutes      INT UNSIGNED NULL,
    quoted_price            DECIMAL(10,2) NULL,
    quoted_price_set_by     INT UNSIGNED NULL,
    quoted_price_set_at     DATETIME NULL,
    base_material           VARCHAR(150) NULL,
    material_source         VARCHAR(150) NULL,
    material_cost           DECIMAL(10,2) NULL,
    created_by              INT UNSIGNED NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parts_client_cpn (client_id, cpn),
    KEY idx_parts_status (client_id, status),
    CONSTRAINT fk_parts_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_parts_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_parts_quoted_by FOREIGN KEY (quoted_price_set_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS part_alternate_numbers (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id     INT UNSIGNED NOT NULL,
    number      VARCHAR(80) NOT NULL,
    label       VARCHAR(80) NULL COMMENT 'e.g. "Drawing no.", "Legacy ref"',
    PRIMARY KEY (id),
    KEY idx_part_alt_numbers_part (part_id),
    CONSTRAINT fk_part_alt_numbers_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The raw/free-issue material a part is made from. Free text because the
-- source material may not be a tracked Part in this system at all (e.g. the
-- client's own bar-stock CPN, or a plain description).
CREATE TABLE IF NOT EXISTS part_free_issue_materials (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id     INT UNSIGNED NOT NULL,
    reference   VARCHAR(190) NOT NULL COMMENT 'Client CPN or free-text material description',
    notes       VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_part_free_issue_materials_part (part_id),
    CONSTRAINT fk_part_free_issue_materials_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS part_files (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id             INT UNSIGNED NOT NULL,
    file_path           VARCHAR(500) NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    mime_type           VARCHAR(120) NULL,
    file_size           INT UNSIGNED NULL,
    version_no          INT UNSIGNED NOT NULL DEFAULT 1,
    is_current          TINYINT(1) NOT NULL DEFAULT 1,
    uploaded_by         INT UNSIGNED NOT NULL,
    uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_part_files_part (part_id, is_current),
    CONSTRAINT fk_part_files_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id           INT UNSIGNED NOT NULL,
    order_number        VARCHAR(30) NOT NULL COMMENT 'e.g. ORD-2026-0001',
    po_file_path        VARCHAR(500) NOT NULL,
    po_original_filename VARCHAR(255) NOT NULL,
    placed_by           INT UNSIGNED NOT NULL,
    placed_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes                TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_number (order_number),
    KEY idx_orders_client (client_id),
    CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_placed_by FOREIGN KEY (placed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_lines (
    id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id                    INT UNSIGNED NOT NULL,
    part_id                     INT UNSIGNED NOT NULL,
    line_no                     INT UNSIGNED NOT NULL,
    qty_ordered                 INT UNSIGNED NOT NULL,
    unit_price                  DECIMAL(10,2) NOT NULL COMMENT 'Snapshot of part.quoted_price at order time',
    stage                       ENUM('awaiting_free_issue','ready_for_production','in_production','complete','closed') NOT NULL,
    qty_free_issue_required     INT UNSIGNED NOT NULL DEFAULT 0,
    qty_free_issue_received     INT UNSIGNED NOT NULL DEFAULT 0,
    qty_delivered                INT UNSIGNED NOT NULL DEFAULT 0,
    qty_invoiced                 INT UNSIGNED NOT NULL DEFAULT 0,
    notes                       TEXT NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_lines_order_lineno (order_id, line_no),
    KEY idx_order_lines_part (part_id),
    KEY idx_order_lines_stage (stage),
    CONSTRAINT fk_order_lines_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_lines_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS production_status_log (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_line_id   INT UNSIGNED NOT NULL,
    from_stage      VARCHAR(30) NULL,
    to_stage        VARCHAR(30) NOT NULL,
    changed_by      INT UNSIGNED NOT NULL,
    notes           VARCHAR(255) NULL,
    changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_production_status_log_line (order_line_id, changed_at),
    CONSTRAINT fk_production_status_log_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE CASCADE,
    CONSTRAINT fk_production_status_log_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS free_issue_receipts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_line_id   INT UNSIGNED NOT NULL,
    qty_received    INT UNSIGNED NOT NULL,
    received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_by     INT UNSIGNED NOT NULL,
    notes           VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_free_issue_receipts_line (order_line_id),
    CONSTRAINT fk_free_issue_receipts_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE CASCADE,
    CONSTRAINT fk_free_issue_receipts_user FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_notes (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type                ENUM('free_issue_in','goods_out') NOT NULL,
    client_id           INT UNSIGNED NOT NULL,
    reference           VARCHAR(30) NOT NULL COMMENT 'e.g. FIDN-2026-0001 / DN-2026-0001',
    pdf_file_path       VARCHAR(500) NULL,
    issued_by           INT UNSIGNED NOT NULL,
    issued_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    invoiced            TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'goods_out only',
    invoiced_at         DATETIME NULL,
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_notes_reference (reference),
    KEY idx_delivery_notes_client_type (client_id, type),
    KEY idx_delivery_notes_uninvoiced (type, invoiced),
    CONSTRAINT fk_delivery_notes_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_delivery_notes_issued_by FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_note_lines (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_note_id    INT UNSIGNED NOT NULL,
    order_line_id       INT UNSIGNED NOT NULL,
    qty                 INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_delivery_note_lines_dn (delivery_note_id),
    KEY idx_delivery_note_lines_line (order_line_id),
    CONSTRAINT fk_delivery_note_lines_dn FOREIGN KEY (delivery_note_id) REFERENCES delivery_notes (id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_note_lines_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
    id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_note_id            INT UNSIGNED NOT NULL,
    clearbooks_invoice_id       VARCHAR(64) NOT NULL COMMENT 'Clear Books internal ID',
    clearbooks_invoice_number   VARCHAR(64) NOT NULL COMMENT 'Human-readable Clear Books invoice number',
    amount                      DECIMAL(10,2) NOT NULL,
    raised_by                   INT UNSIGNED NOT NULL,
    raised_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes                       VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_delivery_note (delivery_note_id),
    CONSTRAINT fk_invoices_delivery_note FOREIGN KEY (delivery_note_id) REFERENCES delivery_notes (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_raised_by FOREIGN KEY (raised_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS route_cards (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_line_id   INT UNSIGNED NOT NULL,
    reference       VARCHAR(30) NOT NULL COMMENT 'e.g. RC-2026-0001',
    pdf_file_path   VARCHAR(500) NULL,
    generated_by    INT UNSIGNED NOT NULL,
    generated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_route_cards_reference (reference),
    KEY idx_route_cards_line (order_line_id),
    CONSTRAINT fk_route_cards_line FOREIGN KEY (order_line_id) REFERENCES order_lines (id) ON DELETE CASCADE,
    CONSTRAINT fk_route_cards_generated_by FOREIGN KEY (generated_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_log (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    to_email        VARCHAR(190) NOT NULL,
    subject         VARCHAR(255) NOT NULL,
    template_key    VARCHAR(60) NOT NULL,
    related_type    VARCHAR(40) NULL,
    related_id      INT UNSIGNED NULL,
    status          ENUM('sent','failed') NOT NULL,
    error           TEXT NULL,
    sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email_log_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
