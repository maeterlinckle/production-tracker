<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Mail\Mailer;
use App\Models\NotificationPreference;
use App\Models\Setting;
use App\Models\User;

/**
 * The scheduled digest of parts still outstanding.
 *
 * Driven by cron rather than by anything inside a request: `bin/reminders.php`
 * is the entry point, and the schedule lives in crontab where the rest of the
 * server's scheduling lives. What this class owns is *whether* to send on a
 * given run, who to, and what goes in it.
 *
 * Three rules that between them stop this becoming the email everybody filters
 * into a folder:
 *
 *   - it goes only to staff who ticked it on their own preferences page;
 *   - it is skipped entirely when there is nothing outstanding — an empty
 *     digest teaches people the message is not worth opening;
 *   - it will not send twice in the same interval, so an extra cron entry or a
 *     re-run after a failure does not double up.
 */
final class Reminders
{
    public const KIND = 'parts_outstanding';

    /** Sending is off until somebody turns it on: a fresh install emails nobody. */
    public static function isEnabled(): bool
    {
        return Setting::bool('reminders_enabled', false);
    }

    /**
     * How often, in days. 1 is a daily digest; 7 is a Monday-morning summary
     * if that is the day cron happens to fire it on.
     */
    public static function intervalDays(): int
    {
        return max(1, min(30, (int) (Setting::get('reminders_interval_days', '1') ?? '1')));
    }

    /** Lines open longer than this are called out separately in the digest. */
    public static function ageingDays(): int
    {
        return max(1, min(365, (int) (Setting::get('reminders_ageing_days', '21') ?? '21')));
    }

    /** Should a run happening right now actually send? */
    public static function isDue(): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        $last = self::lastRun();

        if ($last === null) {
            return true;
        }

        // A few minutes' grace, so a daily cron at 07:00 is not skipped because
        // yesterday's run started at 07:00:04.
        $nextDue = strtotime($last['ran_at']) + (self::intervalDays() * 86400) - 300;

        return time() >= $nextDue;
    }

    /** @return array<string,mixed>|null */
    public static function lastRun(): ?array
    {
        return Database::one(
            'SELECT * FROM reminder_runs WHERE kind = :kind ORDER BY ran_at DESC LIMIT 1',
            ['kind' => self::KIND]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentRuns(int $limit = 10): array
    {
        return Database::all(
            'SELECT r.*, u.name AS triggered_by_name
               FROM reminder_runs r
          LEFT JOIN users u ON u.id = r.triggered_by
              WHERE r.kind = :kind
           ORDER BY r.ran_at DESC
              LIMIT ' . (int) $limit,
            ['kind' => self::KIND]
        );
    }

    /**
     * Junction staff who asked for this digest.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recipients(): array
    {
        return array_values(array_filter(
            User::allStaff(),
            static fn (array $user): bool => (bool) $user['is_active']
                && NotificationPreference::isSubscribed((int) $user['id'], self::KIND)
        ));
    }

    /**
     * Send the digest.
     *
     * `$force` is what the "send it now" button on the settings screen passes:
     * it skips the due check but not the "is there anything to say" check, and
     * it still records the run so the schedule moves on with it.
     *
     * @return array{ran:bool,reason:string,recipients:int,items:int,sent:int,failed:int}
     */
    public static function run(bool $force = false, ?int $triggeredBy = null): array
    {
        $skip = static fn (string $reason): array => [
            'ran' => false, 'reason' => $reason, 'recipients' => 0, 'items' => 0, 'sent' => 0, 'failed' => 0,
        ];

        if (!self::isEnabled() && !$force) {
            return $skip('Reminders are switched off.');
        }

        if (!$force && !self::isDue()) {
            return $skip('Not due yet — the last run was less than ' . self::intervalDays() . ' day(s) ago.');
        }

        if (!Mailer::isReady()) {
            return $skip('Email is not configured, so nothing could be sent.');
        }

        if (!Mailer::isTemplateActive(self::KIND)) {
            return $skip('The “parts outstanding” template is switched off in Settings → Email templates.');
        }

        $lines = PartsOnOrder::lines();

        if ($lines === []) {
            // Deliberately not recorded as a run: nothing was sent, and marking
            // it would push the next genuine digest a whole interval away.
            return $skip('Nothing is outstanding, so no digest was sent.');
        }

        $recipients = self::recipients();

        if ($recipients === []) {
            return $skip('Nobody at Junction has opted in to this digest yet.');
        }

        $ageingDays = self::ageingDays();
        $totals = PartsOnOrder::totals($lines, $ageingDays);
        $fields = self::fields($lines, $totals, $ageingDays);

        $sent = $failed = 0;

        foreach ($recipients as $user) {
            $ok = Mailer::sendTemplate(
                self::KIND,
                (string) $user['email'],
                (string) $user['name'],
                $fields
            );

            $ok ? $sent++ : $failed++;
        }

        Database::insert(
            'INSERT INTO reminder_runs (kind, recipients, items, sent, failed, triggered_by)
             VALUES (:kind, :recipients, :items, :sent, :failed, :triggered_by)',
            [
                'kind' => self::KIND,
                'recipients' => count($recipients),
                'items' => count($lines),
                'sent' => $sent,
                'failed' => $failed,
                'triggered_by' => $triggeredBy,
            ]
        );

        return [
            'ran' => true,
            'reason' => '',
            'recipients' => count($recipients),
            'items' => count($lines),
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    /**
     * The merge fields for one digest.
     *
     * @param array<int,array<string,mixed>> $lines
     * @param array<string,int> $totals
     * @return array<string,string>
     */
    private static function fields(array $lines, array $totals, int $ageingDays): array
    {
        // Longest-open first: the digest is a nudge, and the thing most worth
        // nudging about is what has been sitting the longest.
        usort($lines, static fn (array $a, array $b): int => (int) $b['days_open'] <=> (int) $a['days_open']);

        $items = [];
        foreach (array_slice($lines, 0, 40) as $line) {
            $hold = PartsOnOrder::holdReason($line);

            $items[] = $line['order_number'] . '  '
                . $line['cpn'] . ' ' . $line['part_name'] . ' — '
                . $line['client_name'] . ' — '
                . (int) $line['qty_outstanding'] . ' of ' . (int) $line['qty_ordered'] . ' outstanding — '
                . ($hold !== '' ? $hold : (int) $line['days_open'] . ' days');
        }

        if (count($lines) > 40) {
            $items[] = '… and ' . (count($lines) - 40) . ' more on the report.';
        }

        return [
            'count'         => (string) count($lines),
            'items'         => implode("\n", $items),
            'ageing_count'  => (string) $totals['ageing'],
            'ageing_days'   => (string) $ageingDays,
            'ageing_line'   => $totals['ageing'] === 0
                ? ''
                : $totals['ageing'] . ' of these ' . ($totals['ageing'] === 1 ? 'has' : 'have')
                    . ' been open for more than ' . $ageingDays . ' days.',
            'blocked_count' => (string) $totals['blocked'],
            'report_url'    => absolute_url('/staff/reports/parts-on-order'),
        ];
    }
}
