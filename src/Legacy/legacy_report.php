<?php

declare(strict_types=1);

/*
 * Frozen snapshot of the original reporting module (circa 2016 style):
 * string-interpolated SQL, float money, unescaped HTML.
 *
 * It is the reference implementation. The refactor has to reproduce its numbers,
 * so nothing here gets "fixed": tests assert that the old holes are still there.
 */

function legacy_report_rows(PDO $db, string $from, string $to): array
{
    $sql = "SELECT id, customer, status, subtotal, tax_rate, discount
              FROM orders
             WHERE created_at >= '$from'
               AND created_at <= '$to'
          ORDER BY created_at ASC, id ASC";

    $rows = [];
    foreach ($db->query($sql) as $r) {
        $subtotal = (float) $r['subtotal'];
        $tax      = $subtotal * (float) $r['tax_rate'];
        $discount = (float) $r['discount'];

        $rows[] = [
            'id'       => (int) $r['id'],
            'customer' => (string) $r['customer'],
            'status'   => (string) $r['status'],
            'subtotal' => round($subtotal, 2),
            'tax'      => round($tax, 2),
            'discount' => round($discount, 2),
            'total'    => round($subtotal + $tax - $discount, 2),
        ];
    }

    return $rows;
}

function legacy_report_summary(array $rows): array
{
    $net = 0.0;
    $tax = 0.0;
    $discount = 0.0;

    foreach ($rows as $row) {
        $net      += $row['subtotal'];
        $tax      += $row['tax'];
        $discount += $row['discount'];
    }

    return [
        'orders'   => count($rows),
        'net'      => round($net, 2),
        'tax'      => round($tax, 2),
        'discount' => round($discount, 2),
        'total'    => round($net + $tax - $discount, 2),
    ];
}

function legacy_report_html(array $rows): string
{
    $html = '<table class="report"><thead><tr><th>#</th><th>Customer</th>'
          . '<th>Status</th><th>Total</th></tr></thead><tbody>';

    foreach ($rows as $row) {
        $class = $row['status'] === 'paid' ? 'ok' : 'pending';
        $html .= '<tr class="' . $class . '">'
               . '<td>' . $row['id'] . '</td>'
               . '<td>' . $row['customer'] . '</td>'
               . '<td>' . ucfirst($row['status']) . '</td>'
               . '<td>' . number_format($row['total'], 2) . '</td>'
               . '</tr>';
    }

    return $html . '</tbody></table>';
}
