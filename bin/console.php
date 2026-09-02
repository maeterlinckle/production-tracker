<?php

declare(strict_types=1);

/*
 * General admin CLI. Run `php bin/console.php` with no arguments for the
 * command list.
 *
 * This is where anything that touches the database lives, so that manage.sh
 * never writes SQL of its own: every account change, setting and lookup goes
 * through the application's own models, prepared statements and validation.
 * manage.sh handles the things that need root instead — services, ownership,
 * backups and cron.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Capabilities;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Mail\EmailTemplate;
use App\Mail\Mailer;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invite;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ClearBooksClient;
use App\Services\ClearBooksPosting;
use App\Services\PdfService;
use App\Services\Reminders;

function option(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return $default;
}

function cliFlag(array $argv, string $name): bool
{
    return in_array("--{$name}", $argv, true);
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * Read a secret from stdin rather than from the command line.
 *
 * An argument is visible in `ps` and lands in the shell history; a piped value
 * is neither. install.sh uses this for the first administrator's password.
 */
function readStdin(): string
{
    $value = stream_get_contents(STDIN);

    return $value === false ? '' : trim($value);
}

function table(array $headers, array $rows): void
{
    $widths = array_map('strlen', $headers);
    foreach ($rows as $row) {
        foreach ($row as $i => $cell) {
            $widths[$i] = max($widths[$i], strlen((string) $cell));
        }
    }

    $printRow = static function (array $cells) use ($widths): void {
        $parts = [];
        foreach ($cells as $i => $cell) {
            $parts[] = str_pad((string) $cell, $widths[$i]);
        }
        echo rtrim(implode('  ', $parts)) . "\n";
    };

    $printRow($headers);
    echo str_repeat('-', array_sum($widths) + 2 * (count($widths) - 1)) . "\n";
    foreach ($rows as $row) {
        $printRow($row);
    }
    echo "\n" . count($rows) . " row(s).\n";
}

/**
 * Who this process is, for a permissions message that can be acted on.
 *
 * `tracker` runs the console as the web user, but the console can also be run
 * by hand as somebody else — and "not writable" means something quite different
 * depending on which of those you did.
 */
function currentUser(): string
{
    if (function_exists('posix_geteuid')) {
        $uid = posix_geteuid();
        $info = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;

        return is_array($info) ? $info['name'] . " (uid {$uid})" : "uid {$uid}";
    }

    return (string) (getenv('USERNAME') ?: getenv('USER') ?: 'this user');
}

/** "owned by www-data:www-data, mode 0755" — the two facts a fix needs. */
function describeOwnership(string $path): string
{
    $name = static function (?int $id, string $function): string {
        if ($id === null) {
            return '?';
        }
        $info = function_exists($function) ? $function($id) : false;

        return is_array($info) ? (string) $info['name'] : (string) $id;
    };

    $uid = @fileowner($path);
    $gid = @filegroup($path);
    $mode = @fileperms($path);

    return 'owned by ' . $name($uid === false ? null : $uid, 'posix_getpwuid')
        . ':' . $name($gid === false ? null : $gid, 'posix_getgrgid')
        . ($mode === false ? '' : ', mode ' . substr(sprintf('%o', $mode), -4));
}

/**
 * The largest upload the application offers, and which kind it belongs to.
 *
 * The one place that number is worked out. `doctor` compares PHP's limits
 * against it, and `install.sh` and `tracker php-limits` set PHP's limits from
 * it — all three have to agree, and they only do if they ask the same question
 * of the same config.
 *
 * @return array{0:int,1:string} bytes, and the kind that is largest
 */
function largestAllowedUpload(): array
{
    $largest = 0;
    $kindName = '';

    foreach ((array) Config::get('uploads', []) as $kind => $rules) {
        $bytes = (int) ($rules['max_bytes'] ?? 0);
        if ($bytes > $largest) {
            $largest = $bytes;
            $kindName = (string) $kind;
        }
    }

    return [$largest, $kindName];
}

