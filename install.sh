#!/usr/bin/env bash
#
# Production Tracker — turn-key installer for a Linux server.
#
#   sudo ./install.sh
#
# Checks for PHP, Apache (or nginx) and MariaDB, installs whatever is missing,
# copies the application into place, sets ownership and modes, creates the
# database and its user, writes .env, configures the web server, runs the
# migrations and creates the first administrator.
#
# Everything it asks for can also be supplied in an answers file so the whole
# thing can run unattended:
#
#   sudo ./install.sh --answers=/root/tracker.answers --non-interactive
#
# Run it with --dry-run first if you want to see the plan without touching
# anything. Day-to-day administration afterwards lives in ./manage.sh.
#
set -euo pipefail

# Deliberately namespaced and not readonly. A bare `readonly VERSION` collides
# with the VERSION= line in /etc/os-release, which kills the script on startup.
INSTALLER_VERSION="1.0"
readonly SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_URL="https://github.com/maeterlinckle/production-tracker.git"

# --- Answers ----------------------------------------------------------------
# Anything already in the environment, or set by an answers file or a flag, is
# treated as answered. The prompts below carry the defaults, so these stay
# empty on purpose — otherwise the question would never be asked.
INSTALL_DIR="${INSTALL_DIR:-}"
APP_NAME="${APP_NAME:-}"
APP_URL="${APP_URL:-}"
APP_TIMEZONE="${APP_TIMEZONE:-}"
APP_KEY="${APP_KEY:-}"
APP_CURRENCY="${APP_CURRENCY:-GBP}"
APP_CURRENCY_SYMBOL="${APP_CURRENCY_SYMBOL:-£}"

DB_NAME="${DB_NAME:-}"
DB_USER="${DB_USER:-}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-}"

WEB_SERVER="${WEB_SERVER:-}"          # apache | nginx | none
SERVER_NAME="${SERVER_NAME:-}"        # the hostname the site answers on
HTTP_PORT="${HTTP_PORT:-80}"
TLS_MODE="${TLS_MODE:-}"              # proxy | direct-https | plain-http
TLS_CERT="${TLS_CERT:-}"
TLS_KEY="${TLS_KEY:-}"
MAKE_DEFAULT_SITE="${MAKE_DEFAULT_SITE:-}"

ADMIN_NAME="${ADMIN_NAME:-}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"

# Mail and Clear Books are placeholders in .env: both are finished from the
# Settings screens, because both need values an installer cannot know. They are
# accepted here so an answers file can carry a known-good configuration over to
# a rebuilt server.
MAIL_HOST="${MAIL_HOST:-}"
MAIL_PORT="${MAIL_PORT:-587}"
MAIL_ENCRYPTION="${MAIL_ENCRYPTION:-tls}"
MAIL_USERNAME="${MAIL_USERNAME:-}"
MAIL_PASSWORD="${MAIL_PASSWORD:-}"
MAIL_FROM_ADDRESS="${MAIL_FROM_ADDRESS:-}"
MAIL_FROM_NAME="${MAIL_FROM_NAME:-}"

CLEARBOOKS_CLIENT_ID="${CLEARBOOKS_CLIENT_ID:-}"
CLEARBOOKS_CLIENT_SECRET="${CLEARBOOKS_CLIENT_SECRET:-}"

INSTALL_CRON="${INSTALL_CRON:-}"
UPLOAD_MAX_PHOTO_MB="${UPLOAD_MAX_PHOTO_MB:-10}"
UPLOAD_MAX_DRAWING_MB="${UPLOAD_MAX_DRAWING_MB:-25}"

NON_INTERACTIVE=no
DRY_RUN=no
SKIP_PACKAGES=no
ASSUME_YES=no

# --- Discovered at run time -------------------------------------------------
OS_ID=""; OS_LIKE=""; OS_NAME=""; PKG=""
WEB_USER=""; WEB_GROUP=""
APACHE_SVC=""; APACHE_SITES_DIR=""; APACHE_BIN=""
PHP_BIN=""; PHP_VERSION=""; PHP_FPM_SOCKET=""; PHP_FPM_SVC=""; PHP_PKG_PREFIX="php"
DB_CLIENT=""; DB_SVC=""
DB_ROOT_CNF=""
UPGRADE_MODE=no
PLAN=()

# ---------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[36m'
else
    C_RESET=""; C_BOLD=""; C_DIM=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""
fi

say()  { printf '%s\n' "$*"; }
step() { printf '\n%s==>%s %s%s%s\n' "$C_BLUE" "$C_RESET" "$C_BOLD" "$*" "$C_RESET"; }
ok()   { printf '  %s[ ok ]%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
info() { printf '  %s[ .. ]%s %s\n' "$C_DIM" "$C_RESET" "$*"; }
warn() { printf '  %s[warn]%s %s\n' "$C_YELLOW" "$C_RESET" "$*"; }
die()  { printf '\n%sError:%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }

cleanup() {
    [ -n "$DB_ROOT_CNF" ] && [ -f "$DB_ROOT_CNF" ] && rm -f "$DB_ROOT_CNF"
    return 0
}
trap cleanup EXIT

usage() {
    cat <<'USAGE'
Production Tracker installer

  sudo ./install.sh [options]

Options
  --answers=FILE       read the answers from a file (shell KEY=value lines)
  --non-interactive    never prompt; every required answer must already be set
  --dir=PATH           where to install     (default /var/www/production-tracker)
  --domain=NAME        the hostname the site answers on
  --web-server=WHICH   apache | nginx | none       (default apache)
  --tls=MODE           proxy | direct-https | plain-http
  --skip-packages      do not install anything with the package manager
  --cron               install the reminder cron entry without asking
  --dry-run            show the plan and stop without changing anything
  --yes                assume yes for confirmations
  --help               this text

Answers file keys
  INSTALL_DIR APP_NAME APP_URL APP_TIMEZONE APP_CURRENCY APP_CURRENCY_SYMBOL
  DB_NAME DB_USER DB_PASSWORD DB_HOST DB_PORT DB_ROOT_PASSWORD
  WEB_SERVER SERVER_NAME HTTP_PORT TLS_MODE TLS_CERT TLS_KEY MAKE_DEFAULT_SITE
  ADMIN_NAME ADMIN_EMAIL ADMIN_PASSWORD
  MAIL_HOST MAIL_PORT MAIL_ENCRYPTION MAIL_USERNAME MAIL_PASSWORD
  MAIL_FROM_ADDRESS MAIL_FROM_NAME
  CLEARBOOKS_CLIENT_ID CLEARBOOKS_CLIENT_SECRET
  INSTALL_CRON UPLOAD_MAX_PHOTO_MB UPLOAD_MAX_DRAWING_MB

An answers file holds a database password and an administrator password, so
create it with mode 600 and delete it when the install is done.
USAGE
}

# ---------------------------------------------------------------------------
# Prompts
# ---------------------------------------------------------------------------
# Anything already set — by an answers file, a flag or the environment — is
# taken as answered and never asked about again.
ask() { # ask VARNAME "Question" "default"   (an empty answer is allowed)
    local var="$1" question="$2" default="${3:-}" answer

    [ -n "${!var:-}" ] && return 0

    if [ "$NON_INTERACTIVE" = yes ]; then
        printf -v "$var" '%s' "$default"
        return 0
    fi

    if [ -n "$default" ]; then
        read -r -p "  $question [$default]: " answer || true
        answer="${answer:-$default}"
    else
        read -r -p "  $question: " answer || true
    fi

    printf -v "$var" '%s' "$answer"
}

ask_required() { # ask_required VARNAME "Question" ["default"]
    local var="$1" question="$2" default="${3:-}" answer

    [ -n "${!var:-}" ] && return 0

    if [ "$NON_INTERACTIVE" = yes ]; then
        [ -n "$default" ] || die "$var is required but not set, and --non-interactive was given."
        printf -v "$var" '%s' "$default"
        return 0
    fi

    while true; do
        if [ -n "$default" ]; then
            read -r -p "  $question [$default]: " answer || true
            answer="${answer:-$default}"
        else
            read -r -p "  $question: " answer || true
        fi

        [ -n "$answer" ] && break
        warn "This one cannot be left blank."
    done

    printf -v "$var" '%s' "$answer"
}

ask_secret() { # ask_secret VARNAME "Question" min_length
    local var="$1" question="$2" min="${3:-0}" first second

    [ -n "${!var:-}" ] && return 0

    if [ "$NON_INTERACTIVE" = yes ]; then
        die "$var is required but not set, and --non-interactive was given."
    fi

    while true; do
        read -r -s -p "  $question: " first || true; echo
        read -r -s -p "  Confirm: " second || true; echo

        if [ "$first" != "$second" ]; then
            warn "They did not match. Try again."
            continue
        fi
        if [ "${#first}" -lt "$min" ]; then
            warn "At least $min characters, please."
            continue
        fi
        break
    done

    printf -v "$var" '%s' "$first"
}

confirm() { # confirm "Question" [default-yes]
    local question="$1" default="${2:-yes}" answer prompt

    [ "$ASSUME_YES" = yes ] && return 0
    if [ "$NON_INTERACTIVE" = yes ]; then
        [ "$default" = yes ]
        return $?
    fi

    prompt=$([ "$default" = yes ] && echo "[Y/n]" || echo "[y/N]")
    read -r -p "  $question $prompt: " answer || true
    answer="${answer:-$default}"

    case "${answer,,}" in
        y|yes) return 0 ;;
        *)     return 1 ;;
    esac
}

