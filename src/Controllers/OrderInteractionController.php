<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\OrderQuery;
use App\Services\Notifications;

/** Order notes and queries (item 8) -- shared between the client and staff order pages, since both sides raise/answer them. */
final class OrderInteractionController
{
    public function addNote(string $id): void
    {
        $order = $this->findVisibleOrder((int) $id);
        if ($order === null) {
            return;
        }

        $body = trim((string) Request::post('body', ''));
        if ($body === '') {
            Flash::error('Enter a note.');
            Response::redirect($this->orderUrl($order['id']));
        }

        OrderNote::create($order['id'], (int) Auth::id(), $body);
        Flash::success('Note added.');
        Response::redirect($this->orderUrl($order['id']));
    }

    public function raiseQuery(string $id): void
    {
        $order = $this->findVisibleOrder((int) $id);
        if ($order === null) {
            return;
        }

        Auth::authorize('raise_queries');

        $subject = trim((string) Request::post('subject', ''));
        $body = trim((string) Request::post('body', ''));
        if ($subject === '' || $body === '') {
            Flash::error('Enter a subject and a question.');
            Response::redirect($this->orderUrl($order['id']));
        }

        $queryId = OrderQuery::create($order['id'], (int) Auth::id(), $subject, $body);
        Notifications::queryRaised(OrderQuery::find($queryId), $order);

        Flash::success('Query raised.');
        Response::redirect($this->orderUrl($order['id']));
    }

    public function replyQuery(string $id, string $queryId): void
    {
        $order = $this->findVisibleOrder((int) $id);
        if ($order === null) {
            return;
        }

        $query = OrderQuery::find((int) $queryId);
        if ($query === null || (int) $query['order_id'] !== $order['id']) {
            View::renderError(404, 'Query not found', 'That query does not exist on this order.');

            return;
        }

        $body = trim((string) Request::post('body', ''));
        if ($body === '') {
            Flash::error('Enter a reply.');
            Response::redirect($this->orderUrl($order['id']));
        }

        OrderQuery::reply($query['id'], (int) Auth::id(), $body);

        if ((int) Auth::id() !== (int) $query['raised_by']) {
            Notifications::queryAnswered($query, $order, $body, Auth::name());
        }

        Flash::success('Reply added.');
        Response::redirect($this->orderUrl($order['id']));
    }

    private function findVisibleOrder(int $id): ?array
    {
        $order = Order::find($id);
        if ($order === null || (!Auth::isStaff() && (int) $order['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'Order not found', 'That order does not exist or is not available to you.');

            return null;
        }

        return $order;
    }

    private function orderUrl(int $orderId): string
    {
        return Auth::isStaff() ? '/staff/orders/' . $orderId : '/orders/' . $orderId;
    }
}