/**
 * Where PHP is reading its settings from.
 *
 * A limit that will not move is nearly always a second file setting it again
 * further down the list, so the fix starts with knowing which files are in
 * play — and whether ours is among them at all.
 */
function iniSourceHint(): string
{
    $loaded = php_ini_loaded_file();
    $scanned = array_filter(array_map('trim', explode(',', (string) php_ini_scanned_files())));
    $ours = array_values(array_filter($scanned, static fn (string $file): bool
        => str_contains($file, '99-production-tracker.ini')));

    $hint = 'PHP is reading ' . ($loaded === false ? 'no php.ini' : $loaded);
    $hint .= $scanned === [] ? ' and no scanned files' : ' and ' . count($scanned) . ' scanned file(s)';

    return $hint . ($ours === []
        ? '; none of them is 99-production-tracker.ini'
        : '; including ' . $ours[0]);
}

/**
 * The state of the PDF font cache, as a [status, detail] pair for `doctor`.
 *
 * "Writable" is only meaningful together with *who is asking*. `tracker` runs
 * this as the web user, which is the account whose answer matters — but run the
 * console by hand as yourself and the same healthy directory reads as
 * unwritable, because it belongs to the web server and you are not in its
 * group. Reporting that as a failure would send somebody to fix a working
 * installation, so it is a warning that says how to check it properly.
 *
 * @return array{0:string,1:string}
 */
function fontCacheCheck(string $path): array
{
    if (!is_dir($path)) {
        return ['fail', $path . ' does not exist, so every PDF re-parses every font.'
            . ' Create it with "sudo tracker permissions"'];
    }

    if (is_writable($path)) {
        return PdfService::fontCacheIsWarm()
            ? ['ok', 'warm']
            : ['warn', 'writable but empty — run "tracker pdf-warm"; until then every PDF is about a second slower'];
    }

    $owner = @fileowner($path);
    $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $somebodyElsesDirectory = $euid !== null && $euid !== 0 && $owner !== false && $owner !== $euid;

    if ($somebodyElsesDirectory) {
        return ['warn', 'cannot be checked as ' . currentUser() . ' — it is ' . describeOwnership($path)
            . '. Run "sudo tracker doctor" to check it as the web server does'];
    }

    return ['fail', $path . ' is not writable, so every PDF re-parses every font. It is '
        . describeOwnership($path) . ', and this check ran as ' . currentUser()
        . '. Fix it with "sudo tracker permissions"'
        // Without the posix extension there is no way to ask which user this
        // is, so the reassurance above cannot be offered — say so rather than
        // sending somebody to fix something that may not be broken.
        . ($euid === null ? ', or run "sudo tracker doctor" if you are not the web server user' : '')];
}

// ---------------------------------------------------------------------------
// Checking
// ---------------------------------------------------------------------------

