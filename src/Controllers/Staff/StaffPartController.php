<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Image;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Core\Validator;
use App\Core\View;
use App\Models\Client;
use App\Models\OrderLine;
use App\Models\Part;
use App\Models\PartFile;
use App\Models\PartLink;
use App\Models\PartMedia;
use App\Services\Notifications;
use App\Services\PartForm;
use App\Services\PartView;

final class StaffPartController
{
    public function index(): void
    {
        $onlyUnquoted = Request::query('filter') === 'unquoted';
        $parts = $onlyUnquoted ? Part::unquoted() : Part::all();

        View::render('staff/parts/index', [
            'title' => 'Parts',
            'parts' => $parts,
            'onlyUnquoted' => $onlyUnquoted,
            'mainPhotos' => PartMedia::mainPhotosFor(array_column($parts, 'id')),
        ]);
    }

    /**
     * Raise a part on a client's behalf (item 1).
     *
     * Enquiries still arrive as a drawing attached to an email, and typing it in
     * for the client is faster than teaching them the form for a one-off. What
     * comes out is an ordinary part on their account: they can see it, edit it
     * and order against it exactly as if they had raised it themselves, and the
     * only trace of where it came from is created_by.
     */
    public function create(): void
    {
        Auth::authorize('create_client_parts');

        View::render('staff/parts/create', [
            'title' => 'New part',
            'clients' => Client::all(),
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('create_client_parts');

        $clientId = (int) Request::post('client_id', 0);
        $client = Client::find($clientId);

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

        if ($client === null) {
            $errors['client_id'] = 'Choose the client this part belongs to.';
        } elseif (!isset($errors['cpn']) && Part::cpnExists($clientId, (string) $data['cpn'])) {
            $errors['cpn'] = 'This client already has a part with that CPN.';
        }

        if ($errors !== []) {
            Flash::setErrors($errors);
            Flash::setOld($data + ['client_id' => $clientId]);
            Response::redirect('/staff/parts/new');
        }

        $data['client_id'] = $clientId;
        $data['created_by'] = Auth::id();
        $partId = Part::create($data);

        $this->saveAltNumbers($partId);
        $this->saveFreeIssueMaterials($partId);
        Part::setFreeIssue($partId, Part::readFreeIssueInput(), (int) Auth::id());

        Flash::success('Part created for ' . $client['name'] . '. It is theirs now — set a price to make it orderable.');
        Response::redirect('/staff/parts/' . $partId);
    }

    private function saveAltNumbers(int $partId): void
    {
        $numbers = Request::post('alt_number', []);
        $labels = Request::post('alt_label', []);

        if (!is_array($numbers)) {
            return;
        }

        foreach ($numbers as $i => $number) {
            if (trim((string) $number) !== '') {
                Part::addAlternateNumber($partId, trim((string) $number), trim((string) ($labels[$i] ?? '')) ?: null);
            }
        }
    }

    private function saveFreeIssueMaterials(int $partId): void
    {
        $references = Request::post('free_issue_ref', []);
        $notes = Request::post('free_issue_notes', []);

        if (!is_array($references)) {
            return;
        }

        foreach ($references as $i => $reference) {
            if (trim((string) $reference) !== '') {
                Part::addFreeIssueMaterial($partId, trim((string) $reference), trim((string) ($notes[$i] ?? '')) ?: null);
            }
        }
    }

    public function show(string $id): void
    {
        $part = Part::find((int) $id);
        if ($part === null) {
            View::renderError(404, 'Part not found', 'That part does not exist.');

            return;
        }

        // The same template the client sees, with Junction's own sections
        // switched on inside it — see App\Services\PartView.
        View::render('parts/show', PartView::payload($part));
    }

    /**
     * Editing a part from the Junction side (item 7).
     *
     * There was no way to do this at all: staff could set a price and fill in
     * workshop details from the part page, but the client-visible fields — the
     * name, the description, the free-issue ratio somebody had typed wrong —
     * could only be corrected by asking the client to do it.
     *
     * The same form the client uses, with more of it switched on. Which parts
     * are switched on is decided in App\Services\PartForm, and enforced there
     * too rather than only hidden here.
     */
    public function edit(string $id): void
    {
        $part = Part::find((int) $id);
        if ($part === null) {
            View::renderError(404, 'Part not found', 'That part does not exist.');

            return;
        }

        if (!PartForm::canEditAnything()) {
            Auth::authorize('create_client_parts');
        }

        View::render('parts/edit', [
            'title' => 'Edit ' . $part['cpn'],
            'part' => $part,
            'altNumbers' => Part::alternateNumbers($part['id']),
            'freeIssueMaterials' => Part::freeIssueMaterials($part['id']),
        ]);
    }

    public function update(string $id): void
    {
        $part = Part::find((int) $id);
        if ($part === null) {
            View::renderError(404, 'Part not found', 'That part does not exist.');

            return;
        }

        if (!PartForm::canEditAnything()) {
            Auth::authorize('create_client_parts');
        }

        $errors = PartForm::apply($part, (int) Auth::id());

        if ($errors !== []) {
            Flash::setErrors($errors);
            Response::redirect('/staff/parts/' . $id . '/edit');
        }

        Flash::success('Part updated.');
        Response::redirect('/staff/parts/' . $id);
    }

    /**
     * Archiving and deleting from the staff side (item 2).
     *
     * These existed only on the client's own page, so a part raised by Junction
     * on a client's behalf could be archived by nobody at Junction. Gated on the
     * same capability as raising one: whoever can put a part on a client's
     * account can take it off again.
     */
    public function archive(string $id): void
    {
        Auth::authorize('create_client_parts');

        $part = Part::find((int) $id);
        if ($part === null) {
            View::renderError(404, 'Part not found', 'That part does not exist.');

            return;
        }

        Part::setArchived((int) $part['id'], !(bool) $part['is_archived'], (int) Auth::id());

        Flash::success((bool) $part['is_archived']
            ? 'Part unarchived.'
            : 'Part archived. It is hidden from active lists but its history is kept, and this can be undone at any time.');
        Response::redirect('/staff/parts/' . $id);
    }

    public function delete(string $id): void
    {
        Auth::authorize('create_client_parts');

        $part = Part::find((int) $id);
        if ($part === null) {
            View::renderError(404, 'Part not found', 'That part does not exist.');

            return;
        }

        if (!Part::delete((int) $part['id'])) {
            Flash::error('This part has orders against it, so it cannot be deleted. Archive it instead to hide it while keeping its history.');
            Response::redirect('/staff/parts/' . $id);
        }

        Flash::success('Part deleted.');
        Response::redirect('/staff/parts');
    }

    /** AJAX: quoted, orderable siblings of a part, for the order builder. */
    public function linkedSummary(string $id): void
    {
        Auth::authorize('raise_orders');

        $part = Part::find((int) $id);
        if ($part === null) {
            Response::json(['results' => []]);

            return;
        }

        Response::json(['results' => array_map(
            static fn ($p) => Part::orderableJson($p),
            Part::linkedOrderable($part['id'])
        )]);
    }

    /**
     * A new revision of the drawing (item 5).
     *
     * The same versioning the client's own upload uses: the new file becomes
     * current and the one it replaces is kept and stays viewable. A drawing is
     * the record of what was agreed, and parts made to the old revision were
     * made to something — overwriting it would lose the only evidence of what.
     */
    /**
     * The same question as the client's own check, for whichever client is
     * selected on the staff form.
     *
     * Its own endpoint rather than a shared one taking a client id: the client's
     * version must only ever answer about their own parts, and the way to
     * guarantee that is for it not to accept a client id at all. This one does,
     * and is behind the capability for creating parts on a client's behalf.
     */
    public function checkCpn(): void
    {
        Auth::authorize('create_client_parts');

        $cpn = trim((string) Request::query('cpn', ''));
        $clientId = (int) Request::query('client_id', 0);

        if ($cpn === '' || $clientId <= 0) {
            Response::json(['available' => null]);
        }

        $existing = Part::findByCpn($clientId, $cpn);

        Response::json($existing === null
            ? ['available' => true]
            : [
                'available' => false,
                'part' => [
                    'cpn' => $existing['cpn'],
                    'name' => $existing['name'],
                    'archived' => (bool) $existing['is_archived'],
                    'url' => url('/staff/parts/' . $existing['id']),
                ],
            ]);
    }

    public function uploadDrawing(string $id): void
    {
        Auth::authorize('edit_workshop_fields');

        $part = Part::find((int) $id);
        if ($part === null) {
            View::renderError(404, 'Part not found', 'That part does not exist.');

            return;
        }

        $allowed = Config::get('uploads.drawing.extensions');
        $maxBytes = (int) Config::get('uploads.drawing.max_bytes');
        $uploaded = 0;

        foreach (Upload::files('drawings') as $file) {
            $error = Upload::validate($file, $allowed, $maxBytes);
            if ($error !== null) {
                Flash::error($error);
                continue;
            }

            $relativePath = Upload::store($file, 'drawings/' . $part['id']);
            $absolutePath = Upload::absolutePath($relativePath);
            $mime = $absolutePath !== null ? Upload::detectMime($absolutePath) : null;

            PartFile::create([
                'part_id' => $part['id'],
                'file_path' => $relativePath,
                'original_filename' => Upload::displayName((string) $file['name']),
                'mime_type' => $mime,
                'file_size' => (int) $file['size'],
                'uploaded_by' => Auth::id(),
            ]);
            $uploaded++;
        }

        if ($uploaded > 0) {
            Flash::success($uploaded === 1
                ? 'Drawing uploaded. It is now the current revision; the one before it is kept in the history.'
                : $uploaded . ' drawings uploaded. The last is the current revision.');
        }

        Response::redirect('/staff/parts/' . $id);
    }

    // -- Part media library (item 6) -----------------------------------------

    /**
     * Reference material that belongs to the part rather than to one order:
     * what the finished thing looks like, how it sits on the machine, what the
     * settings were, the programs that cut it.
     */
    public function uploadMedia(string $id): void
    {
        Auth::authorize('edit_workshop_fields');

        $part = Part::find((int) $id);
        if ($part === null) {
            View::renderError(404, 'Part not found', 'That part does not exist.');

            return;
        }

        $kind = (string) Request::post('kind', 'photo');
        if (!in_array($kind, PartMedia::KINDS, true)) {
            $kind = 'photo';
        }

        $rules = match ($kind) {
            'document' => 'uploads.part_document',
            'tooling' => 'uploads.part_tooling',
            default => 'uploads.photo',
        };

        $allowed = Config::get($rules . '.extensions');
        $maxBytes = (int) Config::get($rules . '.max_bytes');
        $caption = trim((string) Request::post('caption', '')) ?: null;
        $asMain = $kind === 'photo' && (bool) Request::post('is_main');
        $uploaded = 0;

        foreach (Upload::files('files') as $file) {
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
                'kind' => $kind,
                // Only the first of a multi-file upload can be the main photo;
                // the rest would each demote the one before it and the last
                // would win, which is not what anybody meant by ticking a box.
                'is_main' => $asMain && $uploaded === 0,
                'caption' => $caption,
                'file_path' => $relativePath,
                'original_filename' => Upload::displayName((string) $file['name']),
                'mime_type' => $mime,
                'thumb_path' => Image::process($relativePath, $mime),
                'file_size' => (int) $file['size'],
                'uploaded_by' => Auth::id(),
            ]);
            $uploaded++;
        }

        if ($uploaded > 0) {
            Flash::success($uploaded . ' file(s) added to this part. They stay with the part for every order of it.');
        }

        Response::redirect('/staff/parts/' . $id);
    }

