<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Mail\EmailTemplate;
use App\Models\Client;
use App\Models\PartQuote;
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
            'quotingRate' => PartQuote::defaults()['rate'],
            'quotingMarkup' => rtrim(rtrim(number_format(PartQuote::defaults()['markup'], 2, '.', ''), '0'), '.'),
        ]);
    }

    /**
     * The house figures every draft part quote starts from.
     *
     * They live here rather than in config because they are commercial
     * numbers, not deployment ones: the rate moves when the workshop's costs
     * move, and that is a Tuesday afternoon decision, not a redeploy.
     */
    public function quoting(): void
    {
        Auth::authorize('manage_settings');

        View::render('staff/settings/quoting', [
            'title' => 'Quoting',
            'defaults' => PartQuote::defaults(),
            'partsWithDraft' => PartQuote::draftCount(),
            'partsOverriding' => PartQuote::overrideCount(),
        ]);
    }

    public function updateQuoting(): void
    {
        Auth::authorize('manage_settings');

        $rate = Request::post('machine_rate_per_minute', '');
        $markup = Request::post('markup_percent', '');

        if (!is_numeric($rate) || (float) $rate < 0 || !is_numeric($markup) || (float) $markup < 0) {
            Flash::error('Both figures have to be numbers, and neither can be negative.');
            Response::redirect('/staff/settings/quoting');
        }

        PartQuote::saveDefaults((float) $rate, (float) $markup);

        // Every draft that follows the house figures has just changed, and its
        // cached total would otherwise still be yesterday's.
        $touched = PartQuote::recalculateFollowers();

        Flash::success(
            'Quoting figures saved.'
            . ($touched > 0
                ? ' ' . $touched . ' draft ' . ($touched === 1 ? 'quote has' : 'quotes have') . ' moved with them.'
                : '')
        );
        Response::redirect('/staff/settings/quoting');
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
