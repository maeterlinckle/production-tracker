<?php

declare(strict_types=1);

/*
 * Create (or reset) a Junction staff user.
 *
 *   php bin/create-admin.php
 *   php bin/create-admin.php --name="Nick" --email=nick@junctioninc.co.uk --password=...
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Models\Role;
use App\Models\User;

function option(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return $default;
}

function prompt(string $label, bool $hidden = false): string
{
    echo $label;
    if ($hidden && PHP_OS_FAMILY !== 'Windows') {
        system('stty -echo');
        $value = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $value = trim((string) fgets(STDIN));
    }

    return $value;
}

$name = option($argv, 'name') ?? prompt('Name: ');
$email = option($argv, 'email') ?? prompt('Email: ');
$password = option($argv, 'password') ?? prompt('Password (min 12 characters): ', true);

if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}

$existing = User::findByEmail($email);

if ($existing !== null) {
    User::updatePasswordHash((int) $existing['id'], password_hash($password, PASSWORD_DEFAULT));
    User::setActive((int) $existing['id'], true);
    echo "Existing user found — password reset and account reactivated.\n";
} else {
    $userId = User::create([
        'client_id' => null,
        'side' => 'staff',
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    Role::setForUser($userId, ['staff.admin'], array_column(Role::forSide('staff'), 'slug'));
    echo "Staff user created with the staff.admin role.\n";
}

echo 'Sign in at: ' . Config::get('app.url') . "/login\n";
