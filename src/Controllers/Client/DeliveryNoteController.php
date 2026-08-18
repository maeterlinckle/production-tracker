<?php

declare(strict_types=1);

namespace App\Controllers\Client;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Upload;
use App\Core\View;
use App\Models\DeliveryNote;

/** Delivery notes now live grouped on their order page (item 9) -- this controller just streams the PDF. */
final class DeliveryNoteController
{
    public function downloadPdf(string $id): void
    {
        $note = DeliveryNote::find((int) $id);
        if ($note === null || (!Auth::isStaff() && (int) $note['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'Delivery note not found', 'That delivery note does not exist or is not available to you.');

            return;
        }

        $absolute = $note['pdf_file_path'] !== null ? Upload::absolutePath($note['pdf_file_path']) : null;
        if ($absolute === null || !is_file($absolute)) {
            View::renderError(404, 'File not found', 'The delivery note PDF is missing from storage.');

            return;
        }

        Response::file($absolute, $note['reference'] . '.pdf', 'application/pdf');
    }
}
