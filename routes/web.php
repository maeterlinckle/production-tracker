<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\BrandingController;
use App\Controllers\FileController;
use App\Controllers\HealthController;
use App\Controllers\InviteController;
use App\Controllers\OrderInteractionController;
use App\Controllers\PreferencesController;
use App\Controllers\Client\DashboardController;
use App\Controllers\Client\DeliveryNoteController;
use App\Controllers\Client\OrderController;
use App\Controllers\Client\PartController;
use App\Controllers\Client\TeamController;
use App\Controllers\Staff\ClearBooksController;
use App\Controllers\Staff\EmailSettingsController;
use App\Controllers\Staff\EmailTemplateController;
use App\Controllers\Staff\ReminderController;
use App\Controllers\Staff\ReportController;
use App\Controllers\Staff\SettingsController;
use App\Controllers\Staff\StaffUserController;
use App\Controllers\Staff\StaffCheckInController;
use App\Controllers\Staff\StaffClientController;
use App\Controllers\Staff\StaffDashboardController;
use App\Controllers\Staff\StaffDeliveryNoteController;
use App\Controllers\Staff\StaffInvoiceController;
use App\Controllers\Staff\StaffOrderController;
use App\Controllers\Staff\StaffPartController;
use App\Core\Router;

$router = new Router();

// -- Public / auth ----------------------------------------------------------
$router->get('/login', [AuthController::class, 'showLogin'], ['guest'], 'login');
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);
$router->get('/branding/logo/{variant:light|dark}', [BrandingController::class, 'logo']);

// Unauthenticated on purpose, and says only whether the application is up and
// can reach its database. install.sh and manage.sh both call it.
$router->get('/health', [HealthController::class, 'index']);

// Accepting an invitation. Reached signed-out, from a link in an email — hence
// 'guest' rather than 'auth'. The token is 64 hex characters; anything else is
// not an invitation and never reaches the controller.
$router->get('/invite/{token:[a-f0-9]{64}}', [InviteController::class, 'show'], ['guest']);
$router->post('/invite/{token:[a-f0-9]{64}}', [InviteController::class, 'accept'], ['guest', 'csrf']);

// -- Client area (also reachable by staff, scoped to their own client) -----
$router->group(['auth'], function (Router $router): void {
    $router->get('/', [DashboardController::class, 'index'], [], 'dashboard');

    $router->get('/parts', [PartController::class, 'index']);
    $router->get('/parts/new', [PartController::class, 'create']);
    $router->post('/parts', [PartController::class, 'store'], ['csrf']);
    $router->get('/parts/{id:\d+}', [PartController::class, 'show']);
    $router->get('/parts/{id:\d+}/edit', [PartController::class, 'edit']);
    $router->post('/parts/{id:\d+}', [PartController::class, 'update'], ['csrf']);
    $router->post('/parts/{id:\d+}/archive', [PartController::class, 'archive'], ['csrf']);
    $router->post('/parts/{id:\d+}/delete', [PartController::class, 'delete'], ['csrf']);
    $router->post('/parts/{id:\d+}/files', [PartController::class, 'uploadFile'], ['csrf']);
    $router->post('/parts/{id:\d+}/photos', [PartController::class, 'uploadPhoto'], ['csrf']);
    $router->post('/parts/{id:\d+}/photos/{photoId:\d+}/delete', [PartController::class, 'deletePhoto'], ['csrf']);
    $router->get('/parts/{id:\d+}/link-search', [PartController::class, 'searchLinkable']);
    $router->get('/parts/{id:\d+}/linked-summary', [PartController::class, 'linkedSummary']);
    $router->get('/parts-search-orderable', [PartController::class, 'searchOrderable']);
    $router->post('/parts/{id:\d+}/links', [PartController::class, 'linkPart'], ['csrf']);
    $router->post('/parts/{id:\d+}/links/{otherId:\d+}/delete', [PartController::class, 'unlinkPart'], ['csrf']);

    $router->get('/orders', [OrderController::class, 'index']);
    $router->get('/orders/new', [OrderController::class, 'create']);
    $router->post('/orders', [OrderController::class, 'store'], ['csrf']);
    $router->get('/orders/{id:\d+}', [OrderController::class, 'show']);
    $router->post('/orders/{id:\d+}/notes', [OrderInteractionController::class, 'addNote'], ['csrf']);
    $router->post('/orders/{id:\d+}/queries', [OrderInteractionController::class, 'raiseQuery'], ['csrf']);
    $router->post('/orders/{id:\d+}/queries/{queryId:\d+}/reply', [OrderInteractionController::class, 'replyQuery'], ['csrf']);

    // Asking for a different quantity, and the purchase order paperwork that
    // usually comes with it (item 8). Neither changes the order by itself.
    $router->post('/orders/{id:\d+}/lines/{lineId:\d+}/change-request', [OrderController::class, 'requestQuantityChange'], ['csrf']);
    $router->post('/orders/{id:\d+}/po-documents', [OrderController::class, 'uploadPoDocument'], ['csrf']);

    // Rejected parts going the other way: the client raises the note, and
    // Junction moves the quantity when the parcel actually turns up.
    $router->post('/orders/{id:\d+}/parts-returns', [OrderController::class, 'raisePartsReturn'], ['csrf']);

    $router->get('/delivery-notes/{id:\d+}/pdf', [DeliveryNoteController::class, 'downloadPdf']);

    $router->get('/files/drawings/{id:\d+}', [FileController::class, 'drawing']);
    $router->get('/files/po/{id:\d+}', [FileController::class, 'po']);
    $router->get('/files/po-documents/{id:\d+}', [FileController::class, 'poDocument']);
    $router->get('/files/part-media/{id:\d+}', [FileController::class, 'partMedia']);
    // The grids ask for the thumbnail; the tile links to the real thing.
    $router->get('/files/part-media/{id:\d+}/{variant:thumb}', [FileController::class, 'partMedia']);
    $router->get('/files/order-photos/{id:\d+}', [FileController::class, 'orderPhoto']);
    $router->get('/files/order-photos/{id:\d+}/{variant:thumb}', [FileController::class, 'orderPhoto']);

    $router->get('/preferences', [PreferencesController::class, 'edit']);
    $router->post('/preferences', [PreferencesController::class, 'update'], ['csrf']);

    $router->get('/team', [TeamController::class, 'index']);
    $router->post('/team', [TeamController::class, 'store'], ['csrf']);
    $router->post('/team/{id:\d+}/roles', [TeamController::class, 'updateRoles'], ['csrf']);
    $router->post('/team/{id:\d+}/toggle-active', [TeamController::class, 'toggleActive'], ['csrf']);
    $router->post('/team/{id:\d+}/reinvite', [TeamController::class, 'reinvite'], ['csrf']);
});

