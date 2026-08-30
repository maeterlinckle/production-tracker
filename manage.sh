#!/usr/bin/env bash
#
# Production Tracker — administration.
#
#   sudo ./manage.sh help
#
# One place for the jobs that are awkward or risky without a proper flow:
# migrations, account and credential resets, backups, health checks and config.
# Day-to-day work — orders, parts, pricing, delivery notes — is done in the web
# interface, and deliberately has no command here.
#
# Anything that needs the database goes through bin/console.php so it uses the
# application's own models, prepared statements and validation. Anything that
# needs root — services, ownership, backups, cron — is done here.
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$APP_DIR/.env"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/production-tracker}"
BACKUP_KEEP="${BACKUP_KEEP:-14}"
REPO_URL="${REPO_URL:-https://github.com/maeterlinckle/production-tracker.git}"

QUIET=no
ASSUME_YES=no

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[36m'
else
    C_RESET=""; C_BOLD=""; C_DIM=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""
fi

say()  { [ "$QUIET" = yes ] || printf '%s\n' "$*"; }
step() { [ "$QUIET" = yes ] || printf '\n%s==>%s %s%s%s\n' "$C_BLUE" "$C_RESET" "$C_BOLD" "$*" "$C_RESET"; }
ok()   { [ "$QUIET" = yes ] || printf '  %s[ ok ]%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
info() { [ "$QUIET" = yes ] || printf '  %s[ .. ]%s %s\n' "$C_DIM" "$C_RESET" "$*"; }
warn() { printf '  %s[warn]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
die()  { printf '\n%sError:%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }

# A whole positive number and nothing else — for values read back out of the
# application, where half an answer must not become a limit.
is_number() { case "${1:-}" in "") return 1 ;; *[!0-9]*) return 1 ;; *) return 0 ;; esac; }

# Is there a systemd unit by this name?
#
# The output is captured and tested, never piped into `grep -q`. This script
# runs under `set -o pipefail`, where grep exiting on its first match SIGPIPEs
# the producer and the pipeline then reports failure — so a match reads as a
# miss. It cost an install: see the same note in install.sh.
unit_exists() { # unit_exists NAME
    have systemctl || return 1

    local units
    units="$(systemctl list-unit-files --no-legend "$1.service" 2>/dev/null || true)"

    [ -n "$units" ]
}

usage() {
    cat <<'USAGE'
Production Tracker — administration

  sudo ./manage.sh <command> [arguments]

Checking
  status                      services, versions, disk and database at a glance
  doctor                      full check of PHP, config, storage and database
  health                      call the site's own /health endpoint
  stats                       counts from the tracker
  logs [-f] [-n LINES]        the application log

Users
  users                       list every account
  create-admin                create a Junction staff administrator
  reset-password [EMAIL]      set a new password and lift any lockout
  invite-link EMAIL           print a fresh invitation link (when email is down)
  unlock [EMAIL]              clear sign-in lockouts (all accounts if no email)
  activate EMAIL              re-enable an account
  deactivate EMAIL            disable an account
  set-roles EMAIL ROLES       comma-separated, e.g. staff.quoting,staff.production
  roles                       list the roles and what each one can do

Application
  settings                    show the application settings
  set-setting KEY VALUE       change one
  config KEY [VALUE]          read or change a value in .env
  migrate [--status]          apply pending database migrations
  db-grant                    re-apply the database grant (fixes a migration
                              that stops with "command denied")
  reset-database              empty the database and rebuild the schema, ready
                              for a first administrator (asks twice; ignores --yes)
  reset-uploads               delete every uploaded and generated file
                              (asks twice; ignores --yes)

Email and integrations
  install-composer            install Composer, if the machine has none
  composer-install            install the PHP packages (fetches Composer first)
  mail-status                 show the mail configuration and the send log
  mail-test EMAIL             send a test message and report the result
  send-reminders [--force]    run the outstanding-parts digest now
  clearbooks-status           Clear Books connection and posting settings

Server
  backup [DIR]                dump the database and archive the uploads
  restore DUMP [UPLOADS]      restore from a backup
  update [SOURCE_DIR]         copy in a new version and migrate
                              (no SOURCE_DIR: pull from the project repository)
  permissions                 re-apply ownership and file modes
  php-limits                  set PHP's upload limits to match the application
  pdf-warm                    build the PDF font cache
  package [FILE]              build a distributable archive of this install
  cron-install                daily backup and the reminder digest
  cron-remove                 remove them again
  restart                     restart the web server and PHP-FPM

Options
  --quiet                     only print warnings and errors (for cron)
  --yes                       do not ask for confirmation
USAGE
}

# ---------------------------------------------------------------------------
# Environment
# ---------------------------------------------------------------------------
require_root() {
    [ "$(id -u)" -eq 0 ] || die "This needs root:  sudo $0 $*"
}

env_get() { # env_get KEY
    local key="$1" line value
    [ -r "$ENV_FILE" ] || return 0

    line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | tail -1 || true)"
    [ -n "$line" ] || return 0

    value="${line#*=}"
    value="${value#"${value%%[![:space:]]*}"}"          # trim leading space
    value="${value%"${value##*[![:space:]]}"}"          # trim trailing space

    case "$value" in
        \"*\") value="${value%\"}"; value="${value#\"}" ;;
        \'*\') value="${value%\'}"; value="${value#\'}" ;;
        *)     value="${value%% #*}" ;;                 # strip an inline comment
    esac

    printf '%s' "$value"
}

env_set() { # env_set KEY VALUE
    local key="$1" value="$2" backup tmp

    [ -f "$ENV_FILE" ] || die "No .env at $ENV_FILE."

    backup="$ENV_FILE.$(date +%Y%m%d-%H%M%S).bak"
    cp -p "$ENV_FILE" "$backup"
    chmod 600 "$backup"

    if grep -qE "^[[:space:]]*${key}=" "$ENV_FILE"; then
        # Written through a temp file so the original mode and owner survive.
        tmp="$(mktemp)"
        awk -v k="$key" -v v="$value" '
            $0 ~ "^[[:space:]]*" k "=" { print k "=" v; next }
            { print }
        ' "$ENV_FILE" > "$tmp"
        cat "$tmp" > "$ENV_FILE"
        rm -f "$tmp"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi

    ok "$key set (previous .env kept as $(basename "$backup"))"
}

detect_web_user() {
    local candidate
    for candidate in www-data apache http nginx; do
        id -u "$candidate" >/dev/null 2>&1 && { printf '%s' "$candidate"; return 0; }
    done
    printf 'root'
}

PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || die "PHP is not on the PATH."

WEB_USER="$(detect_web_user)"
WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || printf '%s' "$WEB_USER")"
DB_CLIENT="$(command -v mariadb || command -v mysql || true)"
DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"

