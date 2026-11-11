<?php

declare(strict_types=1);

namespace App\Report;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class OrderReport
{
    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<OrderRow> */
    public function rows(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, customer, status, subtotal, tax_rate, discount
               FROM orders
              WHERE created_at >= :from
                AND created_at <= :to
           ORDER BY created_at ASC, id ASC'
        );

        $stmt->execute([
            'from' => $this->parseDate('from', $from)->format(self::DATE_FORMAT),
            'to'   => $this->parseDate('to', $to)->format(self::DATE_FORMAT),
        ]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = OrderRow::fromDatabaseRow($row);
        }

        return $rows;
    }

    public function summary(array $rows): array
    {
        $net = $tax = $discount = 0;

        foreach ($rows as $row) {
            $net      += $row->subtotalCents;
            $tax      += $row->taxCents;
            $discount += $row->discountCents;
        }

        return [
            'orders'   => count($rows),
            'net'      => $net / 100,
            'tax'      => $tax / 100,
            'discount' => $discount / 100,
            'total'    => ($net + $tax - $discount) / 100,
        ];
    }

    /**
     * The legacy query interpolated the range straight into the SQL string.
     * Rejecting anything that is not a real date closes the hole without
     * changing behaviour for valid callers.
     */
    private function parseDate(string $field, string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);

        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            throw new InvalidArgumentException(sprintf('Invalid %s date: %s', $field, $value));
        }

        return $date;
    }
}
