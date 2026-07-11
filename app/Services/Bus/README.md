# Bus Module — Deletion & Reversal Contract

> **Contract:** All financial operations in the Bus module follow the
> **additive-reversal** pattern. Original `transactions` and `account_entries`
> rows are **never deleted or modified**; reversals are always added as
> inverse `account_entries` on the same `transaction_id` (via
> `TransactionService::reverseTransaction()`) or as a fresh
> `recordJournalTransfer()` entry. Soft-delete is used to hide rows from
> views/reports while preserving the audit trail.

---

## 1. Three distinct deletion paths

| Method | Status effect | Payment reversal | Soft-delete | Idempotent | Use case |
|---|---|---|---|---|---|
| `BusBookingService::cancelBooking()` | `Cancelled` / `Refunded` / `PartiallyRefunded` (visible) | via `recordExpense` (cash refund) | ❌ no | partial | Customer-initiated cancellation that must keep the booking row visible for audit |
| `BusBookingService::deleteBooking()` | hidden (soft-deleted) | ❌ no (requires no payments) | ✅ yes | ✅ via `BusBooking::run()` | Simple admin delete when booking has NO payments |
| `BusBookingService::deleteBookingWithReversal()` | hidden (soft-deleted) + payments soft-deleted | ✅ yes (per-payment `reverseTransaction`) | ✅ yes | ✅ via `withTrashed` + `trashed()` check | Admin delete for ANY booking including those with payments |

Same shape for inventory/company/ticket but with their own semantically-specific
entry points (`deleteInventory()`, `deleteCompany()`, `BusTicketService::delete()`).

---

## 2. Why three methods (not one)

| Scenario | Why one method doesn't fit |
|---|---|
| **Customer cancels (operational)** | Booking row MUST stay visible for audit. Status changes to `Cancelled/Refunded/PartiallyRefunded`. Soft-delete would hide a valid accounting event. |
| **Admin deletes a no-payment booking** | Only ledger entries need reversing (no payment transactions exist). Simple path. |
| **Admin deletes a fully-paid booking** | Payment transactions must be reversed individually + ledger entries. Simple path can't handle this — `recordJournalTransfer` on ledger without reversing payments leaves customer account in negative state. |
| **Booking was modified prices (`update()`)** | Bus module has NO `update()` for prices (audit-safe by design). No destructive pattern exists. |

---

## 3. Service methods — full signature

### `cancelBooking(BusBooking $booking, array $data = []): BusRefundRequest`

- Status can become `Cancelled`, `Refunded`, or `PartiallyRefunded` (visible).
- Creates a `BusRefundRequest` audit row.
- Refund amount depends on penalties; uses `recordExpense` for cash refund.
- Reverses ledger entries: company cost (`applyCompanyCreditOnCancel`) and customer sale debt (`reverseCustomerSaleDebt`).
- **No** soft-delete.
- **No** reversal of original payment transactions (payment was already offset by the refund).

### `deleteBooking(BusBooking $booking): bool`

- Wrapped in `BusBooking::run(...)` — opens the deletion gate for the model's `deleting` observer.
- Loosened status constraint (no longer `'Only pending'`).
- **Still REQUIRES no payments.** If payments exist, throws with a message pointing the caller to `deleteBookingWithReversal()` instead.
- Reverses company cost + customer sale debt (additive `recordJournalTransfer`).
- Restores inventory tickets.
- Soft-deletes the booking row.

### `deleteBookingWithReversal(int $bookingId, ?int $userId = null): bool`

- Mirrors `FlightBookingService::deleteBookingWithReversal()` / `HajjUmraBookingService::deleteBookingWithReversal()` / `VisaBookingService::deleteBookingWithReversal()`.
- Wrapped in `BusBooking::run(...)` (opens the gate).
- Locks the booking row + reloads with relations (`payments.transaction`, `inventory.company`, `customer`).
- Idempotency guard: if `trashed()` is true, throws a clean Arabic RuntimeException.
- Reverses each payment transaction via `TransactionService::reverseTransaction($tx)` — additive.
- Reverses company cost + customer sale debt via `recordJournalTransfer` (additive).
- Restores inventory tickets.
- Soft-deletes the payment rows (requires `SoftDeletes` trait on `BusPayment`).
- Soft-deletes the booking row.

### `deleteInventory(BusInventory $inventory): bool`

- Wrapped in `BusInventory::run(...)`.
- **Bug fix (Phase 8):** when `payment_type === Cash && transaction_id` is set,
  calls `TransactionService::reverseTransaction($tx)` to add inverse
  `account_entries` on the cash expense — fixing the silent financial leak
  where the cashbox balance was permanently debited on Cash inventory deletion.
- Service-level `bookings()->count() > 0` check stays (complementary safety layer).
- Soft-deletes the inventory row.
- Observers in `BusInventory::booted()` block direct `$inventory->delete()` from outside
  `BusInventory::run()`.

### `deleteCompany(BusCompany $company): bool`