WEB_READ_CHECKED=no

run_as_web() {
    if [ "$(id -u)" -ne 0 ]; then
        "$@"
    elif have runuser; then
        runuser -u "$WEB_USER" -- "$@"
    elif have sudo; then
        sudo -u "$WEB_USER" -- "$@"
    else
        local quoted="" arg
        for arg in "$@"; do quoted+=" $(printf '%q' "$arg")"; done
        su -s /bin/sh -c "$quoted" "$WEB_USER"
    fi
}

# PHP runs as the web user, so the web user has to be able to read the tree. It
# cannot if the application sits somewhere only root can traverse — a checkout
# under /root being the usual way that happens. Say so once, plainly, instead of
# letting PHP fail to open src/bootstrap.php over and over.
assert_web_can_read() {
    [ "$WEB_READ_CHECKED" = yes ] && return 0
    WEB_READ_CHECKED=yes

    [ "$(id -u)" -eq 0 ] || return 0
    id -u "$WEB_USER" >/dev/null 2>&1 || return 0

    if run_as_web test -r "$APP_DIR/src/bootstrap.php" 2>/dev/null; then
        return 0
    fi

    say "" >&2
    printf '%sThe web server user cannot read this installation.%s\n' "$C_BOLD" "$C_RESET" >&2
    say "" >&2
    warn "$WEB_USER cannot read $APP_DIR/src/bootstrap.php"
    say "" >&2
    say "  PHP runs as $WEB_USER, so it needs to read the application files." >&2
    say "  A directory only root can enter — anything under /root, typically —" >&2
    say "  will always fail this way." >&2
    say "" >&2
    say "  Fix the ownership and modes:" >&2
    say "" >&2
    say "      sudo $APP_DIR/manage.sh permissions" >&2
    say "" >&2
    say "  If the application really does live under /root, move it somewhere" >&2
    say "  the web server can reach, such as /var/www/production-tracker." >&2
    say "" >&2

    exit 1
}

console() {
    assert_web_can_read
    ( cd "$APP_DIR" && run_as_web "$PHP_BIN" bin/console.php "$@" )
}

confirm() {
    local question="$1" answer
    [ "$ASSUME_YES" = yes ] && return 0
    read -r -p "  $question [y/N]: " answer || true
    [ "${answer,,}" = y ] || [ "${answer,,}" = yes ]
}

web_service() {
    local candidate
    for candidate in apache2 httpd nginx; do
        if unit_exists "$candidate"; then
            printf '%s' "$candidate"; return 0
        fi
    done
    printf ''
}

db_service() {
    local candidate
    for candidate in mariadb mysqld mysql; do
        if unit_exists "$candidate"; then
            printf '%s' "$candidate"; return 0
        fi
    done
    printf ''
}

fpm_service() {
    local candidate
    for candidate in php-fpm php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm; do
        if unit_exists "$candidate"; then
            printf '%s' "$candidate"; return 0
        fi
    done
    printf ''
}

# A defaults file for the application's own database credentials, so a password
# never appears in the process list — and is removed however the script ends.
#
# An EXIT trap rather than the per-function `trap ... RETURN` this used to
# carry. A RETURN trap only fires when the function returns normally, and
# `set -e` does not return from a function -- it exits the shell. So the one
# case the cleanup existed for, a command failing part-way, was the one case it
# did not cover: a failed backup left the password in /tmp. Measured, not
# assumed.
DB_CNF_FILES=()

db_cnf_cleanup() {
    local f
    for f in ${DB_CNF_FILES+"${DB_CNF_FILES[@]}"}; do
        [ -n "$f" ] && rm -f "$f"
    done
    DB_CNF_FILES=()
}

trap db_cnf_cleanup EXIT

db_client_cnf() {
    local cnf; cnf="$(mktemp)"; chmod 600 "$cnf"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
        "$(env_get DB_USERNAME)" "$(env_get DB_PASSWORD)" \
        "$(env_get DB_HOST)" "$(env_get DB_PORT)" > "$cnf"
    DB_CNF_FILES+=("$cnf")
    printf '%s' "$cnf"
}

# ---------------------------------------------------------------------------
# Checking
# ---------------------------------------------------------------------------
cmd_status() {
    step "Production Tracker at $APP_DIR"

    say "  PHP            $("$PHP_BIN" -r 'echo PHP_VERSION;')"
    say "  Application    $(env_get APP_NAME) — $(env_get APP_URL)"
    say "  Environment    APP_ENV=$(env_get APP_ENV)  APP_DEBUG=$(env_get APP_DEBUG)  TRUSTED_PROXIES=$(env_get TRUSTED_PROXIES)"
    say "  Database       $(env_get DB_DATABASE) on $(env_get DB_HOST) as $(env_get DB_USERNAME)"
    say "  Web user       $WEB_USER"

    step "Services"
    local svc
    for svc in "$(web_service)" "$(db_service)" php-fpm; do
        [ -n "$svc" ] || continue
        if unit_exists "$svc"; then
            if systemctl is-active --quiet "$svc"; then ok "$svc is running"; else warn "$svc is NOT running"; fi
        fi
    done

    step "Disk"
    say "  $(df -h "$APP_DIR" | awk 'NR==2 {printf "%s used of %s (%s) on %s", $3, $2, $5, $6}')"
    say "  Uploads        $(du -sh "$APP_DIR/storage/uploads" 2>/dev/null | cut -f1) in $APP_DIR/storage/uploads"

    step "Migrations"
    assert_web_can_read
    ( cd "$APP_DIR" && run_as_web "$PHP_BIN" bin/migrate.php --status ) | tail -4

    step "Tracker"
    console stats || warn "The database could not be reached — run: $0 doctor"
}

cmd_doctor() { console doctor; }
cmd_stats()  { console stats; }
cmd_users()  { console user:list; }
cmd_roles()  { console roles; }

