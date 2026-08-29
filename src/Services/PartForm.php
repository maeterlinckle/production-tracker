<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Validator;
use App\Models\Part;

/**
 * Reading a part edit out of the request, and applying whatever the person
 * doing it is allowed to change.
 *
 * There is one edit form for both audiences, so there has to be one place that
 * decides what a submission is permitted to touch — and it has to be the
 * server. The template hides what somebody cannot edit; this refuses it, which
 * is a different job. A client posting `internal_notes` gets nothing.
 *
 * The capability names are the same ones the template asks, so the form and the
 * rules behind it cannot drift into disagreeing about who sees what.
 */
final class PartForm
{
    /** Everything a client may set on their own part, and staff on their behalf. */
    public static function canEditClientFields(): bool
    {
        return Auth::isStaff()
            ? Auth::can('create_client_parts')
            : Auth::can('manage_parts');
    }

    public static function canEditWorkshopFields(): bool
    {
        return Auth::isStaff() && Auth::can('edit_workshop_fields');
    }

    public static function canSetPricing(): bool
    {
        return Auth::isStaff() && Auth::can('set_pricing');
    }

    /** Anybody who can change something on this form at all. */
    public static function canEditAnything(): bool
    {
        return self::canEditClientFields() || self::canEditWorkshopFields();
    }

    /**
     * Validate and apply. Returns field => message; an empty array means it
     * saved.
     *
     * @return array<string,string>
     */
    public static function apply(array $part, int $userId): array
    {
        $partId = (int) $part['id'];
        $errors = [];

        if (self::canEditClientFields()) {
            $errors = self::applyClientFields($partId, $errors);
        }

        if (self::canEditWorkshopFields()) {
            self::applyWorkshopFields($partId, $userId);
        }

        if (self::canSetPricing()) {
            self::applyPricing($partId, $part, $userId);
        }

        return $errors;
    }

    /**
     * @param array<string,string> $errors
     * @return array<string,string>
     */
    private static function applyClientFields(int $partId, array $errors): array
    {
        $data = [
            'name' => Request::post('name', ''),
            'description' => Request::post('description', ''),
            'target_price' => Request::post('target_price') ?: null,
            'notes' => Request::post('notes', ''),
        ] + Part::readOrderReferenceInput();

        $validator = new Validator($data);
        $validator->required('name', 'Name');
        Part::validateOrderReference($data, $validator);

        if ($data['target_price'] !== null) {
            $validator->numeric('target_price', 'Target price');
        }

        if ($validator->fails()) {
            return $errors + $validator->errors();
        }

        // Absent from readOrderReferenceInput() means the person saving was
        // never shown the box. Preserve what is stored rather than blanking a
        // figure they could not see — the same rule material cost follows.
        if (!array_key_exists('last_order_value', $data)) {
            $existing = Part::find($partId);
            $data['last_order_value'] = $existing['last_order_value'] ?? null;
        }

        Part::updateClientFields($partId, $data, (int) Auth::id());

        // Both lists are replace-in-full: the form shows every row it holds, so
        // what comes back is the whole list, and a row somebody deleted from
        // the form has to actually go.
        Part::clearAlternateNumbers($partId);
        self::saveAlternateNumbers($partId);

        Part::clearFreeIssueMaterials($partId);
        self::saveFreeIssueMaterials($partId);
        Part::setFreeIssue($partId, Part::readFreeIssueInput(), (int) Auth::id());

        return $errors;
    }

    private static function applyWorkshopFields(int $partId, int $userId): void
    {
        $data = [
            'internal_notes' => Request::post('internal_notes') ?: null,
            'base_material' => Request::post('base_material') ?: null,
            'material_source' => Request::post('material_source') ?: null,
        ];

        // Material cost is a price, so it follows the same rule every other
        // price does: absent from the form and refused here for anybody without
        // view_pricing, rather than merely hidden.
        $data['material_cost'] = Auth::can('view_pricing')
            ? (Request::post('material_cost') ?: null)
            : null;

        if (!Auth::can('view_pricing')) {
            // Preserve what is there rather than blanking it on somebody else's
            // save. A workshop user editing build time must not wipe a cost
            // they were never shown.
            $existing = Part::find($partId);
            $data['material_cost'] = $existing['material_cost'] ?? null;
        }

        Part::updateStaffFields($partId, $data, $userId);
    }

    private static function applyPricing(int $partId, array $part, int $userId): void
    {
        // An unticked checkbox posts nothing, so the flag is read as
        // present-or-absent rather than from a value.
        Part::setPriceUnderReview($partId, (bool) Request::post('price_under_review'), $userId);

        $posted = Request::post('quoted_price');

        // Setting a price is what makes a part orderable, so it goes through
        // setQuotedPrice() — which also flips the status and records who priced
        // it. Clearing the box is not a way to un-quote a part; that would
        // silently strip it from every order form.
        if ($posted !== null && $posted !== '' && is_numeric($posted)) {
            $price = (float) $posted;

            if ($part['quoted_price'] === null || (float) $part['quoted_price'] !== $price) {
                Part::setQuotedPrice($partId, $price, $userId);
            }
        }
    }

    private static function saveAlternateNumbers(int $partId): void
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

    private static function saveFreeIssueMaterials(int $partId): void
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
}
