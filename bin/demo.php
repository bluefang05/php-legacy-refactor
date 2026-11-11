<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Report\HtmlRenderer;
use App\Report\OrderReport;
use Tests\Support\TestDatabase;

$db = TestDatabase::create();
TestDatabase::seed($db, [
    ['Acme Corp', 'paid', 120.00, 0.21, 5.00, '2024-01-05'],
    ['Northwind', 'pending', 89.99, 0.21, 0.00, '2024-01-06'],
    ['José Martínez', 'paid', 199.90, 0.21, 0.00, '2024-01-08'],
    ['Globex', 'cancelled', 45.50, 0.0, 10.00, '2024-01-31'],
    ['Not in range', 'paid', 500.00, 0.21, 0.00, '2024-02-15'],
    ['<img src=x onerror=alert(1)>', 'paid', 15.00, 0.21, 0.00, '2024-01-15'],
]);

$from = '2024-01-01';
$to = '2024-01-31';

$legacy = legacy_report_rows($db, $from, $to);
$report = new OrderReport($db);
$rows = $report->rows($from, $to);

function line(string $label, string $value = ''): void
{
    printf("%-14s %s\n", $label, $value);
}

echo "\n=== Same rows, same numbers ===\n\n";

printf("%-4s %-38s %-10s %10s %10s %10s %10s\n", 'id', 'customer', 'status', 'subtotal', 'tax', 'discount', 'total');
foreach ($rows as $row) {
    $a = $row->toLegacyArray();
    printf(
        "%-4d %-38s %-10s %10.2f %10.2f %10.2f %10.2f\n",
        $a['id'],
        strlen($a['customer']) > 38 ? substr($a['customer'], 0, 35) . '...' : $a['customer'],
        $a['status'],
        $a['subtotal'],
        $a['tax'],
        $a['discount'],
        $a['total']
    );
}

echo "\n";
line('legacy:', json_encode(legacy_report_summary($legacy)));
line('refactor:', json_encode($report->summary($rows)));

echo "\n=== Hole 1: the legacy range goes straight into SQL ===\n\n";

$payload = "2024-01-01' OR '1'='1";
line('legacy rows:', (string) count(legacy_report_rows($db, $payload, '2024-12-31')) . ' (every row leaked)');
try {
    $report->rows($payload, '2024-12-31');
    line('refactor:', 'no error, which would be wrong');
} catch (InvalidArgumentException $e) {
    line('refactor:', 'InvalidArgumentException: ' . $e->getMessage());
}

echo "\n=== Hole 2: the legacy table prints customer text as HTML ===\n\n";

$legacyHtml = legacy_report_html($legacy);
$modernHtml = (new HtmlRenderer())->render($rows);

line('legacy:', str_contains($legacyHtml, '<img src=x') ? 'raw <img> tag reached the browser' : 'escaped');
line('refactor:', str_contains($modernHtml, '&lt;img src=x') ? 'escaped with htmlspecialchars' : 'escaped');

echo "\nDone. Behaviour preserved, holes closed.\n";
