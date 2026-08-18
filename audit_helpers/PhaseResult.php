<?php

namespace AuditHelpers;

/**
 * Aggregates one phase's execution metrics + findings.
 */
class PhaseResult
{
    /** @var Finding[] */
    public array $findings = [];

    public int $testsExecuted = 0;
    public int $testsPassed = 0;
    public int $testsFailed = 0;
    public int $testsBlocked = 0;
    public int $testsSkipped = 0;

    public ?string $startedAt = null;
    public ?string $finishedAt = null;
    public ?string $fatalError = null;

    public function __construct(public string $phaseName) {}

    public function recordPass(): void
    {
        $this->testsExecuted++;
        $this->testsPassed++;
    }

    public function recordFail(string $scenario, string $expected, string $actual, string $severity = 'high', array $context = []): void
    {
        $this->testsExecuted++;
        $this->testsFailed++;
        $f = new Finding(
            phase: $this->phaseName,
            module: $context['module'] ?? 'cross',
            role: $context['role'] ?? 'system',
            severity: $severity,
            scenario: $scenario,
            expected: $expected,
            actual: $actual,
            transactionIds: $context['tx_ids'] ?? [],
            accountIds: $context['account_ids'] ?? [],
            diffEgp: (float) ($context['diff_egp'] ?? 0.0),
            rootCause: $context['root_cause'] ?? null,
            context: $context,
        );
        $this->findings[] = $f;
    }

    public function recordInfo(string $scenario, string $note): void
    {
        $this->testsExecuted++;
        $this->testsPassed++;
        $this->findings[] = new Finding(
            phase: $this->phaseName,
            module: 'cross',
            role: 'system',
            severity: 'info',
            scenario: $scenario,
            expected: $note,
            actual: $note,
        );
    }

    public function recordBlock(string $scenario, string $reason): void
    {
        $this->testsExecuted++;
        $this->testsBlocked++;
        $this->findings[] = new Finding(
            phase: $this->phaseName,
            module: 'cross',
            role: 'system',
            severity: 'medium',
            scenario: $scenario,
            expected: 'Test should run',
            actual: "BLOCKED: {$reason}",
        );
    }

    public function recordSkip(string $scenario, string $reason): void
    {
        $this->testsExecuted++;
        $this->testsSkipped++;
        $this->findings[] = new Finding(
            phase: $this->phaseName,
            module: 'cross',
            role: 'system',
            severity: 'info',
            scenario: $scenario,
            expected: 'Test should run',
            actual: "SKIPPED: {$reason}",
        );
    }

    public function start(): void
    {
        $this->startedAt = date('c');
    }

    public function finish(): void
    {
        $this->finishedAt = date('c');
    }

    public function hasFatalError(): bool
    {
        return $this->fatalError !== null;
    }

    public function noGoFindings(): array
    {
        return array_values(array_filter($this->findings, fn ($f) => $f->isNoGo()));
    }

    public function toArray(): array
    {
        return [
            'phase_name'    => $this->phaseName,
            'tests_executed'=> $this->testsExecuted,
            'tests_passed'  => $this->testsPassed,
            'tests_failed'  => $this->testsFailed,
            'tests_blocked' => $this->testsBlocked,
            'tests_skipped' => $this->testsSkipped,
            'started_at'    => $this->startedAt,
            'finished_at'   => $this->finishedAt,
            'fatal_error'   => $this->fatalError,
            'no_go_count'   => count($this->noGoFindings()),
            'findings'      => array_map(fn ($f) => $f->toArray(), $this->findings),
        ];
    }
}
