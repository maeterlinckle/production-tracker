<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Part
{
    /**
     * The relationship a client can choose between an order quantity and the
     * free-issue material it needs.
     *
     * The range is deliberately generous at the top: a single length of bar
     * yielding ten small turned parts is ordinary, and a client who cannot say
     * so ends up sending ten times the material they meant to.
     */
    public const FREE_ISSUE_RELATIONSHIPS = ['none', 'divide', 'multiply'];
    public const FREE_ISSUE_FACTOR_MIN = 2;
    public const FREE_ISSUE_FACTOR_MAX = 10;

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT p.*, c.name AS client_name, u.name AS free_issue_updated_by_name
               FROM parts p
               JOIN clients c ON c.id = p.client_id
          LEFT JOIN users u ON u.id = p.free_issue_updated_by
              WHERE p.id = :id',
            ['id' => $id]
        );
    }

    /**
     * The relationship and factor as posted by either part form.
     *
     * There is one dropdown per direction so a chosen number survives switching
     * between them, which means the reader has to pick the one the direction
     * calls for rather than trusting a single field. Anything out of range, or a
     * direction of "none", collapses to a factor of 1 — the same value the CHECK
     * constraint in migration 003 insists on.
     *
     * @return array{free_issue_relationship:string,free_issue_factor:int}
     */
    public static function readFreeIssueInput(): array
    {
        $relationship = (string) \App\Core\Request::post('free_issue_relationship', 'none');

        if (!in_array($relationship, self::FREE_ISSUE_RELATIONSHIPS, true)) {
            $relationship = 'none';
        }

        if ($relationship === 'none') {
            return ['free_issue_relationship' => 'none', 'free_issue_factor' => 1];
        }

        $factor = (int) \App\Core\Request::post(
            $relationship === 'divide' ? 'free_issue_factor_divide' : 'free_issue_factor_multiply',
            self::FREE_ISSUE_FACTOR_MIN
        );

        if ($factor < self::FREE_ISSUE_FACTOR_MIN || $factor > self::FREE_ISSUE_FACTOR_MAX) {
            return ['free_issue_relationship' => 'none', 'free_issue_factor' => 1];
        }

        return ['free_issue_relationship' => $relationship, 'free_issue_factor' => $factor];
    }

    /**
     * Set the relationship, recording who did it.
     *
     * Both sides come through here — the client setting it at quote time and
     * Junction correcting it later — so "who last touched this" is answerable
     * whichever of them it was.
     */
    public static function setFreeIssueRelationship(int $id, string $relationship, int $factor, int $userId): void
    {
        Database::query(
            'UPDATE parts SET free_issue_relationship = :relationship, free_issue_factor = :factor,
                    free_issue_updated_by = :user_id, free_issue_updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'relationship' => $relationship,
                'factor' => $relationship === 'none' ? 1 : $factor,
                'user_id' => $userId,
            ]
        );
    }

    public static function forClient(int $clientId, bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM parts WHERE client_id = :client_id';
        if (!$includeArchived) {
            $sql .= ' AND is_archived = 0';
        }
        $sql .= ' ORDER BY cpn';

        return Database::all($sql, ['client_id' => $clientId]);
    }

    public static function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT p.*, c.name AS client_name FROM parts p JOIN clients c ON c.id = p.client_id';
        if (!$includeArchived) {
            $sql .= ' WHERE p.is_archived = 0';
        }
        $sql .= ' ORDER BY c.name, p.cpn';

        return Database::all($sql);
    }

    public static function unquoted(): array
    {
        return Database::all(
            "SELECT p.*, c.name AS client_name FROM parts p JOIN clients c ON c.id = p.client_id
             WHERE p.status = 'draft' AND p.is_archived = 0 ORDER BY p.created_at"
        );
    }

    /** Quoted, orderable parts matching a search term -- for the AJAX combobox on Place Order. */
    public static function searchOrderable(int $clientId, string $term, int $limit = 15): array
    {
        $like = '%' . $term . '%';

        return Database::all(
            "SELECT * FROM parts
             WHERE client_id = :client_id AND status = 'quoted' AND is_archived = 0
               AND (cpn LIKE :like1 OR name LIKE :like2)
             ORDER BY cpn LIMIT " . $limit,
            ['client_id' => $clientId, 'like1' => $like, 'like2' => $like]
        );
    }

    /** Quoted, orderable siblings of $partId via part_links -- for the "usually ordered with" prompt during ordering. */
    public static function linkedOrderable(int $partId): array
    {
        return Database::all(
            "SELECT p.* FROM part_links pl
             JOIN parts p ON p.id = IF(pl.part_id = :id1, pl.linked_part_id, pl.part_id)
             WHERE (pl.part_id = :id2 OR pl.linked_part_id = :id3)
               AND p.status = 'quoted' AND p.is_archived = 0
             ORDER BY p.cpn",
            ['id1' => $partId, 'id2' => $partId, 'id3' => $partId]
        );
    }

    /** JSON-ready shape for the order-building combobox: enough to render the row and compute free-issue qty client-side. */
    public static function orderableJson(array $part): array
    {
        return [
            'id' => (int) $part['id'],
            'cpn' => $part['cpn'],
            'name' => $part['name'],
            'unit_price' => $part['quoted_price'] !== null ? (float) $part['quoted_price'] : null,
            'has_free_issue' => (int) $part['id'] > 0 && self::freeIssueMaterials((int) $part['id']) !== [],
            'free_issue_relationship' => $part['free_issue_relationship'],
            'free_issue_factor' => (int) $part['free_issue_factor'],
        ];
    }

    /** Any non-archived part matching a search term -- for the "usually ordered with" linker. */
    public static function searchAll(int $clientId, string $term, int $excludeId, int $limit = 15): array
    {
        $like = '%' . $term . '%';

        return Database::all(
            'SELECT * FROM parts
             WHERE client_id = :client_id AND is_archived = 0 AND id != :exclude_id
               AND (cpn LIKE :like1 OR name LIKE :like2)
             ORDER BY cpn LIMIT ' . $limit,
            ['client_id' => $clientId, 'exclude_id' => $excludeId, 'like1' => $like, 'like2' => $like]
        );
    }

    public static function cpnExists(int $clientId, string $cpn, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM parts WHERE client_id = :client_id AND cpn = :cpn';
        $params = ['client_id' => $clientId, 'cpn' => $cpn];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude';
            $params['exclude'] = $excludeId;
        }

        return Database::one($sql, $params) !== null;
    }

    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO parts (
                client_id, cpn, name, description, usual_order_qty, target_price, notes, created_by
            ) VALUES (
                :client_id, :cpn, :name, :description, :usual_order_qty, :target_price, :notes, :created_by
            )',
            [
                'client_id' => $data['client_id'],
                'cpn' => $data['cpn'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'usual_order_qty' => $data['usual_order_qty'] ?? null,
                'target_price' => $data['target_price'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'],
            ]
        );
    }

    public static function updateClientFields(int $id, array $data): void
    {
        Database::query(
            'UPDATE parts SET
                name = :name, description = :description, usual_order_qty = :usual_order_qty,
                target_price = :target_price, notes = :notes
             WHERE id = :id',
            [
                'id' => $id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'usual_order_qty' => $data['usual_order_qty'] ?? null,
                'target_price' => $data['target_price'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        );
    }

    public static function setQuotedPrice(int $id, float $price, int $staffUserId): void
    {
        Database::query(
            "UPDATE parts SET quoted_price = :price, quoted_price_set_by = :user_id,
                quoted_price_set_at = NOW(), status = 'quoted'
             WHERE id = :id",
            ['price' => $price, 'user_id' => $staffUserId, 'id' => $id]
        );
    }

    public static function updateStaffFields(int $id, array $data): void
    {
        Database::query(
            'UPDATE parts SET
                internal_notes = :internal_notes, build_time_minutes = :build_time_minutes,
                base_material = :base_material, material_source = :material_source,
                material_cost = :material_cost
             WHERE id = :id',
            [
                'id' => $id,
                'internal_notes' => $data['internal_notes'] ?? null,
                'build_time_minutes' => $data['build_time_minutes'] ?? null,
                'base_material' => $data['base_material'] ?? null,
                'material_source' => $data['material_source'] ?? null,
                'material_cost' => $data['material_cost'] ?? null,
            ]
        );
    }

    public static function setArchived(int $id, bool $archived): void
    {
        Database::query('UPDATE parts SET is_archived = :archived WHERE id = :id', ['archived' => $archived ? 1 : 0, 'id' => $id]);
    }

    public static function isReferencedByOrders(int $id): bool
    {
        return Database::one('SELECT id FROM order_lines WHERE part_id = :id LIMIT 1', ['id' => $id]) !== null;
    }

    /** Returns false (and does nothing) if the part is referenced by any order line. */
    public static function delete(int $id): bool
    {
        if (self::isReferencedByOrders($id)) {
            return false;
        }

        Database::query('DELETE FROM parts WHERE id = :id', ['id' => $id]);

        return true;
    }

    /**
     * Free-issue quantity required for a given order quantity, from the
     * part's relationship/factor (item 4/5). Division always rounds up --
     * you can't send a fractional piece of material.
     */
    public static function freeIssueQtyFor(array $part, int $orderQty): int
    {
        $factor = max(1, (int) ($part['free_issue_factor'] ?? 1));

        return match ($part['free_issue_relationship'] ?? 'none') {
            'divide' => (int) ceil($orderQty / $factor),
            'multiply' => $orderQty * $factor,
            default => $orderQty,
        };
    }

    // -- Alternate numbers --------------------------------------------------

    public static function alternateNumbers(int $partId): array
    {
        return Database::all('SELECT * FROM part_alternate_numbers WHERE part_id = :part_id ORDER BY id', ['part_id' => $partId]);
    }

    public static function addAlternateNumber(int $partId, string $number, ?string $label): void
    {
        Database::insert(
            'INSERT INTO part_alternate_numbers (part_id, number, label) VALUES (:part_id, :number, :label)',
            ['part_id' => $partId, 'number' => $number, 'label' => $label]
        );
    }

    public static function clearAlternateNumbers(int $partId): void
    {
        Database::query('DELETE FROM part_alternate_numbers WHERE part_id = :part_id', ['part_id' => $partId]);
    }

    // -- Free-issue source materials -----------------------------------------

    public static function freeIssueMaterials(int $partId): array
    {
        return Database::all('SELECT * FROM part_free_issue_materials WHERE part_id = :part_id ORDER BY id', ['part_id' => $partId]);
    }

    public static function addFreeIssueMaterial(int $partId, string $reference, ?string $notes): void
    {
        Database::insert(
            'INSERT INTO part_free_issue_materials (part_id, reference, notes) VALUES (:part_id, :reference, :notes)',
            ['part_id' => $partId, 'reference' => $reference, 'notes' => $notes]
        );
    }

    public static function clearFreeIssueMaterials(int $partId): void
    {
        Database::query('DELETE FROM part_free_issue_materials WHERE part_id = :part_id', ['part_id' => $partId]);
    }
}