// -- Staff area --------------------------------------------------------------
$router->group(['staff'], function (Router $router): void {
    $router->get('/staff', [StaffDashboardController::class, 'index']);

    $router->get('/staff/clients', [StaffClientController::class, 'index']);
    $router->get('/staff/clients/new', [StaffClientController::class, 'create']);
    $router->post('/staff/clients', [StaffClientController::class, 'store'], ['csrf']);
    // Pulling a client's details from their Clear Books customer record. On
    // demand only -- see App\Services\ClearBooksCustomerSync.
    $router->post('/staff/clients/from-clearbooks', [StaffClientController::class, 'prefillFromClearBooks'], ['csrf']);
    $router->get('/staff/clients/{id:\d+}', [StaffClientController::class, 'show']);
    $router->post('/staff/clients/{id:\d+}', [StaffClientController::class, 'update'], ['csrf']);
    $router->post('/staff/clients/{id:\d+}/from-clearbooks', [StaffClientController::class, 'pullFromClearBooks'], ['csrf']);
    $router->post('/staff/clients/{id:\d+}/users', [StaffClientController::class, 'addUser'], ['csrf']);
    $router->post('/staff/clients/{id:\d+}/users/{userId:\d+}/reinvite', [StaffClientController::class, 'reinviteUser'], ['csrf']);

    $router->get('/staff/parts', [StaffPartController::class, 'index']);
    // Registered before the {id} route so "new" is not read as an id.
    $router->get('/staff/parts/new', [StaffPartController::class, 'create']);
    $router->post('/staff/parts', [StaffPartController::class, 'store'], ['csrf']);
    $router->get('/staff/parts/{id:\d+}', [StaffPartController::class, 'show']);
    $router->get('/staff/parts/{id:\d+}/linked-summary', [StaffPartController::class, 'linkedSummary']);
    $router->get('/staff/parts/{id:\d+}/edit', [StaffPartController::class, 'edit']);
    $router->post('/staff/parts/{id:\d+}', [StaffPartController::class, 'update'], ['csrf']);
    $router->post('/staff/parts/{id:\d+}/archive', [StaffPartController::class, 'archive'], ['csrf']);
    $router->post('/staff/parts/{id:\d+}/delete', [StaffPartController::class, 'delete'], ['csrf']);

    // A new drawing revision, and the part's own media library: setup photos,
    // machine settings, tooling files.
    $router->post('/staff/parts/{id:\d+}/drawings', [StaffPartController::class, 'uploadDrawing'], ['csrf']);
    $router->post('/staff/parts/{id:\d+}/media', [StaffPartController::class, 'uploadMedia'], ['csrf']);
    $router->post('/staff/parts/{id:\d+}/media/{mediaId:\d+}/main', [StaffPartController::class, 'setMainMedia'], ['csrf']);
    $router->post('/staff/parts/{id:\d+}/media/{mediaId:\d+}/delete', [StaffPartController::class, 'deleteMedia'], ['csrf']);
    $router->post('/staff/parts/{id:\d+}/price', [StaffPartController::class, 'setPrice'], ['csrf']);
    $router->post('/staff/parts/{id:\d+}/workshop-fields', [StaffPartController::class, 'updateWorkshopFields'], ['csrf']);

    $router->get('/staff/orders', [StaffOrderController::class, 'index']);

    // Raising an order on a client's behalf. Registered before the {id} route
    // so "new" is not read as an id.
    $router->get('/staff/orders/new', [StaffOrderController::class, 'createOrder']);
    $router->post('/staff/clients/{clientId:\d+}/orders', [StaffOrderController::class, 'storeOrder'], ['csrf']);
    $router->get('/staff/clients/{clientId:\d+}/parts-search-orderable', [StaffOrderController::class, 'searchOrderableForClient']);

    $router->get('/staff/orders/{id:\d+}', [StaffOrderController::class, 'show']);

    // Notes and queries, from Junction's side of the merged order page.
    // OrderInteractionController was written for both audiences from the start
    // -- it admits staff and redirects them back to the staff page -- but only
    // the client half was ever routed, so every staff submission 404d.
    $router->post('/staff/orders/{id:\d+}/notes', [OrderInteractionController::class, 'addNote'], ['csrf']);
    $router->post('/staff/orders/{id:\d+}/queries', [OrderInteractionController::class, 'raiseQuery'], ['csrf']);
    $router->post('/staff/orders/{id:\d+}/queries/{queryId:\d+}/reply', [OrderInteractionController::class, 'replyQuery'], ['csrf']);
    // The quantity workflow (item 6). One move action covers advancing, moving
    // back and failing, because they are the same operation with a different
    // destination.
    $router->post('/staff/lines/{id:\d+}/move', [StaffOrderController::class, 'moveQuantity'], ['csrf']);
    $router->post('/staff/lines/{id:\d+}/replacement-material', [StaffOrderController::class, 'requestReplacementMaterial'], ['csrf']);
    $router->post('/staff/lines/{id:\d+}/close', [StaffOrderController::class, 'closeLine'], ['csrf']);
    $router->post('/staff/lines/{id:\d+}/reopen', [StaffOrderController::class, 'reopenLine'], ['csrf']);
    $router->post('/staff/orders/{id:\d+}/close', [StaffOrderController::class, 'closeOrder'], ['csrf']);

    // Built from the order line every time it is asked for, so there is one
    // action rather than a generate/regenerate pair (item 3).
    $router->get('/staff/lines/{id:\d+}/route-card', [StaffOrderController::class, 'routeCard']);
    $router->get('/staff/orders/{id:\d+}/route-cards', [StaffOrderController::class, 'allRouteCards']);

    $router->post('/staff/orders/{id:\d+}/change-requests/{requestId:\d+}/apply', [StaffOrderController::class, 'applyChangeRequest'], ['csrf']);
    $router->post('/staff/orders/{id:\d+}/change-requests/{requestId:\d+}/decline', [StaffOrderController::class, 'declineChangeRequest'], ['csrf']);
    $router->post('/staff/orders/{id:\d+}/po-documents', [StaffOrderController::class, 'uploadPoDocument'], ['csrf']);
    $router->post('/staff/orders/{id:\d+}/po-number', [StaffOrderController::class, 'updatePoNumber'], ['csrf']);
    $router->post('/staff/orders/{id:\d+}/photos', [StaffOrderController::class, 'uploadPhoto'], ['csrf']);
    $router->post('/staff/orders/{id:\d+}/photos/{photoId:\d+}/delete', [StaffOrderController::class, 'deletePhoto'], ['csrf']);

    $router->get('/staff/lines/{id:\d+}/check-in', [StaffCheckInController::class, 'show']);
    $router->post('/staff/lines/{id:\d+}/check-in', [StaffCheckInController::class, 'store'], ['csrf']);
    $router->post('/staff/lines/{id:\d+}/check-in/discrepancy/{receiptId:\d+}/resolve', [StaffCheckInController::class, 'resolveDiscrepancy'], ['csrf']);

    $router->get('/staff/parts-returns/{id:\d+}/check-in', [StaffCheckInController::class, 'showPartsReturn']);
    $router->post('/staff/parts-returns/{id:\d+}/check-in', [StaffCheckInController::class, 'storePartsReturn'], ['csrf']);

    $router->get('/staff/clients/{clientId:\d+}/free-issue-note/new', [StaffDeliveryNoteController::class, 'createFreeIssue']);
    $router->post('/staff/clients/{clientId:\d+}/free-issue-note', [StaffDeliveryNoteController::class, 'storeFreeIssue'], ['csrf']);
    $router->get('/staff/clients/{clientId:\d+}/delivery-note/new', [StaffDeliveryNoteController::class, 'createGoodsOut']);
    $router->post('/staff/clients/{clientId:\d+}/delivery-note', [StaffDeliveryNoteController::class, 'storeGoodsOut'], ['csrf']);

    $router->get('/staff/delivery-notes', [StaffDeliveryNoteController::class, 'index']);
    $router->get('/staff/delivery-notes/{id:\d+}', [StaffDeliveryNoteController::class, 'show']);
    $router->get('/staff/delivery-notes/{id:\d+}/pdf', [StaffDeliveryNoteController::class, 'downloadPdf']);

    $router->post('/staff/delivery-notes/{id:\d+}/invoice', [StaffInvoiceController::class, 'raise'], ['csrf']);
    // Invoicing without the API: the work has gone out and somebody has billed
    // for it, whatever state the Clear Books connection is in.
    $router->post('/staff/delivery-notes/{id:\d+}/invoice-manually', [StaffInvoiceController::class, 'raiseManual'], ['csrf']);

    $router->get('/staff/reports', [ReportController::class, 'index']);
    $router->get('/staff/reports/parts-on-order', [ReportController::class, 'partsOnOrder']);

    $router->get('/staff/settings', [SettingsController::class, 'index']);
    $router->get('/staff/settings/branding', [SettingsController::class, 'branding']);
    $router->post('/staff/settings/logo', [SettingsController::class, 'updateLogo'], ['csrf']);
    $router->post('/staff/settings/logo/{variant:light|dark}/remove', [SettingsController::class, 'removeLogo'], ['csrf']);

    $router->get('/staff/settings/users', [StaffUserController::class, 'index']);
    $router->post('/staff/settings/users', [StaffUserController::class, 'store'], ['csrf']);
    $router->post('/staff/settings/users/{id:\d+}/roles', [StaffUserController::class, 'updateRoles'], ['csrf']);
    $router->post('/staff/settings/users/{id:\d+}/toggle-active', [StaffUserController::class, 'toggleActive'], ['csrf']);
    $router->post('/staff/settings/users/{id:\d+}/reinvite', [StaffUserController::class, 'reinvite'], ['csrf']);

    $router->get('/staff/settings/email', [EmailSettingsController::class, 'index']);
    $router->post('/staff/settings/email', [EmailSettingsController::class, 'update'], ['csrf']);
    $router->post('/staff/settings/email/test', [EmailSettingsController::class, 'test'], ['csrf']);

    // More specific than /staff/settings/email, so these have to be registered
    // where the router will still reach them — the templates and reminders
    // segments are not ids, and would otherwise be swallowed by a looser match.
    $router->get('/staff/settings/email/templates', [EmailTemplateController::class, 'index']);
    $router->get('/staff/settings/email/templates/{key:[a-z0-9_]+}', [EmailTemplateController::class, 'edit']);
    $router->post('/staff/settings/email/templates/{key:[a-z0-9_]+}', [EmailTemplateController::class, 'update'], ['csrf']);
    $router->post('/staff/settings/email/templates/{key:[a-z0-9_]+}/reset', [EmailTemplateController::class, 'reset'], ['csrf']);

    $router->get('/staff/settings/email/reminders', [ReminderController::class, 'index']);
    $router->post('/staff/settings/email/reminders', [ReminderController::class, 'update'], ['csrf']);
    $router->post('/staff/settings/email/reminders/run', [ReminderController::class, 'runNow'], ['csrf']);

    $router->get('/staff/settings/clearbooks', [ClearBooksController::class, 'status']);
    $router->post('/staff/settings/clearbooks', [ClearBooksController::class, 'update'], ['csrf']);
    $router->get('/staff/settings/clearbooks/connect', [ClearBooksController::class, 'connect']);
    $router->post('/staff/settings/clearbooks/posting', [ClearBooksController::class, 'updatePosting'], ['csrf']);
    $router->post('/staff/settings/clearbooks/disconnect', [ClearBooksController::class, 'disconnect'], ['csrf']);
    $router->get('/staff/settings/clearbooks/callback', [ClearBooksController::class, 'callback']);
});

return $router;