cmd_health() {
    local url; url="$(env_get APP_URL)/health"
    have curl || die "curl is not installed."

    step "GET $url"
    if curl -fsS --max-time 15 "$url"; then
        say ""
        ok "The site answered."
    else
        say ""
        warn "No healthy answer. Trying the loopback interface directly..."
        curl -fsS --max-time 15 -H 'X-Forwarded-Proto: https' "http://127.0.0.1/health" \
            || die "The application is not answering. Check: journalctl -u $(web_service) -n 50"
    fi
}

cmd_logs() {
    local log="$APP_DIR/storage/logs/app.log"
    [ -f "$log" ] || die "No log at $log yet."

    local follow=no lines=100 arg
    for arg in "$@"; do
        case "$arg" in
            -f|--follow) follow=yes ;;
            -n) : ;;
            [0-9]*) lines="$arg" ;;
            -n*) lines="${arg#-n}" ;;
        esac
    done

    if [ "$follow" = yes ]; then
        tail -n "$lines" -f "$log"
    else
        tail -n "$lines" "$log"
    fi
}

# ---------------------------------------------------------------------------
# Users
# ---------------------------------------------------------------------------
cmd_create_admin() {
    step "Create a Junction staff administrator"
    say "  Every other account is created by invitation from the interface."
    say "  This is for the first one, or for when nobody can get in."
    say ""

    local name email password confirm_password

    read -r -p "  Full name: " name || true
    read -r -p "  Email: " email || true

    [ -n "$name" ] && [ -n "$email" ] || die "A name and an email address are both needed."

    while true; do
        read -r -s -p "  Password (at least 12 characters): " password || true; echo
        read -r -s -p "  Confirm: " confirm_password || true; echo

        [ "$password" = "$confirm_password" ] || { warn "They did not match."; continue; }
        [ "${#password}" -ge 12 ] || { warn "At least 12 characters, please."; continue; }
        break
    done

    # Piped rather than passed as an argument: an argument is visible in `ps`
    # and lands in root's shell history.
    printf '%s' "$password" | console user:create --name="$name" --email="$email" --roles=staff.admin --stdin-password
}

cmd_reset_password() {
    local email="${1:-}"
    [ -n "$email" ] || die "Which account? Usage: $0 reset-password EMAIL"

    step "Reset the password for $email"
    console user:password --email="$email"
}

cmd_invite_link() {
    [ -n "${1:-}" ] || die "Which account? Usage: $0 invite-link EMAIL"
    console user:invite --email="$1"
}

cmd_unlock() {
    local email="${1:-}"
    if [ -n "$email" ]; then console unlock --email="$email"; else console unlock; fi
}

cmd_activate()   { [ -n "${1:-}" ] || die "Which account? Usage: $0 activate EMAIL";   console user:activate --email="$1"; }
cmd_deactivate() { [ -n "${1:-}" ] || die "Which account? Usage: $0 deactivate EMAIL"; console user:deactivate --email="$1"; }

cmd_set_roles() {
    [ -n "${2:-}" ] || die "Usage: $0 set-roles EMAIL ROLES   (comma separated; see '$0 roles')"
    console user:roles --email="$1" --roles="$2"
}

# ---------------------------------------------------------------------------
# Application
# ---------------------------------------------------------------------------
cmd_settings() { console settings; }

cmd_set_setting() {
    [ -n "${2:-}" ] || die "Usage: $0 set-setting KEY VALUE"
    console setting:set --key="$1" --value="$2"
}

cmd_config() {
    [ -n "${1:-}" ] || die "Usage: $0 config KEY [VALUE]"

    if [ -z "${2:-}" ]; then
        say "$1=$(env_get "$1")"
        return 0
    fi

    require_root config
    env_set "$1" "$2"
    warn "Restart the web server for it to take effect:  $0 restart"
}

cmd_migrate() {
    step "Migrations"
    assert_web_can_read
    ( cd "$APP_DIR" && run_as_web "$PHP_BIN" bin/migrate.php "$@" )
}