function cmdDoctor(array $argv): int
{
    $checks = [];
    $failed = 0;

    $checks[] = ['PHP version', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail', PHP_VERSION];

    foreach (['pdo_mysql', 'json', 'mbstring', 'fileinfo', 'gd', 'curl', 'openssl'] as $ext) {
        $checks[] = ["ext-{$ext}", extension_loaded($ext) ? 'ok' : 'fail', ''];
    }

    $checks[] = ['APP_KEY set', Config::get('app.key') !== '' ? 'ok' : 'warn', Config::get('app.key') === '' ? 'run key:generate' : ''];
    $checks[] = ['APP_URL set', Config::get('app.url') !== '' ? 'ok' : 'warn', (string) Config::get('app.url')];

    try {
        Database::connection();
        $checks[] = ['Database connection', 'ok', (string) Config::get('database.database')];

        $applied = (int) Database::scalar('SELECT COUNT(*) FROM migrations');
        $onDisk = count(glob(Config::get('app.root') . '/database/migrations/*.sql') ?: []);
        $checks[] = [
            'Migrations',
            $applied >= $onDisk ? 'ok' : 'warn',
            "{$applied} applied of {$onDisk} on disk" . ($applied < $onDisk ? ' — run migrate' : ''),
        ];

        $staff = (int) Database::scalar(
            "SELECT COUNT(*) FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
              WHERE r.slug = 'staff.admin' AND u.is_active = 1"
        );
        $checks[] = ['Active staff admins', $staff > 0 ? 'ok' : 'fail', (string) $staff];
    } catch (\Throwable $e) {
        $checks[] = ['Database connection', 'fail', $e->getMessage()];
    }

    foreach (['storage.uploads', 'storage.logs'] as $key) {
        $path = (string) Config::get($key);
        $checks[] = [$key, is_dir($path) && is_writable($path) ? 'ok' : 'fail', $path];
    }

    /*
     * Can PHP actually accept what the application says it allows?
     *
     * These are two separate settings that have to agree and nothing kept them
     * in step: the app offered a 50 MB tooling file while post_max_size was
     * 27 MB, and the upload died in PHP before any application code ran. The
     * body is discarded when that happens, so the CSRF check saw no token and
     * reported an expired session — which is why the symptom looked nothing
     * like a size limit.
     *
     * post_max_size carries the whole form, so it needs room for the largest
     * single file plus the other fields, and ideally for more than one file at
     * a time on the screens that accept several.
     */
    [$largest, $largestKind] = largestAllowedUpload();

    $mb = static fn (int $bytes): string => rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . 'M';
    $perFile = Request::iniBytes('upload_max_filesize');
    $wholeBody = Request::iniBytes('post_max_size');

    $checks[] = [
        'upload_max_filesize',
        $perFile >= $largest ? 'ok' : 'fail',
        $mb($perFile) . ' — the app allows ' . $mb($largest) . " ({$largestKind})"
            . ($perFile >= $largest
                ? ''
                : '; raise it or PHP refuses the file before the app sees it.'
                    . ' Run "sudo tracker php-limits". ' . iniSourceHint()),
    ];

    $checks[] = [
        'post_max_size',
        $wholeBody > $largest ? 'ok' : 'fail',
        $mb($wholeBody) . ' — must exceed ' . $mb($largest) . ' to carry the file plus the rest of the form'
            . ($wholeBody > $largest
                ? ''
                : '; below this an upload fails as "session expired". Run "sudo tracker php-limits"'),
    ];

    $checks[] = ['Composer packages', class_exists(\PHPMailer\PHPMailer\PHPMailer::class) ? 'ok' : 'warn',
        class_exists(\PHPMailer\PHPMailer\PHPMailer::class) ? '' : 'run composer install — email and PDFs need it'];

    // A cold font cache is the difference between a PDF appearing and a PDF
    // appearing eventually: dompdf re-parses every font on every request until
    // it can write the parsed metrics down. See App\Services\PdfService.
    $fontCache = PdfService::fontCachePath();
    $checks[] = array_merge(['PDF font cache'], fontCacheCheck($fontCache));

    $mailProblems = Mailer::problems();
    $checks[] = ['Email', Mailer::isReady() ? 'ok' : 'warn',
        Mailer::isReady() ? 'ready' : ($mailProblems === [] ? 'switched off in Settings' : implode(' ', $mailProblems))];

    $cbProblems = ClearBooksClient::problems();
    $checks[] = ['Clear Books', $cbProblems === [] ? 'ok' : 'warn',
        $cbProblems === [] ? 'ready' : implode(' ', $cbProblems)];

    $checks[] = ['Reminders', Reminders::isEnabled() ? 'ok' : 'warn',
        Reminders::isEnabled() ? 'on, every ' . Reminders::intervalDays() . ' day(s)' : 'off'];

    foreach ($checks as $check) {
        if ($check[1] === 'fail') {
            $failed++;
        }
    }

    table(['Check', 'Status', 'Detail'], $checks);

    return $failed === 0 ? 0 : 1;
}

/**
 * Build the PDF font cache.
 *
 * Run after an install or an update so the first person to open a route card
 * is not the one who waits for it. Idempotent and quick once warm.
 */
function cmdPdfWarm(array $argv): int
{
    $started = microtime(true);
    $warm = PdfService::warmFontCache();
    $ms = (int) round((microtime(true) - $started) * 1000);

    if (!$warm) {
        $cache = PdfService::fontCachePath();
        fail('Could not write the font cache at ' . $cache . '.'
            . (is_dir($cache) ? ' It is ' . describeOwnership($cache) . ',' : ' It does not exist,')
            . ' and this ran as ' . currentUser() . '.'
            . ' Run "sudo tracker permissions" to put it right — until it is writable'
            . ' every PDF re-parses every font.');
    }

    echo "PDF font cache warm ({$ms} ms). " . PdfService::fontCachePath() . "\n";

    return 0;
}

/**
 * The PHP limits this application needs, in megabytes: per file, then per request.
 *
 * Printed as two plain numbers because the callers are shell scripts —
 * `install.sh` and `tracker php-limits` both write PHP's ini from this, so the
 * limits PHP enforces and the limits the application offers come from the same
 * config rather than from a number copied into a script and left behind.
 *
 * The headroom on the second one is the point of it: post_max_size carries the
 * whole form, not just the file, and several of the upload screens take more
 * than one file at a time.
 */
function cmdUploadLimits(array $argv): int
{
    [$largest] = largestAllowedUpload();

    $perFileMb = max(1, (int) ceil($largest / 1048576));

    echo $perFileMb . ' ' . ($perFileMb + 32) . "\n";

    return 0;
}

function cmdStats(array $argv): int
{
    $count = static fn (string $table): int => (int) Database::scalar("SELECT COUNT(*) FROM {$table}");

    table(['Metric', 'Count'], [
        ['Clients', $count('clients')],
        ['Users', $count('users')],
        ['Pending invitations', (int) Database::scalar('SELECT COUNT(*) FROM user_invites WHERE accepted_at IS NULL AND expires_at > NOW()')],
        ['Parts', $count('parts')],
        ['Orders', $count('orders')],
        ['Order lines outstanding', (int) Database::scalar('SELECT COUNT(*) FROM order_lines WHERE qty_completed + qty_cancelled < qty_ordered')],
        ['Quantity failed and to remake', (int) Database::scalar('SELECT COALESCE(SUM(qty_failed), 0) FROM order_lines')],
        ['Change requests awaiting a decision', (int) Database::scalar("SELECT COUNT(*) FROM order_line_change_requests WHERE status = 'pending'")],
        ['Delivery notes', $count('delivery_notes')],
        ['Invoices raised', $count('invoices')],
        ['Emails sent', (int) Database::scalar("SELECT COUNT(*) FROM email_log WHERE status = 'sent'")],
        ['Emails failed', (int) Database::scalar("SELECT COUNT(*) FROM email_log WHERE status = 'failed'")],
    ]);

    return 0;
}

function cmdKeyGenerate(array $argv): int
{
    echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . "\n";
    echo "Add this to your .env file.\n";

    return 0;
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------

function cmdUserList(array $argv): int
{
    $activeOnly = cliFlag($argv, 'active-only');

    $sql = 'SELECT u.id, u.side, u.name, u.email, u.is_active, u.password_set_at, u.last_login_at, c.name AS client_name
              FROM users u
         LEFT JOIN clients c ON c.id = u.client_id';
    if ($activeOnly) {
        $sql .= ' WHERE u.is_active = 1';
    }
    $sql .= ' ORDER BY u.side, u.name';

    $rows = Database::all($sql);

    table(
        ['ID', 'Side', 'Name', 'Email', 'Client', 'Roles', 'Status', 'Last sign-in'],
        array_map(static fn (array $r): array => [
            $r['id'],
            $r['side'],
            $r['name'],
            $r['email'],
            $r['client_name'] ?? '—',
            implode(',', Role::slugsForUser((int) $r['id'])) ?: '—',
            $r['password_set_at'] === null ? 'invited' : ((bool) $r['is_active'] ? 'active' : 'inactive'),
            $r['last_login_at'] ?? 'never',
        ], $rows)
    );

    return 0;
}

/**
 * Create a staff account with a password, for the installer.
 *
 * Every other account is created by invitation from the interface — this is the
 * one path that sets a password directly, because the first administrator has
 * nobody to invite them.
 */
function cmdUserCreate(array $argv): int
{
    $name = option($argv, 'name') ?? fail('Usage: user:create --name= --email= [--roles=staff.admin] --stdin-password');
    $email = option($argv, 'email') ?? fail('An --email is required.');
    $roles = explode(',', (string) option($argv, 'roles', 'staff.admin'));

    $password = cliFlag($argv, 'stdin-password') ? readStdin() : (string) option($argv, 'password', '');

    if (strlen($password) < 12) {
        fail('The password must be at least 12 characters.');
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        fail("'{$email}' is not a valid email address.");
    }

    if (User::emailExists($email)) {
        fail("A user with the email {$email} already exists. Use user:password to reset it instead.");
    }

    $allowed = array_column(Role::forSide('staff'), 'slug');
    $roles = array_values(array_intersect(array_map('trim', $roles), $allowed));

    if ($roles === []) {
        fail('None of those roles exist. Valid staff roles: ' . implode(', ', $allowed));
    }

    $userId = User::create([
        'client_id' => null,
        'side' => 'staff',
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'is_active' => 1,
    ]);

    User::updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT));
    Role::setForUser($userId, $roles, $allowed);

    echo "Created staff user {$email} (#{$userId}) with roles: " . implode(', ', $roles) . "\n";

    return 0;
}

