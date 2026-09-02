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
 * token endpoints, the scope names, the invoice and attachment paths and every
 * field in the payloads — is taken from that spec rather than inferred.
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
 * is a duplicate invoice, and neither does an attachment upload, for the same
 * reason.
 *
 * What this class does *not* hold is how any particular invoice should be
 * posted. Nominal code, VAT treatment, VAT rate, business and payment terms are
 * per client and live on the client's own record — see ClearBooksPosting.
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
     * `businesses:read` is what makes the business picker possible; the rest
     * are the invoice itself and the reference data an invoice line has to
     * name. Attachments need no scope of their own — the spec secures
     * `POST /accounting/sales/{salesType}/{salesId}/attachments/{fileName}`
     * with `accounting.sales:write`, which is already here.
     */
    public const SCOPES = [
        'businesses:read',
        'accounting.customers:read',
        'accounting.sales:read',
        'accounting.sales:write',
        'accounting.account_codes:read',
        'accounting.vat:read',
    ];

    /**
     * The most this application will push into an invoice attachment.
     *
     * The spec sets no limit, so this is Junction's own: a PO is a page or two
     * of PDF, and anything past a few megabytes is a scan somebody should fix
     * rather than something to spend an invoice-raising timeout on.
     */
    public const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    // -- Configuration -------------------------------------------------------
    // The connection, and only the connection. The Settings-table value wins
    // when set, so a staff.admin can configure this without a redeploy; .env is
    // the fallback for a fresh install.

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

    public static function isConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '' && self::redirectUri() !== '';
    }

    public static function isConnected(): bool
    {
        return ClearBooksToken::get() !== null;
    }

    /**
     * What is wrong with the connection itself.
     *
     * Only the global half: credentials and consent. What is wrong with a
     * particular client's posting details is ClearBooksPosting::problems(),
     * shown on that client's page, because that is where it gets fixed.
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
    // Read by the client page so the business, nominal code, VAT treatment and
    // VAT rate are picked from live lists rather than typed from memory.
    //
    // Every one of these takes the business explicitly, because the business is
    // itself a per-client choice now: the codes and rates that come back are the
    // ones belonging to whichever business this client's invoices are posted to,
    // and reading them under a different business would offer a list that the
    // invoice would then be rejected for using.

    /** @return array<int,array<string,mixed>> */
    public static function businesses(): array
    {
        // Deliberately no business header: this is the call that tells you what
        // the businesses are.
        return self::httpGet('/businesses', null);
    }

    /** @return array<int,array<string,mixed>> */
    public static function salesAccountCodes(?int $businessId): array
    {
        return array_values(array_filter(
            self::httpGet('/accounting/accountCodes', $businessId),
            static fn (array $code): bool => ($code['sales'] ?? false) === true
        ));
    }

    /** @return array<int,array<string,mixed>> */
    public static function vatTreatments(?int $businessId): array
    {
        return self::httpGet('/accounting/vatTreatments/sales', $businessId);
    }

    /** @return array<int,array<string,mixed>> */
    public static function vatRates(?int $businessId, string $vatTreatment = ''): array
    {
        return self::httpGet(
            '/accounting/vatRates/sales',
            $businessId,
            $vatTreatment !== '' ? ['vatTreatment' => $vatTreatment] : []
        );
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
    public static function customer(int $customerId, ?int $businessId = null): ?array
    {
        try {
            $customer = self::httpGet('/accounting/customers/' . $customerId, $businessId);
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
     * `reference`, `description` and `dateDue` are all optional.
     *
     * `description` is the field the Clear Books interface labels **Summary** —
     * the spec says so in as many words — so that is where this client's
     * summary template ends up once its placeholders are filled in.
     *
     * `dateDue` is left off entirely when the client is set to let Clear Books
     * decide. The API exposes a single date where the interface has a set of
     * rules, so for a client on anything more elaborate than "n days from the
     * invoice" the honest option is to send nothing and let the contact's own
     * default in Clear Books apply.
     *
     * @param array<int,array{description:string,quantity:int|float,unit_price:float}> $lines
     * @return array{id:string,number:string,amount:float}
     */
    public static function createSalesInvoice(
        ClearBooksPosting $posting,
        array $lines,
        string $reference,
        ?string $summary = null
    ): array {
        $problems = array_merge(self::problems(), $posting->problems());
        if ($problems !== []) {
            throw new RuntimeException('Clear Books is not ready: ' . implode(' ', $problems));
        }

        if ($lines === []) {
            throw new RuntimeException('An invoice needs at least one line.');
        }

        $accountCode = (int) $posting->accountCode;
        $vatRateKey = $posting->vatRateKey;
        $date = date('Y-m-d');

        $payload = [
            'date' => $date,
            'reference' => $reference,
            'customerId' => (int) $posting->customerId,
            'vatTreatment' => $posting->vatTreatment,
            'lineItems' => array_map(static fn (array $line): array => [
                'description' => (string) $line['description'],
                'quantity' => (float) $line['quantity'],
                'unitPrice' => (float) $line['unit_price'],
                'accountCode' => $accountCode,
                'vatRateKey' => $vatRateKey,
            ], array_values($lines)),
        ];

        $dueDate = $posting->dueDate($date);
        if ($dueDate !== null) {
            $payload['dateDue'] = $dueDate;
        }

        if ($summary !== null && $summary !== '') {
            $payload['description'] = $summary;
        }

        // Deliberately no retry: a 429 or a timeout on a create is ambiguous,
        // and the wrong guess raises the same invoice twice.
        $response = self::request('POST', '/accounting/sales/invoices', $posting->businessId, $payload, false);

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

    /**
     * Attach a file to a sales invoice.
     *
     * Per the spec's Sales Attachments tag:
     * `POST /accounting/sales/{salesType}/{salesId}/attachments/{fileName}`,
     * where `salesType` is one of `invoices`, `creditNotes` or `quotes`, and
     * the body is the raw file as `application/octet-stream`. The filename is
     * part of the path rather than a field, which is why it is URL-encoded
     * here and sanitised before it ever gets this far. A 201 comes back with
     * the created Attachment — `id`, `name`, `size`, `dateUploaded`.
     *
     * Not retried, for the same reason the create is not: a timeout on a POST
     * is ambiguous, and the wrong guess puts the same PO on the invoice twice.
     *
     * @return array{id:int,name:string,size:int}
     */
    public static function attachToSalesInvoice(
        ?int $businessId,
        int $invoiceId,
        string $fileName,
        string $contents
    ): array {
        if ($invoiceId <= 0) {
            throw new RuntimeException('Clear Books did not return an invoice id, so there is nothing to attach to.');
        }

        if ($contents === '') {
            throw new RuntimeException('The file is empty, so there is nothing to attach.');
        }

        if (strlen($contents) > self::MAX_ATTACHMENT_BYTES) {
            throw new RuntimeException(sprintf(
                '%s is %s, over the %s limit this application puts on an invoice attachment.',
                $fileName,
                self::formatBytes(strlen($contents)),
                self::formatBytes(self::MAX_ATTACHMENT_BYTES)
            ));
        }

        $path = '/accounting/sales/invoices/' . $invoiceId . '/attachments/' . rawurlencode($fileName);

        $headers = self::headers($businessId);
        $headers[] = 'Content-Type: application/octet-stream';

        [$status, $decoded, $raw] = self::send('POST', self::API_BASE . $path, $headers, null, $contents);

        if ($status >= 400) {
            throw new RuntimeException(self::describeError($status, $decoded, $raw));
        }

        return [
            'id' => (int) ($decoded['id'] ?? 0),
            'name' => (string) ($decoded['name'] ?? $fileName),
            'size' => (int) ($decoded['size'] ?? strlen($contents)),
        ];
    }

    // -- Transport -----------------------------------------------------------

    /**
     * @param array<string,mixed> $query
     * @return array<mixed>
     */
    private static function httpGet(string $path, ?int $businessId, array $query = []): array
    {
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return self::request('GET', $path, $businessId);
    }

    /**
     * The bearer token, and the business header when there is a business to name.
     *
     * X-Business-ID is optional per the spec — required only for a
     * multi-business authorisation — so an unset business is left off the
     * request entirely rather than sent as 0.
     *
     * @return array<int,string>
     */
    private static function headers(?int $businessId): array
    {
        $headers = [
            'Authorization: Bearer ' . self::accessToken(),
            'Accept: application/json',
        ];

        if ($businessId !== null && $businessId > 0) {
            $headers[] = 'X-Business-ID: ' . $businessId;
        }

        return $headers;
    }

    /**
     * One API call, with the bearer token and business header attached.
     *
     * @param array<string,mixed>|null $body
     * @return array<mixed>
     */
    private static function request(
        string $method,
        string $path,
        ?int $businessId,
        ?array $body = null,
        bool $retry = true
    ): array {
        $headers = self::headers($businessId);

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

    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