choose() { # choose VARNAME "Question" "opt1:description" "opt2:description" ...
    local var="$1" question="$2"; shift 2
    local options=("$@") i answer valid opt

    if [ -n "${!var:-}" ]; then
        for opt in "${options[@]}"; do
            [ "${opt%%:*}" = "${!var}" ] && return 0
        done
        die "Invalid value '${!var}' for $var."
    fi

    if [ "$NON_INTERACTIVE" = yes ]; then
        printf -v "$var" '%s' "${options[0]%%:*}"
        return 0
    fi

    say ""
    say "  $question"
    for i in "${!options[@]}"; do
        printf '    %d) %-14s %s\n' "$((i + 1))" "${options[$i]%%:*}" "${options[$i]#*:}"
    done

    while true; do
        read -r -p "  Choose 1-${#options[@]} [1]: " answer || true
        answer="${answer:-1}"
        if [[ "$answer" =~ ^[0-9]+$ ]] && [ "$answer" -ge 1 ] && [ "$answer" -le "${#options[@]}" ]; then
            valid="${options[$((answer - 1))]%%:*}"
            printf -v "$var" '%s' "$valid"
            return 0
        fi
        warn "Enter a number between 1 and ${#options[@]}."
    done
}

# ---------------------------------------------------------------------------
# Small utilities
# ---------------------------------------------------------------------------
have() { command -v "$1" >/dev/null 2>&1; }

unit_exists() { # unit_exists NAME — is there a systemd unit by this name?
    have systemctl || return 1

    # Captured, not piped into `grep -q`. See the note on the PHP extension
    # check: with `pipefail`, grep exiting early SIGPIPEs the producer and the
    # pipeline reports failure even though it matched.
    local units
    units="$(systemctl list-unit-files --no-legend "$1.service" 2>/dev/null || true)"

    [ -n "$units" ]
}

version_ge() { # version_ge HAVE WANT
    local lowest
    lowest="$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -1 || true)"

    [ "$lowest" = "$2" ]
}

# A password for the database user.
#
# Alphanumeric on purpose: it travels through .env, a MariaDB GRANT and a shell
# without needing a single escape.
#
# Note what this does *not* do. The obvious spelling is
#
#     tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 28
#
# and it is a trap here. /dev/urandom never ends, so `tr` keeps writing after
# `head` has taken its 28 bytes and closed the pipe; `tr` dies of SIGPIPE, and
# under `set -o pipefail` the whole pipeline reports failure. Assigned with
# `x="$(random_password)"` under `set -e`, that aborts the installer — silently,
# because nothing has printed an error. So the randomness is read in a bounded
# chunk and filtered in the shell, where there is no pipe to break.
random_password() {
    local raw=""

    if have openssl; then
        raw="$(openssl rand -base64 64 2>/dev/null || true)"
    fi

    if [ -z "$raw" ]; then
        # head reads a file here rather than consuming a pipe, so nothing is
        # left writing into a closed one.
        raw="$(head -c 256 /dev/urandom 2>/dev/null | base64 2>/dev/null || true)"
    fi

    [ -n "$raw" ] || die "No source of randomness (no openssl, no readable /dev/urandom) — cannot generate a database password."

    # Pure bash: strip everything that is not alphanumeric, then take 28.
    raw="${raw//[^A-Za-z0-9]/}"

    [ "${#raw}" -ge 28 ] || die "Could not generate a long enough database password."

    printf '%s' "${raw:0:28}"
}

# The APP_KEY that encrypts secrets stored in the database (today, the SMTP
# password). 32 random bytes, base64, in the same "base64:..." form the console
# emits. Falls back to PHP when openssl is missing, because base64 of raw random
# bytes is not something to improvise with shell tools.
random_app_key() {
    if have openssl; then
        printf 'base64:%s' "$(openssl rand -base64 32)"
    elif [ -n "$PHP_BIN" ] && have "$PHP_BIN"; then
        "$PHP_BIN" -r 'echo "base64:" . base64_encode(random_bytes(32));'
    else
        printf ''
    fi
}

# Quote a value for MariaDB's single-quoted string literals.
sql_quote() { printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/\\\\'/g"; }

# Quote a value for .env: quoted unless it is plainly safe unquoted.
env_quote() {
    local value="$1"
    if [[ "$value" =~ ^[A-Za-z0-9_./:@=+-]*$ ]] && [ -n "$value" ]; then
        printf '%s' "$value"
    else
        printf '"%s"' "${value//\"/\\\"}"
    fi
}

run_as_web() { # run a command as the web server user
    if have runuser; then
        runuser -u "$WEB_USER" -- "$@"
    elif have sudo; then
        sudo -u "$WEB_USER" -- "$@"
    else
        local quoted="" arg
        for arg in "$@"; do quoted+=" $(printf '%q' "$arg")"; done
        su -s /bin/sh -c "$quoted" "$WEB_USER"
    fi
}

php_app() { # run one of the application's CLI scripts as the web user
    ( cd "$INSTALL_DIR" && run_as_web "$PHP_BIN" "$@" )
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
ANSWERS_FILE=""

for arg in "$@"; do
    case "$arg" in
        --answers=*)       ANSWERS_FILE="${arg#*=}" ;;
        --non-interactive) NON_INTERACTIVE=yes; ASSUME_YES=yes ;;
        --dir=*)           INSTALL_DIR="${arg#*=}" ;;
        --domain=*)        SERVER_NAME="${arg#*=}" ;;
        --web-server=*)    WEB_SERVER="${arg#*=}" ;;
        --tls=*)           TLS_MODE="${arg#*=}" ;;
        --skip-packages)   SKIP_PACKAGES=yes ;;
        --cron)            INSTALL_CRON=yes ;;
        --dry-run)         DRY_RUN=yes ;;
        --yes|-y)          ASSUME_YES=yes ;;
        --help|-h)         usage; exit 0 ;;
        *)                 die "Unknown option '$arg'. Try --help." ;;
    esac
done

if [ -n "$ANSWERS_FILE" ]; then
    [ -r "$ANSWERS_FILE" ] || die "Cannot read the answers file '$ANSWERS_FILE'."
    # shellcheck disable=SC1090
    . "$ANSWERS_FILE"
fi

say ""
say "${C_BOLD}Production Tracker installer ${INSTALLER_VERSION}${C_RESET}"
say "${C_DIM}Source: ${SRC_DIR}${C_RESET}"

# ---------------------------------------------------------------------------
# 1. Preflight
# ---------------------------------------------------------------------------
step "Checking the machine"

[ "$(id -u)" -eq 0 ] || die "This installer changes system packages and file ownership, so it must run as root:  sudo ./install.sh"

case "$(uname -s)" in
    Linux) : ;;
    *) die "This installer targets Linux. On Windows or macOS, follow the manual steps in docs/INSTALL.md." ;;
esac

[ -f "$SRC_DIR/public/index.php" ] && [ -d "$SRC_DIR/database/migrations" ] \
    || die "This does not look like the Production Tracker source tree — public/index.php is missing. Run install.sh from inside the checkout of ${REPO_URL}."

# /etc/os-release is a shell fragment defining NAME, VERSION, ID, HOME_URL and a
# dozen more. Sourcing it here would drop all of them into this script's
# namespace, where they can collide with its own variables. Read it in a
# subshell and lift out only the three fields needed.
if [ -r /etc/os-release ]; then
    eval "$(
        # shellcheck disable=SC1091
        . /etc/os-release 2>/dev/null
        printf 'OS_ID=%q\nOS_LIKE=%q\nOS_NAME=%q\n' \
            "${ID:-unknown}" "${ID_LIKE:-}" "${PRETTY_NAME:-${NAME:-${ID:-unknown}}}"
    )"
else
    die "Cannot read /etc/os-release, so the distribution cannot be identified."
fi

case " ${OS_ID} ${OS_LIKE} " in
    *" debian "*|*" ubuntu "*) PKG=apt ;;
    *" fedora "*|*" rhel "*|*" centos "*) PKG=dnf ;;
    *" suse "*|*" opensuse "*) PKG=zypper ;;
    *" arch "*) PKG=pacman ;;
    *)
        if   have apt-get; then PKG=apt
        elif have dnf;     then PKG=dnf
        elif have yum;     then PKG=yum
        elif have zypper;  then PKG=zypper
        elif have pacman;  then PKG=pacman
        else die "No supported package manager found (apt, dnf, yum, zypper, pacman)."
        fi
        ;;
esac

[ "$PKG" = dnf ] && ! have dnf && have yum && PKG=yum

case "$PKG" in
    apt)            WEB_USER=www-data; WEB_GROUP=www-data; APACHE_SVC=apache2 ;;
    dnf|yum|zypper) WEB_USER=apache;   WEB_GROUP=apache;   APACHE_SVC=httpd ;;
    pacman)         WEB_USER=http;     WEB_GROUP=http;     APACHE_SVC=httpd ;;
