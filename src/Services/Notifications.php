<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Capabilities;
use App\Mail\Mailer;
use App\Models\DeliveryNote;
use App\Models\NotificationPreference;
use App\Models\OrderLine;
use App\Models\Role;
use App\Models\User;

/**
 * Fires the opt-in email notifications at each key workflow stage.
 *
 * Every user starts subscribed to nothing, so "notify" here always means
 * "notify if they asked to hear about this". The wording of each message lives
 * in App\Mail\EmailTemplate and is editable from Settings → Email templates;
 * what this class supplies is the merge fields, which is the contract between
 * the two — a field named here is a field the editor offers, because both read
 * the same array.
 */
final class Notifications
{
    public static function partQuoted(array $part): void
    {
        self::notifyUser(
            (int) $part['created_by'],
            'part_quoted',
            [
                'cpn'          => (string) $part['cpn'],
                'part_name'    => (string) $part['name'],
                'quoted_price' => format_money($part['quoted_price'] ?? null),
                'part_url'     => absolute_url('/parts/' . $part['id']),
            ],
            'part',
            (int) $part['id'],
            // The quoted price is in the body, so this one follows the same
            // rule as the invoice message below.
            'view_pricing'
        );
    }

    /**
     * Confirms an order to the client side.
     *
     * When a client places their own order that is the person who placed it.
     * When Junction types one in on the phone it is the client's own users —
     * telling the member of staff who just pressed the button that they pressed
     * the button is not a notification, and the people who need to know their
     * order is on the system are at the other end.
     */
    public static function orderConfirmed(array $order, array $placedByUser): void
    {
        $fields = [
            'order_number' => (string) $order['order_number'],
            'po_filename'  => (string) ($order['po_original_filename'] ?? '—'),
            'line_count'   => (string) count(OrderLine::forOrder((int) $order['id'])),
            'order_url'    => absolute_url('/orders/' . $order['id']),
        ];

        if (($placedByUser['side'] ?? 'client') === 'staff') {
            self::notifyClientUsers((int) $order['client_id'], 'order_confirmed', $fields, 'order', (int) $order['id']);

            return;
        }

        self::notifyUser((int) $placedByUser['id'], 'order_confirmed', $fields, 'order', (int) $order['id']);
    }

    public static function orderLineInProduction(array $line, array $order, int $clientId): void
    {
        self::notifyClientUsers(
            $clientId,
            'order_in_production',
            [
                'order_number' => (string) $order['order_number'],
                'cpn'          => (string) $line['cpn'],
                'part_name'    => (string) $line['part_name'],
                'qty_ordered'  => (string) (int) $line['qty_ordered'],
                'order_url'    => absolute_url('/orders/' . $order['id']),
            ],
            'order',
            (int) $order['id']
        );
    }

    public static function freeIssueNoteIssued(array $deliveryNote, int $clientId): void
    {
        $lines = DeliveryNote::lines((int) $deliveryNote['id']);
        $first = $lines[0] ?? [];

        self::notifyClientUsers(
            $clientId,
            'free_issue_note_issued',
            [
                'reference'    => (string) $deliveryNote['reference'],
                'order_number' => (string) ($first['order_number'] ?? '—'),
                'cpn'          => (string) ($first['cpn'] ?? '—'),
                'part_name'    => (string) ($first['part_name'] ?? '—'),
                'qty_required' => (string) (int) ($first['qty'] ?? 0),
                'note_url'     => absolute_url('/delivery-notes/' . $deliveryNote['id'] . '/pdf'),
            ],
            'delivery_note',
            (int) $deliveryNote['id']
        );
    }

    public static function freeIssueCheckedIn(array $line, array $order, int $clientId): void
    {
        self::notifyClientUsers(
            $clientId,
            'free_issue_checked_in',
            [
                'order_number' => (string) $order['order_number'],
                'cpn'          => (string) $line['cpn'],
                'part_name'    => (string) $line['part_name'],
                'qty_received' => (string) (int) $line['qty_free_issue_received'],
                'qty_required' => (string) (int) $line['qty_free_issue_required'],
                'status'       => OrderLine::freeIssueStatusSentence($line),
                'order_url'    => absolute_url('/orders/' . $order['id']),
            ],
            'order',
            (int) $order['id']
        );
    }

    /**
     * Material that arrived and cannot be used, going back.
     *
     * Deliberately its own message rather than a variation on the check-in one:
     * a rejection asks the client to do something (send more), where a check-in
     * only tells them something.
     */
    public static function materialRejected(array $line, array $order, array $returnNote, int $qty, string $reason, int $clientId): void
    {
        self::notifyClientUsers(
            $clientId,
            'material_rejected',
            [
                'order_number'     => (string) $order['order_number'],
                'cpn'              => (string) $line['cpn'],
                'part_name'        => (string) $line['part_name'],
                'qty_rejected'     => (string) $qty,
                'reason'           => $reason,
                'return_reference' => (string) $returnNote['reference'],
                'qty_outstanding'  => (string) OrderLine::freeIssueOutstanding($line),
                'return_note_url'  => absolute_url('/delivery-notes/' . $returnNote['id'] . '/pdf'),
            ],
            'delivery_note',
            (int) $returnNote['id']
        );
    }

