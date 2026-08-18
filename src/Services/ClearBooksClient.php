<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\ClearBooksToken;
use App\Models\Setting;
use DateTimeImmutable;
use RuntimeException;

/**
 * Clear Books REST API client.
 *
 * Written against the published OpenAPI description at
 * https://api.clearbooks.co.uk/spec/v1.yaml (v1.0.0) and the reference at
 * https://api-docs.clearbooks.co.uk/. Everything below — the authorisation and
 * token endpoints, the scope names, the invoice path and every field in the
 * payload — is taken from that spec rather than inferred.
 *
 * Authentication is OAuth 2, authorization-code grant, confidential client.
 * There is no static API key. PKCE is supported by Clear Books and is used
 * here: it costs one hash and removes the class of attack where an intercepted
 * authorisation code is redeemed by somebody else.
 *
 * Two behaviours of theirs shape this class:
 *
 *  - **Refresh tokens are single use.** Every successful refresh returns a new
 *    pair and invalidates the old one, so the store is written on every
 *    exchange and a failed refresh has to surface rather than be retried.
 *  - **One access token per user per application.** Completing the consent
 *    flow a second time silently revokes the first token, so reconnecting is a
 *    deliberate act rather than something to trigger automatically.
 *
 * Requests are rate limited above roughly 5/second and answer 429. Reads retry
 * with exponential backoff; the invoice POST does not, because a retried create
 * is a duplicate invoice.
 */
final class ClearBooksClient
{
    /**
     * Endpoints, from the spec's `servers` block and its OAuth2 security scheme.
     *
     * Constants rather than configuration: these are properties of Clear Books,
     * not of this installation, and an operator who can edit them can only get
     * them wrong. The previous implementation kept them in .env and every one
     * of the values there was a guess.
     */
    public const API_BASE = 'https://api.clearbooks.co.uk/v1';
    public const AUTHORIZE_URL = 'https://secure.clearbooks.co.uk/account/action/oauth/';
    public const TOKEN_URL = 'https://api.clearbooks.co.uk/oauth/token';

    /**
     * The least this application can ask for and still do its job.
     *
     * `businesses:read` is what makes the business picker on the settings page
     * possible; the rest are the invoice itself and the reference data an
     * invoice line has to name.
     */
    public const SCOPES = [
        'businesses:read',
        'accounting.customers:read',
        'accounting.sales:read',
        'accounting.sales:write',
        'accounting.account_codes:read',
        'accounting.vat:read',
    ];

    // -- Configuration -------------------------------------------------------
    // The Settings-table value wins when set, so a staff.admin can configure
    // this without a redeploy; .env is the fallback for a fresh install.

    public static function clientId(): string
    {
        return Setting::get('clearbooks_client_id') ?: (string) Config::get('clearbooks.client_id');
    }

    public static function clientSecret(): string
    {
        return Setting::get('clearbooks_client_secret') ?: (string) Config::get('clearbooks.client_secret');
    }

    public static function redirectUri(): string
    {
        return Setting::get('clearbooks_redirect_uri') ?: (string) Config::get('clearbooks.redirect_uri');
    }

    /**
     * Which Clear Books business to post to.
     *
     * Sent as X-Business-ID. The spec marks it optional — required only for a
     * multi-business authorisation — so an empty value is left off the request
     * entirely rather than sent as 0.
     */
    public static function businessId(): ?int
    {
        $value = Setting::get('clearbooks_business_id');

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * The sales nominal every invoice line is posted to.
     *
     * Required on a line item, and there is no sensible default: which code
     * machining income belongs to is Junction's own chart of accounts. The
     * settings page lists the codes from the API so it can be picked rather
     * than typed.
     */
    public static function accountCode(): ?int
    {
        $value = Setting::get('clearbooks_account_code');

        return $value === null || $value === '' ? null : (int) $value;
    }

    /** VAT treatment for the document, e.g. standard UK sales. Required by the API. */
    public static function vatTreatment(): string
    {
        return (string) (Setting::get('clearbooks_vat_treatment') ?? '');
    }

    /** VAT rate key applied to each line, from /accounting/vatRates/sales. */
    public static function vatRateKey(): string
    {
        return (string) (Setting::get('clearbooks_vat_rate_key') ?? '');
    }

    /** Days from issue to due date on the invoice. */
    public static function paymentTermsDays(): int
    {
        return max(0, (int) (Setting::get('clearbooks_payment_terms_days', '30') ?? '30'));
    }

    public static function isConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '' && self::redirectUri() !== '';
    }