esac

ok "$OS_NAME (package manager: $PKG)"

if ! have systemctl; then
    warn "systemd was not found. Services will need starting by hand."
fi

# ---------------------------------------------------------------------------
# 2. What is already here
# ---------------------------------------------------------------------------
step "Looking for PHP, a web server, MariaDB and Composer"

PHP_BIN="$(command -v php || true)"
if [ -n "$PHP_BIN" ]; then
    PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo "0")"
    if "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' 2>/dev/null; then
        ok "PHP $PHP_VERSION"
    else
        warn "PHP $PHP_VERSION is too old — 8.1 or newer is required."
        PLAN+=("Upgrade PHP to 8.1 or newer")
    fi
else
    info "PHP is not installed"
    PLAN+=("Install PHP 8.1+ with pdo_mysql, mbstring, fileinfo, gd and curl")
fi

if have apache2ctl || have apachectl || have httpd; then
    ok "Apache is installed"
else
    info "Apache is not installed"
fi

if have nginx; then ok "nginx is installed"; fi

for candidate in mariadb mysql; do
    if have "$candidate"; then DB_CLIENT="$candidate"; break; fi
done

if have mariadbd || have mysqld || [ -d /var/lib/mysql ]; then
    ok "MariaDB/MySQL server is installed"
else
    info "MariaDB is not installed"
    PLAN+=("Install the MariaDB server")
fi

if have composer; then
    ok "Composer $(composer --version --no-ansi 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
else
    info "Composer is not installed"
    PLAN+=("Install Composer, then use it to fetch PHPMailer, dompdf and the QR code library")
fi

# ---------------------------------------------------------------------------
# 3. Questions
# ---------------------------------------------------------------------------
step "Settings"

if [ -z "$WEB_SERVER" ]; then
    if have nginx && ! have apache2ctl && ! have apachectl && ! have httpd; then
        choose WEB_SERVER "nginx is already installed. Which web server should serve the site?" \
            "nginx:use the nginx already installed" \
            "apache:install and configure Apache" \
            "none:configure the web server yourself later"
    else
        WEB_SERVER=apache
    fi
fi

ask_required INSTALL_DIR "Install directory" "/var/www/production-tracker"

# An existing install has a .env. Unpacking the source straight into the install
# directory and running from there is a first install, not an upgrade, even
# though public/index.php is already sitting in place.
INSTALL_DIR_ABS="$(cd "$INSTALL_DIR" 2>/dev/null && pwd || printf '%s' "$INSTALL_DIR")"

if [ -f "$INSTALL_DIR/.env" ] \
   || { [ -f "$INSTALL_DIR/public/index.php" ] && [ "$INSTALL_DIR_ABS" != "$SRC_DIR" ]; }; then
    UPGRADE_MODE=yes
    say ""
    warn "An existing installation was found at $INSTALL_DIR."
    say  "         The application files will be refreshed and the migrations re-run."
    say  "         ${C_BOLD}.env, storage/ and the database are left alone.${C_RESET}"
    confirm "Continue and upgrade in place?" || die "Nothing was changed."
fi

ask_required APP_NAME "Application name (shown in the browser title)" "Production Tracker"
ask SERVER_NAME "Hostname the site answers on (blank for any)"

choose TLS_MODE "How will HTTPS be handled?" \
    "proxy:TLS terminates at a reverse proxy in front of this server (recommended)" \
    "direct-https:this server terminates TLS with a certificate you already have" \
    "plain-http:no TLS at all — a trusted LAN only"

case "$TLS_MODE" in
    direct-https)
        ask TLS_CERT "Path to the TLS certificate (fullchain)" "/etc/ssl/certs/ssl-cert-snakeoil.pem"
        ask TLS_KEY  "Path to the TLS private key" "/etc/ssl/private/ssl-cert-snakeoil.key"
        [ -r "$TLS_CERT" ] || die "Cannot read the certificate at $TLS_CERT."
        [ -r "$TLS_KEY" ]  || die "Cannot read the private key at $TLS_KEY."
        ;;
    plain-http)
        warn "Without TLS, sign-in passwords cross the network in the clear."
        warn "Only do this on a network you control."
        ;;
esac

if [ -z "$APP_URL" ]; then
    default_scheme="https"; default_port_suffix=""
    [ "$TLS_MODE" = plain-http ] && default_scheme="http"
    [ "$TLS_MODE" = plain-http ] && [ "$HTTP_PORT" != "80" ] && default_port_suffix=":$HTTP_PORT"
    default_host="${SERVER_NAME:-$(hostname -f 2>/dev/null || hostname)}"
    ask_required APP_URL "Public URL of the site" "${default_scheme}://${default_host}${default_port_suffix}"
fi
APP_URL="${APP_URL%/}"

if [ -z "$APP_TIMEZONE" ]; then
    detected="$(timedatectl show --property=Timezone --value 2>/dev/null || cat /etc/timezone 2>/dev/null || echo Europe/London)"
    ask_required APP_TIMEZONE "Timezone" "${detected:-Europe/London}"
fi

ask_required DB_NAME "Database name" "production_tracker"
ask_required DB_USER "Database user" "tracker"

[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "The database name may only contain letters, digits and underscores."
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "The database user may only contain letters, digits and underscores."

DB_PASSWORD_GENERATED=no
if [ -z "$DB_PASSWORD" ]; then
    DB_PASSWORD="$(random_password)"
    DB_PASSWORD_GENERATED=yes
fi

# Not asked for: there is nothing an operator could usefully choose here, and a
# generated key means outbound email can be configured from the Settings page on
# day one without anyone having to touch .env. An answers file may still carry
# one over from an existing install, and that must win — reusing the old key is
# what keeps an already-stored SMTP password readable.
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(random_app_key)"
    [ -n "$APP_KEY" ] || warn "No way to generate APP_KEY yet (no openssl, no PHP). One will be generated after the packages are installed."
fi

# The from address is the one mail setting with a sensible guess available, and
# having it wrong is the difference between "the invitation bounced" and "the
# invitation arrived". Everything else is finished from Settings → Email.
if [ -z "$MAIL_FROM_ADDRESS" ] && [ "$NON_INTERACTIVE" != yes ]; then
    ask MAIL_FROM_ADDRESS "Address outbound email should come from (optional, set it later in Settings)"
fi

step "The first administrator"
say "  This account can do everything, including inviting everyone else."
say "  ${C_DIM}Every account after this one is created by invitation from the interface,${C_RESET}"
say "  ${C_DIM}so this is the only password anybody other than its owner ever types.${C_RESET}"

ask_required ADMIN_NAME  "Full name"
ask_required ADMIN_EMAIL "Email address (this is the sign-in name)"

[[ "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "'$ADMIN_EMAIL' is not a valid email address."

ask_secret ADMIN_PASSWORD "Password (at least 12 characters)" 12
[ "${#ADMIN_PASSWORD}" -ge 12 ] || die "The administrator password must be at least 12 characters."

if [ -z "$INSTALL_CRON" ]; then
    if [ "$NON_INTERACTIVE" = yes ]; then
        INSTALL_CRON=no
    else
        confirm "Install the daily reminder cron entry? (the digest itself stays off until you switch it on in Settings)" \
            && INSTALL_CRON=yes || INSTALL_CRON=no
    fi
fi

# ---------------------------------------------------------------------------
# 4. The plan
# ---------------------------------------------------------------------------
[ "$UPGRADE_MODE" = yes ] && PLAN+=("Refresh the application files at $INSTALL_DIR") \
                          || PLAN+=("Copy the application to $INSTALL_DIR")
PLAN+=("Create the database '$DB_NAME' and the user '$DB_USER'@'localhost'")
PLAN+=("Write $INSTALL_DIR/.env (mode 640, root:$WEB_GROUP)")
PLAN+=("Set ownership to root:$WEB_GROUP, and storage/ to $WEB_USER:$WEB_GROUP")
case "$WEB_SERVER" in
    apache) PLAN+=("Configure Apache to serve $INSTALL_DIR/public on port $HTTP_PORT") ;;
    nginx)  PLAN+=("Configure nginx and php-fpm to serve $INSTALL_DIR/public") ;;
    none)   PLAN+=("Skip the web server — you will configure it yourself") ;;
esac
PLAN+=("Apply the database migrations")
PLAN+=("Create the staff administrator $ADMIN_EMAIL")
[ "$INSTALL_CRON" = yes ] && PLAN+=("Add the daily reminder cron entry")

step "Plan"
for item in "${PLAN[@]}"; do
    say "  • $item"
done
say ""
say "  ${C_DIM}Nothing outside $INSTALL_DIR, the web server config, /etc/php* and the${C_RESET}"
say "  ${C_DIM}'$DB_NAME' database is touched.${C_RESET}"

if [ "$DRY_RUN" = yes ]; then
    say ""
    ok "Dry run — stopping here without changing anything."
    exit 0
fi

say ""
confirm "Go ahead?" || die "Nothing was changed."

