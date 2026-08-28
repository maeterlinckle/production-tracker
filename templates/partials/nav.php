<?php
/**
 * Responsive navigation.
 *
 * A handful of top-level destinations, with everything occasional grouped
 * underneath: the actions that used to be buttons buried on a list page (new
 * part, place an order) are menu entries of their own, and the whole admin area
 * — client companies included — lives under Settings. Every entry is filtered
 * by capability, so a user only ever sees what their roles can actually reach,
 * and a group with nothing visible in it disappears rather than opening onto an
 * empty list.
 *
 * Desktop and mobile render from the same markup on purpose. A group is a
 * <details> element: on a phone it is an accordion inside the slide-out menu,
 * on a desktop the same element is styled as a drop-down. There is no second
 * structure to keep in step, and it works with JavaScript switched off.
 */

use App\Core\Auth;

$user    = auth_user();
$isStaff = Auth::isStaff();
$home    = $isStaff ? '/staff' : '/';

/**
 * A nav entry. 'children' makes it a group; the parent's own href is then only
 * a label, not a destination.
 */
$staffLinks = [
    // The doing comes before the reading: the orders group leads with the work
    // in front of the workshop rather than with the full history.
    ['label' => 'Orders', 'href' => '/staff/orders', 'permission' => null, 'children' => [
        ['label' => 'Place an order', 'href' => '/staff/orders/new',     'permission' => 'raise_orders'],
        ['label' => 'All orders',     'href' => '/staff/orders',         'permission' => 'view_orders'],
        ['label' => 'Delivery notes', 'href' => '/staff/delivery-notes', 'permission' => 'view_orders'],
    ]],

    ['label' => 'Parts', 'href' => '/staff/parts', 'permission' => null, 'children' => [
        ['label' => 'New part',  'href' => '/staff/parts/new', 'permission' => 'create_client_parts'],
        ['label' => 'All parts', 'href' => '/staff/parts',     'permission' => null],
    ]],

    ['label' => 'Reports', 'href' => '/staff/reports', 'permission' => 'view_orders'],

    // Everything occasional: set up once, visited rarely. Client companies are
    // here rather than in the day-to-day flow for the same reason — a client is
    // created when it is taken on and then largely left alone.
    ['label' => 'Settings', 'href' => '/staff/settings', 'permission' => null, 'children' => [
        ['label' => 'Clients',         'href' => '/staff/clients',                  'permission' => 'manage_clients'],
        ['label' => 'Users',           'href' => '/staff/settings/users',           'permission' => 'manage_settings'],
        ['label' => 'Quoting',         'href' => '/staff/settings/quoting',         'permission' => 'manage_settings'],
        ['label' => 'Logo',            'href' => '/staff/settings/branding',        'permission' => 'manage_settings'],
        ['label' => 'Email',           'href' => '/staff/settings/email',           'permission' => 'manage_settings'],
        ['label' => 'Email templates', 'href' => '/staff/settings/email/templates', 'permission' => 'manage_settings'],
        ['label' => 'Reminders',       'href' => '/staff/settings/email/reminders', 'permission' => 'manage_settings'],
        ['label' => 'Clear Books',     'href' => '/staff/settings/clearbooks',      'permission' => 'manage_settings'],
    ]],
];

$clientLinks = [
    ['label' => 'Parts', 'href' => '/parts', 'permission' => null, 'children' => [
        ['label' => 'New part',  'href' => '/parts/new', 'permission' => 'manage_parts'],
        ['label' => 'All parts', 'href' => '/parts',     'permission' => null],
    ]],

    ['label' => 'Orders', 'href' => '/orders', 'permission' => null, 'children' => [
        ['label' => 'Place an order', 'href' => '/orders/new', 'permission' => 'place_orders'],
        ['label' => 'All orders',     'href' => '/orders',     'permission' => 'view_orders'],
    ]],

    ['label' => 'Team', 'href' => '/team', 'permission' => 'manage_client_users'],
];

$links = $isStaff ? $staffLinks : $clientLinks;

/** Can this user see this entry at all? */
$allowed = static function (array $link): bool {
    return $link['permission'] === null || can((string) $link['permission']);
};

// Resolve groups: drop the children a user cannot see, then drop the group if
// nothing is left in it.
$visible = [];

foreach ($links as $link) {
    if (!$allowed($link)) {
        continue;
    }

    if (isset($link['children'])) {
        $link['children'] = array_values(array_filter($link['children'], $allowed));

        if ($link['children'] === []) {
            continue;
        }
    }

    $visible[] = $link;
}

/**
 * Which child of a group is the page you are actually on — at most one.
 *
 * Not `is_active_path()` per child: these menus nest one path inside another
 * ("New part" is /parts/new and "All parts" is /parts), so a prefix rule would
 * mark both as current. `active_path()` picks the longest match.
 */
