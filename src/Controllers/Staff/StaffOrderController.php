<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Core\View;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderNote;
use App\Models\OrderPhoto;
use App\Models\OrderQuery;
use App\Models\Part;
use App\Models\RouteCard;
use App\Services\Notifications;
use App\Services\PdfService;
use App\Services\QrCodeService;

final class StaffOrderController
{
    public function index(): void
    {
        View::render('staff/orders/index', ['title' => 'Orders', 'orders' => Order::all()]);
    }

    public function show(string $id): void
    {
        $order = Order::find((int) $id);
        if ($order === null) {
            View::renderError(404, 'Order not found', 'That order does not exist.');

            return;
        }

        $client = Client::find((int) $order['client_id']);
        $lines = OrderLine::forOrder($order['id']);

        $routeCards = [];
        foreach ($lines as $line) {
            $routeCards[$line['id']] = RouteCard::latestForLine((int) $line['id']);
        }

        $deliveryNotes = DeliveryNote::forOrder($order['id']);
        $invoicesByDn = [];
        foreach ($deliveryNotes as $dn) {
            $invoicesByDn[$dn['id']] = Invoice::forDeliveryNote((int) $dn['id']);
        }

        $queries = array_map(static function (array $q) {
            $q['replies'] = OrderQuery::replies((int) $q['id']);

            return $q;
        }, OrderQuery::forOrder($order['id']));

        View::render('staff/orders/show', [
            'title' => $order['order_number'],
            'order' => $order,
            'client' => $client,
            'lines' => $lines,
            'routeCards' => $routeCards,
            'deliveryNotes' => $deliveryNotes,
            'invoicesByDn' => $invoicesByDn,
            'photos' => OrderPhoto::forOrder($order['id']),
            'notes' => OrderNote::forOrder($order['id']),
            'queries' => $queries,
            'rollupStatus' => Order::rollupStatus($lines),
        ]);
    }

    public function setStage(string $id): void
    {
        Auth::authorize('production_control');
        $line = OrderLine::find((int) $id);
        if ($line === null) {
            View::renderError(404, 'Line not found', 'That order line does not exist.');

            return;
        }

        $stage = (string) Request::post('stage', '');
        if (!in_array($stage, OrderLine::STAGES, true)) {
            Flash::error('Unknown stage.');
            Response::redirect('/staff/orders/' . $line['order_id']);
        }

        $wasInProduction = $line['stage'] === 'in_production';
        OrderLine::setStage((int) $id, $stage, (int) Auth::id(), Request::post('notes') ?: null);

        if ($stage === 'in_production' && !$wasInProduction) {
            $order = Order::find((int) $line['order_id']);
            if ($order !== null) {
                Notifications::orderLineInProduction($line, $order, (int) $order['client_id']);
            }
        }

        Flash::success('Status updated.');
        Response::redirect('/staff/orders/' . $line['order_id']);
    }

    public function recordCompletion(string $id): void
    {
        Auth::authorize('production_control');
        $line = OrderLine::find((int) $id);
        if ($line === null) {
            View::renderError(404, 'Line not found', 'That order line does not exist.');

            return;
        }

        $qty = (int) Request::post('qty', 0);
        if ($qty <= 0) {
            Flash::error('Enter a quantity completed.');
            Response::redirect('/staff/orders/' . $line['order_id']);
        }

        OrderLine::recordCompletion((int) $id, $qty, (int) Auth::id());

        Flash::success('Completion recorded.');
        Response::redirect('/staff/orders/' . $line['order_id']);
    }

    public function generateRouteCard(string $id): void
    {
        Auth::authorize('production_control');
        $line = OrderLine::find((int) $id);
        if ($line === null) {
            View::renderError(404, 'Line not found', 'That order line does not exist.');

            return;
        }

        $order = Order::find((int) $line['order_id']);
        $part = Part::find((int) $line['part_id']);
        $client = Client::find((int) $order['client_id']);
        $reference = RouteCard::nextReference();

        $qr = QrCodeService::pngDataUri(QrCodeService::jobUrl('/staff/orders/' . $order['id']));
        $relativePath = PdfService::renderAndStore(
            'pdf/route-card',
            [
                'routeCard' => ['reference' => $reference, 'generated_at' => date('Y-m-d H:i:s')],
                'line' => $line,
                'order' => $order,
                'part' => $part,
                'client' => $client,
                'qrDataUri' => $qr,
            ],
            'route-cards/' . $order['id'],
            $reference . '.pdf'
        );

        RouteCard::createWithPdf((int) $id, $reference, (int) Auth::id(), $relativePath);

        Flash::success('Route card generated.');
        Response::redirect('/staff/orders/' . $line['order_id']);
    }

    public function downloadRouteCard(string $id): void
    {
        $routeCard = RouteCard::find((int) $id);
        if ($routeCard === null) {
            View::renderError(404, 'Route card not found', 'That route card does not exist.');

            return;
        }

        $absolute = Upload::absolutePath($routeCard['pdf_file_path']);
        if ($absolute === null || !is_file($absolute)) {
            View::renderError(404, 'File not found', 'The route card PDF is missing from storage.');

            return;
        }

        Response::file($absolute, $routeCard['reference'] . '.pdf', 'application/pdf');
    }

    public function uploadPhoto(string $id): void
    {
        Auth::authorize('production_control');
        $order = Order::find((int) $id);
        if ($order === null) {
            View::renderError(404, 'Order not found', 'That order does not exist.');

            return;
        }

        $allowed = \App\Core\Config::get('uploads.photo.extensions');
        $maxBytes = (int) \App\Core\Config::get('uploads.photo.max_bytes');
        $lineId = (int) Request::post('order_line_id', 0) ?: null;
        $caption = trim((string) Request::post('caption', '')) ?: null;

        foreach (Upload::files('photos') as $file) {
            $error = Upload::validate($file, $allowed, $maxBytes);
            if ($error !== null) {
                Flash::error($error);
                continue;
            }

            $relativePath = Upload::store($file, 'order-photos/' . $order['id']);
            $absolutePath = Upload::absolutePath($relativePath);

            OrderPhoto::create([
                'order_id' => $order['id'],
                'order_line_id' => $lineId,
                'file_path' => $relativePath,
                'original_filename' => Upload::displayName((string) $file['name']),
                'mime_type' => $absolutePath !== null ? Upload::detectMime($absolutePath) : null,
                'file_size' => (int) $file['size'],
                'caption' => $caption,
                'uploaded_by' => Auth::id(),
            ]);
        }

        Flash::success('Photo uploaded.');
        Response::redirect('/staff/orders/' . $id);
    }

    public function deletePhoto(string $id, string $photoId): void
    {
        Auth::authorize('production_control');
        $photo = OrderPhoto::find((int) $photoId);
        if ($photo !== null && (int) $photo['order_id'] === (int) $id) {
            Upload::delete($photo['file_path']);
            OrderPhoto::delete($photo['id']);
            Flash::success('Photo removed.');
        }

        Response::redirect('/staff/orders/' . $id);
    }
}