# ---------------------------------------------------------------------------
# 5. Packages
# ---------------------------------------------------------------------------
pkg_install() {
    case "$PKG" in
        apt)     DEBIAN_FRONTEND=noninteractive apt-get install -y -o Dpkg::Options::=--force-confold "$@" ;;
        dnf)     dnf install -y "$@" ;;
        yum)     yum install -y "$@" ;;
        zypper)  zypper --non-interactive install "$@" ;;
        pacman)  pacman -S --needed --noconfirm "$@" ;;
    esac
}

pkg_refresh() {
    case "$PKG" in
        apt)     apt-get update -qq ;;
        dnf|yum) : ;;
        zypper)  zypper --non-interactive refresh ;;
        pacman)  pacman -Sy --noconfirm ;;
    esac
}

# Fetch a URL to stdout. curl or wget, whichever the machine has.
fetch_url() {
    if have curl; then
        curl -fsSL --retry 3 --retry-delay 2 --max-time 120 "$1"
    elif have wget; then
        wget -qO- --tries=3 --timeout=120 "$1"
    else
        return 1
    fi
}

#
# Make sure `composer` is on the PATH.
#
# Unlike its sibling asset register, this application does not degrade
# gracefully without its packages: dompdf renders every delivery note and route
# card, and the QR code on a free-issue note is what the workshop scans. So
# Composer is not optional here, and the installer says so rather than leaving
# an install that looks complete and cannot print.
#
# Two routes, in order of how much trust each asks for:
#
#   1. The distribution's own package. No new trust: signed by the same
#      repository as everything else on the machine. Tried tolerantly, because
#      the package is named differently across distributions and does not exist
#      at all on some.
#   2. The official installer from getcomposer.org, checked against the SHA-384
#      that Composer publishes at composer.github.io/installer.sig — the method
#      Composer's own documentation gives, signature check included.
#
ensure_composer() {
    if have composer; then
        return 0
    fi

    if [ "$SKIP_PACKAGES" = yes ]; then
        warn "--skip-packages: Composer was not installed, so the PDF and QR code libraries are missing."
        return 1
    fi

    info "Composer is needed for PHPMailer, dompdf and the QR code library — installing it"

    pkg_install composer >/dev/null 2>&1 || true
    hash -r 2>/dev/null || true

    if have composer; then
        ok "Composer installed from the distribution's packages"
        return 0
    fi

    info "Not in this distribution's packages — using the official installer"

    have curl || have wget || pkg_install curl >/dev/null 2>&1 || true
    hash -r 2>/dev/null || true

    local tmp setup expected actual
    tmp="$(mktemp -d)"
    setup="$tmp/composer-setup.php"

    if ! fetch_url "https://getcomposer.org/installer" > "$setup" 2>/dev/null || [ ! -s "$setup" ]; then
        rm -rf "$tmp"
        warn "Could not download the Composer installer (no network, or getcomposer.org is unreachable)."
        return 1
    fi

    # The path goes in as an argument, not interpolated into the code string: a
    # temp path is not attacker-controlled, but building code by string
    # concatenation is a habit worth not having.
    # `|| true` because this is an assignment from a pipeline under `set -e`:
    # with no network, fetch_url fails, the pipeline fails, and the installer
    # would abort here rather than reaching the signature check below that is
    # written to handle exactly this.
    expected="$(fetch_url 'https://composer.github.io/installer.sig' 2>/dev/null | tr -d '[:space:]' || true)"
    actual="$("$PHP_BIN" -r 'echo hash_file("sha384", $argv[1]);' "$setup" 2>/dev/null)"

    if [ -z "$expected" ] || [ -z "$actual" ] || [ "$expected" != "$actual" ]; then
        rm -rf "$tmp"
        warn "The Composer installer failed its signature check, so it was NOT run."
        warn "Expected $expected"
        warn "Got      $actual"
        return 1
    fi

    if COMPOSER_ALLOW_SUPERUSER=1 "$PHP_BIN" "$setup" --quiet --install-dir=/usr/local/bin --filename=composer; then
        rm -rf "$tmp"
        hash -r 2>/dev/null || true

        if have composer; then
            ok "Composer installed to /usr/local/bin/composer (signature verified)"
            return 0
        fi
    fi

    rm -rf "$tmp"
    warn "The Composer installer ran but left no usable binary."

    return 1
}

apt_php_candidate() {
    apt-cache policy php-cli 2>/dev/null | awk '/Candidate:/ {print $2}' \
        | grep -oE '[0-9]+\.[0-9]+' | head -1
}

# Debian and Ubuntu LTS releases can be a PHP major behind. Offer the standard
# third-party repository rather than failing, but never add it silently.
apt_add_php_repo() {
    say ""
    warn "The PHP in this distribution's own repositories is older than 8.1."
    say  "        The usual fix is Ondrej Sury's PHP packages — a widely used"
    say  "        third-party repository (deb.sury.org / ppa:ondrej/php)."

    confirm "Add that repository and install PHP 8.3 from it?" || \
        die "PHP 8.1 or newer is required. Upgrade the distribution, or install PHP yourself and re-run with --skip-packages."

    pkg_install ca-certificates apt-transport-https lsb-release curl gnupg

    if [ "$OS_ID" = ubuntu ]; then
        pkg_install software-properties-common
        add-apt-repository -y ppa:ondrej/php
    else
        curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
        echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
            > /etc/apt/sources.list.d/sury-php.list
    fi

    apt-get update -qq
    PHP_PKG_PREFIX="php8.3"
}

dnf_enable_php_stream() {
    have dnf || return 0
    dnf module list php >/dev/null 2>&1 || return 0

    local stream
    for stream in 8.3 8.2 8.1; do
        if dnf -q module list "php:$stream" >/dev/null 2>&1; then
            info "Enabling the php:$stream module stream"
            dnf module reset -y php >/dev/null 2>&1 || true
            dnf module enable -y "php:$stream" >/dev/null 2>&1 || true
            return 0
        fi
    done
}

install_packages() {
    step "Installing packages"

    if [ "$SKIP_PACKAGES" = yes ]; then
        warn "--skip-packages: nothing will be installed."
        return 0
    fi

    pkg_refresh

    local pkgs=()

    case "$PKG" in
        apt)
            local candidate
            candidate="$(apt_php_candidate || true)"
            if [ -n "$candidate" ]; then
                if version_ge "$candidate" 8.1; then
                    info "The distribution offers PHP $candidate"
                else
                    apt_add_php_repo
                fi
            fi

            # dompdf needs mbstring, gd and dom (php-xml); endroid/qr-code needs
            # gd; the Clear Books client needs curl.
            pkgs+=("${PHP_PKG_PREFIX}-cli" "${PHP_PKG_PREFIX}-mysql" "${PHP_PKG_PREFIX}-mbstring"
                   "${PHP_PKG_PREFIX}-gd" "${PHP_PKG_PREFIX}-xml" "${PHP_PKG_PREFIX}-curl"
                   "${PHP_PKG_PREFIX}-zip")
            [ "$WEB_SERVER" = apache ] && pkgs+=(apache2 "libapache2-mod-${PHP_PKG_PREFIX}")
            [ "$WEB_SERVER" = nginx ]  && pkgs+=("${PHP_PKG_PREFIX}-fpm")
            pkgs+=(mariadb-server mariadb-client curl)
            ;;
        dnf|yum)
            dnf_enable_php_stream
            pkgs+=(php-cli php-mysqlnd php-mbstring php-gd php-xml php-json)
            [ "$WEB_SERVER" = apache ] && pkgs+=(httpd php)
            [ "$WEB_SERVER" = nginx ]  && pkgs+=(nginx php-fpm)
            pkgs+=(mariadb-server curl tar)
            ;;
        zypper)
            pkgs+=(php8 php8-mysql php8-mbstring php8-gd php8-fileinfo php8-dom php8-curl)
            [ "$WEB_SERVER" = apache ] && pkgs+=(apache2 apache2-mod_php8)
            [ "$WEB_SERVER" = nginx ]  && pkgs+=(nginx php8-fpm)
            pkgs+=(mariadb curl tar)
            ;;
        pacman)
            pkgs+=(php php-gd)
            [ "$WEB_SERVER" = apache ] && pkgs+=(apache php-apache)
            [ "$WEB_SERVER" = nginx ]  && pkgs+=(nginx php-fpm)
            pkgs+=(mariadb curl tar)
            ;;
    esac

    info "${pkgs[*]}"
    pkg_install "${pkgs[@]}" || die "Package installation failed. Fix the errors above and re-run."

    # Arch does not initialise MariaDB's data directory for you.
    if [ "$PKG" = pacman ] && [ ! -d /var/lib/mysql/mysql ]; then
        mariadb-install-db --user=mysql --basedir=/usr --datadir=/var/lib/mysql >/dev/null
    fi

    # This script runs the very binaries it just installed. Drop bash's cached
    # command lookups so a path resolved before the install cannot go stale.
    hash -r 2>/dev/null || true

    ok "Packages installed"
}

install_packages

PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || die "PHP still is not on the PATH after installation."

PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' \
    || die "PHP $PHP_VERSION is installed but the application needs 8.1 or newer."

# If openssl was missing earlier there is now a PHP to fall back on.
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(random_app_key)"
    [ -n "$APP_KEY" ] && ok "Generated an APP_KEY"
fi

step "Checking PHP extensions"

