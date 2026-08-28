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
            'SELECT p.*, c.name AS client_name,
                    u.name AS free_issue_updated_by_name,
                    cb.name AS created_by_name,
                    ub.name AS updated_by_name,
                    qb.name AS quoted_price_set_by_name
               FROM parts p
               JOIN clients c ON c.id = p.client_id
          LEFT JOIN users u  ON u.id  = p.free_issue_updated_by
          LEFT JOIN users cb ON cb.id = p.created_by
          LEFT JOIN users ub ON ub.id = p.updated_by
          LEFT JOIN users qb ON qb.id = p.quoted_price_set_by
              WHERE p.id = :id',
            ['id' => $id]
        );
    }

    /**
     * The free-issue toggle, relationship and factor as posted by any of the
     * part forms.
     *
     * The toggle comes first and overrules the rest: with the box unchecked
     * there is no material, so there is no ratio for material either, and the
     * hidden dropdowns still sitting in the form are ignored rather than
     * silently saved. Anything out of range, or a direction of "none",
     * collapses to a factor of 1 — the value the CHECK constraint insists on.
     *
     * @return array{has_free_issue:bool,free_issue_relationship:string,free_issue_factor:int}
     */
    public static function readFreeIssueInput(): array
    {
        $none = ['has_free_issue' => false, 'free_issue_relationship' => 'none', 'free_issue_factor' => 1];

        if (!\App\Core\Request::post('has_free_issue')) {
            return $none;
        }

        $relationship = (string) \App\Core\Request::post('free_issue_relationship', 'none');

        if (!in_array($relationship, self::FREE_ISSUE_RELATIONSHIPS, true)) {
            $relationship = 'none';
        }

        if ($relationship === 'none') {
            return ['has_free_issue' => true, 'free_issue_relationship' => 'none', 'free_issue_factor' => 1];
        }

        $factor = (int) \App\Core\Request::post(
            $relationship === 'divide' ? 'free_issue_factor_divide' : 'free_issue_factor_multiply',
            self::FREE_ISSUE_FACTOR_MIN
        );

        if ($factor < self::FREE_ISSUE_FACTOR_MIN || $factor > self::FREE_ISSUE_FACTOR_MAX) {
            return ['has_free_issue' => true, 'free_issue_relationship' => 'none', 'free_issue_factor' => 1];
        }

        return ['has_free_issue' => true, 'free_issue_relationship' => $relationship, 'free_issue_factor' => $factor];
    }

    /**
     * Set the toggle and relationship together, recording who did it.
     *
     * Both sides come through here — the client setting it at quote time and
     * Junction correcting it later — so "who last touched this" is answerable
     * whichever of them it was. Turning the toggle off also clears the source
     * materials: leaving them behind would put a part in the odd position of
     * listing material it does not use.
     *
     * @param array{has_free_issue:bool,free_issue_relationship:string,free_issue_factor:int} $input
     */
    public static function setFreeIssue(int $id, array $input, int $userId): void
    {
        Database::query(
            'UPDATE parts SET has_free_issue = :has_free_issue,
                    free_issue_relationship = :relationship, free_issue_factor = :factor,
                    free_issue_updated_by = :user_id, free_issue_updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'has_free_issue' => $input['has_free_issue'] ? 1 : 0,
                'relationship' => $input['has_free_issue'] ? $input['free_issue_relationship'] : 'none',
                'factor' => $input['has_free_issue'] && $input['free_issue_relationship'] !== 'none'
                    ? $input['free_issue_factor']
                    : 1,
                'user_id' => $userId,
            ]
        );

        if (!$input['has_free_issue']) {
            self::clearFreeIssueMaterials($id);
        }
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

    /** How many parts a listing page shows before it paginates. */
    public const PER_PAGE = 25;

    /**
     * The parts listing: filtered, searched, ordered by relevance, one page at
     * a time.
     *
     * Both listings go through here — Junction's and the client's — because
     * they are the same question asked with a different scope, and two
     * implementations of "find me the part" is how the two lists start
     * disagreeing about what a match is.
     *
     * `include_internal` is not a convenience. Junction's own fields — the
     * internal notes, the material and where it comes from — are not shown to
     * a client anywhere, and a search that matched on them would hand back
     * their contents a guess at a time: type a word, see which parts come out.
     * So the client's search sees only what the client's part page shows.
     *
     * Relevance is ordered rather than scored: an exact part number first,
     * then a part number that starts with what was typed, then a name that
     * does, then anything else that contains it. That is enough to put the
     * part somebody is thinking of at the top when they type its number, which
     * is what the box is for.
     *
     * @param array{
     *     term?:string, client_id?:int|null, archived?:bool|null,
     *     only_unquoted?:bool, include_internal?:bool, page?:int, per_page?:int
     * } $options
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $options = []): array
    {
        $term = trim((string) ($options['term'] ?? ''));
        $clientId = $options['client_id'] ?? null;
        // `??` would read an explicit null as "not given", and null is a
        // meaningful value here: it means both archived and active.
        $archived = array_key_exists('archived', $options) ? $options['archived'] : false;
        $perPage = max(1, (int) ($options['per_page'] ?? self::PER_PAGE));
        $includeInternal = (bool) ($options['include_internal'] ?? false);

        // % and _ are LIKE's own, and somebody typing a part number that
        // contains one means the character, not a wildcard.
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $startsWith = addcslashes($term, '%_\\') . '%';

        $where = [];
        $params = [];

        if ($clientId !== null) {
            $where[] = 'p.client_id = :client_id';
            $params['client_id'] = (int) $clientId;
        }

        // null means "both", which is what a search across everything wants.
        if ($archived !== null) {
            $where[] = 'p.is_archived = :archived';
            $params['archived'] = $archived ? 1 : 0;
        }

        if (!empty($options['only_unquoted'])) {
            $where[] = "p.status = 'draft'";
        }

        if ($term !== '') {
            $fields = ['p.cpn', 'p.name', 'p.description', 'p.notes'];
            if ($includeInternal) {
                $fields[] = 'p.internal_notes';
                $fields[] = 'p.base_material';
                $fields[] = 'p.material_source';
            }

            // A placeholder per occurrence: with emulated prepares off, PDO
            // will not let one name be bound twice in a statement.
            $clauses = [];
            foreach ($fields as $i => $field) {
                $clauses[] = $field . ' LIKE :term' . $i;
                $params['term' . $i] = $like;
            }

            // The number a client knows the part by is often not the number it
            // is filed under, which is the whole reason alternate numbers
            // exist. A search that ignored them would miss the case it was
            // most needed for.
            $clauses[] = 'EXISTS (SELECT 1 FROM part_alternate_numbers a
                                   WHERE a.part_id = p.id
                                     AND (a.number LIKE :term_alt1 OR a.label LIKE :term_alt2))';
            $params['term_alt1'] = $like;
            $params['term_alt2'] = $like;

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM parts p JOIN clients c ON c.id = p.client_id' . $whereSql,
            $params
        );

        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($options['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $perPage;

        $order = 'c.name, p.cpn';
        if ($term !== '') {
            $params['exact'] = $term;
            $params['prefix1'] = $startsWith;
            $params['prefix2'] = $startsWith;
            $params['contains'] = $like;
            $order = 'CASE
                        WHEN p.cpn = :exact       THEN 0
                        WHEN p.cpn LIKE :prefix1  THEN 1
                        WHEN p.name LIKE :prefix2 THEN 2
                        WHEN p.cpn LIKE :contains THEN 3
                        ELSE 4
                      END, c.name, p.cpn';
        }

        // LIMIT and OFFSET are cast integers rather than placeholders: PDO
        // binds parameters as strings, and MariaDB will not take LIMIT '25'.
        $rows = Database::all(
            'SELECT p.*, c.name AS client_name
               FROM parts p
               JOIN clients c ON c.id = p.client_id'
            . $whereSql
            . ' ORDER BY ' . $order
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
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
            // The order builder warns on this as the part is added, which is
            // the last moment before somebody commits to a quantity.
            'price_under_review' => (bool) ($part['price_under_review'] ?? false),
            'has_free_issue' => (bool) $part['has_free_issue'],
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

    /**
     * The part already using this CPN for this client, if there is one.
     *
     * Uniqueness is per client — `uq_parts_client_cpn` — so two clients may
     * each have their own ACME-100 and neither is wrong. Returns the part
     * rather than a yes/no because the live check on the new-part form offers
     * a link to it: "already in use" is a dead end, "already in use, here it
     * is" usually ends the question.
     *
     * Archived parts count. The unique key does not exempt them, so a CPN one
     * is holding really is unavailable, and saying so here beats letting the
     * insert fail after the form has been filled in.
     *
     * @return array<string,mixed>|null
     */
    public static function findByCpn(int $clientId, string $cpn): ?array
    {
        return Database::one(
            'SELECT id, cpn, name, is_archived FROM parts WHERE client_id = :client_id AND cpn = :cpn',
            ['client_id' => $clientId, 'cpn' => trim($cpn)]
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

    /**
     * Every method that writes to a part takes the user doing it.
     *
     * Required rather than optional on purpose. `parts` recorded who created a
     * row and not who last changed it, so "who did this" could be answered for
     * the first version and no other — and three people can edit a part now.
     * Making the argument mandatory means a new write path cannot quietly skip
     * the stamp.
     */
    public static function updateClientFields(int $id, array $data, int $userId): void
    {
        Database::query(
            'UPDATE parts SET
                name = :name, description = :description, usual_order_qty = :usual_order_qty,
                target_price = :target_price, notes = :notes, updated_by = :updated_by
             WHERE id = :id',
            [
                'id' => $id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'usual_order_qty' => $data['usual_order_qty'] ?? null,
                'target_price' => $data['target_price'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $userId,
            ]
        );
    }

    public static function setQuotedPrice(int $id, float $price, int $staffUserId): void
    {
        Database::query(
            "UPDATE parts SET quoted_price = :price, quoted_price_set_by = :user_id,
                quoted_price_set_at = NOW(), status = 'quoted', updated_by = :user_id2
             WHERE id = :id",
            ['price' => $price, 'user_id' => $staffUserId, 'user_id2' => $staffUserId, 'id' => $id]
        );
    }

    public static function updateStaffFields(int $id, array $data, int $userId): void
    {
        // Build time is not here any more. It is the sum of
        // `part_time_entries` and is rewritten by PartTimeEntry when those
        // rows change — a form that could also type a total straight in would
        // be a second, quieter way to set the same figure, and the two would
        // disagree within a week.
        Database::query(
            'UPDATE parts SET
                internal_notes = :internal_notes,
                base_material = :base_material, material_source = :material_source,
                material_cost = :material_cost, updated_by = :updated_by
             WHERE id = :id',
            [
                'id' => $id,
                'internal_notes' => $data['internal_notes'] ?? null,
                'base_material' => $data['base_material'] ?? null,
                'material_source' => $data['material_source'] ?? null,
                'material_cost' => $data['material_cost'] ?? null,
                'updated_by' => $userId,
            ]
        );
    }

    /**
     * Flag that this part's price is about to move (item 9).
     *
     * A warning, not a change: the current price stays the current price until
     * somebody sets a new one. A second "provisional" price column would be a
     * number nobody had committed to, quoted back at us.
     */
    public static function setPriceUnderReview(int $id, bool $underReview, int $userId): void
    {
        Database::query(
            'UPDATE parts SET price_under_review = :flag, updated_by = :updated_by WHERE id = :id',
            ['flag' => $underReview ? 1 : 0, 'updated_by' => $userId, 'id' => $id]
        );
    }

    public static function setArchived(int $id, bool $archived, int $userId): void
    {
        Database::query(
            'UPDATE parts SET is_archived = :archived, updated_by = :updated_by WHERE id = :id',
            ['archived' => $archived ? 1 : 0, 'updated_by' => $userId, 'id' => $id]
        );
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
     * part's relationship/factor. Division always rounds up -- you can't send
     * a fractional piece of material.
     *
     * A part with the toggle off needs none, whatever the other columns still
     * happen to say: the toggle is the answer to "is there free issue here",
     * and every caller asks this one function rather than reading the columns.
     */
    public static function freeIssueQtyFor(array $part, int $orderQty): int
    {
        if (!self::hasFreeIssue($part)) {
            return 0;
        }

        $factor = max(1, (int) ($part['free_issue_factor'] ?? 1));

        return match ($part['free_issue_relationship'] ?? 'none') {
            'divide' => (int) ceil($orderQty / $factor),
            'multiply' => $orderQty * $factor,
            default => $orderQty,
        };
    }

    /** The single question every free-issue field on every screen is gated on. */
    public static function hasFreeIssue(array $part): bool
    {
        return (bool) ($part['has_free_issue'] ?? false);
    }

    /**
     * How many final parts a given number of free-issue units yields.
     *
     * The other direction from freeIssueQtyFor(), and the reason both exist:
     * for anything but a 1:1 part, the number of physical things on the shop
     * floor before the machining is not the number of parts that come out of
     * it. Ten bars at divide-by-2 are ten things to trip over and twenty parts
     * to invoice.
     *
     * Division rounds down here where the other rounds up, and both are the
     * cautious direction: you cannot get a whole part out of half the castings
     * it takes, and you cannot send half a bar.
     */
    public static function finalPartsFor(array $part, int $units): int
    {
        if (!self::hasFreeIssue($part)) {
            return $units;
        }

        $factor = max(1, (int) ($part['free_issue_factor'] ?? 1));

        return match ($part['free_issue_relationship'] ?? 'none') {
            'divide' => $units * $factor,
            'multiply' => intdiv($units, $factor),
            default => $units,
        };
    }

    /**
     * Does this part count differently before and after it is machined?
     *
     * False for everything 1:1, which is most parts — and where it is false
     * every conversion in the application is the identity, so the two-unit
     * model costs those parts nothing and shows them nothing.
     */
    public static function convertsQuantity(array $part): bool
    {
        return self::hasFreeIssue($part)
            && ($part['free_issue_relationship'] ?? 'none') !== 'none'
            && (int) ($part['free_issue_factor'] ?? 1) > 1;
    }

    /**
     * The relationship in plain words, for the note on an order line.
     *
     * Deliberately phrased with both real figures from the order rather than
     * as a ratio: "divide by 2" makes a reader do the arithmetic, and the
     * arithmetic is the thing people get wrong.
     */
    public static function conversionSentence(array $part, int $orderedParts): ?string
    {
        if (!self::convertsQuantity($part)) {
            return null;
        }

        return self::freeIssueQtyFor($part, $orderedParts) . ' received parts will produce '
             . $orderedParts . ' final parts.';
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
