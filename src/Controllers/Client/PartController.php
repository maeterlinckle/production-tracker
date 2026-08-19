<?php

declare(strict_types=1);

namespace App\Controllers\Client;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Image;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Core\Validator;
use App\Core\View;
use App\Models\Part;
use App\Models\PartFile;
use App\Models\PartLink;
use App\Models\PartMedia;
use App\Services\PartForm;
use App\Services\PartView;

final class PartController
{
    public function index(): void
    {
        $showArchived = Request::query('filter') === 'archived';
        $parts = Part::forClient((int) Auth::clientId(), true);

        if (!$showArchived) {
            $parts = array_values(array_filter($parts, static fn ($p) => !(bool) $p['is_archived']));
        } else {
            $parts = array_values(array_filter($parts, static fn ($p) => (bool) $p['is_archived']));
        }

        View::render('parts/index', ['title' => 'Parts', 'parts' => $parts, 'showArchived' => $showArchived]);
    }

    public function create(): void
    {
        Auth::authorize('manage_parts');
        View::render('parts/create', ['title' => 'New part']);
    }

    public function store(): void
    {
        Auth::authorize('manage_parts');
        $clientId = (int) Auth::clientId();

        $data = [
            'cpn' => Request::post('cpn', ''),
            'name' => Request::post('name', ''),
            'description' => Request::post('description', ''),
            'usual_order_qty' => Request::post('usual_order_qty') ?: null,
            'target_price' => Request::post('target_price') ?: null,
            'notes' => Request::post('notes', ''),
        ];

        $validator = new Validator($data);
        $validator->required('cpn', 'Client part number')
            ->maxLength('cpn', 'Client part number', 80)
            ->required('name', 'Name');

        if ($data['usual_order_qty'] !== null) {
            $validator->integerMin('usual_order_qty', 'Usual order quantity', 1);
        }
        if ($data['target_price'] !== null) {
            $validator->numeric('target_price', 'Target price');
        }

        $errors = $validator->errors();
        if (!isset($errors['cpn']) && Part::cpnExists($clientId, (string) $data['cpn'])) {
            $errors['cpn'] = 'You already have a part with this CPN.';
        }

        if ($errors !== []) {
            Flash::setErrors($errors);
            Flash::setOld($data);
            Response::redirect('/parts/new');
        }

        $data['client_id'] = $clientId;
        $data['created_by'] = Auth::id();
        $partId = Part::create($data);

        $this->saveAltNumbers($partId);
        $this->saveFreeIssueMaterials($partId);
        $this->saveFreeIssue($partId);
        $this->handleFileUploads($partId);

        Flash::success('Part created. Junction will review it and set a quoted price.');
        Response::redirect('/parts/' . $partId);
    }

    public function show(string $id): void
    {
        $part = $this->findOwnedPart((int) $id);

        // One payload, one template, both audiences — see App\Services\PartView.
        View::render('parts/show', PartView::payload($part));
    }