    public static function isConnected(): bool
    {
        return ClearBooksToken::get() !== null;
    }

    /**
     * Everything still standing between this install and a working invoice
     * push. Shown on the settings page, so the answer to "why is the button
     * greyed out" is on the screen rather than in a log.
     *
     * @return array<int,string>
     */
    public static function problems(): array
    {
        $problems = [];

        if (self::clientId() === '' || self::clientSecret() === '') {
            $problems[] = 'No API client ID and secret have been entered.';
        }

        if (self::redirectUri() === '') {
            $problems[] = 'No redirect URI has been set.';
        }

        if (!self::isConnected()) {
            $problems[] = 'Nobody has completed the Clear Books consent flow yet.';
        }

        if (self::accountCode() === null) {
            $problems[] = 'No sales account code has been chosen — an invoice line cannot be posted without one.';
        }

        if (self::vatTreatment() === '') {
            $problems[] = 'No VAT treatment has been chosen.';
        }

        if (self::vatRateKey() === '') {
            $problems[] = 'No VAT rate has been chosen.';
        }

        return $problems;
    }

    public static function isReady(): bool
    {
        return self::problems() === [];
    }

    // -- OAuth ---------------------------------------------------------------

    /**
     * The URL to send the operator to, and the PKCE verifier to keep for the
     * callback.
     *
     * The verifier is returned rather than stored here so the caller can put it
     * in the session alongside the state parameter — the two belong together
     * and both have to survive exactly one round trip.
     *
     * @return array{url:string,code_verifier:string}
     */
    public static function authorizeUrl(string $state): array
    {
        // RFC 7636: 43-128 characters from the unreserved set. 64 hex
        // characters sits comfortably inside that and needs no extra encoding.
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUri(),
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return [
            'url' => self::AUTHORIZE_URL . '?' . $query,
            'code_verifier' => $verifier,
        ];
    }

