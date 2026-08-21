<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Registry of opt-in notification types. Every user starts with none selected.
 *
 * Three things decide what somebody is offered:
 *
 *   - `side`. Most of these are events on an order and both sides care about
 *     them; the outstanding-parts digest is Junction's own workload and would
 *     mean nothing to a client, so it is staff-only.
 *   - `capability`. A message that carries a price is only ever sent to a
 *     recipient who holds view_pricing, so offering the checkbox to anybody else
 *     is offering them a message they can never receive. The preferences screen
 *     asks the same question the sender does, from the same table here.
 *   - `group`. Purely for reading: thirteen checkboxes in one list is a wall,
 *     and the same thirteen under five headings is a page somebody can find
 *     their way around.
 *
 * Transactional messages — an invitation, and its link — are deliberately
 * absent: they are not something anybody opts into.
 */
final class NotificationTypes
{
    /**
     * The headings, in the order they should be shown.
     *
     * Ordered by how much of the day they account for rather than
     * alphabetically: an order moving is the common case, and the digest is
     * something one or two people subscribe to once.
     */
    private const GROUPS = [
        'orders' => 'Orders',
        'free_issue' => 'Free-issue material',
        'despatch' => 'Despatch, returns and invoicing',
        'questions' => 'Questions and changes',
        'workload' => 'Junction workload',
    ];

    /** type => [label, side, required capability or null, group] */
    private const TYPES = [
        'part_quoted'            => ['A part you created has been quoted', 'both', 'view_pricing', 'orders'],
        'order_confirmed'        => ['An order has been confirmed', 'both', null, 'orders'],
        'order_in_production'    => ['An order line has started production', 'both', null, 'orders'],

        'free_issue_note_issued' => ['A Free-Issue Sent note has been issued', 'both', null, 'free_issue'],
        'free_issue_checked_in'  => ['Free-issue material has been checked in', 'both', null, 'free_issue'],
        'material_rejected'      => ['Free-issue material has been rejected and is being returned', 'both', null, 'free_issue'],

        'delivery_note_issued'   => ['Completed parts have been sent out on a delivery note', 'both', null, 'despatch'],
        // Staff-only: the client raises this one themselves, so telling them
        // about it would be telling them what they have just done. Junction is
        // the side that needs to know parts are on their way back.
        'parts_returned'         => ['A client is returning rejected parts', 'staff', null, 'despatch'],
        'invoice_raised'         => ['An invoice has been raised', 'both', 'view_pricing', 'despatch'],

        'query_raised'           => ['A new query has been raised on an order', 'both', null, 'questions'],
        'query_answered'         => ['A query you raised has been answered', 'both', null, 'questions'],
        'quantity_change_requested' => ['A client has asked to change a quantity', 'staff', null, 'questions'],
        'quantity_change_decided'   => ['A quantity change has been decided', 'both', null, 'questions'],

        'parts_outstanding'      => ['The scheduled digest of parts still outstanding', 'staff', null, 'workload'],
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

    /**
     * The same list under its headings, for the preferences screen.
     *
     * Empty groups are dropped rather than shown empty — a client has no
     * business seeing a "Junction workload" heading with nothing under it.
     *
     * @param array<int,string> $roles
     * @return array<string,array{label:string,types:array<string,string>}>
     */
    public static function groupedForUser(string $side, array $roles): array
    {
        $available = self::forUser($side, $roles);
        $grouped = [];

        foreach (self::GROUPS as $groupKey => $groupLabel) {
            $types = [];

            foreach ($available as $key => $label) {
                if (self::TYPES[$key][3] === $groupKey) {
                    $types[$key] = $label;
                }
            }

            if ($types !== []) {
                $grouped[$groupKey] = ['label' => $groupLabel, 'types' => $types];
            }
        }

        return $grouped;
    }
}
