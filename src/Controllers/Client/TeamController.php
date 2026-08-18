<?php

declare(strict_types=1);

namespace App\Controllers\Client;

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

/** Client-side self-service: a client.admin manages users within their own client only. */
final class TeamController
{
    public function index(): void
    {
        Auth::authorize('manage_client_users');

        $users = User::forClient((int) Auth::clientId());

        foreach ($users as &$user) {
            $user['has_password'] = User::hasPassword((int) $user['id']);
            $user['invite'] = $user['has_password'] ? null : Invite::outstandingFor((int) $user['id']);
        }
        unset($user);

        View::render('team/index', [
            'title' => 'Team',
            'users' => $users,
            'roles' => Role::forSide('client'),
        ]);
    }

    public function store(): void
    {
        Auth::authorize('manage_client_users');
        $clientId = (int) Auth::clientId();

        $name = trim((string) Request::post('name', ''));
        $email = trim((string) Request::post('email', ''));
        $roleSlugs = Request::post('roles', []);
        $roleSlugs = is_array($roleSlugs) ? $roleSlugs : [];

        $validator = new Validator(['name' => $name, 'email' => $email]);
        $validator->required('name', 'Name')->required('email', 'Email')->email('email', 'Email');

        if ($validator->fails()) {
            Flash::error(implode(' ', $validator->errors()));
            Response::redirect('/team');
        }

        if (User::emailExists($email)) {
            Flash::error('A user with that email already exists.');
            Response::redirect('/team');
        }

        // client.admin may only ever grant client.* roles, and only inside their
        // own company — the allowed list is what enforces that, not the form.
        $allowedSlugs = array_column(Role::forSide('client'), 'slug');

        $result = Invitations::invite(
            $name,
            $email,
            'client',
            $clientId,
            $roleSlugs === [] ? ['client.production'] : $roleSlugs,
            $allowedSlugs
        );

        $result['sent']
            ? Flash::success(Invitations::resultMessage($result, $name))
            : Flash::warning(Invitations::resultMessage($result, $name));

        Response::redirect('/team');
    }

    /** Send the link again — the first one lapsed, or never arrived. */
    public function reinvite(string $id): void
    {
        Auth::authorize('manage_client_users');
        $user = $this->findOwnClientUser((int) $id);
        if ($user === null) {
            return;
        }

        if (User::hasPassword((int) $user['id'])) {
            Flash::error($user['name'] . ' has already set a password, so there is nothing to re-send.');
            Response::redirect('/team');
        }

        $result = Invitations::send((int) $user['id']);

        $result['sent']
            ? Flash::success('A fresh invitation has been sent to ' . $user['email'] . '.')
            : Flash::warning(Invitations::resultMessage($result, (string) $user['name']));

        Response::redirect('/team');
    }

    public function updateRoles(string $id): void
    {
        Auth::authorize('manage_client_users');
        $user = $this->findOwnClientUser((int) $id);
        if ($user === null) {
            return;
        }

        $roleSlugs = Request::post('roles', []);
        $roleSlugs = is_array($roleSlugs) ? $roleSlugs : [];
        $allowedSlugs = array_column(Role::forSide('client'), 'slug');

        Role::setForUser($user['id'], $roleSlugs, $allowedSlugs);

        Flash::success('Roles updated for ' . $user['name'] . '.');
        Response::redirect('/team');
    }

    public function toggleActive(string $id): void
    {
        Auth::authorize('manage_client_users');
        $user = $this->findOwnClientUser((int) $id);
        if ($user === null) {
            return;
        }

        if ((int) $user['id'] === Auth::id()) {
            Flash::error('You cannot deactivate your own account.');
            Response::redirect('/team');
        }

        User::setActive($user['id'], !(bool) $user['is_active']);
        Flash::success(((bool) $user['is_active'] ? 'Deactivated ' : 'Activated ') . $user['name'] . '.');
        Response::redirect('/team');
    }

    private function findOwnClientUser(int $id): ?array
    {
        $user = User::find($id);
        if ($user === null || $user['side'] !== 'client' || (int) $user['client_id'] !== Auth::clientId()) {
            View::renderError(404, 'User not found', 'That user does not exist in your company.');

            return null;
        }

        return $user;
    }
}
