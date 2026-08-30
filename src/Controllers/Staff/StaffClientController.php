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
use App\Models\Order;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use App\Services\ClearBooksCustomerSync;
use App\Services\ClientPurge;
use App\Services\ClientUsers;
use App\Services\Invitations;
use RuntimeException;

final class StaffClientController
{
    /** Field names as a person would say them, for the "what changed" message. */
    private const FIELD_LABELS = [
        'name' => 'name',
        'address_line1' => 'address line 1',
        'address_line2' => 'address line 2',
        'address_city' => 'town',
        'address_county' => 'county',
        'address_postcode' => 'postcode',
        'address_country' => 'country',
        'main_contact_name' => 'contact name',
        'main_contact_email' => 'contact email',
        'main_contact_phone' => 'phone',
        'billing_email' => 'billing email',
        'vat_number' => 'VAT number',
        'company_number' => 'company number',
        'is_active' => 'active/archived',
    ];

    public function index(): void
    {
        View::render('staff/clients/index', ['title' => 'Clients', 'clients' => Client::all()]);
    }

    public function create(): void
    {
        Auth::authorize('manage_clients');
        View::render('staff/clients/create', ['title' => 'New client']);
    }

    /**
     * Fill the new-client form in from Clear Books before anything is saved.
     *
     * There is no local record to update yet, so this fetches, maps and hands
     * the values straight back to the form as prefilled input. Whoever is typing
     * sees what came back and can correct it before it becomes a client —
     * which is better than creating a record and then discovering the fetch
     * brought back somebody else's address.
     *
     * A round trip through the server rather than a fetch in the browser,
     * because this application has no JavaScript build step and one more
     * endpoint is cheaper than one more script.
     */
    public function prefillFromClearBooks(): void
    {
        Auth::authorize('manage_clients');

        $customerId = (int) Request::post('clearbooks_entity_id', 0);
        $typed = $this->extract();

        try {
            $preview = ClearBooksCustomerSync::preview($customerId);
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
            Flash::setOld($typed);
            Response::redirect('/staff/clients/new');

            return;
        }

        // What came back wins, but anything Clear Books does not hold keeps
        // whatever was already typed into the form.
        $prefilled = array_merge($typed, array_filter(
            $preview['fields'],
            static fn (mixed $value): bool => $value !== null && $value !== ''
        ));
        $prefilled['clearbooks_entity_id'] = (string) $customerId;

        Flash::setOld($prefilled);
        Flash::success(
            'Loaded ' . $preview['customer']['name'] . ' from Clear Books. Check it over and save.'
        );
        Response::redirect('/staff/clients/new');
    }

    /** Pull an existing client's details from their Clear Books customer record. */
    public function pullFromClearBooks(string $id): void
    {
        Auth::authorize('manage_clients');
        $client = Client::find((int) $id);
        if ($client === null) {
            View::renderError(404, 'Client not found', 'That client does not exist.');

            return;
        }

        try {
            $changes = ClearBooksCustomerSync::apply((int) $id, (int) Auth::id());
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect('/staff/clients/' . $id);

            return;
        }

        // Say what moved. "Updated from Clear Books" with nothing else is a
        // message that cannot be checked, and the common answer is that the two
        // records already agreed.
        Flash::success($changes === []
            ? 'Clear Books has nothing different — the local record already matches.'
            : 'Updated from Clear Books: ' . implode(', ', array_map(
                static fn (array $change): string => self::fieldLabel($change['field']),
                $changes
            )) . '.');

        Response::redirect('/staff/clients/' . $id);
    }