    /** A client asking to change a quantity. Goes to Junction, since Junction decides. */
    public static function quantityChangeRequested(array $request, array $line, array $order, string $requestedBy): void
    {
        $fields = [
            'order_number'  => (string) $order['order_number'],
            'cpn'           => (string) $line['cpn'],
            'part_name'     => (string) $line['part_name'],
            'requested_by'  => $requestedBy,
            'qty_current'   => (string) (int) $request['qty_at_request'],
            'qty_requested' => (string) (int) $request['qty_requested'],
            'reason'        => trim((string) ($request['reason'] ?? '')) ?: 'No reason given.',
            'order_url'     => absolute_url('/staff/orders/' . $order['id']),
        ];

        foreach (User::allStaff() as $user) {
            self::notifyUser((int) $user['id'], 'quantity_change_requested', $fields, 'order', (int) $order['id']);
        }
    }

    public static function quantityChangeDecided(array $request, array $line, array $order, string $outcome, string $decidedBy, int $clientId): void
    {
        $notes = trim((string) ($request['review_notes'] ?? ''));

        self::notifyClientUsers(
            $clientId,
            'quantity_change_decided',
            [
                'order_number'   => (string) $order['order_number'],
                'cpn'            => (string) $line['cpn'],
                'part_name'      => (string) $line['part_name'],
                'qty_current'    => (string) (int) $request['qty_at_request'],
                'qty_requested'  => (string) (int) $request['qty_requested'],
                'outcome'        => $outcome,
                'decided_by'     => $decidedBy,
                'decision_notes' => $notes,
                'order_url'      => absolute_url('/orders/' . $order['id']),
            ],
            'order',
            (int) $order['id']
        );
    }

    public static function deliveryNoteIssued(array $deliveryNote, int $clientId): void
    {
        $items = [];
        foreach (DeliveryNote::lines((int) $deliveryNote['id']) as $line) {
            $items[] = $line['order_number'] . '  ' . $line['cpn'] . ' ' . $line['part_name'] . ' — ' . (int) $line['qty'];
        }

        self::notifyClientUsers(
            $clientId,
            'delivery_note_issued',
            [
                'reference' => (string) $deliveryNote['reference'],
                'items'     => implode("\n", $items),
                'note_url'  => absolute_url('/delivery-notes/' . $deliveryNote['id'] . '/pdf'),
            ],
            'delivery_note',
            (int) $deliveryNote['id']
        );
    }

    /**
     * Contains the invoice amount, so — per the pricing-visibility rule — only
     * goes to recipients who hold view_pricing, regardless of what they have
     * opted into. A client.production user simply never gets this one, not a
     * version with the number removed.
     */
    public static function invoiceRaised(array $invoice, array $deliveryNote, int $clientId): void
    {
        self::notifyClientUsers(
            $clientId,
            'invoice_raised',
            [
                'invoice_number' => (string) $invoice['clearbooks_invoice_number'],
                'amount'         => format_money($invoice['amount']),
                'reference'      => (string) $deliveryNote['reference'],
                'raised_at'      => format_date($invoice['raised_at']),
            ],
            'invoice',
            (int) $invoice['id'],
            'view_pricing'
        );
    }

    /** Notifies everyone on the order's client side plus all staff — either side can raise a query. */
    public static function queryRaised(array $query, array $order): void
    {
        $fields = [
            'order_number' => (string) $order['order_number'],
            'raised_by'    => (string) ($query['raised_by_name'] ?? 'Somebody'),
            'subject'      => (string) $query['subject'],
            'question'     => (string) $query['body'],
        ];

        foreach (array_merge(User::forClient((int) $order['client_id']), User::allStaff()) as $user) {
            if ((int) $user['id'] === (int) $query['raised_by']) {
                continue;
            }

            self::notifyUser(
                (int) $user['id'],
                'query_raised',
                $fields + ['order_url' => self::orderUrlFor($user, (int) $order['id'])],
                'order',
                (int) $order['id']
            );
        }
    }

    public static function queryAnswered(array $query, array $order, string $reply = '', string $answeredBy = ''): void
    {
        $recipient = User::find((int) $query['raised_by']);

        self::notifyUser(
            (int) $query['raised_by'],
            'query_answered',
            [
                'order_number' => (string) $order['order_number'],
                'subject'      => (string) $query['subject'],
                'reply'        => $reply,
                'answered_by'  => $answeredBy,
                'order_url'    => $recipient === null
                    ? absolute_url('/orders/' . $order['id'])
                    : self::orderUrlFor($recipient, (int) $order['id']),
            ],
            'order',
            (int) $order['id']
        );
    }

    /** Staff and clients read an order at different addresses. */
    private static function orderUrlFor(array $user, int $orderId): string
    {
        return absolute_url(($user['side'] ?? 'client') === 'staff'
            ? '/staff/orders/' . $orderId
            : '/orders/' . $orderId);
    }

    /**
     * @param array<string,string> $fields
     */
    private static function notifyUser(
        int $userId,
        string $type,
        array $fields,
        string $relatedType,
        int $relatedId,
        ?string $requiredCapability = null
    ): void {
        $user = User::find($userId);

        if ($user === null || !(bool) $user['is_active'] || !NotificationPreference::isSubscribed($userId, $type)) {
            return;
        }

        if ($requiredCapability !== null && !Capabilities::allows(Role::slugsForUser($userId), $requiredCapability)) {
            return;
        }

        Mailer::sendTemplate($type, $user['email'], $user['name'], $fields, $relatedType, $relatedId);
    }

    /**
     * @param array<string,string> $fields
     */
    private static function notifyClientUsers(
        int $clientId,
        string $type,
        array $fields,
        string $relatedType,
        int $relatedId,
        ?string $requiredCapability = null
    ): void {
        foreach (User::forClient($clientId) as $user) {
            self::notifyUser((int) $user['id'], $type, $fields, $relatedType, $relatedId, $requiredCapability);
        }
    }
}
