<?php

declare(strict_types=1);

namespace Tests;

use App\Report\HtmlRenderer;
use App\Report\OrderReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/**
 * The refactor is only correct if the numbers do not move. Every scenario runs
 * the legacy function and the new class over the same rows and diff both outputs.
 */
final class ParityTest extends TestCase
{
    public static function scenarios(): iterable
    {
        yield 'empty range' => ['2024-01-01', '2024-01-31', []];

        yield 'single paid order' => ['2024-01-01', '2024-01-31', [
            ['Acme Corp', 'paid', 120.00, 0.21, 0.0, '2024-01-05'],
        ]];

        yield 'mixed statuses and discounts' => ['2024-01-01', '2024-01-31', [
            ['Acme Corp', 'paid', 120.00, 0.21, 5.00, '2024-01-05'],
            ['Northwind', 'pending', 89.99, 0.21, 0.00, '2024-01-06'],
            ['Globex', 'cancelled', 45.50, 0.0, 10.00, '2024-01-31'],
        ]];

        yield 'zero tax rate' => ['2024-01-01', '2024-01-31', [
            ['Canarias SL', 'paid', 300.00, 0.0, 30.00, '2024-01-10'],
        ]];

        yield 'discount larger than subtotal' => ['2024-01-01', '2024-01-31', [
            ['Clearance', 'paid', 20.00, 0.21, 50.00, '2024-01-12'],
        ]];

        yield 'accented customer names' => ['2024-01-01', '2024-01-31', [
            ['José Martínez', 'paid', 199.90, 0.21, 0.00, '2024-01-08'],
            ['Peña e Hijos', 'pending', 15.00, 0.10, 0.00, '2024-01-09'],
        ]];

        yield 'inclusive boundaries' => ['2024-02-01', '2024-02-01', [
            ['Before', 'paid', 10.00, 0.21, 0.0, '2024-01-31'],
            ['On start', 'paid', 10.00, 0.21, 0.0, '2024-02-01'],
            ['After', 'paid', 10.00, 0.21, 0.0, '2024-02-02'],
        ]];

        yield 'large amounts' => ['2024-01-01', '2024-12-31', [
            ['Enterprise', 'paid', 999999.99, 0.21, 100000.00, '2024-06-30'],
            ['Enterprise II', 'paid', 1234567.89, 0.16, 0.00, '2024-07-01'],
        ]];

        yield 'repeated pennies' => ['2024-01-01', '2024-12-31', array_map(
            static fn (int $i): array => ['Ticket ' . $i, 'paid', 0.07, 0.21, 0.0, '2024-03-01'],
            range(1, 60)
        )];

        yield 'same timestamp keeps id order' => ['2024-05-01', '2024-05-31', [
            ['Third by id', 'paid', 3.00, 0.21, 0.0, '2024-05-10'],
            ['Fourth by id', 'paid', 4.00, 0.21, 0.0, '2024-05-10'],
            ['Fifth by id', 'paid', 5.00, 0.21, 0.0, '2024-05-10'],
        ]];

        yield 'high tax rate' => ['2024-01-01', '2024-01-31', [
            ['Luxury', 'paid', 1000.00, 0.355, 0.00, '2024-01-20'],
        ]];

        yield 'many small orders' => ['2024-01-01', '2024-12-31', array_map(
            static fn (int $i): array => [
                'Client ' . $i,
                $i % 3 === 0 ? 'pending' : 'paid',
                round(9.99 + $i * 1.37, 2),
                21.0,
                round($i * 0.11, 2),
                sprintf('2024-%02d-%02d', ($i % 12) + 1, ($i % 28) + 1),
            ],
            range(1, 120)
        )];
    }

    #[DataProvider('scenarios')]
    public function test_rows_and_summary_match_legacy(string $from, string $to, array $orders): void
    {
        $db = TestDatabase::create();
        TestDatabase::seed($db, $orders);

        $legacyRows = legacy_report_rows($db, $from, $to);

        $report = new OrderReport($db);
        $rows = $report->rows($from, $to);
        $modernRows = array_map(static fn ($row): array => $row->toLegacyArray(), $rows);

        self::assertSame(
            array_column($legacyRows, 'id'),
            array_column($modernRows, 'id'),
            'Row order changed'
        );
        self::assertEqualsWithDelta($legacyRows, $modernRows, 0.0001);
        self::assertEqualsWithDelta(
            legacy_report_summary($legacyRows),
            $report->summary($rows),
            0.0001,
            'Summary totals changed'
        );
    }

    #[DataProvider('scenarios')]
    public function test_rendered_html_matches_legacy(string $from, string $to, array $orders): void
    {
        $db = TestDatabase::create();
        TestDatabase::seed($db, $orders);

        $report = new OrderReport($db);

        self::assertSame(
            legacy_report_html(legacy_report_rows($db, $from, $to)),
            (new HtmlRenderer())->render($report->rows($from, $to))
        );
    }
}