$activeChild = static function (array $link): ?string {
    return active_path($link['children'] === [] ? [] : array_column($link['children'], 'href'));
};

$accountPaths  = ['/preferences'];
$accountActive = active_path($accountPaths);
$accountOpen   = $accountActive !== null;
?>
<header class="site-header">
    <div class="container header-inner">
        <?php /* Not itself a link: the logo inside carries the link home, and
                 the wordmark beside it is sized to sit level with the menu. */ ?>
        <div class="brand">
            <?= partial('partials/brand', [
                'appName'  => config('app.product', 'Production Tracker'),
                'homeHref' => $home,
            ]) ?>
        </div>

        <nav id="primary-nav" class="primary-nav" data-nav aria-label="Main">
            <ul class="nav-list">
                <?php foreach ($visible as $link): ?>
                    <li class="<?= isset($link['children']) ? 'nav-item nav-item-group' : 'nav-item' ?>">
                        <?php if (!isset($link['children'])): ?>
                            <?php $current = active_path_score($link['href']) > 0; ?>
                            <a href="<?= e(url($link['href'])) ?>"
                               class="nav-link<?= $current ? ' is-active' : '' ?>"
                                <?= $current ? 'aria-current="page"' : '' ?>>
                                <?= e($link['label']) ?>
                            </a>
                        <?php else: ?>
                            <?php /* `open` marks the section you are in. On a phone that
                                     expands the accordion, which is the point. On a
                                     desktop the panel is an overlay, so it would sit on
                                     top of the page you just navigated to —
                                     data-nav-autoopen lets the stylesheet keep it shut
                                     there until you actually reach for it. */ ?>
                            <?php $active = $activeChild($link); ?>
                            <details class="nav-group" data-nav-group
                                <?= $active !== null ? 'open data-nav-autoopen' : '' ?>>
                                <summary class="nav-link nav-group-toggle<?= $active !== null ? ' is-active' : '' ?>">
                                    <span><?= e($link['label']) ?></span>
                                    <span class="caret" aria-hidden="true"></span>
                                </summary>
                                <ul class="nav-sublist">
                                    <?php foreach ($link['children'] as $child): ?>
                                        <?php $isCurrent = $child['href'] === $active; ?>
                                        <li>
                                            <a href="<?= e(url($child['href'])) ?>"
                                               class="nav-link nav-sublink<?= $isCurrent ? ' is-active' : '' ?>"
                                                <?= $isCurrent ? 'aria-current="page"' : '' ?>>
                                                <?= e($child['label']) ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="nav-account">
                <button type="button" class="btn btn-ghost btn-icon" data-theme-toggle title="Switch between light and dark">
                    <span class="theme-icon" aria-hidden="true"></span>
                    <span class="btn-label" data-theme-label>Dark mode</span>
                </button>

                <?php /* The account menu. Personal settings only — notification
                         preferences are one person's own choices, so they belong
                         here rather than in the admin area. Same <details>
                         mechanics as the main nav groups. */ ?>
                <details class="nav-group nav-account-group" data-nav-group
                    <?= $accountOpen ? 'open data-nav-autoopen' : '' ?>>
                    <summary class="nav-link nav-user nav-group-toggle<?= $accountOpen ? ' is-active' : '' ?>">
                        <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($user['name'] ?? '?'), 0, 1))) ?></span>
                        <span class="nav-user-text">
                            <span class="nav-user-name"><?= e($user['name'] ?? '') ?></span>
                            <span class="nav-user-role"><?= e(role_summary()) ?></span>
                        </span>
                        <span class="caret" aria-hidden="true"></span>
                    </summary>
                    <ul class="nav-sublist">
                        <?php /* On a desktop the bar shows only the avatar, so the
                                 menu is where you confirm who you are signed in as. */ ?>
                        <li class="nav-account-identity">
                            <strong><?= e($user['name'] ?? '') ?></strong>
                            <span><?= e(role_summary()) ?></span>
                        </li>
                        <li>
                            <a href="<?= e(url('/preferences')) ?>"
                               class="nav-link nav-sublink<?= $accountActive === '/preferences' ? ' is-active' : '' ?>">Email notifications</a>
                        </li>
                    </ul>
                </details>

                <form method="post" action="<?= e(url('/logout')) ?>" class="nav-logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost">Sign out</button>
                </form>
            </div>
        </nav>

        <div class="header-actions">
            <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="primary-nav">
                <span class="nav-toggle-bars" aria-hidden="true"><span></span><span></span><span></span></span>
                <span class="sr-only">Menu</span>
            </button>
        </div>
    </div>
</header>
