<?php

declare(strict_types=1);

namespace App\Controllers\Client;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Upload;
use App\Core\View;
use App\Models\DeliveryNote;
use App\Services\FreeIssueNoteService;

/** Delivery notes live grouped on their order page -- this controller just streams the PDF. */
final class DeliveryNoteController
{
    public function downloadPdf(string $id): void
    {
        $note = DeliveryNote::find((int) $id);
        if ($note === null || (!Auth::isStaff() && (int) $note['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'Delivery note not found', 'That delivery note does not exist or is not available to you.');

            return;
        }

        // Free-issue notes are standing requests and are rendered fresh, so what
        // the client prints always asks for what is still outstanding today
        // rather than what was outstanding when the order was placed.
        if ($note['type'] === 'free_issue_in') {
            $rendered = FreeIssueNoteService::renderLive((int) $id);
            Response::inlineBytes($rendered['bytes'], $rendered['filename'], 'application/pdf');

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
