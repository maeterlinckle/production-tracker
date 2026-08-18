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
use App\Models\Setting;
use App\Services\ClearBooksClient;
use Throwable;

final class ClearBooksController
{
    public function status(): void
    {
        Auth::authorize('manage_settings');

        $connected = ClearBooksClient::isConnected();

        // The reference lists only exist once there is a token to read them
        // with, and a failure here must not take the settings page down — that
        // is the page you go to in order to fix a broken connection.
        $businesses = $accountCodes = $vatTreatments = $vatRates = [];
        $lookupError = null;

        if ($connected) {
            try {
                $businesses = ClearBooksClient::businesses();
                $accountCodes = ClearBooksClient::salesAccountCodes();
                $vatTreatments = ClearBooksClient::vatTreatments();
                $vatRates = ClearBooksClient::vatRates();
            } catch (Throwable $e) {
                $lookupError = $e->getMessage();
            }
        }

        View::render('staff/settings/clearbooks', [
            'title' => 'Clear Books connection',
            'connected' => $connected,
            'configured' => ClearBooksClient::isConfigured(),
            'problems' => ClearBooksClient::problems(),
            'clientId' => ClearBooksClient::clientId(),
            'redirectUri' => ClearBooksClient::redirectUri(),
            'hasSecret' => ClearBooksClient::clientSecret() !== '',
            'businessId' => ClearBooksClient::businessId(),
            'accountCode' => ClearBooksClient::accountCode(),
            'vatTreatment' => ClearBooksClient::vatTreatment(),
            'vatRateKey' => ClearBooksClient::vatRateKey(),
            'paymentTermsDays' => ClearBooksClient::paymentTermsDays(),
            'businesses' => $businesses,
            'accountCodes' => $accountCodes,
            'vatTreatments' => $vatTreatments,
            'vatRates' => $vatRates,
            'lookupError' => $lookupError,
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

    /**
     * The posting details: which business, which nominal code, which VAT
     * treatment and rate. Separate from the credentials because they can only
     * be chosen once a connection exists to read the lists through.
     */
    public function updatePosting(): void
    {
        Auth::authorize('manage_settings');

        Setting::put('clearbooks_business_id', trim((string) Request::post('business_id', '')) ?: null);
        Setting::put('clearbooks_account_code', trim((string) Request::post('account_code', '')) ?: null);
        Setting::put('clearbooks_vat_treatment', trim((string) Request::post('vat_treatment', '')) ?: null);
        Setting::put('clearbooks_vat_rate_key', trim((string) Request::post('vat_rate_key', '')) ?: null);

        $terms = (int) Request::post('payment_terms_days', 30);
        Setting::put('clearbooks_payment_terms_days', (string) max(0, min(365, $terms)));

        Flash::success('Clear Books posting settings saved.');
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

        Flash::success('Clear Books connected. Choose the business and posting details below.');
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
