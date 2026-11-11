<?php

declare(strict_types=1);

namespace App\Report;

final class HtmlRenderer
{
    /** @param list<OrderRow> $rows */
    public function render(array $rows): string
    {
        $html = '<table class="report"><thead><tr><th>#</th><th>Customer</th>'
              . '<th>Status</th><th>Total</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $class = $row->status === 'paid' ? 'ok' : 'pending';
            $html .= '<tr class="' . $class . '">'
                   . '<td>' . $row->id . '</td>'
                   . '<td>' . $this->escape($row->customer) . '</td>'
                   . '<td>' . ucfirst($this->escape($row->status)) . '</td>'
                   . '<td>' . number_format($row->totalCents() / 100, 2) . '</td>'
                   . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
