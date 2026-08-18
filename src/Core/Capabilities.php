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