# The module list is read once and matched in the shell, rather than piping
# `php -m` into `grep -q` per extension.
#
# That pipeline is broken under `set -o pipefail`, which is on at the top of
# this script: `grep -q` exits the instant it matches, PHP is killed by SIGPIPE
# writing the rest of its output, and the *pipeline* then reports failure — so a
# match reads as a miss. It depends on where the extension falls in the output,
# which is why it looked so arbitrary: every module near the start of `php -m`
# was reported missing while pdo_mysql, near the end, passed. json cannot be
# missing from PHP 8 at all, and that was the giveaway.
#
# Reading it once is also eight fewer PHP startups.
php_modules=" $("$PHP_BIN" -m 2>/dev/null | tr '[:upper:]' '[:lower:]' | tr '\n' ' ' || true) "

has_module() { # has_module NAME
    case "$php_modules" in
        *" $1 "*) return 0 ;;
        *)        return 1 ;;
    esac
}

missing=()
for ext in pdo pdo_mysql mbstring fileinfo json curl; do
    if has_module "$ext"; then ok "$ext"; else missing+=("$ext"); warn "$ext is MISSING"; fi
done
for ext in gd dom; do
    if has_module "$ext"; then ok "$ext"; else missing+=("$ext"); warn "$ext is MISSING — delivery notes and QR codes need it"; fi
done
for ext in openssl; do
    if has_module "$ext"; then ok "$ext"; else warn "$ext is missing — the SMTP password cannot be encrypted at rest"; fi
done

if [ "${#missing[@]}" -ne 0 ]; then
    say ""
    warn "PHP reports these modules:"
    say "  ${php_modules}"
    die "Required PHP extensions are missing: ${missing[*]}
       Install them and re-run. On Debian/Ubuntu the packages are named
       php-<extension>; on RHEL/Fedora, php-<extension> too, except pdo_mysql
       which comes from php-mysqlnd and dom which comes from php-xml."
fi

# The account the files will be owned by has to exist. On a --web-server=none
# install there may be no web server package, and so no web user.
if ! id -u "$WEB_USER" >/dev/null 2>&1; then
    found=""
    for candidate in www-data apache http nginx; do
        id -u "$candidate" >/dev/null 2>&1 && { found="$candidate"; break; }
    done

    if [ -n "$found" ]; then
        warn "The user '$WEB_USER' does not exist; using '$found' instead."
        WEB_USER="$found"
    else
        warn "No web server user was found. The files will be owned by root, and"
        warn "storage/ will need its ownership fixing once the web server is installed:"
        warn "  sudo $INSTALL_DIR/manage.sh permissions"
        WEB_USER=root
    fi
fi
WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || printf '%s' "$WEB_USER")"
info "Web server user: $WEB_USER:$WEB_GROUP"

# ---------------------------------------------------------------------------
# 6. Services
# ---------------------------------------------------------------------------
service_up() { # service_up NAME [required]
    local name="$1" required="${2:-no}"

    have systemctl || return 0

    if ! unit_exists "$name"; then
        [ "$required" = yes ] && warn "No $name service was found." || true
        return 0
    fi

    systemctl enable "$name" >/dev/null 2>&1 || true

    if systemctl is-active --quiet "$name"; then
        ok "$name is running"
    elif systemctl start "$name" >/dev/null 2>&1; then
        ok "$name started"
    else
        [ "$required" = yes ] && die "Could not start $name. Check: systemctl status $name"
        warn "Could not start $name."
    fi
}

step "Starting services"

for candidate in mariadb mysqld mysql; do
    if unit_exists "$candidate"; then DB_SVC="$candidate"; break; fi
done
[ -n "$DB_SVC" ] || DB_SVC=mariadb

service_up "$DB_SVC" yes

for candidate in mariadb mysql; do
    if have "$candidate"; then DB_CLIENT="$candidate"; break; fi
done
[ -n "$DB_CLIENT" ] || die "No mariadb/mysql client was found after installation."

case "$WEB_SERVER" in
    apache) service_up "$APACHE_SVC" yes ;;
    nginx)  service_up nginx yes ;;
esac

# php-fpm, where the web server needs it
if [ "$WEB_SERVER" = nginx ] || ! ( have apache2ctl || have apachectl || have httpd ); then
    for candidate in php-fpm "php${PHP_VERSION%.*}-fpm" php8.3-fpm php8.2-fpm php8.1-fpm; do
        if unit_exists "$candidate"; then
            PHP_FPM_SVC="$candidate"
            service_up "$candidate"
            break
        fi
    done
fi

for sock in /run/php-fpm/www.sock "/run/php/php${PHP_VERSION%.*}-fpm.sock" /run/php/php-fpm.sock /var/run/php-fpm/www.sock; do
    [ -S "$sock" ] && { PHP_FPM_SOCKET="$sock"; break; }
done

# ---------------------------------------------------------------------------
# 7. Database
# ---------------------------------------------------------------------------
step "Setting up the database"

db_root() { # db_root <<< "SQL"
    if [ -n "$DB_ROOT_CNF" ]; then
        "$DB_CLIENT" --defaults-extra-file="$DB_ROOT_CNF" "$@"
    else
        "$DB_CLIENT" -u root "$@"
    fi
}

# A fresh MariaDB authenticates root over the unix socket, so as root we are
# usually already in. Fall back to a password, kept in a 600 defaults file so it
# never appears in the process list.
if "$DB_CLIENT" -u root -e 'SELECT 1' >/dev/null 2>&1; then
    ok "Connected to MariaDB as root over the local socket"
else
    if [ -z "$DB_ROOT_PASSWORD" ]; then
        if [ "$NON_INTERACTIVE" = yes ]; then
            die "MariaDB's root account needs a password and DB_ROOT_PASSWORD is not set."
        fi
        say "  MariaDB's root account is password-protected."
        read -r -s -p "  MariaDB root password: " DB_ROOT_PASSWORD || true; echo
    fi

    DB_ROOT_CNF="$(mktemp)"
    chmod 600 "$DB_ROOT_CNF"
    printf '[client]\nuser=root\npassword=%s\n' "$DB_ROOT_PASSWORD" > "$DB_ROOT_CNF"

    db_root -e 'SELECT 1' >/dev/null 2>&1 || die "Could not connect to MariaDB as root."
    ok "Connected to MariaDB as root"
fi

db_version="$(db_root -N -B -e 'SELECT VERSION()' 2>/dev/null || echo unknown)"
info "Server: $db_version"

db_name_sql="$(sql_quote "$DB_NAME")"
db_user_sql="$(sql_quote "$DB_USER")"
db_pass_sql="$(sql_quote "$DB_PASSWORD")"

db_existed=no
if [ -n "$(db_root -N -B -e "SHOW DATABASES LIKE '${db_name_sql}'" 2>/dev/null || true)" ]; then
    db_existed=yes
fi

# The application user gets what it needs to own and migrate its own schema:
# SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX and REFERENCES.
# DROP is part of that — a migration may need it, and withholding it would not
# buy much when the same user already holds DELETE and ALTER.
#
# Withheld, and these are the ones that matter: no GRANT OPTION, no CREATE USER,
# no FILE, no SUPER, no PROCESS, and no rights on any other database. A
# compromise stays inside this schema.
db_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${db_user_sql}'@'localhost' IDENTIFIED BY '${db_pass_sql}';
ALTER USER '${db_user_sql}'@'localhost' IDENTIFIED BY '${db_pass_sql}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES ON \`${DB_NAME}\`.* TO '${db_user_sql}'@'localhost';
FLUSH PRIVILEGES;
SQL

if [ "$db_existed" = yes ]; then
    ok "Database '$DB_NAME' already existed — left as it is, credentials refreshed"
else
    ok "Created the database '$DB_NAME'"
fi
ok "Granted '$DB_USER'@'localhost' the rights the application and its migrations need"

# ---------------------------------------------------------------------------
# 8. Copy the files
# ---------------------------------------------------------------------------
step "Installing the application to $INSTALL_DIR"

mkdir -p "$INSTALL_DIR"

if [ "$(cd "$INSTALL_DIR" && pwd)" = "$SRC_DIR" ]; then
    info "Already unpacked in place — nothing to copy"
else
    tar -cf - -C "$SRC_DIR" \
        --exclude=./.git \
        --exclude=./.github \
        --exclude=./.claude \
        --exclude=./.env \
        --exclude=./vendor \
        --exclude=./node_modules \
        --exclude=./storage/uploads \
        --exclude=./storage/logs \
        --exclude='./*.zip' \
        --exclude='./*.tar.gz' \
        . | tar -xf - -C "$INSTALL_DIR"
    ok "Files copied"
fi

# Just the two roots. The per-kind directories underneath are made by the
# application as files arrive -- Upload, Image and PdfService all mkdir
# recursively -- and listing them here only creates something to go stale.
# It already had: part-photos and order-photos were still being created long
# after the schema change that retired both, and part-media, which replaced
# them, was never in the list at all.
mkdir -p "$INSTALL_DIR/storage/logs" \
         "$INSTALL_DIR/storage/uploads"

# PHPMailer sends the mail, dompdf renders every delivery note and route card,
# and endroid/qr-code draws the code the workshop scans to check material in.
# None of that is optional, so a failure here is loud.
step "Installing PHP dependencies"