    /**
     * The edit form — the same one Junction uses, with the fields a client is
     * allowed to set. See templates/parts/edit.php and App\Services\PartForm.
     */
    public function edit(string $id): void
    {
        Auth::authorize('manage_parts');
        $part = $this->findOwnedPart((int) $id);

        // `errors` is injected by View::capture() from the flash, so a failed
        // save redirects back here with its messages intact.
        View::render('parts/edit', [
            'title' => 'Edit ' . $part['cpn'],
            'part' => $part,
            'altNumbers' => Part::alternateNumbers($part['id']),
            'freeIssueMaterials' => Part::freeIssueMaterials($part['id']),
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('manage_parts');
        $part = $this->findOwnedPart((int) $id);

        $errors = PartForm::apply($part, (int) Auth::id());

        if ($errors !== []) {
            Flash::setErrors($errors);
            Response::redirect('/parts/' . $id . '/edit');
        }

        Flash::success('Part updated.');
        Response::redirect('/parts/' . $id);
    }

    public function archive(string $id): void
    {
        Auth::authorize('manage_parts');
        $part = $this->findOwnedPart((int) $id);

        Part::setArchived((int) $part['id'], !(bool) $part['is_archived'], (int) Auth::id());
        Flash::success((bool) $part['is_archived'] ? 'Part unarchived.' : 'Part archived. It is hidden from active lists but its history is kept, and this can be undone at any time.');
        Response::redirect('/parts/' . $id);
    }

    public function delete(string $id): void
    {
        Auth::authorize('manage_parts');
        $part = $this->findOwnedPart((int) $id);

        if (!Part::delete($part['id'])) {
            Flash::error('This part has orders against it, so it cannot be deleted. Archive it instead to hide it while keeping its history.');
            Response::redirect('/parts/' . $id);
        }

        Flash::success('Part deleted.');
        Response::redirect('/parts');
    }

    public function uploadFile(string $id): void
    {
        Auth::authorize('manage_parts');
        $part = $this->findOwnedPart((int) $id);
        $this->handleFileUploads($part['id']);

        Flash::success('Drawing uploaded.');
        Response::redirect('/parts/' . $part['id']);
    }

    public function uploadPhoto(string $id): void
    {
        Auth::authorize('manage_parts');
        $part = $this->findOwnedPart((int) $id);

        $allowed = Config::get('uploads.photo.extensions');
        $maxBytes = (int) Config::get('uploads.photo.max_bytes');

        foreach (Upload::files('photos') as $file) {
            $error = Upload::validate($file, $allowed, $maxBytes);
            if ($error !== null) {
                Flash::error($error);
                continue;
            }

            $relativePath = Upload::store($file, 'part-media/' . $part['id']);
            $absolutePath = Upload::absolutePath($relativePath);
            $mime = $absolutePath !== null ? Upload::detectMime($absolutePath) : null;

            PartMedia::create([
                'part_id' => $part['id'],
                'kind' => 'photo',
                'file_path' => $relativePath,
                'original_filename' => Upload::displayName((string) $file['name']),
                'mime_type' => $mime,
                'thumb_path' => Image::process($relativePath, $mime),
                'file_size' => (int) $file['size'],
                'uploaded_by' => Auth::id(),
            ]);
        }

        Flash::success('Photo uploaded.');
        Response::redirect('/parts/' . $id);
    }

    public function deletePhoto(string $id, string $photoId): void
    {
        Auth::authorize('manage_parts');
        $part = $this->findOwnedPart((int) $id);

        $photo = PartMedia::find((int) $photoId);
        if ($photo !== null && (int) $photo['part_id'] === $part['id']) {
            Upload::delete($photo['file_path']);
            PartMedia::delete((int) $photo['id']);
            Flash::success('Photo removed.');
        }

        Response::redirect('/parts/' . $id);
    }

    /** AJAX: quoted/orderable parts matching a search term, for the Place Order combobox. */
    public function searchOrderable(): void
    {
        Auth::authorize('place_orders');
        $term = trim((string) Request::query('q', ''));
        $results = $term === '' ? [] : Part::searchOrderable((int) Auth::clientId(), $term);

        Response::json(['results' => array_map(fn ($p) => Part::orderableJson($p), $results)]);
    }

    /** AJAX: quoted/orderable siblings of a part via "usually ordered with" links. */
    public function linkedSummary(string $id): void
    {
        Auth::authorize('place_orders');
        $part = $this->findOwnedPart((int) $id);

        Response::json(['results' => array_map(fn ($p) => Part::orderableJson($p), Part::linkedOrderable($part['id']))]);
    }

    /** AJAX: parts in the same client not yet linked, matching a search term. */
    public function searchLinkable(string $id): void
    {
        Auth::authorize('manage_parts');
        $part = $this->findOwnedPart((int) $id);
        $term = trim((string) Request::query('q', ''));

        $linkedIds = array_column(PartLink::forPart($part['id']), 'id');
        $results = $term === '' ? [] : Part::searchAll((int) Auth::clientId(), $term, $part['id']);
        $results = array_values(array_filter($results, static fn ($p) => !in_array((int) $p['id'], $linkedIds, true)));

        Response::json(['results' => array_map(static fn ($p) => [
            'id' => (int) $p['id'],
            'cpn' => $p['cpn'],
            'name' => $p['name'],
        ], $results)]);
    }

    public function linkPart(string $id): void
    {
        Auth::authorize('manage_parts');
        $part = $this->findOwnedPart((int) $id);
        $otherId = (int) Request::post('linked_part_id', 0);
        $other = Part::find($otherId);

        if ($other !== null && (int) $other['client_id'] === (int) Auth::clientId()) {
            PartLink::link($part['id'], $otherId, (int) Auth::id());
            Flash::success('Linked ' . $other['cpn'] . '.');
        }

        Response::redirect('/parts/' . $id);
    }

    public function unlinkPart(string $id, string $otherId): void
    {
        Auth::authorize('manage_parts');
        $part = $this->findOwnedPart((int) $id);

        PartLink::unlink($part['id'], (int) $otherId);
        Flash::success('Link removed.');
        Response::redirect('/parts/' . $id);
    }

    private function saveAltNumbers(int $partId): void
    {
        $altNumbers = Request::post('alt_number', []);
        $altLabels = Request::post('alt_label', []);
        if (is_array($altNumbers)) {
            foreach ($altNumbers as $i => $number) {
                if (trim((string) $number) !== '') {
                    Part::addAlternateNumber($partId, trim((string) $number), trim((string) ($altLabels[$i] ?? '')) ?: null);
                }
            }
        }
    }

    private function saveFreeIssueMaterials(int $partId): void
    {
        $freeIssueRefs = Request::post('free_issue_ref', []);
        $freeIssueNotes = Request::post('free_issue_notes', []);
        if (is_array($freeIssueRefs)) {
            foreach ($freeIssueRefs as $i => $ref) {
                if (trim((string) $ref) !== '') {
                    Part::addFreeIssueMaterial($partId, trim((string) $ref), trim((string) ($freeIssueNotes[$i] ?? '')) ?: null);
                }
            }
        }
    }

    /**
     * The client's own free-issue answer: whether there is any, and if so what
     * the ratio is.
     *
     * It lives with the part rather than with an order because it is a property
     * of how the part is made, not of one purchase order — and it has to be
     * known before the first order so the free-issue quantity can be worked out
     * as the order is built. Junction can correct it later from the workshop
     * details; whoever last touched it is recorded either way.
     *
     * Called after the source materials are saved: turning the toggle off clears
     * them, so a form submitted with the box unchecked cannot leave a part
     * listing material it does not use.
     */
    private function saveFreeIssue(int $partId): void
    {
        Part::setFreeIssue($partId, Part::readFreeIssueInput(), (int) Auth::id());
    }

    private function handleFileUploads(int $partId): void
    {
        $allowed = Config::get('uploads.drawing.extensions');
        $maxBytes = (int) Config::get('uploads.drawing.max_bytes');

        foreach (Upload::files('drawings') as $file) {
            $error = Upload::validate($file, $allowed, $maxBytes);
            if ($error !== null) {
                Flash::error($error);
                continue;
            }

            $relativePath = Upload::store($file, 'drawings/' . $partId);
            $absolutePath = Upload::absolutePath($relativePath);
            $mime = $absolutePath !== null ? Upload::detectMime($absolutePath) : null;

            PartFile::create([
                'part_id' => $partId,
                'file_path' => $relativePath,
                'original_filename' => Upload::displayName((string) $file['name']),
                'mime_type' => $mime,
                'file_size' => (int) $file['size'],
                'uploaded_by' => Auth::id(),
            ]);
        }
    }

    private function findOwnedPart(int $id): array
    {
        $part = Part::find($id);
        if ($part === null || (!Auth::isStaff() && (int) $part['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'Part not found', 'That part does not exist or is not available to you.');
            exit;
        }

        return $part;
    }
}
