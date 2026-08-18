<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Models\Invite;
use App\Models\User;
use PDO;

/**
 * Accepting an invitation: the one place a password is ever chosen.
 *
 * Reached signed-out, from a link in an email. A token that has been used, has
 * expired, or never existed all produce the same page, deliberately — a
 * different message for "used" would tell somebody holding a stale link that it
 * was real, and there is nothing useful they could do with that.
 */
final class InviteController
{
    public function show(string $token): void
    {
        $invite = Invite::findUsable($token);

        if ($invite === null) {
            $this->renderDeadLink();

            return;
        }

        View::render('auth/invite', [
            'title' => 'Set your password',
            'invite' => $invite,
            'token' => $token,
        ], 'layouts/auth');
    }

    public function accept(string $token): void
    {
        $invite = Invite::findUsable($token);

        if ($invite === null) {
            $this->renderDeadLink();

            return;
        }

        $password = (string) Request::post('password', '');
        $confirm  = (string) Request::post('password_confirm', '');

        $validator = new Validator(['password' => $password]);
        $validator->required('password', 'Password')->minLength('password', 'Password', 10);

        if (!$validator->fails() && $password !== $confirm) {
            Flash::error('The two passwords do not match.');
            Response::redirect('/invite/' . $token);
        }

        if ($validator->fails()) {
            Flash::error(implode(' ', $validator->errors()));
            Response::redirect('/invite/' . $token);
        }

        // One transaction: setting the password, activating the account and
        // spending the invitation are the same act. Half of it happening would
        // leave either a live account with no way in or a spent link on an
        // account nobody can use.
        $userId = (int) $invite['user_id'];
        $hash   = password_hash($password, PASSWORD_DEFAULT);

        Database::transaction(static function (PDO $pdo) use ($userId, $hash, $invite): void {
            $pdo->prepare('UPDATE users SET password_hash = :hash, password_set_at = NOW(), is_active = 1 WHERE id = :id')
                ->execute(['hash' => $hash, 'id' => $userId]);

            $pdo->prepare('UPDATE user_invites SET accepted_at = NOW() WHERE id = :id')
                ->execute(['id' => (int) $invite['id']]);
        });

        Csrf::rotate();
        Auth::login($userId);
        User::touchLogin($userId);

        Flash::success('Welcome. Your password is set and you are signed in.');
        Response::redirect(Auth::isStaff() ? '/staff' : '/');
    }

    private function renderDeadLink(): void
    {
        View::renderError(
            410,
            'This invitation has expired',
            'Invitation links work once and last a limited time. Ask whoever invited you to send a fresh one.'
        );
    }
}