composer_ok=no

if [ -f "$INSTALL_DIR/vendor/autoload.php" ]; then
    ok "vendor/ is already present — leaving it alone"
    composer_ok=yes
elif ensure_composer; then
    info "composer install --no-dev --optimize-autoloader"

    if ( cd "$INSTALL_DIR" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction ); then
        if [ -f "$INSTALL_DIR/vendor/autoload.php" ]; then
            ok "PHPMailer, dompdf and the QR code library installed"
            composer_ok=yes
        else
            warn "Composer reported success but vendor/autoload.php is missing."
        fi
    else
        warn "composer install failed. The output above says why."
    fi
fi

if [ "$composer_ok" != yes ]; then
    warn "The PHP packages are not installed. Without them the tracker cannot send"
    warn "email, generate a delivery note or draw a QR code. To fix it:"
    warn "  cd $INSTALL_DIR && composer install --no-dev --optimize-autoloader"
    warn "or, if composer itself is missing:"
    warn "  sudo $INSTALL_DIR/manage.sh install-composer"
fi

# ---------------------------------------------------------------------------
# 9. .env
# ---------------------------------------------------------------------------
step "Writing the configuration"

ENV_FILE="$INSTALL_DIR/.env"

if [ -f "$ENV_FILE" ]; then
    backup="$ENV_FILE.$(date +%Y%m%d-%H%M%S).bak"
    cp -p "$ENV_FILE" "$backup"
    chmod 600 "$backup"
    warn "An existing .env was kept as $(basename "$backup")"
fi

session_secure=true
trusted_proxies=""
case "$TLS_MODE" in
    proxy)        session_secure=true;  trusted_proxies="*" ;;
    direct-https) session_secure=true;  trusted_proxies=""  ;;
    plain-http)   session_secure=false; trusted_proxies=""  ;;
esac

umask 077
cat > "$ENV_FILE" <<ENV
# ---------------------------------------------------------------------------
# Written by install.sh on $(date '+%Y-%m-%d %H:%M:%S %Z').
# This file holds the database password. Keep it mode 640, root:${WEB_GROUP}.
# ---------------------------------------------------------------------------

# Application
APP_NAME=$(env_quote "$APP_NAME")
APP_PRODUCT=$(env_quote "$APP_NAME")
APP_FULL_NAME=$(env_quote "$APP_NAME by Junction")
APP_TAGLINE="Job Shop Order Tracking"
APP_MARK=PT
APP_VENDOR="Junction Inc Ltd"
APP_VENDOR_URL=https://www.junctioninc.co.uk/

APP_ENV=production
APP_DEBUG=false
APP_URL=$(env_quote "$APP_URL")
APP_TIMEZONE=$(env_quote "$APP_TIMEZONE")
APP_CURRENCY=$(env_quote "$APP_CURRENCY")
APP_CURRENCY_SYMBOL=$(env_quote "$APP_CURRENCY_SYMBOL")

# Encrypts secrets stored in the database — today only the SMTP password.
# Generated here so email can be configured from Settings without shell access.
# Changing it makes the stored SMTP password unreadable; back it up with the
# database, not instead of it.
APP_KEY=$(env_quote "$APP_KEY")

# The reverse proxy's IP(s), comma separated, or "*" when the application is
# never reachable except through it. Without this, HTTPS detection and the
# client IP used for sign-in throttling are both wrong behind a proxy.
TRUSTED_PROXIES=$(env_quote "$trusted_proxies")

# Database
DB_HOST=$(env_quote "$DB_HOST")
DB_PORT=${DB_PORT}
DB_DATABASE=$(env_quote "$DB_NAME")
DB_USERNAME=$(env_quote "$DB_USER")
DB_PASSWORD=$(env_quote "$DB_PASSWORD")
DB_CHARSET=utf8mb4

# Sessions
SESSION_NAME=pt_session
SESSION_LIFETIME=480
SESSION_SECURE_COOKIE=${session_secure}

# Login throttling
LOGIN_THROTTLE_MAX_ATTEMPTS=5
LOGIN_THROTTLE_LOCKOUT_MINUTES=15

STORAGE_PATH=storage

# Outbound email.
# These are the fallback: host, port, encryption and addresses are normally set
# from Settings → Email, and the stored values win once they are. What is here
# is enough to get the first invitation out on a fresh install.
MAIL_HOST=$(env_quote "$MAIL_HOST")
MAIL_PORT=${MAIL_PORT}
MAIL_ENCRYPTION=$(env_quote "$MAIL_ENCRYPTION")
MAIL_USERNAME=$(env_quote "$MAIL_USERNAME")
MAIL_PASSWORD=$(env_quote "$MAIL_PASSWORD")
MAIL_FROM_ADDRESS=$(env_quote "$MAIL_FROM_ADDRESS")
MAIL_FROM_NAME=$(env_quote "${MAIL_FROM_NAME:-$APP_NAME}")

# Clear Books REST API — OAuth 2, authorization code grant, confidential client
# with PKCE. There is no static API key. Request access and register a client at
# https://www.clearbooks.co.uk/support/api/, giving them the redirect URI below,
# then finish the connection at Settings → Clear Books.
#
# The API and OAuth endpoints are not configurable: they are published in the
# Clear Books OpenAPI description and live as constants in the client class.
CLEARBOOKS_CLIENT_ID=$(env_quote "$CLEARBOOKS_CLIENT_ID")
CLEARBOOKS_CLIENT_SECRET=$(env_quote "$CLEARBOOKS_CLIENT_SECRET")
CLEARBOOKS_REDIRECT_URI=${APP_URL}/staff/settings/clearbooks/callback
ENV
umask 022

chown root:"$WEB_GROUP" "$ENV_FILE" 2>/dev/null || chown 0:"$WEB_GROUP" "$ENV_FILE"
chmod 640 "$ENV_FILE"
ok ".env written (mode 640, readable only by root and $WEB_GROUP)"

# ---------------------------------------------------------------------------
# 10. Ownership and modes
# ---------------------------------------------------------------------------
step "Setting ownership and permissions"

chown -R root:"$WEB_GROUP" "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 750 {} +
find "$INSTALL_DIR" -type f -exec chmod 640 {} +

# storage/ is the only place the application writes.
chown -R "$WEB_USER":"$WEB_GROUP" "$INSTALL_DIR/storage"
find "$INSTALL_DIR/storage" -type d -exec chmod 2775 {} +
find "$INSTALL_DIR/storage" -type f -exec chmod 664 {} +

# The two scripts are for the administrator, not for the web server.
for script in install.sh manage.sh; do
    [ -f "$INSTALL_DIR/$script" ] && chmod 750 "$INSTALL_DIR/$script"
done

# A copy of manage.sh travels with the source, and running that one instead of
# the installed one is an easy mistake — it manages nothing, because the source
# tree has no .env. Put the real one on PATH so there is an unambiguous way in.
if [ -d /usr/local/sbin ] && [ -f "$INSTALL_DIR/manage.sh" ]; then
    ln -sf "$INSTALL_DIR/manage.sh" /usr/local/sbin/tracker
    ok "Linked /usr/local/sbin/tracker -> $INSTALL_DIR/manage.sh"
fi

ok "Application files: root:$WEB_GROUP, directories 750, files 640"
ok "storage/: $WEB_USER:$WEB_GROUP, 2775/664 — the only writable tree"

if have getenforce && [ "$(getenforce 2>/dev/null)" = "Enforcing" ]; then
    info "SELinux is enforcing — labelling the files"
    if have semanage; then
        semanage fcontext -a -t httpd_sys_content_t "${INSTALL_DIR}(/.*)?" >/dev/null 2>&1 || true
        semanage fcontext -a -t httpd_sys_rw_content_t "${INSTALL_DIR}/storage(/.*)?" >/dev/null 2>&1 || true
    else
        warn "semanage is missing (dnf install policycoreutils-python-utils) — labels may not survive a relabel."
    fi
    have restorecon && restorecon -R "$INSTALL_DIR" >/dev/null 2>&1 || true
    have chcon && {
        chcon -R -t httpd_sys_content_t "$INSTALL_DIR" >/dev/null 2>&1 || true
        chcon -R -t httpd_sys_rw_content_t "$INSTALL_DIR/storage" >/dev/null 2>&1 || true
    }
    # The Clear Books client makes outbound HTTPS calls from PHP.
    setsebool -P httpd_can_network_connect on >/dev/null 2>&1 || true
    setsebool -P httpd_can_network_connect_db on >/dev/null 2>&1 || true
    ok "SELinux contexts applied"
fi

# ---------------------------------------------------------------------------
# 11. PHP limits
# ---------------------------------------------------------------------------
step "Raising PHP's upload limits to match the application"

# What the application says its largest allowed upload is, asked of the
# application rather than kept as a second number here. These used to be keyed
# off the drawing limit alone, which was 25 MB while the app offered 50 MB for
# tooling files — so PHP threw away the body of an upload the app had just
# promised to accept, and the CSRF check downstream reported it as an expired
# session. `tracker doctor` now compares the two on a running install.
APP_MAX_UPLOAD_MB="$(PT_ROOT="$INSTALL_DIR" "$PHP_BIN" -r '
    require getenv("PT_ROOT") . "/src/bootstrap.php";
    $max = 0;
    foreach ((array) App\Core\Config::get("uploads", []) as $rules) {
        $max = max($max, (int) ($rules["max_bytes"] ?? 0));
    }
    echo (int) ceil($max / 1048576);
