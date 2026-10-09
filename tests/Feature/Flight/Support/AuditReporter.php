<?php

namespace Tests\Feature\Flight\Support;

/**
 * AuditReporter — prints a human-readable report of scenario checks
 * to stdout. Designed to be useful when viewing via `cat log.txt`.
 *
 * Tracks per-section and overall pass/fail counters; supports nested
 * invariant checks per scenario.
 */
class AuditReporter
{
    private string $buffer = '';

    private int $scenarioCount = 0;

    private int $scenarioPass = 0;

    private int $scenarioFail = 0;

    private int $invariantPass = 0;

    private int $invariantFail = 0;

    /** @var array<int, array{title: string, ok: bool, detail: string}> */
    private array $sections = [];

    private ?string $currentSection = null;

    private float $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function banner(string $title): void
    {
        $line = str_repeat('=', 80);
        $this->writeln('');
        $this->writeln($line);
        $this->writeln($title);
        $this->writeln($line);
        $this->writeln('DB: '.($this->safeDbName() ?? 'n/a').'  Started: '.date('Y-m-d H:i:s'));
        $this->writeln($line);
    }

    public function section(string $title): void
    {
        $this->writeln('');
        $this->writeln('── '.$title.' ──');
        $this->currentSection = $title;
    }

    public function scenario(string $label, bool $ok, string $detail = ''): void
    {
        $this->scenarioCount++;
        if ($ok) {
            $this->scenarioPass++;
            $mark = '✓ PASS';
        } else {
            $this->scenarioFail++;
            $mark = '✗ FAIL';
        }
        $detailStr = $detail !== '' ? '  — '.$detail : '';
        $this->writeln(sprintf('  %-9s  %-32s%s', $mark, $label, $detailStr));
    }

    public function invariant(string $label, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->invariantPass++;
            $mark = '✓';
        } else {
            $this->invariantFail++;
            $mark = '✗';
        }
        $detailStr = $detail !== '' ? '  ['.$detail.']' : '';
        $this->writeln(sprintf('  %s %-40s%s', $mark, $label, $detailStr));
    }

    public function info(string $message): void
    {
        $this->writeln('       '.$message);
    }

    public function line(): void
    {
        $this->writeln('');
    }

    public function sectionSummary(string $title, int $total, int $passed): void
    {
        $status = ($passed === $total) ? 'PASS' : 'FAIL';
        $this->writeln(sprintf('  → %s  %d/%d', $status, $passed, $total));
        $this->sections[] = ['title' => $title, 'ok' => ($passed === $total), 'detail' => "{$passed}/{$total}"];
    }

    public function summary(): void
    {
        $runtime = round(microtime(true) - $this->startedAt, 2);
        $this->writeln('');
        $this->writeln(str_repeat('=', 80));
        $this->writeln('FINAL RECONCILIATION');
        $this->writeln(str_repeat('=', 80));

        foreach ($this->sections as $s) {
            $this->writeln(sprintf('  [%s] %-40s %s', $s['ok'] ? '✓' : '✗', $s['title'], $s['detail']));
        }

        $this->writeln('');
        $this->writeln(str_repeat('=', 80));
        $this->writeln(sprintf(
            'SUMMARY: %d/%d scenarios PASS, %d/%d invariants PASS  (runtime: %ss)',
            $this->scenarioPass,
            $this->scenarioCount,
            $this->invariantPass,
            $this->invariantPass + $this->invariantFail,
            $runtime,
        ));
        $this->writeln(str_repeat('=', 80));
    }

    public function totals(): array
    {
        return [
            'scenarios' => ['pass' => $this->scenarioPass, 'fail' => $this->scenarioFail, 'total' => $this->scenarioCount],
            'invariants' => ['pass' => $this->invariantPass, 'fail' => $this->invariantFail],
        ];
    }

    public function flush(): void
    {
        if ($this->buffer !== '') {
            echo $this->buffer;
            $this->buffer = '';
        }
    }

    private function writeln(string $line): void
    {
        // Write directly to STDOUT so PHPUnit's buffered output captures it cleanly.
        // Using fwrite() avoids the "beStrictAboutOutputDuringTests" exception.
        fwrite(STDOUT, $line.PHP_EOL);
    }

    private function safeDbName(): ?string
    {
        try {
            return \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
