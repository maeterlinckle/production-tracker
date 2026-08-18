<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Auth;
use App\Core\Database;

/**
 * Editable email templates.
 *
 * The defaults below are the single source of truth for what the application
 * sends out of the box. The `email_templates` table stores *overrides* only: a
 * row exists for a key precisely when a staff administrator has edited it. That
 * gives three things worth having —
 *
 *   - a fresh install sends properly worded mail with an empty table;
 *   - "reset to the built-in wording" is a DELETE, not a re-seed, so it cannot
 *     go stale;
 *   - the default text exists in exactly one place, so a migration and a class
 *     have nothing to drift apart about.
 *
 * Each definition also carries its own list of merge fields. That list is what
 * the edit screen documents, so the placeholders an administrator is offered
 * are always the ones the sending code actually supplies — the two cannot get
 * out of step, because they are the same array.
 */
final class EmailTemplate
{
    /**
     * Merge fields every template can use, whatever it is about.
     *
     * @var array<string,string>
     */
    public const COMMON_FIELDS = [
        'app_name'       => 'The application name',
        'app_url'        => 'The base web address of this application',
        'recipient_name' => 'Name of the person the message is addressed to',
        'today'          => 'Today’s date',
    ];

    /**
     * The shipped templates.
     *
     * `fields` documents the merge fields specific to this message. `group`
     * decides the heading it is listed under. `sample` supplies the values the
     * preview uses, so wording can be seen rendered without having to find a
     * genuinely overdue order first.
     *
     * @var array<string,array{name:string,description:string,group:string,subject:string,body:string,fields:array<string,string>,sample:array<string,string>}>
     */
    public const DEFAULTS = [
        // -- Quoting and ordering -------------------------------------------
        'part_quoted' => [
            'name'        => 'Part quoted',
            'description' => 'Sent to whoever created a part once Junction has priced it.',
            'group'       => 'Quoting and ordering',
            'subject'     => '{{cpn}} has been quoted — {{app_name}}',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p><strong>{{cpn}} — {{part_name}}</strong> has been quoted at {{quoted_price}}.</p>

<p>You can now place an order for this part.</p>

<p><a href="{{part_url}}">View the part</a></p>
HTML,
            'fields' => [
                'cpn'          => 'The client part number',
                'part_name'    => 'The part name',
                'quoted_price' => 'The quoted unit price. Only ever sent to somebody allowed to see pricing',
                'part_url'     => 'A direct link to the part',
            ],
            'sample' => [
                'cpn'          => 'ACME-100',
                'part_name'    => 'Spindle housing',
                'quoted_price' => '£48.50',
                'part_url'     => 'https://tracker.example.com/parts/12',
            ],
        ],

        'order_confirmed' => [
            'name'        => 'Order confirmed',
            'description' => 'Sent to the person who placed an order once it has been received.',
            'group'       => 'Quoting and ordering',
            'subject'     => 'Order {{order_number}} confirmed — {{app_name}}',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>Your order <strong>{{order_number}}</strong> has been received and is being processed.</p>

<div class="items">
  Your purchase order: {{po_filename}}<br>
  Lines: {{line_count}}
</div>

<p><a href="{{order_url}}">View the order</a></p>
HTML,
            'fields' => [
                'order_number' => 'The Junction order number, e.g. ORD-2026-0004',
                'po_filename'  => 'The purchase order document the client uploaded, by filename',
                'line_count'   => 'How many lines are on the order',
                'order_url'    => 'A direct link to the order',
            ],
            'sample' => [
                'order_number' => 'ORD-2026-0004',
                'po_filename'  => 'PO-88213.pdf',
                'line_count'   => '3',
                'order_url'    => 'https://tracker.example.com/orders/4',
            ],
        ],

        // -- Production ------------------------------------------------------
        'order_in_production' => [
            'name'        => 'Work started on a line',
            'description' => 'Sent when an order line moves into production.',
            'group'       => 'Production',
            'subject'     => 'Your order {{order_number}} is in production — {{app_name}}',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>Work has started on <strong>{{cpn}} — {{part_name}}</strong> from order
{{order_number}}.</p>

<p><a href="{{order_url}}">View the order</a></p>
HTML,
            'fields' => [
                'order_number' => 'The Junction order number',
                'cpn'          => 'The client part number',
                'part_name'    => 'The part name',
                'qty_ordered'  => 'How many were ordered on this line',
                'order_url'    => 'A direct link to the order',
            ],
            'sample' => [
                'order_number' => 'ORD-2026-0004',
                'cpn'          => 'ACME-100',
                'part_name'    => 'Spindle housing',
                'qty_ordered'  => '20',
                'order_url'    => 'https://tracker.example.com/orders/4',
            ],
        ],

        // -- Free issue ------------------------------------------------------
        'free_issue_note_issued' => [
            'name'        => 'Free-issue delivery note issued',
            'description' => 'Sent when the paperwork for material the client has to send in is generated. Each note carries the QR code Junction scans to book the material in.',
            'group'       => 'Free issue',
            'subject'     => 'Free-issue delivery note {{reference}} — {{app_name}}',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>Free-issue delivery note <strong>{{reference}}</strong> has been generated for
{{cpn}} — {{part_name}} on order {{order_number}}.</p>

<div class="items">
  Material required: {{qty_required}}<br>
  Please enclose this note with the material when you send it to Junction.
</div>

<p><a href="{{note_url}}">View the delivery note</a></p>
HTML,
            'fields' => [
                'reference'    => 'The delivery note reference, e.g. DN-2026-0007',
                'order_number' => 'The order the material is for',
                'cpn'          => 'The client part number',
                'part_name'    => 'The part name',
                'qty_required' => 'How much free-issue material the order line needs',
                'note_url'     => 'A direct link to the delivery note PDF',
            ],
            'sample' => [
                'reference'    => 'DN-2026-0007',
                'order_number' => 'ORD-2026-0004',
                'cpn'          => 'ACME-100',
                'part_name'    => 'Spindle housing',
                'qty_required' => '10',
                'note_url'     => 'https://tracker.example.com/delivery-notes/7/pdf',
            ],
        ],

        'free_issue_checked_in' => [
            'name'        => 'Free issue checked in',
            'description' => 'Sent once material has been booked in against an order line and the line can start.',
            'group'       => 'Free issue',
            'subject'     => 'Free issue checked in for {{order_number}} — {{app_name}}',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>Free-issue material for <strong>{{cpn}} — {{part_name}}</strong> on order
{{order_number}} has been checked in.</p>

<div class="items">
  Received so far: {{qty_received}} of {{qty_required}}<br>
  Status: {{status}}
</div>

<p><a href="{{order_url}}">View the order</a></p>
HTML,
            'fields' => [
                'order_number' => 'The Junction order number',
                'cpn'          => 'The client part number',
                'part_name'    => 'The part name',
                'qty_received' => 'How much has been booked in in total',
                'qty_required' => 'How much the line needs',
                'status'       => 'A ready-made sentence: ready for production, still short, or held by a discrepancy',
                'order_url'    => 'A direct link to the order',
            ],
            'sample' => [
                'order_number' => 'ORD-2026-0004',
                'cpn'          => 'ACME-100',
                'part_name'    => 'Spindle housing',
                'qty_received' => '10',
                'qty_required' => '10',
                'status'       => 'Complete — this line is ready for production.',
                'order_url'    => 'https://tracker.example.com/orders/4',
            ],
        ],

        // -- Despatch and invoicing ------------------------------------------
        'delivery_note_issued' => [
            'name'        => 'Goods-out delivery note issued',
            'description' => 'Sent when finished parts are despatched.',
            'group'       => 'Despatch and invoicing',
            'subject'     => 'Delivery note {{reference}} issued — {{app_name}}',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>Delivery note <strong>{{reference}}</strong> has been issued for parts shipped
to you.</p>

<div class="items">{{items}}</div>

<p><a href="{{note_url}}">View the delivery note</a></p>
HTML,
            'fields' => [
                'reference' => 'The delivery note reference',
                'items'     => 'What is on the note: order, part number and quantity, one per line',
                'note_url'  => 'A direct link to the delivery note PDF',
            ],
            'sample' => [
                'reference' => 'DN-2026-0009',
                'items'     => "ORD-2026-0004  ACME-100 Spindle housing — 12\nORD-2026-0004  ACME-200 End cap — 12",
                'note_url'  => 'https://tracker.example.com/delivery-notes/9/pdf',
            ],
        ],

        'invoice_raised' => [
            'name'        => 'Invoice raised',
            'description' => 'Sent when a Clear Books sales invoice is raised against a delivery note. Contains a money amount, so it only ever goes to recipients allowed to see pricing — everyone else receives nothing rather than a version with the figure removed.',
            'group'       => 'Despatch and invoicing',
            'subject'     => 'Invoice {{invoice_number}} raised — {{app_name}}',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>Invoice <strong>{{invoice_number}}</strong> for {{amount}} has been raised against
delivery note {{reference}}.</p>

<p>It will appear in Clear Books in the usual way.</p>
HTML,
            'fields' => [
                'invoice_number' => 'The Clear Books invoice number',
                'amount'         => 'The invoice total',
                'reference'      => 'The delivery note it was raised against',
                'raised_at'      => 'When it was raised',
            ],
            'sample' => [
                'invoice_number' => 'INV-1042',
                'amount'         => '£1,164.00',
                'reference'      => 'DN-2026-0009',
                'raised_at'      => '17 Aug 2026',
            ],
        ],

        // -- Queries ---------------------------------------------------------
        'query_raised' => [
            'name'        => 'Query raised',
            'description' => 'Sent to the other side of an order when somebody opens a query on it.',
            'group'       => 'Queries',
            'subject'     => 'New query on {{order_number}} — {{app_name}}',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>{{raised_by}} has raised a query on order <strong>{{order_number}}</strong>.</p>

<div class="items">
  <strong>{{subject}}</strong><br>
  {{question}}
</div>

<p><a href="{{order_url}}">View and reply</a></p>
HTML,
            'fields' => [
                'order_number' => 'The Junction order number',
                'raised_by'    => 'Who opened the query',
                'subject'      => 'The query’s subject line',
                'question'     => 'What they asked',
                'order_url'    => 'A direct link to the order',
            ],
            'sample' => [
                'order_number' => 'ORD-2026-0004',
                'raised_by'    => 'Jane Buyer',
                'subject'      => 'Material substitution',
                'question'     => 'Can we run these in EN1A instead? The free issue we have is bar stock.',
                'order_url'    => 'https://tracker.example.com/orders/4',
            ],
        ],

        'query_answered' => [
            'name'        => 'Query answered',
            'description' => 'Sent to whoever opened a query when it gets a reply.',
            'group'       => 'Queries',
            'subject'     => 'Your query on {{order_number}} has a reply — {{app_name}}',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>Your query on order <strong>{{order_number}}</strong> has a reply.</p>

<div class="items">
  <strong>{{subject}}</strong><br>
  {{reply}}<br>
  — {{answered_by}}
</div>

<p><a href="{{order_url}}">View the reply</a></p>
HTML,
            'fields' => [
                'order_number' => 'The Junction order number',
                'subject'      => 'The query’s subject line',
                'reply'        => 'The reply itself',
                'answered_by'  => 'Who replied',
                'order_url'    => 'A direct link to the order',
            ],
            'sample' => [
                'order_number' => 'ORD-2026-0004',
                'subject'      => 'Material substitution',
                'reply'        => 'EN1A is fine for this one — we will note it on the route card.',
                'answered_by'  => 'Nick at Junction',
                'order_url'    => 'https://tracker.example.com/orders/4',
            ],
        ],

        // -- Reminders --------------------------------------------------------
        'parts_outstanding' => [
            'name'        => 'Parts outstanding digest',
            'description' => 'The scheduled round-up of order lines still to be finished, sent to Junction staff who have opted in. Run from cron; see Settings → Reminders.',
            'group'       => 'Reminders',
            'subject'     => '{{count}} part(s) still outstanding — {{app_name}}',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>The following order lines are still outstanding.</p>

<div class="items">{{items}}</div>

<p>{{ageing_line}}</p>

<p><a href="{{report_url}}">Open the parts-on-order report</a></p>
HTML,
            'fields' => [
                'count'         => 'How many lines are listed',
                'items'         => 'The list itself: order, part, client, quantity outstanding and how long it has been waiting',
                'ageing_count'  => 'How many have been open longer than the ageing threshold in Settings → Reminders',
                'ageing_days'   => 'That threshold, in days',
                'ageing_line'   => 'A ready-made sentence about the older ones, or blank if there are none',
                'blocked_count' => 'How many are held waiting for free-issue material',
                'report_url'    => 'A direct link to the parts-on-order report',
            ],
            'sample' => [
                'count'         => '3',
                'items'         => "ORD-2026-0004  ACME-100 Spindle housing — Acme Engineering — 8 of 20 outstanding — 11 days\nORD-2026-0003  ACME-200 End cap — Acme Engineering — 20 of 20 outstanding — awaiting free issue\nORD-2026-0001  ACME-100 Spindle housing — Acme Engineering — 2 of 10 outstanding — 26 days",
                'ageing_count'  => '1',
                'ageing_days'   => '21',
                'ageing_line'   => '1 of these has been open for more than 21 days.',
                'blocked_count' => '1',
                'report_url'    => 'https://tracker.example.com/staff/reports/parts-on-order',
            ],
        ],

        // -- Accounts ---------------------------------------------------------
        // Carries a single-use link, so it may not be switched off — see
        // LOCKED_ACTIVE.
        'user_invite' => [
            'name'        => 'Invitation to set up an account',
            'description' => 'Sent when somebody is invited to the tracker. The link lets them choose their own password; nobody else ever knows it.',
            'group'       => 'Accounts',
            'subject'     => 'Your {{app_name}} account is ready to set up',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>{{invited_by}} has set up an account for you on <strong>{{app_name}}</strong>,
the order tracker for {{company}}.</p>

<div class="items">
  <strong>{{recipient_name}}</strong><br>
  Sign in with: {{email}}<br>
  Access: {{role_names}}
</div>

<p>Choose a password to finish setting it up:</p>

<p><a href="{{invite_url}}">Set your password</a></p>

<p>This link works once and expires in {{expires_in}}. If it has lapsed by the time
you get to it, ask whoever invited you to send a fresh one.</p>

<p>If you were not expecting this you can ignore it — the account cannot be used
until somebody sets a password on it.</p>
HTML,
            'fields' => [
                'email'      => 'The address they will sign in with',
                'role_names' => 'The roles they have been given, as a readable list',
                'company'    => 'Their client company, or Junction for a staff account',
                'invited_by' => 'Name of the person who invited them',
                'invite_url' => 'The single-use link itself. A message without this is a message nobody can act on',
                'expires_in' => 'How long the link lasts, e.g. “7 days”',
            ],
            'sample' => [
                'email'      => 'jane@acme-eng.example',
                'role_names' => 'Purchaser',
                'company'    => 'Acme Engineering Ltd',
                'invited_by' => 'Nick at Junction',
                'invite_url' => 'https://tracker.example.com/invite/2f6c…',
                'expires_in' => '7 days',
            ],
        ],

        // -- Diagnostics -------------------------------------------------------
        'smtp_test' => [
            'name'        => 'SMTP test message',
            'description' => 'Sent by the “send a test message” button so the configuration can be proved before anything relies on it.',
            'group'       => 'Diagnostics',
            'subject'     => '{{app_name}}: test email',
            'body'        => <<<'HTML'
<p>This is a test message from {{app_name}}.</p>

<p>If you are reading it, outbound email is working: the message was accepted by
<strong>{{mail_host}}</strong> and delivered to <strong>{{recipient}}</strong>.</p>

<p>If the logo appears at the top of this message, branding is reaching email too.</p>

<p>Sent {{sent_at}} by {{sent_by}}.</p>
HTML,
            'fields' => [
                'mail_host' => 'The SMTP host the message went through',
                'recipient' => 'The address it was sent to',
                'sent_at'   => 'Date and time of the send',
                'sent_by'   => 'Who pressed the button',
            ],
            'sample' => [
                'mail_host' => 'smtp.example.com',
                'recipient' => 'someone@example.com',
                'sent_at'   => '17 Aug 2026, 14:32',
                'sent_by'   => 'Nick at Junction',
            ],
        ],
    ];