' 2>/dev/null || true)"

# A fallback, so a config that cannot be read does not quietly install a zero.
case "$APP_MAX_UPLOAD_MB" in
    ''|*[!0-9]*) APP_MAX_UPLOAD_MB=50 ;;
esac
[ "$APP_MAX_UPLOAD_MB" -ge "$UPLOAD_MAX_DRAWING_MB" ] || APP_MAX_UPLOAD_MB="$UPLOAD_MAX_DRAWING_MB"

# post_max_size carries the whole form, not just the file, and several of the
# upload screens take more than one at a time. The headroom is what stops a
# second ordinary-sized file tipping a request over the edge.
POST_MAX_MB=$((APP_MAX_UPLOAD_MB + 32))
info "Largest upload the application allows: ${APP_MAX_UPLOAD_MB}M (post_max_size ${POST_MAX_MB}M)"

php_ini_body() {
    cat <<INI
; Written by the Production Tracker installer. Delete this file to revert.
; These must be at least as large as the drawing/photo limits the application
; enforces, or PHP rejects the upload before the application ever sees it.
upload_max_filesize = ${APP_MAX_UPLOAD_MB}M
post_max_size = ${POST_MAX_MB}M
max_file_uploads = 20
; dompdf holds the whole document in memory while it renders.
memory_limit = 256M
date.timezone = ${APP_TIMEZONE}
INI
}