    public static function exchangeCode(string $code, string $codeVerifier): void
    {
        self::storeTokenResponse(self::httpForm(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri' => self::redirectUri(),
            'code_verifier' => $codeVerifier,
        ]));
    }

    /**
     * Swap the refresh token for a new pair.
     *
     * Clear Books issues single-use refresh tokens, so what comes back must be
     * stored before anything else happens — losing it means going through the
     * browser consent flow again.
     */
    private static function refresh(string $refreshToken): void
    {
        self::storeTokenResponse(self::httpForm(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
        ]));
    }

    private static function storeTokenResponse(array $response): void
    {
        if (!isset($response['access_token'], $response['refresh_token'])) {
            throw new RuntimeException('Clear Books did not return a token pair: ' . json_encode($response));
        }

        $expiresIn = (int) ($response['expires_in'] ?? 3600);

        ClearBooksToken::save(
            (string) $response['access_token'],
            (string) $response['refresh_token'],
            // A minute of headroom, so a request that starts just before expiry
            // does not arrive just after it.
            (new DateTimeImmutable())->modify('+' . max(60, $expiresIn - 60) . ' seconds')
        );
    }

    private static function accessToken(): string
    {
        $token = ClearBooksToken::get();

        if ($token === null) {
            throw new RuntimeException('Clear Books is not connected yet. A staff administrator needs to connect it from Settings.');
        }

        if (strtotime((string) $token['expires_at']) <= time()) {
            self::refresh((string) $token['refresh_token']);
            $token = ClearBooksToken::get();
        }

        return (string) $token['access_token'];
    }

    // -- Reference data ------------------------------------------------------
    // Read by the settings page so the business, nominal code, VAT treatment
    // and VAT rate are picked from live lists rather than typed from memory.

    /** @return array<int,array<string,mixed>> */
    public static function businesses(): array
    {
        return self::httpGet('/businesses');
    }

    /** @return array<int,array<string,mixed>> */
    public static function salesAccountCodes(): array
    {
        return array_values(array_filter(
            self::httpGet('/accounting/accountCodes'),
            static fn (array $code): bool => ($code['sales'] ?? false) === true
        ));
    }

    /** @return array<int,array<string,mixed>> */
    public static function vatTreatments(): array
    {
        return self::httpGet('/accounting/vatTreatments/sales');
    }

    /** @return array<int,array<string,mixed>> */
    public static function vatRates(): array
    {
        $query = self::vatTreatment() !== '' ? ['vatTreatment' => self::vatTreatment()] : [];

        return self::httpGet('/accounting/vatRates/sales', $query);
    }

    /**
     * Find the Clear Books customer a client company maps to.
     *
     * Used by the client page to turn the stored numeric id back into a name,
     * so a mis-typed reference shows up there rather than as a failed invoice
     * weeks later.
     *
     * @return array<string,mixed>|null
     */
    public static function customer(int $customerId): ?array
    {
        try {
            $customer = self::httpGet('/accounting/customers/' . $customerId);
        } catch (RuntimeException) {
            return null;
        }

        return $customer === [] ? null : $customer;
    }

    // -- Invoicing -----------------------------------------------------------

    /**
     * Raise a sales invoice against a Clear Books customer.
     *
     * The payload is the spec's SalesInvoice: `date`, `customerId`,
     * `vatTreatment` and `lineItems` are required, and each line item requires
     * `description`, `unitPrice`, `quantity`, `accountCode` and `vatRateKey`.
     *
     * @param array<int,array{description:string,quantity:int|float,unit_price:float}> $lines
     * @return array{id:string,number:string,amount:float}
     */
    public static function createSalesInvoice(string $customerId, array $lines, string $reference): array
    {
        $problems = self::problems();
        if ($problems !== []) {
            throw new RuntimeException('Clear Books is not ready: ' . implode(' ', $problems));
        }

        if (!ctype_digit($customerId) || (int) $customerId <= 0) {
            throw new RuntimeException(
                "'{$customerId}' is not a Clear Books customer ID. It is the numeric id of the customer record in Clear Books — set it on the client's own page."
            );
        }

        if ($lines === []) {
            throw new RuntimeException('An invoice needs at least one line.');
        }

        $accountCode = (int) self::accountCode();
        $vatRateKey = self::vatRateKey();

        $payload = [
            'date' => date('Y-m-d'),
            'dateDue' => date('Y-m-d', strtotime('+' . self::paymentTermsDays() . ' days')),
            'reference' => $reference,
            'customerId' => (int) $customerId,
            'vatTreatment' => self::vatTreatment(),
            'lineItems' => array_map(static fn (array $line): array => [
                'description' => (string) $line['description'],
                'quantity' => (float) $line['quantity'],
                'unitPrice' => (float) $line['unit_price'],
                'accountCode' => $accountCode,
                'vatRateKey' => $vatRateKey,
            ], array_values($lines)),
        ];

        // Deliberately no retry: a 429 or a timeout on a create is ambiguous,
        // and the wrong guess raises the same invoice twice.
        $response = self::request('POST', '/accounting/sales/invoices', $payload, false);

        return [
            'id' => (string) ($response['id'] ?? ''),
            // formattedDocumentNumber is the human reference Clear Books shows;
            // documentNumber is the raw one. Fall through both.
            'number' => (string) ($response['formattedDocumentNumber']
                ?? $response['documentNumber']
                ?? $response['id']
                ?? ''),
            // gross is read-only and calculated by Clear Books, so it is the
            // authority on what was actually raised — including VAT this
            // application did not work out for itself.
            'amount' => isset($response['gross'])
                ? (float) $response['gross']
                : array_sum(array_map(
                    static fn (array $line): float => (float) $line['quantity'] * (float) $line['unit_price'],
                    $lines
                )),
        ];
    }

    // -- Transport -----------------------------------------------------------

    /**
     * @param array<string,mixed> $query
     * @return array<mixed>
     */
    private static function httpGet(string $path, array $query = []): array
    {
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return self::request('GET', $path);
    }

    /**
     * One API call, with the bearer token and business header attached.
     *
     * @param array<string,mixed>|null $body
     * @return array<mixed>
     */
    private static function request(string $method, string $path, ?array $body = null, bool $retry = true): array
    {
        $headers = [
            'Authorization: Bearer ' . self::accessToken(),
            'Accept: application/json',
        ];

        $businessId = self::businessId();
        if ($businessId !== null) {
            $headers[] = 'X-Business-ID: ' . $businessId;
        }

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        // Up to three attempts, backing off, but only for reads. Clear Books
        // rate limits above about five requests a second and answers 429; their
        // own guidance is exponential backoff.
        $attempts = $retry ? 3 : 1;
        $delay = 1;

        for ($attempt = 1; ; $attempt++) {
            [$status, $decoded, $raw] = self::send($method, self::API_BASE . $path, $headers, $body);

            if ($status === 429 && $attempt < $attempts) {
                sleep($delay);
                $delay *= 2;
                continue;
            }

            if ($status >= 400) {
                throw new RuntimeException(self::describeError($status, $decoded, $raw));
            }

            return is_array($decoded) ? $decoded : [];
        }
    }

    /**
     * The OAuth token endpoint, which is form-encoded and not authenticated by
     * a bearer token — so it does not go through request().
     *
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    private static function httpForm(string $url, array $fields): array
    {
        [$status, $decoded, $raw] = self::send(
            'POST',
            $url,
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            null,
            http_build_query($fields)
        );

        if ($status >= 400) {
            throw new RuntimeException(self::describeError($status, $decoded, $raw));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,string> $headers
     * @param array<string,mixed>|null $jsonBody
     * @return array{0:int,1:mixed,2:string}
     */
    private static function send(string $method, string $url, array $headers, ?array $jsonBody = null, ?string $rawBody = null): array
    {
        $ch = curl_init($url);

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($rawBody !== null) {
            $options[CURLOPT_POSTFIELDS] = $rawBody;
        } elseif ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Clear Books request failed: {$curlError}");
        }

        return [$status, json_decode((string) $body, true), (string) $body];
    }

    /**
     * Turn an error response into something an operator can act on.
     *
     * Clear Books answers with an array of {errorCode, errorMessage}. Repeating
     * their message is the useful part — "customerId does not exist" says what
     * to fix, where "API error 400" does not.
     */
    private static function describeError(int $status, mixed $decoded, string $raw): string
    {
        $detail = '';

        if (is_array($decoded)) {
            $messages = [];

            foreach ($decoded as $error) {
                if (is_array($error) && isset($error['errorMessage'])) {
                    $messages[] = trim((string) ($error['errorCode'] ?? '') . ' ' . (string) $error['errorMessage']);
                }
            }

            // The OAuth endpoint uses the RFC 6749 shape instead.
            if ($messages === [] && isset($decoded['error'])) {
                $messages[] = trim((string) $decoded['error'] . ' ' . (string) ($decoded['error_description'] ?? ''));
            }

            $detail = implode('; ', $messages);
        }

        if ($detail === '') {
            $detail = trim(substr($raw, 0, 400));
        }

        $prefix = match (true) {
            $status === 401 => 'Clear Books rejected the access token (401). Reconnect from Settings.',
            $status === 403 => 'Clear Books refused this action (403). The connected account may lack the scope or the permission.',
            $status === 404 => 'Clear Books could not find that record (404).',
            $status === 422 => 'Clear Books rejected the invoice on a business rule (422).',
            $status === 429 => 'Clear Books is rate limiting this application (429).',
            default => "Clear Books API error ({$status}).",
        };

        return $detail === '' ? $prefix : $prefix . ' ' . $detail;
    }
}
