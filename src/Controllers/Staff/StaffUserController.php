<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Models\Invite;
use App\Models\Role;
use App\Models\User;
use App\Services\Invitations;

/**
 * Junction's own accounts.
 *
 * Deliberately separate from the client-side team screen: the roles are a
 * different set, and mixing "who at Junction can do what" with "who at Acme can
 * do what" on one page is how somebody ends up granting staff.admin to a
 * customer. Nothing here can create a client-side account, and the client-side
 * screen cannot create one of these.
 */
final class StaffUserController
{
    public function index(): void
    {
        Auth::authorize('manage_settings');

        $users = User::allStaff();

        foreach ($users as &$user) {
            $user['has_password'] = User::hasPassword((int) $user['id']);
            $user['invite'] = $user['has_password'] ? null : Invite::outstandingFor((int) $user['id']);
        }
        unset($user);

        View::render('staff/settings/users', [
            'title' => 'Users',
            'users' => $users,
            'roles' => Role::forSide('staff'),
        ]);
    }

    public function store(): void
    {
        Auth::authorize('manage_settings');

        $name = trim((string) Request::post('name', ''));
        $email = trim((string) Request::post('email', ''));
        $roleSlugs = Request::post('roles', []);
        $roleSlugs = is_array($roleSlugs) ? $roleSlugs : [];

        $validator = new Validator(['name' => $name, 'email' => $email]);
        $validator->required('name', 'Name')->required('email', 'Email')->email('email', 'Email');

        if ($validator->fails()) {
            Flash::error(implode(' ', $validator->errors()));
            Response::redirect('/staff/settings/users');
        }

        if (User::emailExists($email)) {
            Flash::error('A user with that email already exists.');
            Response::redirect('/staff/settings/users');
        }

        if ($roleSlugs === []) {
            Flash::error('Give them at least one role, or the account can sign in and do nothing.');
            Response::redirect('/staff/settings/users');
        }

        $result = Invitations::invite(
            $name,
            $email,
            'staff',
            null,
            $roleSlugs,
            array_column(Role::forSide('staff'), 'slug')
        );

        $result['sent']
            ? Flash::success(Invitations::resultMessage($result, $name))
            : Flash::warning(Invitations::resultMessage($result, $name));

        Response::redirect('/staff/settings/users');
    }

    public function updateRoles(string $id): void
    {
        Auth::authorize('manage_settings');

        $user = $this->findStaffUser((int) $id);
        if ($user === null) {
            return;
        }

        $roleSlugs = Request::post('roles', []);
        $roleSlugs = is_array($roleSlugs) ? $roleSlugs : [];

        // Taking staff.admin off your own account locks you out of this very
        // screen, and with nobody else holding it, out of Settings entirely.
        if ((int) $user['id'] === Auth::id() && !in_array('staff.admin', $roleSlugs, true)) {
            Flash::error('You cannot remove your own administrator role — ask another administrator to do it.');
            Response::redirect('/staff/settings/users');
        }

        Role::setForUser((int) $user['id'], $roleSlugs, array_column(Role::forSide('staff'), 'slug'));

        Flash::success('Roles updated for ' . $user['name'] . '.');
        Response::redirect('/staff/settings/users');
    }

    public function toggleActive(string $id): void
    {
        Auth::authorize('manage_settings');

        $user = $this->findStaffUser((int) $id);
        if ($user === null) {
            return;
        }

        if ((int) $user['id'] === Auth::id()) {
            Flash::error('You cannot deactivate your own account.');
            Response::redirect('/staff/settings/users');
        }

        User::setActive((int) $user['id'], !(bool) $user['is_active']);

        Flash::success(((bool) $user['is_active'] ? 'Deactivated ' : 'Activated ') . $user['name'] . '.');
        Response::redirect('/staff/settings/users');
    }

    public function reinvite(string $id): void
    {
        Auth::authorize('manage_settings');

        $user = $this->findStaffUser((int) $id);
        if ($user === null) {
            return;
        }

        if (User::hasPassword((int) $user['id'])) {
            Flash::error($user['name'] . ' has already set a password, so there is nothing to re-send.');
            Response::redirect('/staff/settings/users');
        }

        $result = Invitations::send((int) $user['id']);

        $result['sent']
            ? Flash::success('A fresh invitation has been sent to ' . $user['email'] . '.')
            : Flash::warning(Invitations::resultMessage($result, (string) $user['name']));

        Response::redirect('/staff/settings/users');
    }

    private function findStaffUser(int $id): ?array
    {
        $user = User::find($id);

        if ($user === null || $user['side'] !== 'staff') {
            View::renderError(404, 'User not found', 'That is not a Junction staff account.');

            return null;
        }

        return $user;
    }
}
