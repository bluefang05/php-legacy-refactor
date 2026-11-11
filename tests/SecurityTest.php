<?php

declare(strict_types=1);

namespace Tests;

use App\Report\HtmlRenderer;
use App\Report\OrderReport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/**
 * Two holes in the legacy module, one test each. The first documents the hole
 * still exists in the frozen snapshot; the second proves the refactor closed it.
 */
final class SecurityTest extends TestCase
{
    private function seedTwoOrders(): \PDO
    {
        $db = TestDatabase::create();
        TestDatabase::seed($db, [
            ['Acme Corp', 'paid', 100.00, 0.21, 0.0, '2024-01-10'],
            ['Globex', 'pending', 50.00, 0.21, 0.0, '2024-02-10'],
        ]);

        return $db;
    }

    public function test_legacy_query_is_injectable_and_the_refactor_rejects_the_input(): void
    {
        $db = $this->seedTwoOrders();
        $payload = "2024-01-01' OR '1'='1";

        self::assertCount(2, legacy_report_rows($db, $payload, '2024-12-31'), 'Legacy hole should still be visible');

        $this->expectException(InvalidArgumentException::class);
        (new OrderReport($db))->rows($payload, '2024-12-31');
    }

    public function test_refactor_rejects_malformed_dates(): void
    {
        $report = new OrderReport($this->seedTwoOrders());

        $this->expectException(InvalidArgumentException::class);
        $report->rows('2024-13-45', '2024-12-31');
    }

    public function test_legacy_html_is_unescaped_and_the_refactor_escapes_it(): void
    {
        $db = TestDatabase::create();
        TestDatabase::seed($db, [
            ['<img src=x onerror=alert(1)>', 'paid', 10.00, 0.21, 0.0, '2024-01-01'],
        ]);

        $legacyHtml = legacy_report_html(legacy_report_rows($db, '2024-01-01', '2024-01-31'));
        self::assertStringContainsString('<img src=x', $legacyHtml, 'Legacy hole should still be visible');

        $report = new OrderReport($db);
        $modernHtml = (new HtmlRenderer())->render($report->rows('2024-01-01', '2024-01-31'));

        self::assertStringNotContainsString('<img src=x', $modernHtml);
        self::assertStringContainsString('&lt;img src=x', $modernHtml);
    }

    public function test_prepared_statements_survive_quotes_in_customer_names(): void
    {
        $db = TestDatabase::create();
        TestDatabase::seed($db, [
            ["O'Brien & Sons", 'paid', 10.00, 0.21, 0.0, '2024-01-01'],
        ]);

        $rows = (new OrderReport($db))->rows('2024-01-01', '2024-01-31');

        self::assertSame("O'Brien & Sons", $rows[0]->customer);
    }
}
