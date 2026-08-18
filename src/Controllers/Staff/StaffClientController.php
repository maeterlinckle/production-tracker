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
use App\Services\Invitations;

final class StaffClientController
{
    public function index(): void
    {
        View::render('staff/clients/index', ['title' => 'Clients', 'clients' => Client::all()]);
    }

    public function create(): void
    {
        Auth::authorize('manage_clients');
        View::render('staff/clients/create', ['title' => 'New client']);
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

        $data = $this->extract();
        $data['is_active'] = Request::boolean('is_active') ? 1 : 0;

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
            'address_postcode' => Request::post('address_postcode') ?: null,
            'address_country' => Request::post('address_country') ?: 'United Kingdom',
            'main_contact_name' => Request::post('main_contact_name') ?: null,
            'main_contact_email' => Request::post('main_contact_email') ?: null,
            'main_contact_phone' => Request::post('main_contact_phone') ?: null,
            'billing_email' => Request::post('billing_email') ?: null,
            'notes' => Request::post('notes') ?: null,
        ];
    }
}
