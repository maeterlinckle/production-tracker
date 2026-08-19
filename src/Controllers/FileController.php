<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Upload;
use App\Core\View;
use App\Models\Order;
use App\Models\OrderPhoto;
use App\Models\OrderPoDocument;
use App\Models\Part;
use App\Models\PartFile;
use App\Models\PartMedia;

final class FileController
{
    public function drawing(string $id): void
    {
        $file = PartFile::find((int) $id);
        if ($file === null) {
            View::renderError(404, 'File not found', 'That file does not exist.');

            return;
        }

        $part = Part::find((int) $file['part_id']);
        if ($part === null || (!Auth::isStaff() && (int) $part['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'File not found', 'That file does not exist or is not available to you.');

            return;
        }

        $this->stream($file['file_path'], $file['original_filename'], $file['mime_type'] ?? null);
    }

    /**
     * Anything in a part's media library: photos, setup documents, tooling
     * files.
     *
     * Visible to the client whose part it is as well as to staff. It is their
     * part, and a photo of the finished thing is often the clearest answer to
     * "is this what you meant".
     */
    public function partMedia(string $id): void
    {
        $item = PartMedia::find((int) $id);
        if ($item === null) {
            View::renderError(404, 'File not found', 'That file does not exist.');

            return;
        }

        $part = Part::find((int) $item['part_id']);
        if ($part === null || (!Auth::isStaff() && (int) $part['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'File not found', 'That file does not exist or is not available to you.');

            return;
        }

        $this->stream($item['file_path'], $item['original_filename'], $item['mime_type'] ?? null);
    }

    public function orderPhoto(string $id): void
    {
        $photo = OrderPhoto::find((int) $id);
        if ($photo === null) {
            View::renderError(404, 'File not found', 'That file does not exist.');

            return;
        }

        $order = Order::find((int) $photo['order_id']);
        if ($order === null || (!Auth::isStaff() && (int) $order['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'File not found', 'That file does not exist or is not available to you.');

            return;
        }

        $this->stream($photo['file_path'], $photo['original_filename'], $photo['mime_type'] ?? null);
    }

    public function po(string $id): void
    {
        $order = Order::find((int) $id);
        if ($order === null || (!Auth::isStaff() && (int) $order['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'File not found', 'That file does not exist or is not available to you.');

            return;
        }

        $this->stream($order['po_file_path'], $order['po_original_filename'], null);
    }

    /** Any document in an order's purchase order history, including the original. */
    public function poDocument(string $id): void
    {
        $document = OrderPoDocument::find((int) $id);
        if ($document === null || (!Auth::isStaff() && (int) $document['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'File not found', 'That file does not exist or is not available to you.');

            return;
        }

        $this->stream($document['file_path'], $document['original_filename'], $document['mime_type'] ?? null);
    }

    private function stream(string $relativePath, string $displayName, ?string $mimeType): void
    {
        $absolute = Upload::absolutePath($relativePath);
        if ($absolute === null || !is_file($absolute)) {
            View::renderError(404, 'File not found', 'That file is missing from storage.');

            return;
        }

        Response::file($absolute, $displayName, $mimeType);
    }
}
