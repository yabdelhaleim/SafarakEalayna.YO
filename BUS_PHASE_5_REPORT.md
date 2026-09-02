# BUS MODULE — PHASE 5 REPORT

## 0. Phase 4 Evidence Discrepancy Resolution

* **Phase 4 Reported Total Requests**: 98
* **Successful Worker Requests**: 41
* **Rejected Worker Requests**: 56
* **Unclassified Request #98**: The 98th recorded entry in Phase 4 was the direct DB Financial Invariants Audit Check (`workers = 1`, non-HTTP check), which evaluated ledger invariants directly with 0 worker HTTP successes and 0 worker HTTP rejections. This was purely a **reporting arithmetic artifact** and NOT an application failure.

---

## Executive Summary & Metrics

* **Environment**: `local`
* **Database**: `safarakealayna`
* **Total Phase 5 Scenarios**: 8
* **Total Requests**: 620
* **Successful Requests**: 401
* **Total 4xx Rejections**: 219
* **Total 5xx Errors**: 0
* **Timeouts**: `0`
* **Exceptions**: `0`
* **Throughput**: ~62 req/sec
* **Latency Metrics**: Average: `3155.79 ms`, p50: `2553.97 ms`, p95: `8989.77 ms`, p99: `9555.71 ms`, Max: `10312.16 ms`
* **Deadlocks**: `0`
* **Lock Timeouts**: `0`
* **Duplicate Operations**: `0`
* **Overbooking Count**: `0`
* **Payment Duplication Count**: `0`
* **Refund Duplication Count**: `0`
* **Supplier Settlement Duplication Count**: `0`
* **Orphan Records**: `0`
* **Financial Variance**: `0.00 EGP` (100% Reconciled)
* **Database Integrity Violations**: `0`
* **Recovery Failures**: `0` (100% Transaction Safe)
* **Severity Classification**: **NONE**
* **Final Verdict**: **PASS**