#
# Re-apply the database grant.
#
# A migration that stops with "command denied" is almost always an install whose
# database user was created by hand with SELECT/INSERT/UPDATE/DELETE and nothing
# else — the schema changes then fail on CREATE or ALTER. Re-granting is quicker
# than working out which statement it tripped on.
#
cmd_db_grant() {
    require_root db-grant
    [ -n "$DB_CLIENT" ] || die "No mariadb/mysql client is installed."

    local db user
    db="$(env_get DB_DATABASE)"
    user="$(env_get DB_USERNAME)"

    step "Re-granting rights on '$db' to '$user'@'localhost'"
    say "  This needs the MariaDB root account."

    "$DB_CLIENT" -u root <<SQL || die "Could not connect to MariaDB as root, or the grant failed."
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES ON \`${db}\`.* TO '${user}'@'localhost';
FLUSH PRIVILEGES;
SQL

    ok "Grant re-applied — try the migrations again:  $0 migrate"
}

#
# Empty the database and put the schema back, ready for a first administrator.
#
# The most destructive thing this script can do: every client, part, order,
# delivery note, invoice and user account goes, and none of it comes back
# without a backup. So it asks twice, and the second answer is a word that
# cannot be typed by accident or produced by leaning on the return key.
#
# It deliberately ignores --yes. That flag exists so the backup can run from
# cron without a prompt; a command that destroys the business's records is not
# something anybody should be able to enable by adding a flag to a line in a
# script. For the same reason it refuses to run unless a person is at the
# terminal — a reset that can be triggered by a stray pipe is a reset waiting
# to happen.
#
# Uploaded files are left alone. The database holds their paths and nothing
# else, so after this they are simply unreferenced; deleting somebody's drawings
# is a separate decision from clearing the records, and this command was asked
# to clear the records.
#
cmd_reset_database() {
    require_root reset-database
    [ -n "$DB_CLIENT" ] || die "No mariadb/mysql client is installed, so the database cannot be cleared."
    [ -t 0 ] || die "reset-database has to be answered at a terminal. It will not run from a script or a pipe."

    local db; db="$(env_get DB_DATABASE)"
    [ -n "$db" ] || die "No DB_DATABASE in $ENV_FILE."

    local cnf; cnf="$(db_client_cnf)"

    local tables
    tables="$("$DB_CLIENT" --defaults-extra-file="$cnf" "$db" -N -B -e \
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';" 2>/dev/null)" \
        || die "Could not connect to '$db'. Check the credentials in $ENV_FILE."

    step "Reset the database '$db'"

    if [ "${tables:-0}" -eq 0 ]; then
        say "  It has no tables — there is nothing to clear."
        say ""
        say "  To build the schema:      $0 migrate"
        say "  Then the first account:   $0 create-admin"
        return 0
    fi

    # What is actually about to go. A confirmation prompt that does not say what
    # is at stake is a prompt people learn to click through.
    say ""
    printf '  %sThis destroys everything in the database.%s\n' "$C_BOLD" "$C_RESET"
    say ""
    say "  $tables tables will be dropped and rebuilt empty. On record right now:"

    local counts
    counts="$("$DB_CLIENT" --defaults-extra-file="$cnf" "$db" -N -B -e "
        SELECT CONCAT('    ', LPAD(c, 6, ' '), '  ', label) FROM (
            SELECT 1 AS o, COUNT(*) AS c, 'user accounts'   AS label FROM users
            UNION ALL SELECT 2, COUNT(*), 'clients'         FROM clients
            UNION ALL SELECT 3, COUNT(*), 'parts'           FROM parts
            UNION ALL SELECT 4, COUNT(*), 'orders'          FROM orders
            UNION ALL SELECT 5, COUNT(*), 'delivery notes'  FROM delivery_notes
            UNION ALL SELECT 6, COUNT(*), 'invoices'        FROM invoices
        ) t ORDER BY o;" 2>/dev/null)" || counts=""

    if [ -n "$counts" ]; then
        say ""
        printf '%s\n' "$counts"
    fi

    say ""
    say "  Uploaded drawings, purchase orders and generated PDFs stay on disk in"
    say "  $APP_DIR/storage/uploads — nothing in the database will point at them."
    say ""
    printf '  %sTake a backup first if there is any doubt:%s  %s backup\n' \
        "$C_YELLOW" "$C_RESET" "$0"
    say ""

    # Two answers, on purpose. The first is the ordinary question; the second is
    # a word nobody types by accident. --yes does not satisfy either.
    local answer
    read -r -p "  Clear the database and start again? [y/N]: " answer || answer=""
    case "${answer,,}" in
        y|yes) ;;
        *)
            say ""
            ok "Nothing was changed."
            return 0
            ;;
    esac

    # Exact and upper-case. `read` drops whitespace either side, so a pasted
    # "RESET " still counts — somebody who pasted the word meant it — but
    # "reset", "yes" and an empty line do not, which is the point: the second
    # answer has to be typed on purpose.
    local phrase
    read -r -p "  Type RESET to confirm: " phrase || phrase=""
    if [ "$phrase" != "RESET" ]; then
        say ""
        ok "Nothing was changed."
        return 0
    fi

    step "Clearing '$db'"

    # One DROP per table rather than a single comma-separated statement:
    # GROUP_CONCAT truncates at 1024 bytes by default, and this schema is already
    # close enough that adding a table or two would have silently left some
    # behind — a half-dropped database that reported success.
    {
        printf 'SET FOREIGN_KEY_CHECKS = 0;\n'
        "$DB_CLIENT" --defaults-extra-file="$cnf" "$db" -N -B -e \
            "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;')
               FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';"
        printf 'SET FOREIGN_KEY_CHECKS = 1;\n'
    } | "$DB_CLIENT" --defaults-extra-file="$cnf" "$db" || die "Dropping the tables failed."

    local left
    left="$("$DB_CLIENT" --defaults-extra-file="$cnf" "$db" -N -B -e \
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';")"
    [ "${left:-1}" -eq 0 ] || die "$left table(s) are still there. The database has not been cleared cleanly."

    ok "Dropped $tables table(s)"

    cmd_migrate

    step "Ready"
    say ""
    say "  '$db' is empty and the schema is current. There are no accounts, so the"
    say "  next step is the one that creates the first administrator:"
    say ""
    say "    $0 create-admin"
    say ""
}

#
# Empty the uploads directory.
#
# The other half of reset-database. That one clears the records and leaves the
# files; this clears the files and leaves the records. Running one without the
# other is a legitimate thing to want — a database reset before handing the
# machine to the same client keeps their drawings — but running neither and
# expecting a fresh start is not, so each says what the other leaves behind.
#
# Nothing here is recoverable. Drawings, purchase orders, part media and every
# generated delivery note and route card are files the database only ever held
# the paths of; a dump does not contain them.
#
# The directory is emptied rather than removed and rebuilt: it keeps whatever
# ownership, mode and SELinux label it already had, which on a working install
# is exactly what is wanted. No subdirectories are recreated either. The
# application makes them on demand — Upload, Image and PdfService all mkdir
# recursively — and a hard-coded list here would go stale the way install.sh's
# already has, where part-photos and order-photos survive a schema change that
# retired both.
#
cmd_reset_uploads() {
    require_root reset-uploads
    [ -t 0 ] || die "reset-uploads has to be answered at a terminal. It will not run from a script or a pipe."

    local uploads="$APP_DIR/storage/uploads"

    # This function deletes a directory tree, so the path is checked rather than
    # trusted: absolute, present, and exactly where it should be under the
    # installation. An empty or surprising value stops here, not at rm.
    [ -n "$APP_DIR" ]            || die "APP_DIR is not set."
    [ -d "$uploads" ]            || die "$uploads does not exist. Nothing to clear."
    case "$uploads" in
        /*/storage/uploads) ;;
        *) die "Refusing to clear '$uploads' — that is not an uploads directory." ;;
    esac

    local files size
    files="$(find "$uploads" -type f 2>/dev/null | wc -l | tr -d ' ')"
    size="$(du -sh "$uploads" 2>/dev/null | cut -f1)"

    step "Clear the uploads directory"

    if [ "${files:-0}" -eq 0 ]; then
        say "  $uploads holds no files — there is nothing to clear."
        return 0
    fi

    say ""
    printf '  %sThis deletes every uploaded and generated file.%s\n' "$C_BOLD" "$C_RESET"
    say ""
    say "  $uploads"
    say "  $files file(s), ${size:-unknown}:"
    say ""

    # Per top-level directory, so the count is recognisable as the things they
    # are rather than one number.
    local dir name count
    for dir in "$uploads"/*/; do
        [ -d "$dir" ] || continue
        name="$(basename "$dir")"
        count="$(find "$dir" -type f 2>/dev/null | wc -l | tr -d ' ')"
        printf '    %6s  %s\n' "$count" "$name"
    done

    say ""
    say "  None of it is in a database dump — the database only holds the paths."
    say "  Afterwards those paths will point at files that are not there, so a"
    say "  drawing or a delivery note opened from the tracker will 404 until the"
    say "  records go too:  $0 reset-database"
    say ""
    printf '  %sTake a backup first if there is any doubt:%s  %s backup\n' \
        "$C_YELLOW" "$C_RESET" "$0"
    say ""

    # The same two answers as reset-database, and for the same reason: --yes
    # must not be able to arm this, and the second one has to be typed on
    # purpose. `read` drops whitespace either side, so a pasted "RESET " counts;
    # "reset" and an empty line do not.
    local answer
    read -r -p "  Delete every uploaded file? [y/N]: " answer || answer=""
    case "${answer,,}" in
        y|yes) ;;
        *)
            say ""
            ok "Nothing was deleted."
            return 0
            ;;
    esac

    local phrase
    read -r -p "  Type RESET to confirm: " phrase || phrase=""
    if [ "$phrase" != "RESET" ]; then
        say ""
        ok "Nothing was deleted."
        return 0
    fi

    step "Clearing $uploads"

    # -mindepth 1 leaves the directory itself alone. Safer than removing and
    # recreating it, and it cannot walk upwards even if the path were wrong.
    find "$uploads" -mindepth 1 -delete || die "Could not empty $uploads."

    local left
    left="$(find "$uploads" -mindepth 1 2>/dev/null | wc -l | tr -d ' ')"
    [ "${left:-1}" -eq 0 ] || die "$left item(s) are still there. The directory has not been cleared cleanly."

    ok "Deleted $files file(s)"

    # It has to stay writable by the web user or the next upload fails with a
    # permission error nobody will connect to this command.
    chown "$WEB_USER":"$WEB_GROUP" "$uploads"
    chmod 2775 "$uploads"
    if have restorecon && have getenforce && [ "$(getenforce)" = "Enforcing" ]; then
        restorecon -R "$uploads" >/dev/null 2>&1 || true
    fi
    ok "Ownership and mode re-applied ($WEB_USER:$WEB_GROUP, 2775)"

    step "Ready"
    say ""
    say "  The directory is empty. The application creates what it needs as files"
    say "  are uploaded, so there is nothing to put back by hand."
    say ""
}

# ---------------------------------------------------------------------------
# Email and integrations
# ---------------------------------------------------------------------------
cmd_mail_status() { console mail:status; }

cmd_mail_test() {
    [ -n "${1:-}" ] || die "Where to? Usage: $0 mail-test you@example.com"
    console mail:test --to="$1"
}

cmd_send_reminders() { console reminders:run "$@"; }

cmd_clearbooks_status() { console clearbooks:status; }

#
# Put `composer` on the PATH.
#
# The same two routes install.sh uses, for the same reasons: the distribution's
# own package first (no new trust — signed by the same repository as everything
# else on the machine), then the official installer from getcomposer.org
# verified against the SHA-384 Composer publishes.
#
# This exists as its own command because an install that predates it has no
# Composer and therefore no dompdf — and "go and work out how to install
# Composer" is not an answer to give somebody whose delivery notes will not
# print.
#
cmd_install_composer() {
    require_root install-composer

    if have composer; then
        ok "Composer is already installed: $(command -v composer)"
        return 0
    fi

    step "Installing Composer"

    local pkg=""
    if   have apt-get; then pkg=apt
    elif have dnf;     then pkg=dnf
    elif have yum;     then pkg=yum
    elif have zypper;  then pkg=zypper
    elif have pacman;  then pkg=pacman
    fi

    case "$pkg" in
        apt)    DEBIAN_FRONTEND=noninteractive apt-get install -y composer >/dev/null 2>&1 || true ;;
        dnf)    dnf install -y composer >/dev/null 2>&1 || true ;;
        yum)    yum install -y composer >/dev/null 2>&1 || true ;;
        zypper) zypper --non-interactive install composer >/dev/null 2>&1 || true ;;
        pacman) pacman -S --needed --noconfirm composer >/dev/null 2>&1 || true ;;
    esac

    hash -r 2>/dev/null || true

    if have composer; then
        ok "Composer installed from the distribution's packages"
        return 0
    fi

    info "Not in this distribution's packages — using the official installer"

    local tmp setup expected actual
    tmp="$(mktemp -d)"
    setup="$tmp/composer-setup.php"

    if have curl; then
        curl -fsSL --max-time 120 https://getcomposer.org/installer -o "$setup" || true
    elif have wget; then
        wget -qO "$setup" --timeout=120 https://getcomposer.org/installer || true
    fi

    [ -s "$setup" ] || { rm -rf "$tmp"; die "Could not download the Composer installer."; }

    # `|| true` on both: these are assignments from a pipeline under `set -e`,
    # so without it a network failure aborts the script silently instead of
    # reaching the signature check below, which reports it properly.
    if have curl; then
        expected="$(curl -fsSL --max-time 60 https://composer.github.io/installer.sig | tr -d '[:space:]' || true)"
    else
        expected="$(wget -qO- --timeout=60 https://composer.github.io/installer.sig | tr -d '[:space:]' || true)"
    fi

    actual="$("$PHP_BIN" -r 'echo hash_file("sha384", $argv[1]);' "$setup")"

    if [ -z "$expected" ] || [ "$expected" != "$actual" ]; then
        rm -rf "$tmp"
        die "The Composer installer failed its signature check, so it was NOT run."
    fi

    COMPOSER_ALLOW_SUPERUSER=1 "$PHP_BIN" "$setup" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -rf "$tmp"
    hash -r 2>/dev/null || true

    have composer || die "The Composer installer ran but left no usable binary."
    ok "Composer installed to /usr/local/bin/composer (signature verified)"
}

cmd_composer_install() {
    require_root composer-install

    have composer || cmd_install_composer

    step "Installing the PHP packages"
    ( cd "$APP_DIR" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction ) \
        || { warn "composer install failed. The output above says why."; return 1; }

    [ -f "$APP_DIR/vendor/autoload.php" ] || { warn "Composer finished but vendor/autoload.php is missing."; return 1; }

    chown -R root:"$WEB_GROUP" "$APP_DIR/vendor"
    find "$APP_DIR/vendor" -type d -exec chmod 750 {} +
    find "$APP_DIR/vendor" -type f -exec chmod 640 {} +

    ok "PHPMailer, dompdf and the QR code library are installed"
}

# ---------------------------------------------------------------------------
# Server
# ---------------------------------------------------------------------------
cmd_backup() {
    require_root backup
    [ -n "$DUMP_BIN" ] || die "Neither mariadb-dump nor mysqldump is installed."

    local dir="${1:-$BACKUP_DIR}"
    local stamp; stamp="$(date +%Y%m%d-%H%M%S)"
    local db;    db="$(env_get DB_DATABASE)"

    mkdir -p "$dir"
    chmod 700 "$dir"

    step "Backing up to $dir"

    local dump="$dir/${db}-${stamp}.sql.gz"
    local cnf; cnf="$(db_client_cnf)"

    # No --events and no --routines.
    #
    # The application user is granted SELECT, INSERT, UPDATE, DELETE, CREATE,
    # DROP, ALTER, INDEX and REFERENCES on its own schema and nothing else --
    # see install.sh, where that list is deliberate. Dumping events needs the
    # EVENT privilege it does not have, so asking for them failed the whole
    # backup with "Couldn't execute 'show events': Access denied".
    #
    # Nothing is lost by not asking. This schema has no events, no stored
    # routines, no triggers and no views: it is plain tables written by
    # numbered migrations, and the same grant that refuses to dump a stored
    # program would refuse to create one. A dump taken without these flags is
    # byte-identical to one taken as root, bar the timestamp comment.
    #
    # --single-transaction stays, and matters for the same reason: it takes a
    # consistent snapshot without LOCK TABLES, which is another privilege the
    # application user does not hold.
    "$DUMP_BIN" --defaults-extra-file="$cnf" --single-transaction --skip-events --skip-routines "$db" \
        | gzip -9 > "$dump"

    chmod 600 "$dump"
    ok "Database  $(basename "$dump")  ($(du -h "$dump" | cut -f1))"

    # The uploads cannot be regenerated from the database — it only holds paths.
    # Drawings, purchase orders and the generated delivery notes all live here.
    #
    # The font cache is the one thing under uploads that can be regenerated, by
    # "tracker pdf-warm" in a second or so, so it is left out: keeping it would
    # put half a megabyte of derived files into every backup and restore them
    # over the top of a perfectly good cache.
    local uploads="$dir/uploads-${stamp}.tar.gz"
    tar -czf "$uploads" --exclude=uploads/cache -C "$APP_DIR/storage" uploads
    chmod 600 "$uploads"
    ok "Uploads   $(basename "$uploads")  ($(du -h "$uploads" | cut -f1))"

    # .env carries APP_KEY, and without it the stored SMTP password is
    # unreadable — a restored database alone would not be a working site.
    cp -p "$ENV_FILE" "$dir/env-${stamp}.bak"
    chmod 600 "$dir/env-${stamp}.bak"
    ok "Config    env-${stamp}.bak"

    if [ "$BACKUP_KEEP" -gt 0 ]; then
        local removed=0 old
        while IFS= read -r old; do
            rm -f "$old"; removed=$((removed + 1))
        done < <(ls -1t "$dir"/*.sql.gz 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)))
        while IFS= read -r old; do rm -f "$old"; done < <(ls -1t "$dir"/uploads-*.tar.gz 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)))
        while IFS= read -r old; do rm -f "$old"; done < <(ls -1t "$dir"/env-*.bak 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)))
        [ "$removed" -gt 0 ] && info "Removed $removed backup set(s) older than the last $BACKUP_KEEP"
    fi

    say ""
    say "  All three files are needed for a working restore. Copy them off this machine."
}

cmd_restore() {
    require_root restore
    local dump="${1:-}" uploads="${2:-}"

    [ -n "$dump" ] || die "Usage: $0 restore DUMP.sql.gz [UPLOADS.tar.gz]"
    [ -r "$dump" ] || die "Cannot read $dump."
    [ -n "$DB_CLIENT" ] || die "No mariadb/mysql client is installed, so the dump cannot be loaded."

    local db; db="$(env_get DB_DATABASE)"

    warn "This replaces everything in the '$db' database."
    [ -n "$uploads" ] && warn "It also replaces $APP_DIR/storage/uploads."
    confirm "Restore over the live data?" || die "Nothing was changed."

    step "Restoring the database"

    local cnf; cnf="$(db_client_cnf)"

    if [ "${dump##*.}" = "gz" ]; then
        gzip -dc "$dump" | "$DB_CLIENT" --defaults-extra-file="$cnf" "$db"
    else
        "$DB_CLIENT" --defaults-extra-file="$cnf" "$db" < "$dump"
    fi
    ok "Database restored"

    if [ -n "$uploads" ]; then
        [ -r "$uploads" ] || die "Cannot read $uploads."
        step "Restoring the uploads"
        rm -rf "$APP_DIR/storage/uploads"
        tar -xzf "$uploads" -C "$APP_DIR/storage"
        ok "Uploads restored"
    fi

    cmd_permissions
    # A restore replaces storage/uploads wholesale, so the font cache goes with
    # it. Rebuilt here for the same reason it is rebuilt after an update.
    console pdf-warm || warn "The PDF font cache could not be built; PDFs will be slower until it can be."
    console doctor || true

    say ""
    warn "If the APP_KEY in .env is not the one this database was written with,"
    warn "the stored SMTP password will not decrypt. Re-enter it in Settings → Email."
}

#
# Apply a new version.
#
# With a directory, copies from there. With nothing, clones the project
# repository into a temp directory first — which is the normal case, and means
# an update is one command on a server with no checkout of its own.
#
cmd_update() {
    require_root update
    local source="${1:-}" tmp=""

    if [ -z "$source" ]; then
        have git || die "git is not installed, so there is nothing to pull with. Pass a directory instead: $0 update /path/to/source"

        tmp="$(mktemp -d)"
        step "Fetching the latest version from $REPO_URL"
        git clone --depth 1 "$REPO_URL" "$tmp/src" >/dev/null 2>&1 \
            || { rm -rf "$tmp"; die "Could not clone $REPO_URL."; }
        source="$tmp/src"
        ok "Cloned $(cd "$source" && git rev-parse --short HEAD)"
    fi

    [ -f "$source/public/index.php" ] || { [ -n "$tmp" ] && rm -rf "$tmp"; die "$source does not look like the Production Tracker source tree."; }

    warn "Back up first if you have not already:  $0 backup"
    if ! confirm "Copy $source over $APP_DIR and run the migrations?"; then
        [ -n "$tmp" ] && rm -rf "$tmp"
        die "Nothing was changed."
    fi

    step "Copying the new version"
    tar -cf - -C "$source" \
        --exclude=./.git --exclude=./.github --exclude=./.claude --exclude=./.env \
        --exclude=./vendor --exclude=./node_modules \
        --exclude=./storage/uploads --exclude=./storage/logs \
        --exclude='./*.zip' --exclude='./*.tar.gz' \
        . | tar -xf - -C "$APP_DIR"
    ok "Files updated — .env, storage/ and the database were left alone"

    [ -n "$tmp" ] && rm -rf "$tmp"

    # An update can introduce a new dependency, so this is part of applying the
    # version rather than optional housekeeping.
    cmd_composer_install || true

    cmd_permissions
    # A new version can raise what the application accepts — the tooling files
    # went to 50 MB in one — and PHP's own limits do not follow on their own.
    cmd_php_limits
    cmd_migrate
    # So the first PDF after an update is not the one that pays to build the
    # font cache -- see AppServicesPdfService. Not fatal: an update that has
    # otherwise worked should not stop here. But it is not silent either, since
    # a cache that cannot be written makes every PDF about a second slower for
    # good, and the message says which directory and who tried.
    console pdf-warm || warn "The PDF font cache could not be built; PDFs will be slower until it can be."
    console doctor || true

    local svc; svc="$(web_service)"
    [ -n "$svc" ] && have systemctl && systemctl reload "$svc" >/dev/null 2>&1 || true
    ok "Done"
}

#
# Set PHP's upload limits to what the application actually offers.
#
# These are two numbers in two places that have to agree, and nothing kept them
# in step: install.sh wrote them once, and the application's own limits then
# moved — tooling files went to 50 MB while PHP was still refusing anything over
# 25. PHP throws the request body away when that happens, so the CSRF token goes
# with it and the user is told their session expired, which is nothing like the
# truth.
#
# The numbers come from the application rather than from a copy kept here, so
# raising a limit in config/config.php and running an update is enough.
#
cmd_php_limits() {
    require_root php-limits

    step "Setting PHP's upload limits to match the application"

    local limits per post
    limits="$(console upload-limits 2>/dev/null | tail -1 || true)"
    per="$(printf '%s' "$limits" | awk '{print $1}')"
    post="$(printf '%s' "$limits" | awk '{print $2}')"

    # A config that cannot be read must not quietly install a zero, which would
    # refuse every upload rather than merely the large ones.
    if ! is_number "${per:-}" || ! is_number "${post:-}"; then
        warn "Could not read the application's own limits; using 50M/82M."
        per=50
        post=82
    fi

    local timezone; timezone="$(env_get APP_TIMEZONE)"
    [ -n "$timezone" ] || timezone="Europe/London"

    php_ini_body() {
        cat <<INI
; Written by Production Tracker. Delete this file to revert.
; These must be at least as large as the limits the application enforces, or
; PHP rejects the upload before the application ever sees it.
upload_max_filesize = ${per}M
post_max_size = ${post}M
max_file_uploads = 20
; dompdf holds the whole document in memory while it renders.
memory_limit = 256M
date.timezone = ${timezone}
INI
    }

    # Every SAPI, because they each have their own copy and the one that matters
    # is whichever is serving: apache2 or fpm for the site, cli for this script's
    # own checks.
    local dir written=0
    for dir in /etc/php/*/apache2/conf.d /etc/php/*/fpm/conf.d /etc/php/*/cli/conf.d \
               /etc/php.d /etc/php8/conf.d /etc/php/conf.d; do
        [ -d "$dir" ] || continue
        php_ini_body > "$dir/99-production-tracker.ini"
        chmod 644 "$dir/99-production-tracker.ini"
        say "  $dir/99-production-tracker.ini"
        written=$((written + 1))
    done

    if [ "$written" -eq 0 ]; then
        local scan_dir
        scan_dir="$("$PHP_BIN" -i 2>/dev/null | awk -F'=> ' '/Scan this dir/ {print $2}' | tr -d ' ' || true)"
        if [ -n "$scan_dir" ] && [ -d "$scan_dir" ]; then
            php_ini_body > "$scan_dir/99-production-tracker.ini"
            written=1
            say "  $scan_dir/99-production-tracker.ini"
        else
            warn "No PHP conf.d directory was found. Set upload_max_filesize and post_max_size by hand."
            return 0
        fi
    fi

    ok "upload_max_filesize ${per}M, post_max_size ${post}M — $written file(s)"

    # A conf.d file is read when the process starts, so fpm keeps the old
    # numbers until it is restarted. Apache's mod_php picks them up on a reload.
    local fpm; fpm="$(fpm_service)"
    if [ -n "$fpm" ] && have systemctl; then
        systemctl restart "$fpm" >/dev/null 2>&1 \
            && ok "$fpm restarted to pick them up" \
            || warn "Could not restart $fpm — do it by hand or the new limits will not apply."
    fi

    # What PHP reports now, rather than what was just written: a value that will
    # not move usually means a second file setting it again further down.
    local effective
    effective="$("$PHP_BIN" -r 'echo ini_get("upload_max_filesize"), "/", ini_get("post_max_size");' 2>/dev/null || true)"
    [ -n "$effective" ] && say "  PHP now reports $effective on the command line"

    return 0
}

cmd_permissions() {
    require_root permissions

    step "Re-applying ownership and modes"

    chown -R root:"$WEB_GROUP" "$APP_DIR"
    find "$APP_DIR" -type d -exec chmod 750 {} +
    find "$APP_DIR" -type f -exec chmod 640 {} +

    # dompdf writes its parsed font metrics here, and it is the one directory
    # the application needs that nothing creates on the way in: it appears the
    # first time a PDF is rendered, as whoever rendered it. Made here so it is
    # always web-owned, and so that running this command fixes a cache left
    # behind by a console command someone ran as root.
    mkdir -p "$APP_DIR/storage/uploads/cache/dompdf-fonts"

    chown -R "$WEB_USER":"$WEB_GROUP" "$APP_DIR/storage"
    find "$APP_DIR/storage" -type d -exec chmod 2775 {} +
    find "$APP_DIR/storage" -type f -exec chmod 664 {} +

    local script
    for script in install.sh manage.sh; do
        [ -f "$APP_DIR/$script" ] && chmod 750 "$APP_DIR/$script"
    done

    [ -f "$ENV_FILE" ] && { chown root:"$WEB_GROUP" "$ENV_FILE"; chmod 640 "$ENV_FILE"; }

    if have restorecon && have getenforce && [ "$(getenforce)" = "Enforcing" ]; then
        restorecon -R "$APP_DIR" >/dev/null 2>&1 || true
    fi

    ok "Application root:$WEB_GROUP (750/640), storage $WEB_USER:$WEB_GROUP (2775/664), .env 640"
}

cmd_package() {
    local out="${1:-$PWD/production-tracker-$(date +%Y%m%d).tar.gz}"

    step "Building $out"
    tar -czf "$out" -C "$APP_DIR" \
        --exclude=./.git --exclude=./.github --exclude=./.claude --exclude=./.env \
        --exclude=./vendor --exclude=./node_modules \
        --exclude=./storage/uploads --exclude=./storage/logs \
        --exclude='./*.tar.gz' --exclude='./*.zip' \
        .

    ok "$out  ($(du -h "$out" | cut -f1))"
    say ""
    say "  Copy it to the new server and:"
    say "    mkdir -p production-tracker && tar -xzf $(basename "$out") -C production-tracker"
    say "    cd production-tracker && sudo ./install.sh"
    say ""
    say "  It contains no .env, no uploads and no database — nothing secret."
}

cmd_cron_install() {
    require_root cron-install

    local file=/etc/cron.d/production-tracker
    cat > "$file" <<CRON
# Production Tracker — installed by manage.sh.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

# Nightly backup of the database, the uploads and .env.
15 2 * * * root ${APP_DIR}/manage.sh backup --quiet

# The outstanding-parts digest. Safe to call daily whatever interval is set in
# Settings → Reminders: the interval is enforced inside the script, and nothing
# is sent while the digest is switched off, nobody has opted in, or there is
# nothing outstanding.
0 7 * * * ${WEB_USER} ${PHP_BIN} ${APP_DIR}/bin/reminders.php
CRON

    chmod 644 "$file"
    ok "Wrote $file"
    say "  Backups go to $BACKUP_DIR, keeping the last $BACKUP_KEEP sets."
    say "  The digest stays off until it is switched on in Settings → Reminders."
}

cmd_cron_remove() {
    require_root cron-remove
    rm -f /etc/cron.d/production-tracker
    ok "Removed /etc/cron.d/production-tracker"
}

cmd_restart() {
    require_root restart

    local svc; svc="$(web_service)"
    if [ -n "$svc" ] && have systemctl; then
        systemctl restart "$svc" && ok "$svc restarted"
    fi

    local fpm; fpm="$(fpm_service)"
    if [ -n "$fpm" ]; then
        systemctl restart "$fpm" && ok "$fpm restarted" || warn "Could not restart $fpm."
    fi

    return 0
}

# ---------------------------------------------------------------------------
# Dispatch
# ---------------------------------------------------------------------------
ARGS=()
for arg in "$@"; do
    case "$arg" in
        --quiet)  QUIET=yes ;;
        --yes|-y) ASSUME_YES=yes ;;
        *)        ARGS+=("$arg") ;;
    esac
done

set -- "${ARGS[@]:-}"
COMMAND="${1:-help}"
shift || true

# A copy of this script travels with the source, so it is easy to run it from a
# checkout rather than from the installation it manages. A checkout has no .env,
# and the web user usually cannot read one under a home directory either. Stop
# here and point at the real install rather than failing further in with a
# confusing error.
case "$COMMAND" in
    # The only commands meaningful from a source tree.
    help|--help|-h|""|package) : ;;
    *)
        if [ ! -f "$ENV_FILE" ]; then
            say ""
            printf '%sThis is not an installation.%s\n' "$C_BOLD" "$C_RESET" >&2
            say ""
            say "  $APP_DIR has no .env, so it is a copy of the source rather than" >&2
            say "  a site this script can manage." >&2
            say "" >&2
            say "  To install from here:    sudo $APP_DIR/install.sh" >&2
            say "  To manage an install:    sudo /var/www/production-tracker/manage.sh $COMMAND" >&2
            say "  Or, if it is on PATH:    sudo tracker $COMMAND" >&2
            say "" >&2
            exit 1
        fi
        ;;
esac

case "$COMMAND" in
    status)             cmd_status ;;
    doctor)             cmd_doctor ;;
    health)             cmd_health ;;
    stats)              cmd_stats ;;
    logs)               cmd_logs "$@" ;;

    users)              cmd_users ;;
    create-admin)       cmd_create_admin ;;
    reset-password)     cmd_reset_password "${1:-}" ;;
    invite-link)        cmd_invite_link "${1:-}" ;;
    unlock)             cmd_unlock "${1:-}" ;;
    activate)           cmd_activate "${1:-}" ;;
    deactivate)         cmd_deactivate "${1:-}" ;;
    set-roles)          cmd_set_roles "${1:-}" "${2:-}" ;;
    roles)              cmd_roles ;;

    settings)           cmd_settings ;;
    set-setting)        cmd_set_setting "${1:-}" "${2:-}" ;;
    config)             cmd_config "${1:-}" "${2:-}" ;;
    migrate)            cmd_migrate "$@" ;;
    db-grant)           cmd_db_grant ;;
    reset-database)     cmd_reset_database ;;
    reset-uploads)      cmd_reset_uploads ;;

    install-composer)   cmd_install_composer ;;
    composer-install)   cmd_composer_install ;;
    mail-status)        cmd_mail_status ;;
    mail-test)          cmd_mail_test "${1:-}" ;;
    send-reminders)     cmd_send_reminders "$@" ;;
    clearbooks-status)  cmd_clearbooks_status ;;

    backup)             cmd_backup "${1:-}" ;;
    restore)            cmd_restore "${1:-}" "${2:-}" ;;
    update)             cmd_update "${1:-}" ;;
    permissions)        cmd_permissions ;;
    php-limits)         cmd_php_limits ;;
    pdf-warm)           console pdf-warm ;;
    package)            cmd_package "${1:-}" ;;
    cron-install)       cmd_cron_install ;;
    cron-remove)        cmd_cron_remove ;;
    restart)            cmd_restart ;;

    help|--help|-h|"")  usage ;;
    *)                  die "Unknown command '$COMMAND'. Try: $0 help" ;;
esac
