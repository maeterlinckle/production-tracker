<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Mail\Mailer;
use App\Models\Client;
use App\Models\Invite;
use App\Models\Role;
use App\Models\User;

/**
 * Creating an account by invitation.
 *
 * Nobody but the account holder ever knows the password: the account is created
 * inactive with a hash of random bytes, and the person invited chooses their own
 * from a single-use link. That removes the two things wrong with an
 * administrator typing a password in — they know it, and it usually arrives by
 * some channel less careful than the one it was chosen in.
 *
 * The result of an invitation is reported honestly. If the email could not go
 * out, the caller is told so and given the link to pass on by hand, rather than
 * the screen saying "invitation sent" about a message that failed.
 */
final class Invitations
{
    /**
     * Invite somebody, creating their account.
     *
     * @param array<int,string> $roleSlugs
     * @return array{user_id:int,sent:bool,invite_url:string}
     */
    public static function invite(string $name, string $email, string $side, ?int $clientId, array $roleSlugs, array $allowedSlugs): array
    {
        $userId = User::createInvited([
            'client_id' => $clientId,
            'side' => $side,
            'name' => $name,
            'email' => $email,
        ]);

        Role::setForUser($userId, $roleSlugs, $allowedSlugs);

        return self::send($userId);
    }

    /**
     * Issue (or re-issue) a link and email it.
     *
     * @return array{user_id:int,sent:bool,invite_url:string}
     */
    public static function send(int $userId): array
    {
        $user = User::find($userId);

        if ($user === null) {
            return ['user_id' => $userId, 'sent' => false, 'invite_url' => ''];
        }

        $token = Invite::issue($userId, (int) Auth::id());
        $url   = absolute_url('/invite/' . $token);

        $company = 'Junction';
        if ($user['client_id'] !== null) {
            $client  = Client::find((int) $user['client_id']);
            $company = $client['name'] ?? 'your company';
        }

        $sent = Mailer::sendTemplate(
            'user_invite',
            (string) $user['email'],
            (string) $user['name'],
            [
                'email'      => (string) $user['email'],
                'role_names' => role_summary(['roles' => Role::slugsForUser($userId)]),
                'company'    => $company,
                'invited_by' => Auth::name(),
                'invite_url' => $url,
                'expires_in' => Invite::LIFETIME_DAYS . ' days',
            ],
            'user',
            $userId
        );

        return ['user_id' => $userId, 'sent' => $sent, 'invite_url' => $url];
    }

    /**
     * The message to show after inviting somebody — the same wording wherever
     * an invitation is sent from, and truthful about whether it actually went.
     *
     * @param array{sent:bool,invite_url:string} $result
     */
    public static function resultMessage(array $result, string $name): string
    {
        if ($result['sent']) {
            return $name . ' has been invited. They have ' . Invite::LIFETIME_DAYS
                . ' days to set a password from the link in their email.';
        }

        return 'The account for ' . $name . ' was created, but the invitation email could not be sent'
            . ' — check Settings → Email. Send them this link instead: ' . $result['invite_url'];
    }
}
