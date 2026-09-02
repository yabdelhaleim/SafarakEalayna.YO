# BUS CONCURRENCY TEST PLAN

Plan detailing parallel worker CLI processes, worker counts (2, 5, 10, 20, 50), isolation barriers, and invariant assertions.

## Concurrency Harness Architecture
* **Execution Engine**: Parallel `php scratch/concurrency_worker.php` subprocesses spawned via `proc_open`.
* **Isolation Barrier**: Independent database connections (`DB::reconnect()`), high-resolution microsecond timers (`microtime(true)`).
* **Pessimistic Locking Contract**: Verifies `lockForUpdate()` behavior on `bus_inventories`, `bus_bookings`, and `accounts`.
