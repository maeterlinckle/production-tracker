-- Editable email templates, invitation-based account creation, scheduled
-- reminders, and the move of the free-issue relationship to a client-set value.
--
-- Same conventions as 001/002: InnoDB/utf8mb4, uq_/idx_/fk_ prefixes,
-- IF NOT EXISTS everywhere so this is safe to re-run.

-- ---------------------------------------------------------------------------
-- Editable email templates
--
-- Overrides only. A row exists for a key precisely when somebody has edited it,
-- so a fresh install sends properly worded mail from an empty table and "reset
-- to the built-in wording" is a DELETE rather than a re-seed. The shipped text
-- lives in App\Mail\EmailTemplate::DEFAULTS, which is therefore the one place
-- it can be wrong.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS email_templates (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_key    VARCHAR(60) NOT NULL,
    subject         VARCHAR(255) NOT NULL,
    body            MEDIUMTEXT NOT NULL,
    is_html         TINYINT(1) NOT NULL DEFAULT 1,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    updated_by      INT UNSIGNED NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_templates_key (template_key),
    CONSTRAINT fk_email_templates_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Invitations
--
-- An account is created inactive and with no usable password; the invitee sets
-- their own from a single-use link. Only the SHA-256 of the token is stored, so
-- the database never holds anything that could be used to claim an invitation.
--
-- accepted_at is what makes it single-use. Rows are kept after acceptance
-- rather than deleted: "who invited this person, and when" is the audit trail
-- for how an account came to exist.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS user_invites (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    invited_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,
    accepted_at     DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_invites_token (token_hash),
    KEY idx_user_invites_user (user_id),
    KEY idx_user_invites_expiry (expires_at),
    CONSTRAINT fk_user_invites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_invites_invited_by FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An invited account has no password until the link is used. The column is NOT
-- NULL and every code path hashes something into it, so "no password yet" needs
-- somewhere of its own to live rather than a magic hash value.
ALTER TABLE users
    ADD COLUMN password_set_at DATETIME NULL AFTER password_hash;

-- Everything that exists today was created with a password, so it counts as set.
UPDATE users SET password_set_at = created_at WHERE password_set_at IS NULL;

-- ---------------------------------------------------------------------------
-- Free-issue relationship becomes the client's own value
--
-- It is set on the part, from the quote stage onwards, by whoever asks for the
-- quote — Junction staff can still correct it. The two audit columns are what
-- make "the client said 4:1 and we changed it" answerable six months later;
-- without them the only record of an override is the value itself.
--
-- The factor is widened to 10 in the interface, not in the schema: TINYINT
-- UNSIGNED already covers it. The CHECK is added so the range is enforced by
-- the database rather than only by the dropdown.
-- ---------------------------------------------------------------------------

ALTER TABLE parts
    ADD COLUMN free_issue_updated_by INT UNSIGNED NULL AFTER free_issue_factor,
    ADD COLUMN free_issue_updated_at DATETIME NULL AFTER free_issue_updated_by,
    ADD CONSTRAINT fk_parts_free_issue_updated_by FOREIGN KEY (free_issue_updated_by) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT chk_parts_free_issue_factor CHECK (
        (free_issue_relationship = 'none' AND free_issue_factor = 1) OR
        (free_issue_relationship <> 'none' AND free_issue_factor BETWEEN 2 AND 10)
    );

-- ---------------------------------------------------------------------------
-- Reminder runs
--
-- One row per digest actually sent, so the reminders screen can say when it
-- last ran and what went out — and so a second run on the same day is a
-- decision rather than an accident.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS reminder_runs (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind            VARCHAR(40) NOT NULL COMMENT 'e.g. parts_outstanding',
    ran_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recipients      INT UNSIGNED NOT NULL DEFAULT 0,
    items           INT UNSIGNED NOT NULL DEFAULT 0,
    sent            INT UNSIGNED NOT NULL DEFAULT 0,
    failed          INT UNSIGNED NOT NULL DEFAULT 0,
    triggered_by    INT UNSIGNED NULL COMMENT 'null when run from cron',
    notes           VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_reminder_runs_kind (kind, ran_at),
    CONSTRAINT fk_reminder_runs_triggered_by FOREIGN KEY (triggered_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
