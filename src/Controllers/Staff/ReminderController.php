<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Mail\Mailer;
use App\Models\Setting;
use App\Services\Reminders;

final class ReminderController
{
    public function index(): void
    {
        Auth::authorize('manage_settings');

        View::render('staff/settings/reminders', [
            'title' => 'Reminders',
            'enabled' => Reminders::isEnabled(),
            'intervalDays' => Reminders::intervalDays(),
            'ageingDays' => Reminders::ageingDays(),
            'recipients' => Reminders::recipients(),
            'lastRun' => Reminders::lastRun(),
            'recentRuns' => Reminders::recentRuns(),
            'mailReady' => Mailer::isReady(),
            'templateActive' => Mailer::isTemplateActive(Reminders::KIND),
            'cronCommand' => 'php ' . rtrim(str_replace('\\', '/', (string) Config::get('app.root', '.')), '/') . '/bin/reminders.php',
        ]);
    }

    public function update(): void
    {
        Auth::authorize('manage_settings');

        $enabled = Request::boolean('reminders_enabled');
        $interval = max(1, min(30, (int) Request::post('interval_days', 1)));
        $ageing = max(1, min(365, (int) Request::post('ageing_days', 21)));

        Setting::put('reminders_enabled', $enabled ? '1' : '0');
        Setting::put('reminders_interval_days', (string) $interval);
        Setting::put('reminders_ageing_days', (string) $ageing);

        if ($enabled && Reminders::recipients() === []) {
            Flash::warning('Reminders are on, but nobody has opted in yet — each person ticks '
                . '“the scheduled digest of parts still outstanding” on their own Email notifications page.');
        } else {
            Flash::success('Reminder settings saved.');
        }

        Response::redirect('/staff/settings/email/reminders');
    }

    /** The "send it now" button: same code path as cron, minus the due check. */
    public function runNow(): void
    {
        Auth::authorize('manage_settings');

        $result = Reminders::run(true, (int) Auth::id());

        if (!$result['ran']) {
            Flash::info($result['reason']);
            Response::redirect('/staff/settings/email/reminders');
        }

        if ($result['failed'] > 0) {
            Flash::warning('Digest sent to ' . $result['sent'] . ' of ' . $result['recipients']
                . ' — ' . $result['failed'] . ' failed. The reason is in the email log under Settings → Email.');
        } else {
            Flash::success('Digest of ' . $result['items'] . ' outstanding line(s) sent to '
                . $result['sent'] . ' ' . ($result['sent'] === 1 ? 'person' : 'people') . '.');
        }

        Response::redirect('/staff/settings/email/reminders');
    }
}
