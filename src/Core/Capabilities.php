<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fixed capability -> allowed-role-slugs matrix. Deliberately not a generic
 * admin-editable permission grid -- the roles are spec'd and fixed, so a small
 * in-code table is simpler and just as auditable. A new role is a row in
 * `roles` and a handful of edits here, both in the same migration-and-commit.
 *
 * 'staff.admin' automatically satisfies any capability that lists at least
 * one staff.* role ("full admin, everything"); 'client.admin' does the same
 * for capabilities listing at least one client.* role. That superset rule is
 * applied generically in Auth::can(), not repeated per capability below.
 */
final class Capabilities
{
    public const MATRIX = [
        // Pricing must be genuinely absent from the response for anyone
        // without one of these roles, not just hidden in the template.
        //
        // staff.invoicing is here because the job is billing: the invoicing
        // panels, the delivery note's own Invoicing card and the amount on a
        // raised invoice are all price-bearing, and gating them away from the
        // one role whose whole purpose is raising invoices left somebody able
        // to configure a client for Clear Books but not to see the button that
        // invoices them.
        'view_pricing' => ['client.purchaser', 'client.admin', 'staff.quoting', 'staff.invoicing', 'staff.admin'],

        // Client side.
        'manage_parts' => ['client.purchaser', 'client.admin'],
        'place_orders' => ['client.purchaser', 'client.admin'],
        'manage_client_users' => ['client.admin'],

        // Asking for a different quantity commits the client to buying more, or
        // to paying for whatever has already been made of what they are cutting
        // back, so it sits with the people who could place the order in the
        // first place rather than with everybody who can read it.
        'request_quantity_change' => ['client.purchaser', 'client.admin'],

        // Sending finished parts back because they failed the client's own
        // inspection. Every client role, deliberately including production:
        // the person who finds the bad part is the one standing at the bench
        // with it, and making them ask a purchaser to fill the form in is how
        // a rejection sits in somebody's inbox for a week. It commits nobody to
        // spending anything, which is what separates it from a quantity change.
        'return_rejected_parts' => [
            'client.production', 'client.production_manager', 'client.purchaser', 'client.admin',
        ],

        // Saying when parts are needed. A production manager schedules the line
        // and knows what has to land in March, which is a different job from
        // buying it -- so this is its own role rather than something bolted on
        // to the purchaser. It commits nobody to spending anything and changes
        // nothing about what is owed; it is a statement of need that Junction
        // reads to decide what to set up next.
        'set_due_dates' => ['client.production_manager', 'client.admin'],

        // Both sides. Reading an order and talking about it are not client-only
        // activities: every staff role works from the order pages, and a query
        // is a conversation, so either side has to be able to open one. Listing
        // the staff roles explicitly rather than special-casing `isStaff()` at
        // the call sites is what lets the navigation ask the same question the
        // controllers do.
        'view_orders' => [
            'client.production', 'client.production_manager', 'client.purchaser', 'client.admin',
            'staff.production', 'staff.quoting', 'staff.invoicing', 'staff.admin',
        ],
        'raise_queries' => [
            'client.production', 'client.production_manager', 'client.purchaser', 'client.admin',
            'staff.production', 'staff.quoting', 'staff.invoicing', 'staff.admin',
        ],

        // Staff side.
        'manage_clients' => ['staff.admin'],
        'set_pricing' => ['staff.quoting', 'staff.admin'],
        'edit_workshop_fields' => ['staff.quoting', 'staff.production', 'staff.admin'],
        'production_control' => ['staff.production', 'staff.admin'],
        'issue_delivery_notes' => ['staff.production', 'staff.admin'],

        // Staff may create a part on a client's behalf -- usually because the
        // enquiry arrived as a drawing attached to an email rather than through
        // the tracker. It is the quoting desk's job, and the part behaves
        // exactly like one the client raised themselves afterwards.
        'create_client_parts' => ['staff.quoting', 'staff.admin'],

        // Orders still arrive by phone and by email. Typing one in for the
        // client is its own job rather than part of quoting: deciding a price
        // and committing somebody to buy at it are different things, and one
        // person holding both should be a decision rather than a side effect.
        'raise_orders' => ['staff.raise_orders', 'staff.admin'],

        // Applying a quantity change moves what will be invoiced, so it belongs
        // with the people who set the price rather than with the workshop.
        // Whoever can raise an order can also amend one when the PO changes.
        'approve_quantity_changes' => ['staff.quoting', 'staff.raise_orders', 'staff.admin'],

        // Cancelling outstanding quantity off an order is the commercial end of
        // the same decision.
        'close_orders' => ['staff.quoting', 'staff.admin'],
        'push_invoices' => ['staff.invoicing', 'staff.admin'],
        'manage_settings' => ['staff.admin'],
    ];

    public static function allows(array $userRoles, string $capability): bool
    {
        $allowedRoles = self::MATRIX[$capability] ?? [];
        if ($allowedRoles === []) {
            return false;
        }

        if (array_intersect($userRoles, $allowedRoles) !== []) {
            return true;
        }

        if (in_array('staff.admin', $userRoles, true) && self::anyStartsWith($allowedRoles, 'staff.')) {
            return true;
        }

        if (in_array('client.admin', $userRoles, true) && self::anyStartsWith($allowedRoles, 'client.')) {
            return true;
        }

        return false;
    }

    private static function anyStartsWith(array $roles, string $prefix): bool
    {
        foreach ($roles as $role) {
            if (str_starts_with($role, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
