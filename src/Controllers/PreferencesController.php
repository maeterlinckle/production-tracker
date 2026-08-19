<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\NotificationTypes;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\NotificationPreference;

/** Per-user notification preferences -- available to any signed-in user, client or staff. */
final class PreferencesController
{
    public function edit(): void
    {
        View::render('preferences/edit', [
            'title' => 'Notification preferences',
            'groups' => NotificationTypes::groupedForUser((string) Auth::side(), Auth::roles()),
            'subscribed' => NotificationPreference::subscribedTypes((int) Auth::id()),
        ]);
    }

    public function update(): void
    {
        $types = Request::post('types', []);
        $types = is_array($types) ? $types : [];

        NotificationPreference::setForUser((int) Auth::id(), $types);

        Flash::success('Notification preferences saved.');
        Response::redirect('/preferences');
    }
}
