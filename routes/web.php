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

    $router->get('/delivery-notes/{id:\d+}/pdf', [DeliveryNoteController::class, 'downloadPdf']);

    $router->get('/files/drawings/{id:\d+}', [FileController::class, 'drawing']);
    $router->get('/files/po/{id:\d+}', [FileController::class, 'po']);
    $router->get('/files/part-photos/{id:\d+}', [FileController::class, 'partPhoto']);
    $router->get('/files/order-photos/{id:\d+}', [FileController::class, 'orderPhoto']);

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
    $router->get('/staff/clients/{id:\d+}', [StaffClientController::class, 'show']);
    $router->post('/staff/clients/{id:\d+}', [StaffClientController::class, 'update'], ['csrf']);
    $router->post('/staff/clients/{id:\d+}/users', [StaffClientController::class, 'addUser'], ['csrf']);
    $router->post('/staff/clients/{id:\d+}/users/{userId:\d+}/reinvite', [StaffClientController::class, 'reinviteUser'], ['csrf']);

    $router->get('/staff/parts', [StaffPartController::class, 'index']);
    $router->get('/staff/parts/{id:\d+}', [StaffPartController::class, 'show']);
    $router->post('/staff/parts/{id:\d+}/price', [StaffPartController::class, 'setPrice'], ['csrf']);
    $router->post('/staff/parts/{id:\d+}/workshop-fields', [StaffPartController::class, 'updateWorkshopFields'], ['csrf']);

    $router->get('/staff/orders', [StaffOrderController::class, 'index']);
    $router->get('/staff/orders/{id:\d+}', [StaffOrderController::class, 'show']);
    $router->post('/staff/lines/{id:\d+}/stage', [StaffOrderController::class, 'setStage'], ['csrf']);
    $router->post('/staff/lines/{id:\d+}/route-card', [StaffOrderController::class, 'generateRouteCard'], ['csrf']);
    $router->get('/staff/route-cards/{id:\d+}/pdf', [StaffOrderController::class, 'downloadRouteCard']);
    $router->post('/staff/lines/{id:\d+}/completion', [StaffOrderController::class, 'recordCompletion'], ['csrf']);
    $router->post('/staff/orders/{id:\d+}/photos', [StaffOrderController::class, 'uploadPhoto'], ['csrf']);
    $router->post('/staff/orders/{id:\d+}/photos/{photoId:\d+}/delete', [StaffOrderController::class, 'deletePhoto'], ['csrf']);

    $router->get('/staff/lines/{id:\d+}/check-in', [StaffCheckInController::class, 'show']);
    $router->post('/staff/lines/{id:\d+}/check-in', [StaffCheckInController::class, 'store'], ['csrf']);
    $router->post('/staff/lines/{id:\d+}/check-in/discrepancy/{receiptId:\d+}/resolve', [StaffCheckInController::class, 'resolveDiscrepancy'], ['csrf']);

    $router->get('/staff/clients/{clientId:\d+}/free-issue-note/new', [StaffDeliveryNoteController::class, 'createFreeIssue']);
    $router->post('/staff/clients/{clientId:\d+}/free-issue-note', [StaffDeliveryNoteController::class, 'storeFreeIssue'], ['csrf']);
    $router->get('/staff/clients/{clientId:\d+}/delivery-note/new', [StaffDeliveryNoteController::class, 'createGoodsOut']);
    $router->post('/staff/clients/{clientId:\d+}/delivery-note', [StaffDeliveryNoteController::class, 'storeGoodsOut'], ['csrf']);

    $router->get('/staff/delivery-notes', [StaffDeliveryNoteController::class, 'index']);
    $router->get('/staff/delivery-notes/{id:\d+}', [StaffDeliveryNoteController::class, 'show']);
    $router->get('/staff/delivery-notes/{id:\d+}/pdf', [StaffDeliveryNoteController::class, 'downloadPdf']);

    $router->post('/staff/delivery-notes/{id:\d+}/invoice', [StaffInvoiceController::class, 'raise'], ['csrf']);

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
