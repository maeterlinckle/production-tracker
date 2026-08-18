<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Response;
use App\Core\View;
use App\Mail\EmailTemplate;
use App\Models\Client;
use App\Models\User;
use App\Services\Branding;
use App\Services\ClearBooksClient;
use App\Services\Reminders;

final class SettingsController
{
    /**
     * The settings index. One card per area, each carrying enough of its own
     * state ("connected", "3 templates customised") that the page answers
     * "is anything wrong?" without opening every screen underneath it.
     */
    public function index(): void
    {
        Auth::authorize('manage_settings');

        View::render('staff/settings/index', [
            'title' => 'Settings',
            'clearBooksConnected' => ClearBooksClient::isConnected(),
            'hasLogo' => Branding::hasAny(),
            'clientCount' => Client::count(),
            'staffCount' => count(User::allStaff()),
            'customisedTemplates' => EmailTemplate::customisedCount(),
            'remindersEnabled' => Reminders::isEnabled(),
        ]);
    }

    public function branding(): void
    {
        Auth::authorize('manage_settings');

        View::render('staff/settings/branding', [
            'title' => 'Logo',
            'logos' => [
                'light' => Branding::path('light') !== null ? Branding::url('light') : null,
                'dark' => Branding::path('dark') !== null ? Branding::url('dark') : null,
            ],
        ]);
    }

    public function updateLogo(): void
    {
        Auth::authorize('manage_settings');

        $saved = [];
        $failed = [];

        foreach (Branding::VARIANTS as $variant) {
            $result = Branding::acceptUpload($variant);
            if (!$result['provided']) {
                continue;
            }
            if ($result['error'] === null) {
                $saved[] = $variant;
            } else {
                $failed[] = $result['error'];
            }
        }

        foreach ($failed as $problem) {
            Flash::error($problem);
        }

        if ($saved !== []) {
            Flash::success(count($saved) === 2 ? 'Both logos updated.' : 'The ' . $saved[0] . ' mode logo was updated.');
        } elseif ($failed === []) {
            Flash::info('No file was chosen, so nothing changed.');
        }

        Response::redirect('/staff/settings/branding');
    }

    public function removeLogo(string $variant): void
    {
        Auth::authorize('manage_settings');

        if (!in_array($variant, Branding::VARIANTS, true)) {
            View::renderError(404, 'Unknown logo', 'That logo variant does not exist.');

            return;
        }

        Branding::remove($variant);
        Flash::success('The ' . $variant . ' mode logo was removed.');
        Response::redirect('/staff/settings/branding');
    }
}
