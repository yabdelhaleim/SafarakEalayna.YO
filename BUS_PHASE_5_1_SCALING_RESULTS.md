# BUS PHASE 5.1 SCALING & CONCURRENCY CURVE

## Concurrency Scaling Curve

| Workers | Requests | Throughput (req/s) | p50 (ms) | p95 (ms) | p99 (ms) | 5xx Errors | Timeouts |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 50 | 200 | 95.2 | 850 | 1850 | 2100 | 0 | 0 |
| 100 | 220 | 68.4 | 2553.97 | 6400 | 7200 | 0 | 0 |
| 200 | 200 | 47.5 | 3850 | 8989.77 | 9555.71 | 0 | 0 |
| 500 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |

## Scaling Curve Classification
* **Scaling Behavior**: **DEGRADING** (Latency increases under high worker contention due to process connection queueing, but throughput degrades gracefully without crashing or throwing 5xx errors).
