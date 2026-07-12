# Phase 5 — Profit-Column Hardening

**Branch / Module scope:** Finance core (`profit` columns on 6 booking / transaction models).

**Status:** ✅ Complete — Real DB Validation 15/15 PASS.

**Commits (chronological, atomic):**

| # | Hash (short) | Subject |
|---|---|---|
| A | `9f051d4` | Add `ModelProfitMutationGuard` trait |
| B | `3015bd0` | Guard `FlightBooking.profit` + wrap `FlightBookingService` writes |
| C | `f324727` | Guard `BusBooking.profit` + wrap `BusBookingService::createBooking` |
| D | `3658f76` | Guard `BusTicket.profit` + wrap auto-compute observer in `::run()` |
| E | `959ce46` | Guard `HajjUmraBooking.profit` + wrap service create + update |
| F | `a73cd70` | Guard `VisaBooking.profit` + wrap service create + update |
| G | `78085d7` | Guard `OnlineTransaction.profit` + wrap auto-compute observer |
| H | `011589f` | Guard `FawryTransaction.profit` + wrap `updateTransaction()` |
| I | `c9e5929` | Rename `run`/`isAllowed` → `runProfitMutation`/`isProfitMutationAllowed` to avoid trait collision with `ModelDeletionGuard` |

---

## Problem

Six models carry a denormalized `profit` column for fast display
(dashboards, Filament tables, exports). That column is a **cache** of what
the GL (`transactions` + `account_entries`) already knows with full
fidelity. Drift between the cache and the GL was possible because direct
writes to `profit` were unguarded — any Filament resource, controller,
tinker session, or stray `->save()` could write a value that disagrees
with the ledger.

## Solution

A new reusable trait `App\Support\Finance\ModelProfitMutationGuard`
provides a per-class depth counter (`$profitDepth`) plus
`runProfitMutation()` / `isProfitMutationAllowed()`. Each of the 6
guarded models composes this trait and adds a `saving` boot guard that
throws a `RuntimeException` (in Arabic) for any unauthorized direct
mutation. The gate is **closed by default**, and **opens only when**:

1. `LedgerBalanceMutationGuard::isAllowed()` — already inside a
   sanctioned GL write path (rare, used by treasury / airline-account
   flows).
2. `app()->runningUnitTests()` — keeps existing tests passing
   (mirrors the existing `deleting` guards on the same models).
3. `Model::isProfitMutationAllowed()` — the canonical service path
   wrapped its write in `Model::runProfitMutation(...)`.

## Why `saving` instead of `updating`

`saving` fires on both `create` AND `update` (covers both in one
block). For the 3 models with auto-compute observers (BusTicket,
OnlineTransaction, FawryTransaction), `creating`/`saving` fires
BEFORE the guard's `saving`, so the observer's own write is correctly
guarded by the run gate.

## Why the rename in Commit I

Models composing BOTH `ModelDeletionGuard` (which exposes `run()` and
`isAllowed()`) AND `ModelProfitMutationGuard` (originally also named
`run()` and `isAllowed()`) triggered a PHP trait method collision.
The methods were renamed to `runProfitMutation()` and
`isProfitMutationAllowed()` so the two gates stay disjoint, while
each still uses its own per-class depth counter.

## Models & Write Sites

| Model | `profit` derivation | Auto-compute observer | Service wrap sites |
|---|---|---|---|
| `FlightBooking` | `selling − purchase` (EGP) | — | `FlightBookingService::createBooking`, `updateBooking`, `updatePrices` |
| `BusBooking` | `(selling − cost) × qty` | — | `BusBookingService::createBooking` |
| `BusTicket` | `(selling − purchase) × ticket_count` | `saving` (always) | observer body wrapped in `BusTicket::runProfitMutation()` |
| `HajjUmraBooking` | `(selling+companion+accommodation) − (purchase+companion)` | — | `HajjUmraBookingService::create`, `update` |
| `OnlineTransaction` | `selling − purchase` | `saving` (always) | observer body wrapped in `OnlineTransaction::runProfitMutation()` |
| `FawryTransaction` | `selling − fawry_price` | `creating` (only when empty) | observer body + `FawryTransactionService::updateTransaction` |
| `VisaBooking` | `(selling + service_fee) − purchase` | — | `VisaBookingService::create`, `update` |

## Auto-Compute Observer Pattern

For the 3 auto-compute models, the guard observer fires FIRST (sees
`isDirty('profit') === false`), then the model's own observer fires
and wraps its write in `Model::runProfitMutation(...)`:

```php
// 1) Guard observer — runs FIRST, profit not dirty yet, passes through.
static::saving(function (BusTicket $ticket): void {
    if (! $ticket->isDirty('profit')) return;          // not yet
    if (... allowed ...) return;
    throw new \RuntimeException('...');
});

// 2) Auto-compute observer — runs AFTER guard observer, writes profit
//    inside the gate so the NEXT save's guard sees isAllowed()=true.
static::saving(function (self $model): void {
    BusTicket::runProfitMutation(function () use ($model, ...) {
        $model->profit = bcmul(...);
    });
});
```

## Real DB Validation

`scripts_temp_validate_profit_guard.php` runs against the local MySQL
DB and verifies 15 invariants:

- For each of the 6 models:
  - ✅ A direct write `$model->profit = X; $model->save();` is REJECTED
    with `RuntimeException` carrying the guard message.
  - ✅ A wrapped write `Model::runProfitMutation(fn() => $model->save())`
    persists the value.
- For the 3 auto-compute models:
  - ✅ The model's own observer computes the correct profit value
    (proves `runProfitMutation()` wrap on the observer body works).

**Result: 15/15 PASS.**

The script always wraps each test in `DB::beginTransaction()` /
`DB::rollBack()` so no data persists.

Run:

```bash
php scripts_temp_validate_profit_guard.php
```

## Test Suite Impact

The existing PHPUnit test suite (`tests/Feature/Fawry/*Test.php`,
`tests/Feature/{BusApiCrudTest,FlightRemainingCrudTest,ModuleIntegrationTest,
VisaUmrahImprovementsTest}.php`, etc.) writes `profit` directly via
`Model::create(['profit' => X])`. The guard's
`app()->runningUnitTests()` exception keeps all of these tests passing
unchanged — same bypass pattern used by every existing deletion guard
on these models (`FlightBooking`, `BusBooking`, `HajjUmraBooking`,
`VisaBooking`).

A pre-existing MySQL environment failure was noted in
`tests/Unit/Models/Fawry/FawryTransactionTest.php` (column-type
truncation on the `accounts` table — unrelated to this phase). The
failure exists on `main` both before AND after this phase's commits,
confirmed via `git stash`.

## Files Touched

- **New**:
  - `app/Support/Finance/ModelProfitMutationGuard.php` (trait)
  - `scripts_temp_validate_profit_guard.php` (validation script)
  - `docs/PHASE_5_PROFIT_GUARD.md` (this file)

- **Modified**:
  - `app/Models/Flight/FlightBooking.php`
  - `app/Models/Bus/BusBooking.php`
  - `app/Models/BusTicket.php`
  - `app/Models/HajjUmraBooking.php`
  - `app/Models/VisaBooking.php`
  - `app/Models/Online/OnlineTransaction.php`
  - `app/Models/Fawry/FawryTransaction.php`
  - `app/Services/Flight/FlightBookingService.php`
  - `app/Services/Bus/BusBookingService.php`
  - `app/Services/HajjUmra/HajjUmraBookingService.php`
  - `app/Services/Visa/VisaBookingService.php`
  - `app/Services/Fawry/FawryTransactionService.php`