ini_written=0
for dir in /etc/php/*/apache2/conf.d /etc/php/*/fpm/conf.d /etc/php/*/cli/conf.d /etc/php.d /etc/php8/conf.d /etc/php/conf.d; do
    [ -d "$dir" ] || continue
    php_ini_body > "$dir/99-production-tracker.ini"
    chmod 644 "$dir/99-production-tracker.ini"
    info "$dir/99-production-tracker.ini"
    ini_written=$((ini_written + 1))
done

if [ "$ini_written" -eq 0 ]; then
    scan_dir="$("$PHP_BIN" -i 2>/dev/null | awk -F'=> ' '/Scan this dir/ {print $2}' | tr -d ' ' || true)"
    if [ -n "$scan_dir" ] && [ -d "$scan_dir" ]; then
        php_ini_body > "$scan_dir/99-production-tracker.ini"
        ok "$scan_dir/99-production-tracker.ini"
    else
        warn "No PHP conf.d directory was found. Set upload_max_filesize and post_max_size by hand."
    fi
else
    ok "Wrote $ini_written PHP configuration file(s)"
fi

if [ -n "$PHP_FPM_SVC" ] && have systemctl; then
    systemctl restart "$PHP_FPM_SVC" >/dev/null 2>&1 \
        && ok "$PHP_FPM_SVC restarted to pick the new limits up" \
        || warn "Could not restart $PHP_FPM_SVC — do it by hand or the new limits will not apply."
fi

# ---------------------------------------------------------------------------
# 12. Web server
# ---------------------------------------------------------------------------
apache_directory_block() {
    # The front-controller rewrite and the security headers live here rather
    # than in public/.htaccess so that AllowOverride can stay None: Apache then
    # never stats a .htaccess, and the HTTPS redirect can be made to match the
    # TLS mode chosen at install time instead of being fixed in the file.
    cat <<BLOCK
    <Directory ${INSTALL_DIR}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php

        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^ index.php [L]
        </IfModule>

        <IfModule mod_headers.c>
            Header always set X-Content-Type-Options "nosniff"
            Header always set X-Frame-Options "SAMEORIGIN"
            Header always set Referrer-Policy "strict-origin-when-cross-origin"
        </IfModule>

        <FilesMatch "^\.">
            Require all denied
        </FilesMatch>
    </Directory>

    # Nothing above public/ is web-reachable, but say so twice.
    <Directory ${INSTALL_DIR}>
        Require all denied
    </Directory>
BLOCK

    # mod_php present? If it is, Apache handles PHP itself and must not also be
    # pointed at the FPM socket. Captured and matched in the shell rather than
    # piped into grep, for the pipefail reason noted on the extension check.
    local apache_modules
    apache_modules="$("$APACHE_BIN" -M 2>/dev/null || true)"

    if [ -n "$PHP_FPM_SOCKET" ] && ! [[ "$apache_modules" =~ php[0-9_]*_module ]]; then
        cat <<BLOCK

    <FilesMatch \.php\$>
        SetHandler "proxy:unix:${PHP_FPM_SOCKET}|fcgi://localhost"
    </FilesMatch>
BLOCK
    fi
}

configure_apache() {
    step "Configuring Apache"

    APACHE_BIN="$(command -v apache2ctl || command -v apachectl || command -v httpd || true)"
    [ -n "$APACHE_BIN" ] || die "Apache is not installed. Install it, or re-run without --skip-packages, or choose --web-server=none."

    # Apache's config language has no ${VAR:-default}, so resolve the log
    # directory here and write a literal path into the vhost.
    local log_dir=/var/log/httpd conf server_name_line
    [ -d /var/log/apache2 ] && log_dir=/var/log/apache2

    if [ "$PKG" = apt ]; then
        APACHE_SITES_DIR=/etc/apache2/sites-available
        a2enmod rewrite headers >/dev/null 2>&1 || true
        [ -n "$PHP_FPM_SOCKET" ] && a2enmod proxy_fcgi setenvif >/dev/null 2>&1 || true
        conf="$APACHE_SITES_DIR/production-tracker.conf"
    else
        APACHE_SITES_DIR=/etc/httpd/conf.d
        [ -d "$APACHE_SITES_DIR" ] || APACHE_SITES_DIR=/etc/apache2/vhosts.d
        [ -d "$APACHE_SITES_DIR" ] || die "Could not find Apache's configuration directory."
        conf="$APACHE_SITES_DIR/production-tracker.conf"
    fi

    server_name_line=""
    [ -n "$SERVER_NAME" ] && server_name_line="    ServerName ${SERVER_NAME}"

    {
        echo "# Production Tracker — written by install.sh on $(date '+%Y-%m-%d %H:%M:%S')."
        echo "# The document root is public/; everything else stays outside it."
        echo ""

        if [ "$TLS_MODE" = direct-https ]; then
            cat <<VHOST
<VirtualHost *:80>
${server_name_line}
    RewriteEngine On
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
${server_name_line}
    DocumentRoot ${INSTALL_DIR}/public

    SSLEngine on
    SSLCertificateFile ${TLS_CERT}
    SSLCertificateKeyFile ${TLS_KEY}

$(apache_directory_block)

    ErrorLog ${log_dir}/production-tracker-error.log
    CustomLog ${log_dir}/production-tracker-access.log combined
</VirtualHost>
VHOST
        else
            cat <<VHOST
<VirtualHost *:${HTTP_PORT}>
${server_name_line}
    DocumentRoot ${INSTALL_DIR}/public

$(apache_directory_block)

    ErrorLog ${log_dir}/production-tracker-error.log
    CustomLog ${log_dir}/production-tracker-access.log combined
</VirtualHost>
VHOST
        fi
    } > "$conf"

    chmod 644 "$conf"
    ok "Wrote $conf"

    if [ "$PKG" = apt ]; then
        a2ensite production-tracker >/dev/null 2>&1 || true

        if [ -z "$MAKE_DEFAULT_SITE" ]; then
            if [ -z "$SERVER_NAME" ]; then MAKE_DEFAULT_SITE=yes; else MAKE_DEFAULT_SITE=ask; fi
        fi

        if [ "$MAKE_DEFAULT_SITE" = ask ]; then
            confirm "Disable Apache's default site so this one answers on the bare IP too?" no \
                && MAKE_DEFAULT_SITE=yes || MAKE_DEFAULT_SITE=no
        fi

        if [ "$MAKE_DEFAULT_SITE" = yes ]; then
            a2dissite 000-default >/dev/null 2>&1 || true
            ok "Apache's default site disabled"
        fi

        if [ "$TLS_MODE" = direct-https ]; then
            a2enmod ssl >/dev/null 2>&1 || true
        fi

        if [ "$HTTP_PORT" != "80" ] && ! grep -q "^Listen ${HTTP_PORT}\b" /etc/apache2/ports.conf 2>/dev/null; then
            echo "Listen ${HTTP_PORT}" >> /etc/apache2/ports.conf
            ok "Added 'Listen ${HTTP_PORT}' to ports.conf"
        fi
    else
        if [ "$HTTP_PORT" != "80" ] && ! grep -q "^Listen ${HTTP_PORT}\b" /etc/httpd/conf/httpd.conf 2>/dev/null; then
            echo "Listen ${HTTP_PORT}" >> /etc/httpd/conf/httpd.conf
            ok "Added 'Listen ${HTTP_PORT}' to httpd.conf"
        fi
    fi

    if "$APACHE_BIN" -t >/dev/null 2>&1; then
        ok "Apache configuration is valid"
    else
        "$APACHE_BIN" -t || true
        die "Apache rejected the configuration above. The site was written to $conf."
    fi

    if have systemctl; then
        systemctl reload "$APACHE_SVC" 2>/dev/null || systemctl restart "$APACHE_SVC"
        ok "Apache reloaded"
    fi
}

configure_nginx() {
    step "Configuring nginx"

    [ -n "$PHP_FPM_SOCKET" ] || die "nginx needs php-fpm, but no php-fpm socket was found. Start php-fpm and re-run."

    local conf link server_name_line

    if [ -d /etc/nginx/sites-available ]; then
        conf=/etc/nginx/sites-available/production-tracker
        link=/etc/nginx/sites-enabled/production-tracker
    else
        conf=/etc/nginx/conf.d/production-tracker.conf
        link=""
    fi

    server_name_line="server_name ${SERVER_NAME:-_};"

    if [ "$TLS_MODE" = direct-https ]; then
        cat > "$conf" <<NGINX
# Production Tracker — written by install.sh on $(date '+%Y-%m-%d %H:%M:%S').
server {
    listen 80;
    ${server_name_line}
    return 301 https://\$host\$request_uri;
}

server {
    # HTTP/2 is left off: the directive to enable it changed name in nginx 1.25
    # and the old spelling warns on new builds. Add it once you know which
    # nginx this box has.
    listen 443 ssl;
    ${server_name_line}
    root ${INSTALL_DIR}/public;
    index index.php;

    ssl_certificate ${TLS_CERT};
    ssl_certificate_key ${TLS_KEY};

    client_max_body_size ${POST_MAX_MB}m;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTPS on;
    }

    location ~ /\. { deny all; }
}
NGINX
    else
        cat > "$conf" <<NGINX
# Production Tracker — written by install.sh on $(date '+%Y-%m-%d %H:%M:%S').
server {
    listen ${HTTP_PORT};
    ${server_name_line}
    root ${INSTALL_DIR}/public;
    index index.php;

    client_max_body_size ${POST_MAX_MB}m;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\. { deny all; }
}
NGINX
    fi

    chmod 644 "$conf"
    [ -n "$link" ] && ln -sf "$conf" "$link"
    ok "Wrote $conf"

    if nginx -t >/dev/null 2>&1; then
        ok "nginx configuration is valid"
    else
        nginx -t || true
        die "nginx rejected the configuration above."
    fi

    have systemctl && { systemctl reload nginx 2>/dev/null || systemctl restart nginx; ok "nginx reloaded"; }
}

case "$WEB_SERVER" in
    apache) configure_apache ;;
    nginx)  configure_nginx ;;
    none)   step "Web server"; warn "Skipped. Point a document root at $INSTALL_DIR/public — docs/INSTALL.md has sample Apache and nginx config." ;;
esac

# Firewall
if have firewall-cmd && firewall-cmd --state >/dev/null 2>&1; then
    step "Opening the firewall"
    firewall-cmd --permanent --add-service=http >/dev/null 2>&1 || true
    [ "$TLS_MODE" = direct-https ] && firewall-cmd --permanent --add-service=https >/dev/null 2>&1 || true
    [ "$HTTP_PORT" != "80" ] && firewall-cmd --permanent --add-port="${HTTP_PORT}/tcp" >/dev/null 2>&1 || true
    firewall-cmd --reload >/dev/null 2>&1 || true
    ok "firewalld updated"
elif have ufw && [[ "$(ufw status 2>/dev/null || true)" == *"Status: active"* ]]; then
    step "Opening the firewall"
    ufw allow "${HTTP_PORT}/tcp" >/dev/null 2>&1 || true
    [ "$TLS_MODE" = direct-https ] && ufw allow 443/tcp >/dev/null 2>&1 || true
    ok "ufw updated"
fi

# ---------------------------------------------------------------------------
# 13. Migrations and the first administrator
# ---------------------------------------------------------------------------
step "Applying the database migrations"
php_app bin/migrate.php || die "The migrations failed. The database credentials are in $ENV_FILE."

step "Creating the administrator"
# Piped rather than passed as an argument: an argument is visible in `ps` and
# lands in root's shell history.
if printf '%s' "$ADMIN_PASSWORD" | php_app bin/console.php user:create \
        --name="$ADMIN_NAME" --email="$ADMIN_EMAIL" --roles=staff.admin --stdin-password; then
    ok "Administrator created"
else
    warn "The administrator was not created — most likely the email already exists."
    warn "Reset that account instead:  sudo $INSTALL_DIR/manage.sh reset-password $ADMIN_EMAIL"
fi

# ---------------------------------------------------------------------------
# 14. Cron
# ---------------------------------------------------------------------------
if [ "$INSTALL_CRON" = yes ]; then
    step "Installing the reminder cron entry"

    cron_file=/etc/cron.d/production-tracker
    cat > "$cron_file" <<CRON
# Production Tracker — written by install.sh on $(date '+%Y-%m-%d %H:%M:%S').
# The outstanding-parts digest. Safe to call daily whatever interval is set in
# Settings → Reminders: the interval is enforced inside the script, and nothing
# is sent while the digest is switched off or there is nothing outstanding.
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
0 7 * * * ${WEB_USER} ${PHP_BIN} ${INSTALL_DIR}/bin/reminders.php
CRON
    chmod 644 "$cron_file"
    ok "Wrote $cron_file (07:00 daily)"
fi

# ---------------------------------------------------------------------------
# 15. Verify
# ---------------------------------------------------------------------------
step "Checking it over"

php_app bin/console.php doctor || warn "The checks above found something worth fixing."

if [ "$WEB_SERVER" != none ] && have curl; then
    health_url="http://127.0.0.1:${HTTP_PORT}/health"
    [ "$TLS_MODE" = direct-https ] && health_url="https://127.0.0.1/health"

    curl_args=(-fsS --max-time 10)
    [ "$TLS_MODE" = direct-https ] && curl_args+=(-k)
    [ "$TLS_MODE" = proxy ] && curl_args+=(-H 'X-Forwarded-Proto: https')
    [ -n "$SERVER_NAME" ] && curl_args+=(-H "Host: ${SERVER_NAME}")

    if response="$(curl "${curl_args[@]}" "$health_url" 2>/dev/null)"; then
        ok "GET /health -> $response"
    else
        warn "GET $health_url did not answer as expected."
        warn "Check the web server log and 'systemctl status ${APACHE_SVC}'."
    fi
fi

# ---------------------------------------------------------------------------
# 16. Done
# ---------------------------------------------------------------------------
say ""
say "${C_GREEN}${C_BOLD}Installed.${C_RESET}"
say ""
say "  Site           ${APP_URL}/login"
say "  Sign in as     ${ADMIN_EMAIL}"
say "  Files          ${INSTALL_DIR}"
say "  Document root  ${INSTALL_DIR}/public"
say "  Database       ${DB_NAME} (user ${DB_USER}@localhost)"
say "  Uploads/logs   ${INSTALL_DIR}/storage"
say ""

if [ "$DB_PASSWORD_GENERATED" = yes ]; then
    say "  The database password was generated and written to ${INSTALL_DIR}/.env."
    say "  It is not stored anywhere else — back that file up."
    say ""
fi

say "  Day-to-day administration:"
say "    sudo tracker status            # anywhere on the system"
say "    sudo ${INSTALL_DIR}/manage.sh status"
say "    sudo ${INSTALL_DIR}/manage.sh reset-password ${ADMIN_EMAIL}"
say "    sudo ${INSTALL_DIR}/manage.sh backup"
say "    sudo ${INSTALL_DIR}/manage.sh help"
say ""

case "$TLS_MODE" in
    proxy)
        say "  ${C_BOLD}This install expects a reverse proxy in front of it.${C_RESET}"
        say "  The proxy must forward Host, X-Forwarded-For and X-Forwarded-Proto: https,"
        say "  or the application will not know it is on HTTPS. TRUSTED_PROXIES is set to"
        say "  '*' in .env — narrow it to the proxy's address if anything else can reach"
        say "  this machine directly."
        ;;
    plain-http)
        say "  ${C_BOLD}There is no TLS on this install.${C_RESET} Passwords cross the network in"
        say "  the clear. Put it behind a proxy or add a certificate before it leaves the LAN."
        ;;
esac

say ""
say "  First things to do once you are signed in:"
say "    1. Settings → Email — the SMTP connection. Nothing can be invited until"
say "       this works, because an invitation is an email."
say "    2. Settings → Logo — yours, for the interface, paperwork and email."
say "    3. Settings → Clients — add the first client and invite their administrator."
say "    4. Settings → Clear Books — connect it, then choose the business, sales"
say "       account code and VAT treatment."
say "    5. Settings → Reminders — switch the digest on if you want it, and tick it"
say "       on your own Email notifications page."
say ""

if [ "$INSTALL_CRON" != yes ]; then
    say "  ${C_DIM}The reminder digest needs one cron entry, which was not installed.${C_RESET}"
    say "  ${C_DIM}Add it later with:  sudo ${INSTALL_DIR}/manage.sh cron-install${C_RESET}"
    say ""
fi

if [ -n "$ANSWERS_FILE" ]; then
    warn "The answers file $ANSWERS_FILE holds passwords. Delete it now."
fi