    private static function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? $field;
    }

    public function store(): void
    {
        Auth::authorize('manage_clients');
        $data = $this->extract();

        $validator = new Validator($data);
        $validator->required('name', 'Client name');
        if ($data['main_contact_email'] !== null) {
            $validator->email('main_contact_email', 'Main contact email');
        }

        if ($validator->fails()) {
            Flash::setErrors($validator->errors());
            Flash::setOld($data);
            Response::redirect('/staff/clients/new');
        }

        $clientId = Client::create($data);
        Flash::success('Client created.');
        Response::redirect('/staff/clients/' . $clientId);
    }

    public function show(string $id): void
    {
        $client = Client::find((int) $id);
        if ($client === null) {
            View::renderError(404, 'Client not found', 'That client does not exist.');

            return;
        }

        $users = User::forClient($client['id']);
        foreach ($users as &$user) {
            $user['has_password'] = User::hasPassword((int) $user['id']);
        }
        unset($user);

        View::render('staff/clients/show', [
            'title' => $client['name'],
            'client' => $client,
            'deactivation' => Client::deactivationDetail((int) $client['id']),
            // What deleting them would remove. Only counted for a switched-off
            // account, because that is the only page that offers it.
            'purgeSummary' => (bool) $client['is_active'] ? [] : ClientPurge::summary((int) $client['id']),
            'users' => $users,
            'parts' => Part::forClient($client['id']),
            'orders' => Order::forClient($client['id']),
            'clientRoles' => Role::forSide('client'),
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('manage_clients');
        $client = Client::find((int) $id);
        if ($client === null) {
            View::renderError(404, 'Client not found', 'That client does not exist.');

            return;
        }

        // Switching an account off is its own action, with its own reason and
        // its own record of who did it -- not a checkbox on the details form
        // that somebody clears while editing a postcode.
        $data = $this->extract();

        $validator = new Validator($data);
        $validator->required('name', 'Client name');

        if ($validator->fails()) {
            Flash::setErrors($validator->errors());
            Response::redirect('/staff/clients/' . $id);
        }

        Client::update((int) $id, $data);
        Flash::success('Client updated.');
        Response::redirect('/staff/clients/' . $id);
    }

    /**
     * Switch a whole client account off, or back on.
     *
     * Nothing is deleted. Their orders and parts drop out of the day-to-day
     * lists, their orders freeze where they stand, and nobody under the
     * account can sign in — all of which is derived from this one flag rather
     * than written across their records, so reactivating puts everything back
     * exactly as it was. See App\Models\Client::setActive().
     */
    public function setActive(string $id): void
    {
        Auth::authorize('manage_clients');

        $client = Client::find((int) $id);
        if ($client === null) {
            View::renderError(404, 'Client not found', 'That client does not exist.');

            return;
        }

        $activate = Request::post('active') === '1';

        if (!$activate && trim((string) Request::post('reason', '')) === '') {
            Flash::error('Say why the account is being switched off — it is the only record of it.');
            Response::redirect('/staff/clients/' . $id);
        }

        Client::setActive((int) $id, $activate, (int) Auth::id(), Request::post('reason'));

        Flash::success($activate
            ? $client['name'] . ' is active again. Their orders, parts and sign-ins are back.'
            : $client['name'] . ' has been switched off. Their orders are frozen, their work is out of the '
                . 'day-to-day lists, and nobody on that account can sign in. Nothing has been deleted.');

        Response::redirect('/staff/clients/' . $id);
    }

    /**
     * Delete a client and everything that was ever theirs.
     *
     * The one place in the application that really deletes rather than
     * archiving. Three things have to be true before it will: staff.admin, an
     * account already switched off, and the client's name typed out in full.
     * The name is what makes it hard to do by accident — a Delete button and a
     * confirmation dialog are two clicks in the same place, and this is not a
     * mistake anybody recovers from.
     */
    public function destroy(string $id): void
    {
        Auth::authorize('manage_clients');

        $client = Client::find((int) $id);
        if ($client === null) {
            View::renderError(404, 'Client not found', 'That client does not exist.');

            return;
        }

        $typed = trim((string) Request::post('confirm_name', ''));

        if ($typed !== trim((string) $client['name'])) {
            Flash::error('The name did not match, so nothing has been deleted. Type "' . $client['name'] . '" exactly.');
            Response::redirect('/staff/clients/' . $id);
        }

        try {
            $result = ClientPurge::purge((int) $id);
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect('/staff/clients/' . $id);

            return;
        }

        $counts = [];
        foreach ($result['rows'] as $label => $n) {
            if ($n > 0) {
                $counts[] = $n . ' ' . $label;
            }
        }

        Flash::success(
            $client['name'] . ' has been deleted, along with '
            . ($counts === [] ? 'everything on the account' : implode(', ', $counts))
            . '. ' . $result['files_deleted'] . ' file(s) removed from disk.'
        );

        Response::redirect('/staff/clients');
    }

    /**
     * Deactivate or reactivate one of a client's users.
     *
     * The same operation the client's own admin has on their team page, from
     * Junction's side — because the person asking for it is as often on the
     * phone to Junction as logged in themselves.
     */
    public function toggleUserActive(string $id, string $userId): void
    {
        Auth::authorize('manage_clients');

        $user = $this->findClientUser((int) $id, (int) $userId);
        if ($user === null) {
            return;
        }

        $deactivating = (bool) $user['is_active'];

        if ($deactivating && ClientUsers::isLastActiveAdmin((int) $user['id'])) {
            Flash::error(
                $user['name'] . ' is the only active administrator on this account. Give somebody else '
                . 'the client admin role first, or the company will have nobody who can manage their own users.'
            );
            Response::redirect('/staff/clients/' . $id);
        }

        User::setActive((int) $user['id'], !$deactivating);

        Flash::success(($deactivating ? 'Deactivated ' : 'Reactivated ') . $user['name']
            . ($deactivating ? '. Their history stays on the orders and queries they raised.' : '.'));

        Response::redirect('/staff/clients/' . $id);
    }

    /** Correct a client user's name or email address. */
    public function updateUser(string $id, string $userId): void
    {
        Auth::authorize('manage_clients');

        $user = $this->findClientUser((int) $id, (int) $userId);
        if ($user === null) {
            return;
        }

        $problem = ClientUsers::updateDetails(
            (int) $user['id'],
            (string) Request::post('name', ''),
            (string) Request::post('email', '')
        );

        $problem === null
            ? Flash::success('Details updated.')
            : Flash::error($problem);

        Response::redirect('/staff/clients/' . $id);
    }

    /**
     * A user, checked to be one of this client's.
     *
     * The id comes off a form, and Junction managing one client's people must
     * not reach into another's through this client's URL.
     *
     * @return array<string,mixed>|null
     */
    private function findClientUser(int $clientId, int $userId): ?array
    {
        $user = User::find($userId);

        if ($user === null || (int) $user['client_id'] !== $clientId) {
            View::renderError(404, 'User not found', 'That user is not on this client account.');

            return null;
        }

        return $user;
    }

    public function addUser(string $id): void
    {
        Auth::authorize('manage_clients');
        $client = Client::find((int) $id);
        if ($client === null) {
            View::renderError(404, 'Client not found', 'That client does not exist.');

            return;
        }

        $name = trim((string) Request::post('name', ''));
        $email = trim((string) Request::post('email', ''));
        $roleSlugs = Request::post('roles', []);
        $roleSlugs = is_array($roleSlugs) ? $roleSlugs : [];

        $validator = new Validator(['name' => $name, 'email' => $email]);
        $validator->required('name', 'Name')->required('email', 'Email')->email('email', 'Email');

        if ($validator->fails()) {
            Flash::error(implode(' ', $validator->errors()));
            Response::redirect('/staff/clients/' . $id);
        }

        if (User::emailExists($email)) {
            Flash::error('A user with that email already exists.');
            Response::redirect('/staff/clients/' . $id);
        }

        $result = Invitations::invite(
            $name,
            $email,
            'client',
            (int) $client['id'],
            $roleSlugs === [] ? ['client.admin'] : $roleSlugs,
            array_column(Role::forSide('client'), 'slug')
        );

        $result['sent']
            ? Flash::success(Invitations::resultMessage($result, $name))
            : Flash::warning(Invitations::resultMessage($result, $name));

        Response::redirect('/staff/clients/' . $id);
    }

    /** Send a client user's invitation again — the first one lapsed, or never arrived. */
    public function reinviteUser(string $id, string $userId): void
    {
        Auth::authorize('manage_clients');

        $user = User::find((int) $userId);
        if ($user === null || (int) $user['client_id'] !== (int) $id) {
            View::renderError(404, 'User not found', 'That user does not exist for this client.');

            return;
        }

        if (User::hasPassword((int) $user['id'])) {
            Flash::error($user['name'] . ' has already set a password, so there is nothing to re-send.');
            Response::redirect('/staff/clients/' . $id);
        }

        $result = Invitations::send((int) $user['id']);

        $result['sent']
            ? Flash::success('A fresh invitation has been sent to ' . $user['email'] . '.')
            : Flash::warning(Invitations::resultMessage($result, (string) $user['name']));

        Response::redirect('/staff/clients/' . $id);
    }

    private function extract(): array
    {
        return [
            'name' => Request::post('name', ''),
            'clearbooks_entity_id' => Request::post('clearbooks_entity_id') ?: null,
            'address_line1' => Request::post('address_line1') ?: null,
            'address_line2' => Request::post('address_line2') ?: null,
            'address_city' => Request::post('address_city') ?: null,
            'address_county' => Request::post('address_county') ?: null,
            'address_postcode' => Request::post('address_postcode') ?: null,
            'address_country' => Request::post('address_country') ?: 'United Kingdom',
            'main_contact_name' => Request::post('main_contact_name') ?: null,
            'main_contact_email' => Request::post('main_contact_email') ?: null,
            'main_contact_phone' => Request::post('main_contact_phone') ?: null,
            'billing_email' => Request::post('billing_email') ?: null,
            'vat_number' => Request::post('vat_number') ?: null,
            'company_number' => Request::post('company_number') ?: null,
            'notes' => Request::post('notes') ?: null,
        ];
    }
}
