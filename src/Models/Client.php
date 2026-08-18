<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Client
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM clients WHERE id = :id', ['id' => $id]);
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
                address_postcode, address_country, main_contact_name, main_contact_email,
                main_contact_phone, billing_email, notes
            ) VALUES (
                :name, :clearbooks_entity_id, :address_line1, :address_line2, :address_city,
                :address_postcode, :address_country, :main_contact_name, :main_contact_email,
                :main_contact_phone, :billing_email, :notes
            )',
            [
                'name' => $data['name'],
                'clearbooks_entity_id' => $data['clearbooks_entity_id'] ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'address_city' => $data['address_city'] ?? null,
                'address_postcode' => $data['address_postcode'] ?? null,
                'address_country' => $data['address_country'] ?? 'United Kingdom',
                'main_contact_name' => $data['main_contact_name'] ?? null,
                'main_contact_email' => $data['main_contact_email'] ?? null,
                'main_contact_phone' => $data['main_contact_phone'] ?? null,
                'billing_email' => $data['billing_email'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        );
    }

    public static function update(int $id, array $data): void
    {
        Database::query(
            'UPDATE clients SET
                name = :name, clearbooks_entity_id = :clearbooks_entity_id,
                address_line1 = :address_line1, address_line2 = :address_line2,
                address_city = :address_city, address_postcode = :address_postcode,
                address_country = :address_country, main_contact_name = :main_contact_name,
                main_contact_email = :main_contact_email, main_contact_phone = :main_contact_phone,
                billing_email = :billing_email, notes = :notes, is_active = :is_active
             WHERE id = :id',
            [
                'id' => $id,
                'name' => $data['name'],
                'clearbooks_entity_id' => $data['clearbooks_entity_id'] ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'address_city' => $data['address_city'] ?? null,
                'address_postcode' => $data['address_postcode'] ?? null,
                'address_country' => $data['address_country'] ?? 'United Kingdom',
                'main_contact_name' => $data['main_contact_name'] ?? null,
                'main_contact_email' => $data['main_contact_email'] ?? null,
                'main_contact_phone' => $data['main_contact_phone'] ?? null,
                'billing_email' => $data['billing_email'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? 1,
            ]
        );
    }
}