- Wrapped in `BusCompany::run(...)`.
- Service-level check: refuses if any non-soft-deleted inventory references the company.
- Soft-deletes the company row.
- GL-safe by construction — `ensureCompanyAccount()` does not post transactions; booking
  cost entries are reversed when bookings are deleted; `BusInventory` cash expenses
  hit the cashbox account, NOT the company account.

### `BusTicketService::delete(BusTicket $record): bool` (legacy resource)

- Wrapped in `BusTicket::run(...)`.
- Soft-deletes the legacy ticket row.
- No financial reversal (legacy resource has no GL impact).

---

## 4. Filament wiring — service-delegated only

All four deletion entry points route through the service layer via custom
`Action::make(...)` + `BulkAction::make(...)`. Direct `Tables\Actions\DeleteAction`
/ `Tables\Actions\DeleteBulkAction` are **forbidden** in this module (the model's
`deleting` observer would throw RuntimeException).

| Path | Calls |
|---|---|
| `BusCompanyResource` (table + bulk) | `app(BusCompanyService::class)->deleteCompany($record)` |
| `EditBusCompany` (header) | `app(BusCompanyService::class)->deleteCompany($record)` |
| `InventoriesRelationManager` (table + bulk) | `app(BusInventoryService::class)->deleteInventory($record)` |
| `BusTicketResource` (table + bulk, hidden nav) | `app(BusTicketService::class)->delete($record)` |

Per-record errors during bulk operations are surfaced via
`Filament\Notifications\Notification` so the batch continues with the rest.

---

## 5. Model guards — `ModelDeletionGuard`

Four models compose the `ModelDeletionGuard` trait + a `deleting` observer:

- `BusBooking` (mirrors `HajjUmraBooking` / `VisaBooking`)
- `BusInventory` (keeps the existing `bookings()->exists()` check as a complementary
  layer — not a replacement)
- `BusCompany`
- `BusTicket` (legacy)

Each observer blocks `$record->delete()` outside `BusXxx::run()` with a clear
Arabic RuntimeException pointing to the canonical service method. The gate is
flipped open only by `BusBooking::run()`, `BusInventory::run()`, `BusCompany::run()`,
or `BusTicket::run()` — used by the corresponding service methods.

Unit tests are exempted via `app()->runningUnitTests()` (matches the Flight / HajjUmra
/ Visa pattern).

---

## 6. Soft-delete scope (Phase 8 migration)

`2026_07_11_140000_add_soft_deletes_to_bus_payment_tables.php` adds `deleted_at` to:

- `bus_payments`
- `bus_company_payments`
- `bus_refund_requests`

This enables `deleteBookingWithReversal()` to soft-delete payment rows without losing
the audit trail.

> **IMPORTANT:** `transactions` and `account_entries` must NEVER gain a `deleted_at`
> column. Their reversals are always done by *adding* new reversal rows via
> `TransactionService::reverseTransaction()` or `recordJournalTransfer()`, never by
> deleting. This rule is shared across the project (Flight, HajjUmra, Visa, Bus).

---

## 7. API + Controller wiring

`BusBookingController::destroy()` routes through `deleteBookingWithReversal()` —
the same entry point as Filament. API callers benefit from the same gate / reversal
guarantees. The legacy `deleteBooking()` is still available for callers that want
the simpler path (no payments).

---

## 8. Why no `update()` for prices

There is **no** `update()` method that touches `cost_per_ticket`, `selling_price`, or
`total_price` in `BusBookingService`. Price corrections are always done by:

- `payBooking()` — additional payment to bring status to `Paid`.
- `cancelBooking()` — full operational reversal.
- `deleteBookingWithReversal()` — full admin reversal + soft-delete.

This avoids the destructive `voidTransactionJournal → repost` pattern that was
removed from HajjUmra / Visa in earlier phases. Price changes always post
**additive** correction entries.

---

## 9. Test coverage

`phase8_bus_deletion_cycle.php` (project root) verifies:

| TEST | Asserts |
|---|---|
| **A** | `cancelBooking()` preserves the existing behavior (no regression) |
| **B** | `deleteBookingWithReversal()` works even with payments (Δ=0 on all accounts from baseline) |
| **C** | `deleteInventory()` reverses the cash purchase expense (Δ=0 on cashbox) |
| **D** | Direct `$record->delete()` outside the canonical path throws RuntimeException via `ModelDeletionGuard` (all 4 models) |
| **E** | All 4 Filament paths route through service (no raw `DeleteAction`/`DeleteBulkAction` remain) |
| **CLEANUP** | DB returns to original state (forceDelete + balance reset) |

Run with:
```bash
php artisan tinker --execute='require "phase8_bus_deletion_cycle.php";'
```

The script is **idempotent** — re-running it wipes prior run leftovers and re-creates
fresh test data.

---

## 10. Origin

This contract mirrors the established pattern from:

- `FlightBookingService::deleteBookingWithReversal()` (Flight module)
- `HajjUmraBookingService::deleteBookingWithReversal()` (HajjUmra module)
- `VisaBookingService::deleteBookingWithReversal()` (Visa module)

All four modules share the `ModelDeletionGuard` trait (`app/Support/Finance/`) and the
additive-reversal invariant. Adding a new module? Copy this README's structure.