function cmdUserPassword(array $argv): int
{
    $email = option($argv, 'email') ?? fail('Usage: user:password --email=');

    $user = User::findByEmail($email);
    if ($user === null) {
        fail("No user with email {$email}");
    }

    if (cliFlag($argv, 'stdin-password')) {
        $password = readStdin();
        if (strlen($password) < 12) {
            fail('The password must be at least 12 characters.');
        }
        $printed = '(read from stdin)';
    } else {
        $password = bin2hex(random_bytes(9));
        $printed = $password;
    }

    User::updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
    User::setActive((int) $user['id'], true);
    Database::run('DELETE FROM login_attempts WHERE email = :email', ['email' => $email]);

    echo "Password reset for {$email}, account activated and any lockout cleared.\n";
    echo "Password: {$printed}\n";

    return 0;
}

function cmdUserRoles(array $argv): int
{
    $email = option($argv, 'email') ?? fail('Usage: user:roles --email= --roles=staff.quoting,staff.production');
    $slugs = option($argv, 'roles');

    $user = User::findByEmail($email);
    if ($user === null) {
        fail("No user with email {$email}");
    }

    // The side is fixed on the account: a client user can never be given staff
    // roles from here, whatever is typed.
    $allowed = array_column(Role::forSide((string) $user['side']), 'slug');

    if ($slugs === null) {
        echo "Roles for {$email}: " . (implode(', ', Role::slugsForUser((int) $user['id'])) ?: '(none)') . "\n";
        echo 'Available for a ' . $user['side'] . " account: " . implode(', ', $allowed) . "\n";

        return 0;
    }

    $requested = array_values(array_intersect(array_map('trim', explode(',', $slugs)), $allowed));

    if ($requested === []) {
        fail('None of those roles exist for a ' . $user['side'] . ' account. Valid: ' . implode(', ', $allowed));
    }

    Role::setForUser((int) $user['id'], $requested, $allowed);
    echo "Roles for {$email} are now: " . implode(', ', $requested) . "\n";

    return 0;
}

