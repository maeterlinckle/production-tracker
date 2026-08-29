<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Client
{
    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT c.*, u.name AS synced_by_name
               FROM clients c
               LEFT JOIN users u ON u.id = c.clearbooks_synced_by
              WHERE c.id = :id',
            ['id' => $id]
        );
    }

    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM clients';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name';

        return Database::all($sql);
    }

    public static function count(bool $activeOnly = false): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM clients' . ($activeOnly ? ' WHERE is_active = 1' : '')
        );
    }

    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO clients (
                name, clearbooks_entity_id, address_line1, address_line2, address_city,
                address_county, address_postcode, address_country, main_contact_name, main_contact_email,
                main_contact_phone, billing_email, vat_number, company_number, notes
            ) VALUES (
                :name, :clearbooks_entity_id, :address_line1, :address_line2, :address_city,
                :address_county, :address_postcode, :address_country, :main_contact_name, :main_contact_email,
                :main_contact_phone, :billing_email, :vat_number, :company_number, :notes
            )',
            [
                'name' => $data['name'],
                'clearbooks_entity_id' => $data['clearbooks_entity_id'] ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'address_city' => $data['address_city'] ?? null,
                'address_county' => $data['address_county'] ?? null,
                'address_postcode' => $data['address_postcode'] ?? null,
                'address_country' => $data['address_country'] ?? 'United Kingdom',
                'main_contact_name' => $data['main_contact_name'] ?? null,
                'main_contact_email' => $data['main_contact_email'] ?? null,
                'main_contact_phone' => $data['main_contact_phone'] ?? null,
                'billing_email' => $data['billing_email'] ?? null,
                'vat_number' => $data['vat_number'] ?? null,
                'company_number' => $data['company_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        );
    }

    /**
     * Stamp a successful pull from Clear Books.
     *
     * Separate from update() because it is a different fact: update() records
     * what the client's details are, this records when we last asked somebody
     * else. Kept apart so an ordinary edit does not make the local copy look
     * freshly synced when it is not.
     */
    /**
     * Is this client's account switched on?
     *
     * Asked by everything that changes something, and cached for the length of
     * one request: a page that touches several orders would otherwise ask the
     * same question a dozen times, and the answer cannot change halfway through
     * a request.
     */
    public static function isActive(int $id): bool
    {
        static $cache = [];

        if (!array_key_exists($id, $cache)) {
            $cache[$id] = (bool) Database::scalar(
                'SELECT is_active FROM clients WHERE id = :id',
                ['id' => $id]
            );
        }

        return $cache[$id];
    }

    /**
     * Switch a client's account off, or back on.
     *
     * Nothing is deleted and nothing cascades. The client's own users keep
     * their individual `is_active` exactly as they had it — the block on
     * signing in comes from the client being off, so switching the client back
     * on restores who could sign in rather than switching everybody on
     * including the people who had been deactivated one at a time.
     *
     * Their orders freeze because every action that would move one asks
     * `isActive()` first, not because anything is written to the orders. A
     * stored frozen flag would have to be set on every order here and unset on
     * every order at reactivation, and the first one missed by a new code path
     * is an order frozen forever with nothing to explain why.
     */
    public static function setActive(int $id, bool $active, int $userId, ?string $reason = null): void
    {
        Database::query(
            'UPDATE clients SET
                is_active = :is_active,
                deactivated_at = :deactivated_at,
                deactivated_by = :deactivated_by,
                deactivated_reason = :reason
             WHERE id = :id',
            [
                'id' => $id,
                'is_active' => $active ? 1 : 0,
                'deactivated_at' => $active ? null : date('Y-m-d H:i:s'),
                'deactivated_by' => $active ? null : $userId,
                'reason' => $active ? null : (trim((string) $reason) ?: null),
            ]
        );
    }

    /** Who switched it off, for the banner on the client's page. */
    public static function deactivationDetail(int $id): ?array
    {
        return Database::one(
            'SELECT c.deactivated_at, c.deactivated_reason, u.name AS deactivated_by_name
               FROM clients c
          LEFT JOIN users u ON u.id = c.deactivated_by
              WHERE c.id = :id AND c.is_active = 0',
            ['id' => $id]
        );
    }

    public static function recordClearBooksSync(int $id, int $userId): void
    {
        Database::query(
            'UPDATE clients SET clearbooks_synced_at = NOW(), clearbooks_synced_by = :user WHERE id = :id',
            ['id' => $id, 'user' => $userId]
        );
    }

    public static function update(int $id, array $data): void
    {
        Database::query(
            'UPDATE clients SET
                name = :name, clearbooks_entity_id = :clearbooks_entity_id,
                address_line1 = :address_line1, address_line2 = :address_line2,
                address_city = :address_city, address_county = :address_county,
                address_postcode = :address_postcode,
                address_country = :address_country, main_contact_name = :main_contact_name,
                main_contact_email = :main_contact_email, main_contact_phone = :main_contact_phone,
                billing_email = :billing_email, vat_number = :vat_number,
                company_number = :company_number, notes = :notes
             WHERE id = :id',
            [
                'id' => $id,
                'name' => $data['name'],
                'clearbooks_entity_id' => $data['clearbooks_entity_id'] ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'address_city' => $data['address_city'] ?? null,
                'address_county' => $data['address_county'] ?? null,
                'address_postcode' => $data['address_postcode'] ?? null,
                'address_country' => $data['address_country'] ?? 'United Kingdom',
                'main_contact_name' => $data['main_contact_name'] ?? null,
                'main_contact_email' => $data['main_contact_email'] ?? null,
                'main_contact_phone' => $data['main_contact_phone'] ?? null,
                'billing_email' => $data['billing_email'] ?? null,
                'vat_number' => $data['vat_number'] ?? null,
                'company_number' => $data['company_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        );
    }
}
