<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
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

        // The relationship is the client's value; Junction can correct it, and
        // that correction is recorded against whoever made it.
        $freeIssue = Part::readFreeIssueInput();
        Part::setFreeIssueRelationship(
            (int) $id,
            $freeIssue['free_issue_relationship'],
            $freeIssue['free_issue_factor'],
            (int) Auth::id()
        );

        Flash::success('Workshop details updated.');
        Response::redirect('/staff/parts/' . $id);
    }
}
