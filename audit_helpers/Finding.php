<?php

namespace AuditHelpers;

/**
 * Single audit finding — the atomic unit of the audit report.
 *
 * Severity levels:
 *   - 'critical' : NO-GO trigger (e.g. money created, lost, or duplicated)
 *   - 'high'     : NO-GO trigger (e.g. post-save Edit path discovered)
 *   - 'medium'   : NO-GO trigger (e.g. refund discrepancy, balance drift > 0.01 EGP)
 *   - 'low'      : tracked but not NO-GO on its own (e.g. UX inconsistency)
 *   - 'info'     : informational (e.g. expected rejection logged)
 */
class Finding
{
    public function __construct(
        public string $phase,           // 'PHASE 11', 'PHASE 15', etc.
        public string $module,          // 'flight' | 'hajj_umra' | 'visa' | 'cross'
        public string $role,            // 'employee' | 'admin' | 'system' | 'http'
        public string $severity,        // 'critical'|'high'|'medium'|'low'|'info'
        public string $scenario,        // short scenario label
        public string $expected,        // expected behavior
        public string $actual,          // observed behavior
        public float $diffEgp = 0.0,    // financial discrepancy (positive = missing money)
        public array $transactionIds = [],
        public array $accountIds = [],
        public ?string $rootCause = null,
        public array $context = [],
    ) {}

    public function toArray(): array
    {
        return [
            'phase'          => $this->phase,
            'module'         => $this->module,
            'role'           => $this->role,
            'severity'       => $this->severity,
            'scenario'       => $this->scenario,
            'expected'       => $this->expected,
            'actual'         => $this->actual,
            'diff_egp'       => $this->diffEgp,
            'transaction_ids'=> $this->transactionIds,
            'account_ids'    => $this->accountIds,
            'root_cause'     => $this->rootCause,
            'context'        => $this->context,
        ];
    }

    public function isNoGo(): bool
    {
        return in_array($this->severity, ['critical', 'high', 'medium'], true);
    }
}
