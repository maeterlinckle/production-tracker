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

    public static function orderConfirmed(array $order, array $placedByUser): void
    {
        self::notifyUser(
            (int) $placedByUser['id'],
            'order_confirmed',
            [
                'order_number' => (string) $order['order_number'],
                'po_filename'  => (string) ($order['po_original_filename'] ?? '—'),
                'line_count'   => (string) count(OrderLine::forOrder((int) $order['id'])),
                'order_url'    => absolute_url('/orders/' . $order['id']),
            ],
            'order',
            (int) $order['id']
        );
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
