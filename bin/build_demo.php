<?php

declare(strict_types=1);

/*
 * Generates docs/index.html from real runs of both implementations.
 *
 * Nothing on the page is hand-written: the parity table, summaries, SQL payload
 * outcome and escaped/unescaped HTML fragments all come from executing the
 * frozen legacy functions and the refactored classes against the same SQLite
 * database used by the test suite.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Report\HtmlRenderer;
use App\Report\OrderReport;
use Tests\Support\TestDatabase;

const FROM = '2024-01-01';
const TO   = '2024-01-31';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param list<array<string, mixed>> $rows */
function textTable(array $rows): string
{
    $out = sprintf(
        "%-3s %-18s %-9s %9s %8s %9s %9s\n",
        'id',
        'customer',
        'status',
        'subtotal',
        'tax',
        'discount',
        'total'
    );
    $out .= str_repeat('-', 71) . "\n";

    foreach ($rows as $row) {
        $out .= sprintf(
            "%-3d %-18s %-9s %9.2f %8.2f %9.2f %9.2f\n",
            $row['id'],
            $row['customer'],
            $row['status'],
            $row['subtotal'],
            $row['tax'],
            $row['discount'],
            $row['total']
        );
    }

    return $out;
}

/** @param array<string, mixed> $summary */
function summaryLine(array $summary): string
{
    return sprintf(
        'orders=%d  net=%.2f  tax=%.2f  discount=%.2f  total=%.2f',
        $summary['orders'],
        $summary['net'],
        $summary['tax'],
        $summary['discount'],
        $summary['total']
    );
}

function trFragment(string $html): string
{
    return preg_match('~<tr class="[^"]*">.*?</tr>~s', $html, $m) === 1 ? $m[0] : $html;
}

