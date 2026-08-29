<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Client;
use App\Models\User;

final class Auth
{
    private const SESSION_KEY = '__auth_user_id';

    private static ?array $userCache = null;
    private static bool $resolved = false;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return !self::check();
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$userCache;
        }

        self::$resolved = true;
        $id = Session::get(self::SESSION_KEY);

        if ($id === null) {
            return null;
        }

        self::$userCache = User::findActive((int) $id);

        return self::$userCache;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user !== null ? (int) $user['id'] : null;
    }

    public static function name(): string
    {
        return self::user()['name'] ?? 'Guest';
    }

    public static function side(): ?string
    {
        return self::user()['side'] ?? null;
    }

    public static function isStaff(): bool
    {
        return self::side() === 'staff';
    }

    public static function isClient(): bool
    {
        return self::side() === 'client';
    }

    /** @return string[] role slugs the current user holds, e.g. ['client.purchaser'] */
    public static function roles(): array
    {
        return self::user()['roles'] ?? [];
    }

    public static function hasRole(string $slug): bool
    {
        return in_array($slug, self::roles(), true);
    }

    public static function hasAnyRole(array $slugs): bool
    {
        return array_intersect($slugs, self::roles()) !== [];
    }

    public static function can(string $capability): bool
    {
        return Capabilities::allows(self::roles(), $capability);
    }

    /** 403s and exits if the current user lacks $capability. */
    public static function authorize(string $capability): void
    {
        if (self::can($capability)) {
            return;
        }

        if (Request::isAjax() || Request::isJson()) {
            Response::json(['error' => 'You do not have permission to do that.'], 403);
        }

        View::renderError(403, 'Not permitted', 'You do not have permission to do that.');
        exit;
    }

    public static function clientId(): ?int
    {
        $user = self::user();

        return $user !== null && $user['client_id'] !== null ? (int) $user['client_id'] : null;
    }

    /** Returns null on success, or an error message. */
    public static function attempt(string $email, string $password): ?string
    {
        $ip = Request::ip();

        if (LoginThrottle::isLocked($email, $ip)) {
            LoginThrottle::record($email, $ip, false);
            $minutes = (int) Config::get('login_throttle.lockout_minutes', 15);

            return "Too many failed sign-in attempts. Please try again in about {$minutes} minutes.";
        }

        $user = User::findByEmail($email);

        // Always run password_verify, even for a missing account, so response
        // time doesn't leak whether the email exists.
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinOKtQZ8Q1Z1Z1Z1Z1Z1Z1Z1Z1Z1Z1Z1a';
        $valid = password_verify($password, $hash);

        if ($user === null || !$valid) {
            LoginThrottle::record($email, $ip, false);

            return 'Incorrect email or password.';
        }

        if (!(bool) $user['is_active']) {
            LoginThrottle::record($email, $ip, false);

            return 'This account has been deactivated. Please contact Junction.';
        }

        // A client account switched off blocks everybody under it, whatever
        // their own is_active says. Checked here rather than by deactivating
        // each user in turn, so switching the client back on restores exactly
        // who could sign in before — including leaving the people who had been
        // deactivated one at a time deactivated.
        //
        // Same wording as an individual deactivation on purpose: which of the
        // two it is is not the signing-in stranger's business.
        if ($user['client_id'] !== null && !Client::isActive((int) $user['client_id'])) {
            LoginThrottle::record($email, $ip, false);

            return 'This account has been deactivated. Please contact Junction.';
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            User::updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        LoginThrottle::clear($email, $ip);
        self::login((int) $user['id']);
        User::touchLogin((int) $user['id']);

        return null;
    }

    public static function login(int $userId): void
    {
        Session::regenerate();
        Session::put(self::SESSION_KEY, $userId);
        self::$resolved = false;
        self::$userCache = null;
        Csrf::rotate();
    }

    public static function logout(): void
    {
        Session::forget(self::SESSION_KEY);
        self::$resolved = false;
        self::$userCache = null;
        Session::destroy();
    }
}