function cmdUserActivate(array $argv): int
{
    return setActive($argv, true);
}

function cmdUserDeactivate(array $argv): int
{
    return setActive($argv, false);
}

function setActive(array $argv, bool $active): int
{
    $email = option($argv, 'email') ?? fail('An --email is required.');

    $user = User::findByEmail($email);
    if ($user === null) {
        fail("No user with email {$email}");
    }

    // Locking out the last administrator is easy to do by accident and
    // impossible to undo from the interface.
    if (!$active && in_array('staff.admin', Role::slugsForUser((int) $user['id']), true)) {
        $others = (int) Database::scalar(
            "SELECT COUNT(*) FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
              WHERE r.slug = 'staff.admin' AND u.is_active = 1 AND u.id <> :id",
            ['id' => (int) $user['id']]
        );

        if ($others === 0) {
            fail('That is the only active staff administrator — deactivating it would lock everyone out of Settings.');
        }
    }

    User::setActive((int) $user['id'], $active);
    echo ($active ? 'Activated ' : 'Deactivated ') . $email . "\n";

    return 0;
}

function cmdUnlock(array $argv): int
{
    $email = option($argv, 'email');

    if ($email !== null) {
        Database::run('DELETE FROM login_attempts WHERE succeeded = 0 AND email = :email', ['email' => $email]);
        echo "Unlocked {$email}.\n";
    } else {
        Database::run('DELETE FROM login_attempts WHERE succeeded = 0');
        echo "Cleared all lockouts.\n";
    }

    return 0;
}

