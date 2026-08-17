<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Support;

/**
 * One line of an e-invoice.
 *
 * `vatRate` and `vatAmount` are both carried even though one implies the other, for the
 * reason ADR 0009 gives about `profit_percent` and `profit_amount`: the rate is what was
 * agreed and the amount is what it came to after flooring, and re-deriving either from the
 * other reintroduces the rounding the invoice already settled.
 */
final readonly class MoadianLine
{
    public function __construct(
        public string $description,
        public int $quantity,
        public int $unitPrice,
        public int $discount,
        public int $vatRate,
        public int $vatAmount,
        public int $total,
        /** IMEI or serial, where the line sold a specific device. */
        public ?string $identifier = null,
        /** The authority's goods classification, when the shop has set one. */
        public ?string $taxCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'discount' => $this->discount,
            'vat_rate' => $this->vatRate,
            'vat_amount' => $this->vatAmount,
            'total' => $this->total,
            'identifier' => $this->identifier,
            'tax_code' => $this->taxCode,
        ];
    }
}
