<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fixed capability -> allowed-role-slugs matrix. Deliberately not a generic
 * admin-editable permission grid -- the seven roles are spec'd and fixed, so
 * a small in-code table is simpler and just as auditable.
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
        'view_pricing' => ['client.purchaser', 'client.admin', 'staff.quoting', 'staff.admin'],

        // Client side.
        'manage_parts' => ['client.purchaser', 'client.admin'],
        'place_orders' => ['client.purchaser', 'client.admin'],
        'manage_client_users' => ['client.admin'],

        // Asking for a different quantity commits the client to buying more, or
        // to paying for whatever has already been made of what they are cutting
        // back, so it sits with the people who could place the order in the
        // first place rather than with everybody who can read it.
        'request_quantity_change' => ['client.purchaser', 'client.admin'],

        // Both sides. Reading an order and talking about it are not client-only
        // activities: every staff role works from the order pages, and a query
        // is a conversation, so either side has to be able to open one. Listing
        // the staff roles explicitly rather than special-casing `isStaff()` at
        // the call sites is what lets the navigation ask the same question the
        // controllers do.
        'view_orders' => [
            'client.production', 'client.purchaser', 'client.admin',
            'staff.production', 'staff.quoting', 'staff.invoicing', 'staff.admin',
        ],
        'raise_queries' => [
            'client.production', 'client.purchaser', 'client.admin',
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

        // Applying a quantity change moves what will be invoiced, so it belongs
        // with the people who set the price rather than with the workshop.
        'approve_quantity_changes' => ['staff.quoting', 'staff.admin'],

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