/**
 * Issue an invitation link without sending it.
 *
 * The link is printed rather than emailed, which is what makes this usable when
 * email is the thing that is broken.
 */
function cmdUserInvite(array $argv): int
{
    $email = option($argv, 'email') ?? fail('Usage: user:invite --email=');

    $user = User::findByEmail($email);
    if ($user === null) {
        fail("No user with email {$email}. Create the account from the interface first, or use user:create for a staff account.");
    }

    if (User::hasPassword((int) $user['id'])) {
        fail("{$email} has already set a password. Use user:password to reset it instead.");
    }

    $token = Invite::issue((int) $user['id'], (int) $user['id']);

    echo "A fresh invitation link for {$email}, valid for " . Invite::LIFETIME_DAYS . " days:\n\n";
    echo '  ' . rtrim((string) Config::get('app.url'), '/') . '/invite/' . $token . "\n\n";
    echo "Any earlier link for this account has been expired.\n";

    return 0;
}

// ---------------------------------------------------------------------------
// Application settings
// ---------------------------------------------------------------------------

function cmdSettings(array $argv): int
{
    $rows = Database::all('SELECT setting_key, setting_value FROM settings ORDER BY setting_key');

    table(['Key', 'Value'], array_map(static function (array $r): array {
        // Never print a secret, even to a root shell — it ends up in scrollback
        // and in whatever is recording the session.
        $secret = str_contains($r['setting_key'], 'password') || str_contains($r['setting_key'], 'secret');

        return [
            $r['setting_key'],
            $secret ? '(set — hidden)' : (string) ($r['setting_value'] ?? ''),
        ];
    }, $rows));

    return 0;
}

function cmdSettingSet(array $argv): int
{
    $key = option($argv, 'key') ?? fail('Usage: setting:set --key= --value=');
    $value = option($argv, 'value');

    Setting::put($key, $value === '' ? null : $value);
    echo "Set {$key}.\n";

    return 0;
}

function cmdRoles(array $argv): int
{
    $rows = [];

    foreach (Role::all() as $role) {
        $capabilities = [];
        foreach (array_keys(Capabilities::MATRIX) as $capability) {
            if (Capabilities::allows([$role['slug']], $capability)) {
                $capabilities[] = $capability;
            }
        }

        $rows[] = [$role['side'], $role['slug'], $role['name'], implode(' ', $capabilities)];
    }

    table(['Side', 'Slug', 'Name', 'Capabilities'], $rows);

    return 0;
}

