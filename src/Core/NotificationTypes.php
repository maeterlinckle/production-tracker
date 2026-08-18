<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Registry of opt-in notification types. Every user starts with none selected.
 *
 * Two things decide whether somebody is offered a given choice:
 *
 *   - `side`. Most of these are events on an order and both sides care about
 *     them; the outstanding-parts digest is Junction's own workload and would
 *     mean nothing to a client, so it is staff-only.
 *   - `capability`. A message that carries a price is only ever sent to a
 *     recipient who holds view_pricing, so offering the checkbox to anybody else
 *     is offering them a message they can never receive. The preferences screen
 *     asks the same question the sender does, from the same table here.
 *
 * Transactional messages — an invitation, and its link — are deliberately
 * absent: they are not something anybody opts into.
 */
final class NotificationTypes
{
    /** type => [label, side, required capability or null] */
    private const TYPES = [
        'part_quoted'            => ['A part you created has been quoted', 'both', 'view_pricing'],
        'order_confirmed'        => ['Your order has been confirmed', 'both', null],
        'order_in_production'    => ['An order line has started production', 'both', null],
        'free_issue_note_issued' => ['A free-issue delivery note has been issued', 'both', null],
        'free_issue_checked_in'  => ['Free-issue material has been checked in', 'both', null],
        'material_rejected'      => ['Free-issue material has been rejected and is being returned', 'both', null],
        'delivery_note_issued'   => ['A delivery note has been issued', 'both', null],
        'invoice_raised'         => ['An invoice has been raised', 'both', 'view_pricing'],
        'query_raised'           => ['A new query has been raised on an order', 'both', null],
        'query_answered'         => ['A query you raised has been answered', 'both', null],
        'quantity_change_requested' => ['A client has asked to change a quantity', 'staff', null],
        'quantity_change_decided'   => ['A quantity change request has been decided', 'both', null],
        'parts_outstanding'      => ['The scheduled digest of parts still outstanding', 'staff', null],
    ];

    /**
     * Every type this application knows about, for validating what was ticked.
     *
     * @return array<string,string> type => label
     */
    public static function all(): array
    {
        return array_map(static fn (array $type): string => $type[0], self::TYPES);
    }

    /**
     * The types one particular user may subscribe to.
     *
     * @param array<int,string> $roles Role slugs held by the user
     * @return array<string,string> type => label
     */
    public static function forUser(string $side, array $roles): array
    {
        $available = [];

        foreach (self::TYPES as $key => [$label, $audience, $capability]) {
            if ($audience !== 'both' && $audience !== $side) {
                continue;
            }

            if ($capability !== null && !Capabilities::allows($roles, $capability)) {
                continue;
            }

            $available[$key] = $label;
        }

        return $available;
    }
}