    /**
     * Templates whose wording may be edited but which cannot be switched off.
     *
     * A silenced template returns false from Mailer::sendTemplate() *without*
     * writing a log row, which is right for a reminder somebody has decided
     * they do not want. It is wrong for an invitation: the interface would go on
     * saying "we have emailed them a link", the link would never arrive, and
     * nothing anywhere would record that it had not.
     *
     * @var array<int,string>
     */
    public const LOCKED_ACTIVE = ['user_invite'];

    public static function canBeDisabled(string $key): bool
    {
        return !in_array($key, self::LOCKED_ACTIVE, true);
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public static function exists(string $key): bool
    {
        return isset(self::DEFAULTS[$key]);
    }

    /**
     * One template, defaults merged with any override.
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $key): ?array
    {
        if (!self::exists($key)) {
            return null;
        }

        $default  = self::DEFAULTS[$key];
        $override = Database::one(
            'SELECT t.*, u.name AS updated_by_name
               FROM email_templates t
               LEFT JOIN users u ON u.id = t.updated_by
              WHERE t.template_key = :key',
            ['key' => $key]
        );

        return [
            'key'             => $key,
            'name'            => $default['name'],
            'description'     => $default['description'],
            'group'           => $default['group'],
            'fields'          => array_merge($default['fields'], self::COMMON_FIELDS),
            'sample'          => $default['sample'],
            'subject'         => $override === null ? $default['subject'] : (string) $override['subject'],
            'body'            => $override === null ? $default['body'] : (string) $override['body'],
            // The shipped bodies are HTML. An override says for itself, because
            // an administrator who rewrites one in plain text should get plain
            // text sent.
            'is_html'         => $override === null || (int) $override['is_html'] === 1,
            // Enforced here rather than only at the point of saving, so a row
            // that predates the rule still sends.
            'is_active'       => !self::canBeDisabled($key)
                || $override === null
                || (int) $override['is_active'] === 1,
            'can_be_disabled' => self::canBeDisabled($key),
            'is_customised'   => $override !== null,
            'updated_at'      => $override['updated_at'] ?? null,
            'updated_by_name' => $override['updated_by_name'] ?? null,
            'default_subject' => $default['subject'],
            'default_body'    => $default['body'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        $templates = [];

        foreach (self::keys() as $key) {
            $template = self::find($key);

            if ($template !== null) {
                $templates[] = $template;
            }
        }

        return $templates;
    }

    /** Save an override. */
    public static function save(string $key, string $subject, string $body, bool $isHtml, bool $isActive): void
    {
        Database::run(
            'INSERT INTO email_templates (template_key, subject, body, is_html, is_active, updated_by)
                  VALUES (:k, :s, :b, :h, :a, :u)
             ON DUPLICATE KEY UPDATE
                  subject = VALUES(subject),
                  body = VALUES(body),
                  is_html = VALUES(is_html),
                  is_active = VALUES(is_active),
                  updated_by = VALUES(updated_by)',
            [
                'k' => $key,
                's' => $subject,
                'b' => $body,
                'h' => $isHtml ? 1 : 0,
                'a' => $isActive ? 1 : 0,
                'u' => Auth::id(),
            ]
        );
    }

    /** Drop the override, so the shipped default applies again. */
    public static function reset(string $key): void
    {
        Database::run('DELETE FROM email_templates WHERE template_key = :key', ['key' => $key]);
    }

    public static function customisedCount(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM email_templates');
    }
}
