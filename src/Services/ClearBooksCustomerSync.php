<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use RuntimeException;

/**
 * Pulling a client's details from their Clear Books customer record.
 *
 * On demand and never in the background. A client's address changing in Clear
 * Books is not an event this application should react to silently — somebody
 * asked for the update, so somebody is looking at the result and can see what
 * changed.
 *
 * The field names below are the Customer schema from
 * https://api.clearbooks.co.uk/spec/v1.yaml, which is `Contact`: `name`,
 * `contactName{title,forenames,surname}`, `email`, `phone`,
 * `address{building,line1,line2,town,county,postcode,countryCode}`,
 * `vatNumber`, `companyNumber`, `externalId`, `archived`. Read out of the spec
 * rather than guessed, which is the whole lesson of the last time this
 * application talked to Clear Books.
 */
final class ClearBooksCustomerSync
{
    /**
     * Fetch a customer and work out what it would change locally.
     *
     * Returns the proposed field values and a per-field before/after list, so
     * the caller can show what happened instead of announcing a silent success.
     *
     * @param array<string,mixed> $client the local row, or [] when there is not one yet
     * @return array{fields:array<string,mixed>,changes:array<int,array{field:string,from:?string,to:?string}>,customer:array<string,mixed>}
     */
    public static function preview(int $customerId, array $client = []): array
    {
        if ($customerId <= 0) {
            throw new RuntimeException(
                'Enter the Clear Books customer ID first. It is the number in the address bar when '
                . 'that customer is open in Clear Books.'
            );
        }

        $customer = ClearBooksClient::customer($customerId);
        if ($customer === null) {
            throw new RuntimeException(
                'Clear Books has no customer with ID ' . $customerId . ', or the connection could not read it. '
                . 'Check the ID, and check the connection under Settings.'
            );
        }

        $fields = self::map($customer, $client);

        $changes = [];
        foreach ($fields as $field => $to) {
            $from = $client[$field] ?? null;
            if ((string) $from !== (string) $to) {
                $changes[] = [
                    'field' => $field,
                    'from' => $from === null || $from === '' ? null : (string) $from,
                    'to' => $to === null || $to === '' ? null : (string) $to,
                ];
            }
        }

        return ['fields' => $fields, 'changes' => $changes, 'customer' => $customer];
    }

    /**
     * Fetch, apply and record who did it.
     *
     * @return array<int,array{field:string,from:?string,to:?string}> what changed
     */
    public static function apply(int $clientId, int $userId): array
    {
        $client = Client::find($clientId);
        if ($client === null) {
            throw new RuntimeException('That client does not exist.');
        }

        $stored = trim((string) ($client['clearbooks_entity_id'] ?? ''));

        if ($stored === '') {
            throw new RuntimeException(
                'This client has no Clear Books customer ID yet. Enter one and save before pulling their details.'
            );
        }

        // Records predating the API verification hold things like "CB-ACME-001"
        // in this column, from when it was believed to be an entity reference.
        // It is the numeric id of a Clear Books customer, and saying so is more
        // use than reporting that a value which is plainly there is missing.
        if (!ctype_digit($stored)) {
            throw new RuntimeException(
                '"' . $stored . '" is not a Clear Books customer ID. It is the number in the address bar '
                . 'when that customer is open in Clear Books — correct it above and save, then pull again.'
            );
        }

        $customerId = (int) $stored;

        $preview = self::preview($customerId, $client);

        Client::update($clientId, array_merge($client, $preview['fields']));
        Client::recordClearBooksSync($clientId, $userId);

        return $preview['changes'];
    }

    /**
     * Clear Books' customer record, in this application's own terms.
     *
     * Where the two disagree about how much detail an address has, nothing is
     * thrown away: Clear Books splits the building from the first line and this
     * table does not, so the two are joined rather than the building dropped.
     *
     * `email` goes to the billing address, because a Clear Books customer is the
     * record invoices are addressed to. It fills the main contact's email as
     * well, but only when that is empty — somebody at Junction may well have put
     * a production contact there deliberately, and an accounts address is not an
     * improvement on it.
     *
     * @param array<string,mixed> $customer
     * @param array<string,mixed> $client
     * @return array<string,mixed>
     */
    public static function map(array $customer, array $client = []): array
    {
        $address = is_array($customer['address'] ?? null) ? $customer['address'] : [];
        $email = self::text($customer['email'] ?? null);

        $fields = [
            'name' => self::text($customer['name'] ?? null) ?? ($client['name'] ?? ''),
            'address_line1' => self::join([$address['building'] ?? null, $address['line1'] ?? null]),
            'address_line2' => self::text($address['line2'] ?? null),
            'address_city' => self::text($address['town'] ?? null),
            'address_county' => self::text($address['county'] ?? null),
            'address_postcode' => self::text($address['postcode'] ?? null),
            'address_country' => self::country($address['countryCode'] ?? null, $client),
            'main_contact_name' => self::contactName($customer['contactName'] ?? null),
            'main_contact_phone' => self::text($customer['phone'] ?? null),
            'billing_email' => $email,
            'vat_number' => self::text($customer['vatNumber'] ?? null),
            'company_number' => self::text($customer['companyNumber'] ?? null),
            // Archived over there means they are not somebody Junction should be
            // raising new work for over here.
            'is_active' => ($customer['archived'] ?? false) === true ? 0 : 1,
        ];

        $existingContactEmail = self::text($client['main_contact_email'] ?? null);
        $fields['main_contact_email'] = $existingContactEmail ?? $email;

        // Anything Clear Books left blank is left alone rather than blanked out
        // locally. A field they do not fill in is not a statement that ours is
        // wrong, and somebody has usually typed the missing half in by hand.
        foreach ($fields as $field => $value) {
            if ($value === null && array_key_exists($field, $client)) {
                $fields[$field] = $client[$field];
            }
        }

        return $fields;
    }

    /** Trimmed, or null for anything that is effectively absent. */
    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** @param array<int,mixed> $parts */
    private static function join(array $parts): ?string
    {
        $kept = array_values(array_filter(array_map([self::class, 'text'], $parts)));

        return $kept === [] ? null : implode(' ', $kept);
    }

    /** "Mr John Smith", from the three pieces Clear Books holds separately. */
    private static function contactName(mixed $contactName): ?string
    {
        if (!is_array($contactName)) {
            return null;
        }

        return self::join([
            $contactName['title'] ?? null,
            $contactName['forenames'] ?? null,
            $contactName['surname'] ?? null,
        ]);
    }

    /**
     * Clear Books holds a country *code*; this table holds a country *name* and
     * defaults it to United Kingdom. Writing "GB" into a column full of
     * "United Kingdom" would make the client list read as though two different
     * countries were involved, so the one code that matters here is named and
     * anything else is stored as it came — visibly a code, rather than quietly
     * wrong.
     *
     * @param array<string,mixed> $client
     */
    private static function country(mixed $countryCode, array $client): ?string
    {
        $code = self::text($countryCode);
        if ($code === null) {
            return self::text($client['address_country'] ?? null);
        }

        return strtoupper($code) === 'GB' ? 'United Kingdom' : $code;
    }
}
