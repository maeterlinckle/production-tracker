<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Models\Client;
use App\Models\OrderLine;
use App\Models\Part;
use App\Models\PartFile;
use App\Models\PartLink;
use App\Models\PartPhoto;
use App\Services\Notifications;

final class StaffPartController
{
    public function index(): void
    {
        $onlyUnquoted = Request::query('filter') === 'unquoted';
        $parts = $onlyUnquoted ? Part::unquoted() : Part::all();

        View::render('staff/parts/index', ['title' => 'Parts', 'parts' => $parts, 'onlyUnquoted' => $onlyUnquoted]);
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

        View::render('staff/parts/show', [
            'title' => $part['cpn'],
            'part' => $part,
            'files' => PartFile::forPart($part['id']),
            'photos' => PartPhoto::forPart($part['id']),
            'altNumbers' => Part::alternateNumbers($part['id']),
            'freeIssueMaterials' => Part::freeIssueMaterials($part['id']),
            'linkedParts' => PartLink::forPart($part['id']),
            'orderLines' => OrderLine::forPart($part['id']),
        ]);
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
        ]);

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
