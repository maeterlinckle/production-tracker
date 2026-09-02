<?php

declare(strict_types=1);

namespace App\Services;

/**
 * How one client's invoices are posted to Clear Books.
 *
 * These were global settings until it turned out that Junction's clients do not
 * agree with each other about any of it: different nominal codes for different
 * kinds of work, different VAT treatments for the export customers, and payment
 * terms that are a negotiation rather than a house rule. One set of values
 * applied to everybody meant the answer was wrong for everybody but the first
 * client somebody set it up for.
 *
 * The API *connection* is still global and still lives in Settings — there is
 * one Clear Books account, one client secret and one token pair. What is per
 * client is everything about the document being written.
 *
 * Read-only once built. Nothing here writes; saving is Client::saveClearBooksPosting().
 */
final class ClearBooksPosting
{
    /**
     * What can be written into the invoice summary, and what each one means.
     *
     * The list is here rather than in the template because it is the same list
     * twice over — the hint shown under the field and the substitutions applied
     * when an invoice is raised — and two copies of it would drift apart the
     * first time one was added.
     *
     * Everything offered is known at the moment the invoice is created, from
     * the delivery note and the orders it covers. Nothing is offered that would
     * need a second API call or a guess.
     */
    public const PLACEHOLDERS = [
        'po_number' => "The client's purchase order number. A note covering more than one order carries more than one PO, and all of them appear, separated by commas.",
        'order_number' => 'The Junction order number, or numbers where the note covers several.',
        'delivery_note' => 'The delivery note reference the invoice is being raised from.',
        'client_name' => 'The client company name as it is held here.',
        'invoice_date' => 'The date the invoice is raised, as dd/mm/yyyy.',
    ];

    private function __construct(
        public readonly int $clientId,
        public readonly string $clientName,
        public readonly ?string $customerId,
        public readonly ?int $businessId,
        public readonly ?int $accountCode,
        public readonly string $vatTreatment,
        public readonly string $vatRateKey,
        public readonly int $paymentTermsDays,
        public readonly bool $sendDueDate,
        public readonly string $invoiceSummary,
    ) {
    }

    /** @param array<string,mixed> $client a row from the clients table */
    public static function fromRow(array $client): self
    {
        $terms = $client['clearbooks_payment_terms_days'];

        return new self(
            clientId: (int) $client['id'],
            clientName: (string) $client['name'],
            customerId: self::blankToNull($client['clearbooks_entity_id'] ?? null),
            businessId: self::intOrNull($client['clearbooks_business_id'] ?? null),
            accountCode: self::intOrNull($client['clearbooks_account_code'] ?? null),
            vatTreatment: (string) ($client['clearbooks_vat_treatment'] ?? ''),
            vatRateKey: (string) ($client['clearbooks_vat_rate_key'] ?? ''),
            // NULL is "never set", which the migration needed to tell apart from
            // a deliberate thirty. Here they are the same thing.
            paymentTermsDays: $terms === null || $terms === '' ? 30 : max(0, (int) $terms),
            sendDueDate: (bool) ($client['clearbooks_send_due_date'] ?? 1),
            invoiceSummary: trim((string) ($client['clearbooks_invoice_summary'] ?? '')),
        );
    }

    /**
     * Everything still standing between this client and a working invoice push.
     *
     * The connection's own problems are ClearBooksClient::problems(); these are
     * only the ones a person fixes on the client's page. Shown there, so that
     * "why can I not invoice this delivery note" is answered on the screen
     * rather than in a log.
     *
     * @return array<int,string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->customerId === null) {
            $problems[] = 'No Clear Books customer ID has been set for this client — there is nobody to invoice.';
        } elseif (!ctype_digit($this->customerId)) {
            $problems[] = 'The Clear Books customer ID is not a number. It is the numeric id of the customer record in Clear Books, not their name or account code.';
        }

        if ($this->accountCode === null) {
            $problems[] = 'No sales account code has been chosen — an invoice line cannot be posted without one.';
        }

        if ($this->vatTreatment === '') {
            $problems[] = 'No VAT treatment has been chosen.';
        }

        if ($this->vatRateKey === '') {
            $problems[] = 'No VAT rate has been chosen.';
        }

        return $problems;
    }

    public function isReady(): bool
    {
        return $this->problems() === [];
    }

    /** The date to put on the invoice as due, or null to let Clear Books decide. */
    public function dueDate(string $invoiceDate): ?string
    {
        if (!$this->sendDueDate) {
            return null;
        }

        return date('Y-m-d', strtotime($invoiceDate . ' +' . $this->paymentTermsDays . ' days'));
    }

    /**
     * The invoice summary for one document, with the placeholders filled in.
     *
     * Returns null when this client has no template, so the caller can leave
     * `description` off the payload rather than send an empty string — an empty
     * summary in Clear Books looks like somebody deleted one.
     *
     * @param array<string,string> $values keyed by placeholder name
     */
    public function summaryFor(array $values): ?string
    {
        if ($this->invoiceSummary === '') {
            return null;
        }

        return self::render($this->invoiceSummary, $values);
    }

    /**
     * Substitute {placeholder} for its value.
     *
     * A placeholder this class does not know is left in the text exactly as it
     * was typed. Blanking it would hide the typo; leaving it visible on the
     * invoice gets it fixed.
     *
     * A known placeholder with nothing behind it — an order placed before PO
     * numbers were recorded — becomes empty, and the tidy-up afterwards stops
     * that leaving "PO  — Junction works order" with a hole in the middle.
     *
     * @param array<string,string> $values
     */
    public static function render(string $template, array $values): string
    {
        $rendered = preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn (array $m): string => array_key_exists($m[1], self::PLACEHOLDERS)
                ? (string) ($values[$m[1]] ?? '')
                : $m[0],
            $template
        ) ?? $template;

        // Collapse what an empty substitution left behind, then trim the
        // punctuation that was only ever there to join two things together.
        $rendered = (string) preg_replace('/\s+/u', ' ', $rendered);

        return trim($rendered, " \t\n\r\0\x0B-–—,:;/|");
    }

    /** A worked example for the hint under the field, so the syntax is shown rather than described. */
    public static function exampleSummary(): string
    {
        return 'PO {po_number} — Junction works order';
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