// ---------------------------------------------------------------------------
// Email
// ---------------------------------------------------------------------------

function cmdMailStatus(array $argv): int
{
    $problems = Mailer::problems();

    table(['Setting', 'Value'], [
        ['Enabled', Mailer::isEnabled() ? 'yes' : 'no'],
        ['Host', Mailer::setting('host', 'host') ?: '(not set)'],
        ['Port', Mailer::setting('port', 'port', '587')],
        ['Encryption', Mailer::setting('encryption', 'encryption', 'tls')],
        ['Username', Mailer::setting('username', 'username') ?: '(none)'],
        ['Password', Mailer::passwordSource()],
        ['From', Mailer::setting('from_address', 'from_address') ?: '(not set)'],
        ['Ready', Mailer::isReady() ? 'yes' : 'no'],
        ['Problems', $problems === [] ? 'none' : implode(' ', $problems)],
        ['Templates edited', (string) EmailTemplate::customisedCount()],
    ]);

    $log = EmailLog::recent(10);

    if ($log !== []) {
        echo "\nRecent send attempts\n\n";
        table(['When', 'To', 'Subject', 'Status', 'Error'], array_map(static fn (array $r): array => [
            $r['sent_at'],
            $r['to_email'],
            mb_strimwidth((string) $r['subject'], 0, 40, '…'),
            $r['status'],
            mb_strimwidth((string) ($r['error'] ?? ''), 0, 50, '…'),
        ], $log));
    }

    return Mailer::isReady() ? 0 : 1;
}

function cmdMailTest(array $argv): int
{
    $to = option($argv, 'to') ?? fail('Usage: mail:test --to=someone@example.com');

    $ok = Mailer::sendTemplate('smtp_test', $to, null, [
        'mail_host' => Mailer::setting('host', 'host'),
        'recipient' => $to,
        'sent_at' => date('j M Y, H:i'),
        'sent_by' => 'the console',
    ]);

    if ($ok) {
        echo "Sent to {$to}. If it does not arrive, check the spam folder, then mail:status.\n";

        return 0;
    }

    $latest = EmailLog::recent(1);
    fwrite(STDERR, 'Failed: ' . ($latest[0]['error'] ?? 'see mail:status for the log.') . "\n");

    return 1;
}

function cmdRemindersRun(array $argv): int
{
    $result = Reminders::run(cliFlag($argv, 'force'));

    if (!$result['ran']) {
        echo $result['reason'], "\n";

        return 0;
    }

    printf(
        "Digest of %d outstanding line(s): sent %d, failed %d.\n",
        $result['items'],
        $result['sent'],
        $result['failed']
    );

    return $result['failed'] > 0 ? 1 : 0;
}

// ---------------------------------------------------------------------------
// Clear Books
// ---------------------------------------------------------------------------

