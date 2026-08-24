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
                company_number = :company_number, notes = :notes, is_active = :is_active
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
                'is_active' => $data['is_active'] ?? 1,
            ]
        );
    }
}