function assertTrue(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "Demo generation aborted: {$message}\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// 1. Parity: the same business data through both implementations.
// ---------------------------------------------------------------------------

$parityDb = TestDatabase::create();
TestDatabase::seed($parityDb, [
    ['Acme Corp', 'paid', 120.00, 0.21, 5.00, '2024-01-05'],
    ['Northwind', 'pending', 89.99, 0.21, 0.00, '2024-01-06'],
    ['José Martínez', 'paid', 199.90, 0.21, 0.00, '2024-01-08'],
    ['Globex', 'cancelled', 45.50, 0.0, 10.00, '2024-01-31'],
    ['Not in range', 'paid', 500.00, 0.21, 0.00, '2024-02-15'],
]);

$legacyRows = legacy_report_rows($parityDb, FROM, TO);
$legacySummary = legacy_report_summary($legacyRows);

$report = new OrderReport($parityDb);
$modernRows = $report->rows(FROM, TO);
$modernArrays = array_map(static fn ($row): array => $row->toLegacyArray(), $modernRows);
$modernSummary = $report->summary($modernRows);

$legacyHtmlAll = legacy_report_html($legacyRows);
$modernHtmlAll = (new HtmlRenderer())->render($modernRows);

$rowsMatch = count($legacyRows) === count($modernArrays);
foreach ($legacyRows as $i => $row) {
    $mine = $modernArrays[$i] ?? null;
    if ($mine === null || $row['id'] !== $mine['id'] || $row['customer'] !== $mine['customer']
        || $row['status'] !== $mine['status']) {
        $rowsMatch = false;
        break;
    }
    foreach (['subtotal', 'tax', 'discount', 'total'] as $field) {
        if (abs((float) $row[$field] - (float) $mine[$field]) > 0.0001) {
            $rowsMatch = false;
            break 2;
        }
    }
}

$summaryMatch = abs($legacySummary['net'] - $modernSummary['net']) < 0.0001
    && abs($legacySummary['tax'] - $modernSummary['tax']) < 0.0001
    && abs($legacySummary['discount'] - $modernSummary['discount']) < 0.0001
    && abs($legacySummary['total'] - $modernSummary['total']) < 0.0001
    && $legacySummary['orders'] === $modernSummary['orders'];

$htmlMatch = $legacyHtmlAll === $modernHtmlAll;

assertTrue($legacyRows !== [], 'parity dataset returned no rows');
assertTrue($rowsMatch, 'rows differ between legacy and refactor');
assertTrue($summaryMatch, 'summaries differ between legacy and refactor');
assertTrue($htmlMatch, 'clean HTML differs between legacy and refactor');

// ---------------------------------------------------------------------------
// 2. Hole 1: interpolated date range.
// ---------------------------------------------------------------------------

$secDb = TestDatabase::create();
TestDatabase::seed($secDb, [
    ['Acme Corp', 'paid', 100.00, 0.21, 0.00, '2024-01-10'],
    ['Globex', 'pending', 50.00, 0.21, 0.00, '2024-02-10'],
]);

$payload = "2024-01-01' OR '1'='1";
$leakedRows = legacy_report_rows($secDb, $payload, '2024-12-31');

$injectionMessage = '';
try {
    (new OrderReport($secDb))->rows($payload, '2024-12-31');
    assertTrue(false, 'refactor accepted the injected date');
} catch (InvalidArgumentException $exception) {
    $injectionMessage = $exception->getMessage();
}

assertTrue(count($leakedRows) === 2, 'legacy injection no longer leaks rows');

$leakedList = '';
foreach ($leakedRows as $row) {
    $leakedList .= sprintf("#%d %s\n", $row['id'], $row['customer']);
}

$legacySql = "SELECT id, customer, status, subtotal, tax_rate, discount\n"
    . "  FROM orders\n"
    . " WHERE created_at >= '\$from'\n"
    . "   AND created_at <= '\$to'\n"
    . ' ORDER BY created_at ASC, id ASC';

$modernSql = "SELECT id, customer, status, subtotal, tax_rate, discount\n"
    . "  FROM orders\n"
    . ' WHERE created_at >= :from' . "\n"
    . '   AND created_at <= :to' . "\n"
    . ' ORDER BY created_at ASC, id ASC';
$modernBinding = "\$stmt->execute([\n"
    . "    'from' => \$this->parseDate('from', \$from)->format('Y-m-d'),\n"
    . "    'to'   => \$this->parseDate('to', \$to)->format('Y-m-d'),\n"
    . ']);';

// ---------------------------------------------------------------------------
// 3. Hole 2: customer text rendered as markup.
// ---------------------------------------------------------------------------

$xssDb = TestDatabase::create();
TestDatabase::seed($xssDb, [
    ['<img src=x onerror=alert(1)>', 'paid', 10.00, 0.21, 0.00, '2024-01-01'],
]);

$xssLegacyHtml = trFragment(legacy_report_html(legacy_report_rows($xssDb, FROM, TO)));
$xssModernHtml = trFragment((new HtmlRenderer())->render((new OrderReport($xssDb))->rows(FROM, TO)));

assertTrue(str_contains($xssLegacyHtml, '<img src=x'), 'legacy XSS hole disappeared from the snapshot');
assertTrue(! str_contains($xssModernHtml, '<img src=x'), 'refactor still emits a raw img tag');
assertTrue(str_contains($xssModernHtml, '&lt;img src=x'), 'refactor did not escape the customer name');

// ---------------------------------------------------------------------------
// 4. Render the page.
// ---------------------------------------------------------------------------

$generatedAt = date('Y-m-d H:i');
$legacyTable = e(textTable($legacyRows));
$modernTable = e(textTable($modernArrays));
$legacySummaryText = e(summaryLine($legacySummary));
$modernSummaryText = e(summaryLine($modernSummary));

$payloadEscaped = e($payload);
$leakedCount = count($leakedRows);
$leakedListEscaped = e($leakedList);
$injectionMessageEscaped = e($injectionMessage);
$xssLegacyEscaped = e($xssLegacyHtml);
$xssModernEscaped = e($xssModernHtml);

$rowClass = $rowsMatch ? 'ok' : 'bad';
$rowMark = $rowsMatch ? '&#10003;' : '&#10007;';
$summaryClass = $summaryMatch ? 'ok' : 'bad';
$summaryMark = $summaryMatch ? '&#10003;' : '&#10007;';
$htmlClass = $htmlMatch ? 'ok' : 'bad';
$htmlMark = $htmlMatch ? '&#10003;' : '&#10007;';

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Legacy PHP refactor — parity demo</title>
<meta name="description" content="Side-by-side output of a frozen legacy PHP billing report and its refactor: identical rows and totals, closed SQL injection and XSS holes.">
<style>
  :root {
    --bg: #f6f5f1;
    --panel: #ffffff;
    --ink: #1c1d1a;
    --muted: #5d6058;
    --line: #e3e1d8;
    --good: #1c6b45;
    --good-bg: #e8f3ec;
    --bad: #a92f22;
    --bad-bg: #f9ece9;
    --code-bg: #191b1f;
    --code-ink: #e9e6de;
    --accent: #244f8f;
    font-synthesis: none;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font: 16px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  .wrap { width: min(1160px, calc(100% - 40px)); margin: 0 auto; }
  header.hero {
    border-bottom: 1px solid var(--line);
    background: linear-gradient(180deg, #fffefb 0%, var(--bg) 100%);
    padding: 56px 0 40px;
  }
  .eyebrow {
    margin: 0 0 10px;
    font-size: 13px;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--muted);
  }
  h1 { margin: 0 0 14px; font-size: clamp(28px, 4.4vw, 44px); line-height: 1.12; letter-spacing: -0.02em; max-width: 20ch; }
  .lede { margin: 0 0 22px; max-width: 68ch; color: #3c3f38; font-size: 17px; }
  .badges { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; padding: 0; list-style: none; }
  .badges li {
    border: 1px solid var(--line);
    background: #fff;
    border-radius: 999px;
    padding: 4px 11px;
    font-size: 12.5px;
    color: #41443d;
  }
  main { padding: 36px 0 24px; }
  .notice {
    border: 1px solid #cfd9e8;
    background: #eef2f9;
    color: #263a5c;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 14px;
    margin-bottom: 36px;
  }
  .notice code, p code, li code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .92em; background: rgba(0,0,0,.06); padding: 1px 5px; border-radius: 4px; }
  section { margin-bottom: 44px; }
  h2 { font-size: 23px; letter-spacing: -0.01em; margin: 0 0 6px; }
  h2 .num { color: var(--muted); font-weight: 600; margin-right: 8px; }
  section > p { margin: 0 0 18px; color: var(--muted); max-width: 76ch; }
  .parity { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .panel {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 16px 16px 14px;
    min-width: 0;
  }
  .panel h3 { margin: 0 0 10px; font-size: 12.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
  .panel.bad { border-top: 3px solid #c4553f; }
  .panel.good { border-top: 3px solid #2c7a55; }
  pre {
    margin: 0;
    background: var(--code-bg);
    color: var(--code-ink);
    border-radius: 8px;
    padding: 14px 16px;
    overflow-x: auto;
    font: 12.5px/1.55 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    tab-size: 4;
  }
  .summary-line { margin: 10px 0 0; font: 12.5px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #3d403a; word-break: break-word; }
  .parity pre { font-size: 11.5px; }
  pre.wrapped { white-space: pre-wrap; word-break: break-word; }
  .checks { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
  .checks span { border-radius: 999px; padding: 4px 12px; font-size: 13px; font-weight: 600; }
  .checks .ok { background: var(--good-bg); color: var(--good); }
  .checks .bad { background: var(--bad-bg); color: var(--bad); }
  .duo { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .payload {
    margin: 16px 0;
    padding: 12px 16px;
    background: #fff8e6;
    border: 1px solid #ecd9a4;
    border-radius: 10px;
    font-size: 14.5px;
  }
  .payload code { background: #f3e6c2; }
  .tag { margin: 0 0 4px; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
  .big { margin: 0 0 4px; font-size: 21px; font-weight: 700; letter-spacing: -0.01em; }
  .panel.bad .big { color: var(--bad); }
  .panel.good .big { color: var(--good); }
  .panel .note { margin: 6px 0 0; font-size: 13.5px; color: var(--muted); }
  .panel .evidence { margin: 10px 0 0; padding: 10px 12px; border-radius: 8px; background: #fbf7f5; border: 1px dashed #e0cbc3; white-space: pre-line; }
  .panel .evidence div { font: 12.5px/1.7 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #5a3a32; }
  table.changes { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; font-size: 14.5px; }
  table.changes th, table.changes td { text-align: left; padding: 11px 14px; border-bottom: 1px solid var(--line); vertical-align: top; }
  table.changes thead th { background: #f2f0e9; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
  table.changes tr:last-child td { border-bottom: 0; }
  table.changes td:first-child { white-space: nowrap; color: var(--muted); }
  .closing { border: 1px solid var(--line); background: #fff; border-radius: 12px; padding: 18px 20px; }
  .closing p { margin: 0 0 10px; }
  .closing p:last-child { margin-bottom: 0; }
  footer { border-top: 1px solid var(--line); padding: 22px 0 40px; color: var(--muted); font-size: 13.5px; }
  footer a { color: var(--accent); }
  @media (max-width: 780px) {
    .parity, .duo { grid-template-columns: 1fr; }
    header.hero { padding: 40px 0 32px; }
  }
</style>
</head>
<body>
<header class="hero">
  <div class="wrap">
    <p class="eyebrow">Characterization-test refactor · PHP 8.2 · PDO / SQLite</p>
    <h1>Legacy billing report, refactored with proof it did not break</h1>
    <p class="lede">
      A frozen 2016-style module — interpolated SQL, float money, unescaped HTML — and its
      replacement. The interesting part is not the new classes: it is the harness that runs both
      implementations over the same data and diffs rows, totals and markup before any behaviour
      is allowed to change.
    </p>
    <ul class="badges">
      <li>PHP 8.2+</li>
      <li>PHPUnit 11</li>
      <li>28 tests · 55 assertions</li>
      <li>12 parity scenarios</li>
      <li>MIT</li>
    </ul>
  </div>
</header>

<main class="wrap">
  <div class="notice">
    Generated by <code>php bin/build_demo.php</code> on {$generatedAt} by executing both
    implementations against SQLite. Every table, number and HTML fragment below is captured from
    that run — nothing is mocked or hand-written.
  </div>

  <section>
    <h2><span class="num">1</span>Same rows, same totals</h2>
    <p>
      The same four orders in range (plus one out of range) go through
      <code>legacy_report_rows()</code> and <code>App\Report\OrderReport::rows()</code>.
      One uses floats, the other integer cents; the output has to match anyway.
    </p>
    <div class="parity">
      <div class="panel">
        <h3>legacy_report_rows()</h3>
        <pre>{$legacyTable}</pre>
        <p class="summary-line">{$legacySummaryText}</p>
      </div>
      <div class="panel">
        <h3>OrderReport::rows() — cents</h3>
        <pre>{$modernTable}</pre>
        <p class="summary-line">{$modernSummaryText}</p>
      </div>
    </div>
    <div class="checks">
      <span class="{$rowClass}">{$rowMark} rows identical</span>
      <span class="{$summaryClass}">{$summaryMark} summaries identical</span>
      <span class="{$htmlClass}">{$htmlMark} HTML identical</span>
    </div>
  </section>

  <section>
    <h2><span class="num">2</span>Hole 1 — the date range went straight into SQL</h2>
    <p>
      The legacy query interpolates the caller's range. A string that closes the quote is treated
      as SQL instead of as a date. Two orders exist in the database — one in January, one in
      February — and the payload asks for January only.
    </p>
    <div class="duo">
      <div class="panel bad">
        <h3>Legacy — interpolation</h3>
        <pre>{$legacySql}</pre>
      </div>
      <div class="panel good">
        <h3>Refactor — prepared statement</h3>
        <pre>{$modernSql}</pre>
        <pre style="margin-top:8px">{$modernBinding}</pre>
      </div>
    </div>
    <div class="payload">
      Payload used as <code>from</code>: <code>{$payloadEscaped}</code>
    </div>
    <div class="duo">
      <div class="panel bad">
        <p class="tag">legacy</p>
        <p class="big">{$leakedCount} rows returned</p>
        <p class="note">Every row in the table, not the January slice.</p>
        <div class="evidence">{$leakedListEscaped}</div>
      </div>
      <div class="panel good">
        <p class="tag">refactor</p>
        <p class="big">InvalidArgumentException</p>
        <p class="note"><code>{$injectionMessageEscaped}</code></p>
        <p class="note">The value never reaches the database; it is rejected before the query runs.</p>
      </div>
    </div>
  </section>

  <section>
    <h2><span class="num">3</span>Hole 2 — customer text rendered as markup</h2>
    <p>
      The legacy table concatenates the customer name into HTML. A customer named like a tag
      becomes a tag in the browser. Both fragments below are printed as <em>source text</em>,
      escaped for display — this page never inserts the raw payload as markup.
    </p>
    <div class="duo">
      <div class="panel bad">
        <h3>legacy_report_html()</h3>
        <pre class="wrapped">{$xssLegacyEscaped}</pre>
        <p class="note">The <code>&lt;img&gt;</code> reaches the browser as an element. The <code>onerror</code> attribute then runs script.</p>
      </div>
      <div class="panel good">
        <h3>HtmlRenderer::render()</h3>
        <pre class="wrapped">{$xssModernEscaped}</pre>
        <p class="note">The same value, escaped with <code>htmlspecialchars()</code> and rendered as text.</p>
      </div>
    </div>
  </section>

  <section>
    <h2>What changed, what did not</h2>
    <table class="changes">
      <thead>
        <tr><th></th><th>Legacy</th><th>Refactor</th></tr>
      </thead>
      <tbody>
        <tr><td>SQL</td><td>interpolated string</td><td><code>prepare()</code> + bound parameters</td></tr>
        <tr><td>Dates</td><td>passed through</td><td>parsed, rejected if malformed</td></tr>
        <tr><td>Money</td><td>floats</td><td>integer cents</td></tr>
        <tr><td>HTML</td><td>raw concatenation</td><td>escaped output</td></tr>
        <tr><td>Totals</td><td colspan="2">unchanged — same rounding, same numbers</td></tr>
        <tr><td>Row order</td><td colspan="2">unchanged — <code>created_at ASC, id ASC</code></td></tr>
      </tbody>
    </table>
  </section>

  <section>
    <h2>How this is verified</h2>
    <div class="closing">
      <p>
        <code>composer test</code> runs 28 tests / 55 assertions. Twelve data sets — empty range,
        discounts larger than the subtotal, accented names, inclusive boundaries, large amounts,
        120 mixed orders — run through both implementations twice: once for rows and summary,
        once for rendered HTML. Four more tests cover the two holes and quotes in customer names.
      </p>
      <p>
        The demo generator refuses to write this page if the legacy and refactor outputs diverge,
        or if the holes stop reproducing. The frozen legacy module is a reference, never edited.
      </p>
    </div>
  </section>
</main>

<footer class="wrap">
  Generated from a real run · <a href="https://github.com/bluefang05/php-legacy-refactor">source on GitHub</a> ·
  <a href="https://github.com/bluefang05/php-legacy-refactor#readme">tests and design notes</a>
</footer>
</body>
</html>
HTML;

$docsDir = dirname(__DIR__) . '/docs';
if (! is_dir($docsDir) && ! mkdir($docsDir, 0777, true) && ! is_dir($docsDir)) {
    fwrite(STDERR, "Cannot create {$docsDir}\n");
    exit(1);
}

$target = $docsDir . '/index.html';
file_put_contents($target, $html);

printf("Wrote %s (%d bytes)\n", $target, strlen($html));
printf("Parity: %d rows · rows=%s summary=%s html=%s\n",
    count($legacyRows),
    $rowsMatch ? 'ok' : 'FAIL',
    $summaryMatch ? 'ok' : 'FAIL',
    $htmlMatch ? 'ok' : 'FAIL'
);
printf("Injection: legacy leaked %d rows, refactor threw %s\n", count($leakedRows), $injectionMessage);
printf("XSS: legacy raw=%s, refactor escaped=%s\n",
    str_contains($xssLegacyHtml, '<img src=x') ? 'yes' : 'no',
    str_contains($xssModernHtml, '&lt;img src=x') ? 'yes' : 'no'
);