function cmdClearBooksStatus(array $argv): int
{
    $problems = ClearBooksClient::problems();

    table(['Setting', 'Value'], [
        ['Client ID', ClearBooksClient::clientId() !== '' ? '(set)' : '(not set)'],
        ['Client secret', ClearBooksClient::clientSecret() !== '' ? '(set — hidden)' : '(not set)'],
        ['Redirect URI', ClearBooksClient::redirectUri() ?: '(not set)'],
        ['Connected', ClearBooksClient::isConnected() ? 'yes' : 'no'],
        ['Connection problems', $problems === [] ? 'none' : implode(' ', $problems)],
    ]);

    // How an invoice is posted is per client, not per installation: nominal
    // code, VAT and terms all belong to the customer relationship. So "ready to
    // invoice" is a question about a client, and the honest answer is a list.
    echo "\nPosting details, per client\n\n";

    $rows = [];
    $notReady = 0;

    foreach (Client::all(true) as $client) {
        $posting = ClearBooksPosting::fromRow($client);
        $clientProblems = $posting->problems();
        $notReady += $clientProblems === [] ? 0 : 1;

        $rows[] = [
            (string) $client['name'],
            (string) ($posting->businessId ?? '-'),
            (string) ($posting->accountCode ?? '-'),
            $posting->vatTreatment ?: '-',
            $posting->vatRateKey ?: '-',
            $posting->sendDueDate ? $posting->paymentTermsDays . ' days' : 'left to Clear Books',
            $clientProblems === [] ? 'ready' : count($clientProblems) . ' to set',
        ];
    }

    $rows === []
        ? print("  No active clients.\n")
        : table(['Client', 'Business', 'Code', 'VAT treatment', 'VAT rate', 'Due date', 'Status'], $rows);

    echo "\nEndpoints (fixed, from the published Clear Books OpenAPI description)\n\n";
    table(['What', 'URL'], [
        ['Authorisation', ClearBooksClient::AUTHORIZE_URL],
        ['Token', ClearBooksClient::TOKEN_URL],
        ['API', ClearBooksClient::API_BASE],
    ]);

    return $problems === [] && $notReady === 0 ? 0 : 1;
}

// ---------------------------------------------------------------------------

$commands = [
    'doctor' => ['Environment, config, storage and database health check', 'cmdDoctor'],
    'stats' => ['Row counts across the tracker', 'cmdStats'],
    'pdf-warm' => ['Build the PDF font cache so the first PDF is not the slow one', 'cmdPdfWarm'],
    'upload-limits' => ['Print the PHP upload limits this application needs, in MB', 'cmdUploadLimits'],
    'key:generate' => ['Print a fresh APP_KEY for .env', 'cmdKeyGenerate'],

    'user:list' => ['List accounts  [--active-only]', 'cmdUserList'],
    'user:create' => ['Create a staff account  --name= --email= [--roles=] --stdin-password', 'cmdUserCreate'],
    'user:password' => ['Reset a password  --email= [--stdin-password]', 'cmdUserPassword'],
    'user:roles' => ['Show or set roles  --email= [--roles=a,b]', 'cmdUserRoles'],
    'user:activate' => ['Re-enable an account  --email=', 'cmdUserActivate'],
    'user:deactivate' => ['Disable an account  --email=', 'cmdUserDeactivate'],
    'user:invite' => ['Print a fresh invitation link  --email=', 'cmdUserInvite'],
    'unlock' => ['Clear sign-in lockouts  [--email=]', 'cmdUnlock'],
    'roles' => ['List the roles and what each one can do', 'cmdRoles'],

    'settings' => ['Show the application settings', 'cmdSettings'],
    'setting:set' => ['Change one  --key= --value=', 'cmdSettingSet'],

    'mail:status' => ['Mail configuration and the recent send log', 'cmdMailStatus'],
    'mail:test' => ['Send a test message  --to=', 'cmdMailTest'],
    'reminders:run' => ['Run the outstanding-parts digest  [--force]', 'cmdRemindersRun'],

    'clearbooks:status' => ['Clear Books connection and posting settings', 'cmdClearBooksStatus'],
];

$command = $argv[1] ?? '';

if ($command === '' || in_array($command, ['--help', '-h', 'help'], true)) {
    echo "Production Tracker admin console\n\n";
    echo "Usage: php bin/console.php <command> [--flag=value]\n\n";

    foreach ($commands as $name => [$description, $fn]) {
        printf("  %-20s %s\n", $name, $description);
    }

    echo "\nDay-to-day work — orders, parts, pricing — is done in the web interface.\n";
    echo "This is for the things that are awkward or risky without a proper flow.\n";

    exit(0);
}

if (!isset($commands[$command])) {
    fail("Unknown command: {$command}. Run without arguments for the command list.");
}

exit($commands[$command][1]($argv));
