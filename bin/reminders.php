<?php

declare(strict_types=1);

/*
 * The scheduled reminder run.
 *
 *   php bin/reminders.php            send if due
 *   php bin/reminders.php --force    send regardless of the interval
 *   php bin/reminders.php --dry-run  say what would happen, send nothing
 *
 * Intended for cron:
 *
 *   0 7 * * * php /path/to/production-tracker/bin/reminders.php
 *
 * Deliberately silent when there is nothing to do. Cron mails the owner
 * anything a job prints, so a script that says "nothing to send" every morning
 * is a script that gets filtered — and then the one run that failed is filtered
 * with it. Output means something happened or something is wrong.
 *
 * Exit codes: 0 for "ran, or correctly did nothing", 1 for a genuine problem,
 * so a monitoring wrapper can tell the difference.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Services\Reminders;

$force  = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun) {
    $due = $force || Reminders::isDue();
    $recipients = Reminders::recipients();
    $lines = \App\Services\PartsOnOrder::lines();

    echo 'Enabled:     ', Reminders::isEnabled() ? 'yes' : 'no', PHP_EOL;
    echo 'Due now:     ', $due ? 'yes' : 'no', PHP_EOL;
    echo 'Interval:    every ', Reminders::intervalDays(), ' day(s)', PHP_EOL;
    echo 'Outstanding: ', count($lines), ' line(s)', PHP_EOL;
    echo 'Recipients:  ', count($recipients), PHP_EOL;

    foreach ($recipients as $user) {
        echo '  - ', $user['name'], ' <', $user['email'], '>', PHP_EOL;
    }

    exit(0);
}

try {
    $result = Reminders::run($force);
} catch (Throwable $e) {
    fwrite(STDERR, 'Reminder run failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$result['ran']) {
    // Only worth saying out loud when somebody asked for it explicitly.
    if ($force) {
        echo $result['reason'], PHP_EOL;
    }

    exit(0);
}

if ($result['failed'] > 0) {
    fwrite(STDERR, sprintf(
        "Digest of %d line(s): sent %d, FAILED %d. See the email log.\n",
        $result['items'],
        $result['sent'],
        $result['failed']
    ));

    exit(1);
}

echo sprintf("Digest of %d outstanding line(s) sent to %d recipient(s).\n", $result['items'], $result['sent']);
exit(0);
