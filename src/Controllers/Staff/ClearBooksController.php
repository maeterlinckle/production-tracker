<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\ClearBooksToken;
use App\Models\Client;
use App\Models\Setting;
use App\Services\ClearBooksClient;
use App\Services\ClearBooksPosting;
use Throwable;

final class ClearBooksController
{
    public function status(): void
    {
        Auth::authorize('manage_settings');

        // Which clients could actually be invoiced right now. The connection
        // being healthy is only half the answer since the posting details moved
        // onto the clients themselves, and "connected, ready" on this page while
        // every client is unconfigured would be the more misleading half.
        $clients = [];
        foreach (Client::all(true) as $client) {
            $posting = ClearBooksPosting::fromRow($client);
            $clients[] = [
                'id' => (int) $client['id'],
                'name' => (string) $client['name'],
                'problems' => $posting->problems(),
            ];
        }

        View::render('staff/settings/clearbooks', [
            'title' => 'Clear Books connection',
            'connected' => ClearBooksClient::isConnected(),
            'configured' => ClearBooksClient::isConfigured(),
            'problems' => ClearBooksClient::problems(),
            'clientId' => ClearBooksClient::clientId(),
            'redirectUri' => ClearBooksClient::redirectUri(),
            'hasSecret' => ClearBooksClient::clientSecret() !== '',
            'clients' => $clients,
            'scopes' => ClearBooksClient::SCOPES,
            'authorizeUrl' => ClearBooksClient::AUTHORIZE_URL,
            'apiBase' => ClearBooksClient::API_BASE,
            'token' => ClearBooksToken::get(),
        ]);
    }

    /** The API client credentials — the part that has to exist before connecting. */
    public function update(): void
    {
        Auth::authorize('manage_settings');

        Setting::put('clearbooks_client_id', trim((string) Request::post('client_id', '')) ?: null);

        // Blank means "leave the stored secret alone", so an unrelated edit to
        // the redirect URI cannot silently wipe it.
        $secret = trim((string) Request::post('client_secret', ''));
        if ($secret !== '') {
            Setting::put('clearbooks_client_secret', $secret);
        }

        Setting::put('clearbooks_redirect_uri', trim((string) Request::post('redirect_uri', '')) ?: null);

        Flash::success('Clear Books API client settings saved.');
        Response::redirect('/staff/settings/clearbooks');
    }

    public function connect(): void
    {
        Auth::authorize('manage_settings');

        if (!ClearBooksClient::isConfigured()) {
            Flash::error('Set the Clear Books client ID, secret and redirect URI first.');
            Response::redirect('/staff/settings/clearbooks');
        }

        $state = bin2hex(random_bytes(16));
        $authorize = ClearBooksClient::authorizeUrl($state);

        // The state and the PKCE verifier both have to survive exactly one
        // round trip, and neither is any use without the other.
        Session::put('__clearbooks_oauth_state', $state);
        Session::put('__clearbooks_code_verifier', $authorize['code_verifier']);

        Response::redirect($authorize['url']);
    }

    public function callback(): void
    {
        Auth::authorize('manage_settings');

        $state = (string) Request::query('state', '');
        $expected = Session::pull('__clearbooks_oauth_state');
        $verifier = Session::pull('__clearbooks_code_verifier');

        if ($expected === null || !hash_equals((string) $expected, $state)) {
            Flash::error('Clear Books connection failed: the request could not be verified. Please start again.');
            Response::redirect('/staff/settings/clearbooks');
        }

        // Clear Books reports a refusal by redirecting back with an error
        // rather than a code, and saying so beats "did not return a code".
        $error = (string) Request::query('error', '');
        if ($error !== '') {
            Flash::error('Clear Books refused the connection: ' . $error
                . ((string) Request::query('error_description', '') !== ''
                    ? ' — ' . (string) Request::query('error_description', '')
                    : ''));
            Response::redirect('/staff/settings/clearbooks');
        }

        $code = (string) Request::query('code', '');
        if ($code === '' || $verifier === null) {
            Flash::error('Clear Books did not return an authorization code.');
            Response::redirect('/staff/settings/clearbooks');
        }

        try {
            ClearBooksClient::exchangeCode($code, (string) $verifier);
        } catch (Throwable $e) {
            Flash::error('Could not complete the Clear Books connection: ' . $e->getMessage());
            Response::redirect('/staff/settings/clearbooks');
        }

        Flash::success(
            'Clear Books connected. Each client now needs its own posting details — business, nominal '
            . 'code, VAT treatment and rate — set on that client\'s page before an invoice can be raised for them.'
        );
        Response::redirect('/staff/settings/clearbooks');
    }

    /**
     * Forget the stored tokens.
     *
     * Not a disconnect at their end — the spec gives no revoke endpoint — but
     * it stops this application using a connection somebody wants ended, and
     * re-authorising issues a fresh pair anyway.
     */
    public function disconnect(): void
    {
        Auth::authorize('manage_settings');

        ClearBooksToken::clear();

        Flash::success('The stored Clear Books tokens have been deleted. Connect again when you need to raise an invoice.');
        Response::redirect('/staff/settings/clearbooks');
    }
}
