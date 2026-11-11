<?php

declare(strict_types=1);

namespace App\Report;

/**
 * One report line, with money held as integer cents.
 *
 * The legacy module multiplied floats, which drifts once a report has enough
 * lines. Cents keep every arithmetic step exact.
 */
final class OrderRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $customer,
        public readonly string $status,
        public readonly int $subtotalCents,
        public readonly int $taxCents,
        public readonly int $discountCents,
    ) {
    }

    public static function fromDatabaseRow(array $row): self
    {
        $subtotalCents = (int) round((float) $row['subtotal'] * 100);
        $taxCents      = (int) round($subtotalCents * (float) $row['tax_rate']);

        return new self(
            (int) $row['id'],
            (string) $row['customer'],
            (string) $row['status'],
            $subtotalCents,
            $taxCents,
            (int) round((float) $row['discount'] * 100),
        );
    }

    public function totalCents(): int
    {
        return $this->subtotalCents + $this->taxCents - $this->discountCents;
    }

    /** Same shape the legacy module produced, for side-by-side comparison. */
    public function toLegacyArray(): array
    {
        return [
            'id'       => $this->id,
            'customer' => $this->customer,
            'status'   => $this->status,
            'subtotal' => $this->subtotalCents / 100,
            'tax'      => $this->taxCents / 100,
            'discount' => $this->discountCents / 100,
            'total'    => $this->totalCents() / 100,
        ];
    }
}
