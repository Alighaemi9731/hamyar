<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Support;

/**
 * One invoice, in the shape the tax authority asks for.
 *
 * ## A pure value, built by a mapper that never touches the network
 *
 * The spec's word is "pure": {@see \App\Modules\Moadian\Services\InvoiceMapper} turns a
 * `SalesInvoice` into one of these with no HTTP, no config lookup and no clock. That is
 * what makes the mapping unit-testable against fixture invoices, which matters more here
 * than almost anywhere else in the product — a mapping bug is a wrong tax filing, and it is
 * discovered at audit rather than at the till.
 *
 * ## Money is integer rial, everywhere, and the payload does not round
 *
 * Golden rule 2, and ADR 0009's amendment: per-line VAT was floored to a whole toman when
 * the invoice was issued, and this reproduces the stored figure. Re-deriving it here at the
 * current rate would round once over the invoice instead of once per line, and disagree
 * with the paper the customer is holding.
 */
final readonly class MoadianPayload
{
    /**
     * @param  list<MoadianLine>  $lines
     * @param  array<string, scalar|null>  $seller
     * @param  array<string, scalar|null>  $buyer
     */
    public function __construct(
        /** The shop's own reference — the invoice number, so a rejection can be traced back. */
        public string $reference,
        /** `main` for a sale, `cancel` for a void, `correction` for an amendment. */
        public string $type,
        /** Issue instant, UTC. The authority wants an epoch; the driver formats it. */
        public int $issuedAt,
        public array $seller,
        public array $buyer,
        public array $lines,
        public int $subtotal,
        public int $discountTotal,
        public int $vatTotal,
        public int $total,
        /** ADR 0009 rule 3: signed, disclosed, never absorbed. */
        public int $roundingAdjustment,
        /** Set on a cancellation or correction — the original's authority reference. */
        public ?string $referencesSubmission = null,
    ) {}

    /**
     * The wire shape, for a driver to serialise and for a test to assert against.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'type' => $this->type,
            'issued_at' => $this->issuedAt,
            'seller' => $this->seller,
            'buyer' => $this->buyer,
            'lines' => array_map(static fn (MoadianLine $line): array => $line->toArray(), $this->lines),
            'totals' => [
                'subtotal' => $this->subtotal,
                'discount' => $this->discountTotal,
                'vat' => $this->vatTotal,
                'rounding' => $this->roundingAdjustment,
                'total' => $this->total,
            ],
            'references_submission' => $this->referencesSubmission,
        ];
    }
}