    public function setMainMedia(string $id, string $mediaId): void
    {
        Auth::authorize('edit_workshop_fields');

        $item = PartMedia::find((int) $mediaId);
        if ($item !== null && (int) $item['part_id'] === (int) $id) {
            PartMedia::setMain((int) $mediaId);
            Flash::success('Main photo updated.');
        }

        Response::redirect('/staff/parts/' . $id);
    }

    public function deleteMedia(string $id, string $mediaId): void
    {
        Auth::authorize('edit_workshop_fields');

        $item = PartMedia::find((int) $mediaId);
        if ($item !== null && (int) $item['part_id'] === (int) $id) {
            Upload::delete($item['file_path']);
            PartMedia::delete((int) $mediaId);
            Flash::success('File removed.');
        }

        Response::redirect('/staff/parts/' . $id);
    }

    public function setPrice(string $id): void
    {
        Auth::authorize('set_pricing');
        $price = Request::post('quoted_price', '');

        $validator = new Validator(['quoted_price' => $price]);
        $validator->required('quoted_price', 'Quoted price')->numeric('quoted_price', 'Quoted price');

        if ($validator->fails()) {
            Flash::error('Enter a valid quoted price.');
            Response::redirect('/staff/parts/' . $id);
        }

        Part::setQuotedPrice((int) $id, (float) $price, (int) Auth::id());
        Notifications::partQuoted(Part::find((int) $id));
        Flash::success('Quoted price set — the client can now see it and place an order.');
        Response::redirect('/staff/parts/' . $id);
    }

    public function updateWorkshopFields(string $id): void
    {
        Auth::authorize('edit_workshop_fields');

        Part::updateStaffFields((int) $id, [
            'internal_notes' => Request::post('internal_notes') ?: null,
            'build_time_minutes' => Request::post('build_time_minutes') ?: null,
            'base_material' => Request::post('base_material') ?: null,
            'material_source' => Request::post('material_source') ?: null,
            'material_cost' => Request::post('material_cost') ?: null,
        ], (int) Auth::id());

        // The toggle, the source materials and the ratio are the client's
        // values; Junction can correct them, and that correction is recorded
        // against whoever made it. Same order as the client's own form: clear,
        // re-save, then apply the toggle, which clears again if it is now off.
        Part::clearFreeIssueMaterials((int) $id);
        $this->saveFreeIssueMaterials((int) $id);
        Part::setFreeIssue((int) $id, Part::readFreeIssueInput(), (int) Auth::id());

        Flash::success('Workshop details updated.');
        Response::redirect('/staff/parts/' . $id);
    }
}
