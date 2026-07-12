# Wallet Module — Phase 8 Fix Contract

> **Date:** 2026-07-12
> **Phase:** 8 — `module_type` unification + Filament filter + `updateTransaction()` repost
> **Validation:** `php scripts/phase_8_wallet_module_fix.php` → **28/28 PASSED**

---

## 1. Problem Statement

The Wallet module had **two co-existing `module_type` values** that broke the
Transfer Dashboard's aggregation logic and the per-module Filament resources:

| Source | module_type value |
|--------|-------------------|
| `WalletTransactionService::ensureCustomerAccount()` (auto-created customer accounts) | `'wallet'` |
| `TransferAccounts/Manage*` pages (manual account creation) | `'wallet_transfer'` |
| `TransferDashboardController` (stats queries) | `'wallet_transfer'` |
| `TransferAccounts/Transfer*Resource` (Filament tables) | `'wallet_transfer'` |
| `StoreAccountRequest` / `UpdateAccountRequest` (validation) | accepts only `'wallet_transfer'` |

**Symptoms:**

1. **Hidden dashboard data.** Customer accounts created via `ensureCustomerAccount()`
   were tagged `module_type='wallet'` — invisible to `TransferDashboardController`
   which strictly filters on `'wallet_transfer'`. The receivables total
   undercounted the real wallet_transfer customer debt.
2. **Missing form filter.** `WalletTransactionResource`'s `wallet_account_id` and
   `cash_account_id` Selects were module-agnostic — a user could pick a wallet
   account tagged `module_type='bus'` for a `wallet_transfer` transaction,
   silently polluting the ledger.
3. **`updateTransaction()` was destructive.** It only mutated the model's
   `amount` / `service_fee` / `amount_paid` fields. The linked `Transaction`
   and `AccountEntry` rows stayed at the OLD values — silent drift between the
   model and the ledger. Same bug pattern fixed in Online (Phase 9),
   HajjUmra (Phase 8), and Visa (Phase 8).

---

## 2. Solution Strategy — Unification on `wallet_transfer`

We **chose Strategy (أ)** (full migration to `'wallet_transfer'`) for the
following reasons:

- **DB was empty** for both values (`SELECT COUNT(*) FROM accounts WHERE
  module_type IN ('wallet','wallet_transfer','wallets') = 0`).
- **`'wallet_transfer'` is the canonical key** used by 12+ read sites
  (TransferAccounts resources, dashboard, treasury, validation).
- **`'wallet'` was an isolated bug** in a single write site
  (`ensureCustomerAccount`).
- **`AccountModuleDivision::LEGACY_MODULE_TO_TYPE`** could safely drop its
  `wallet → wallet_transfer` and `wallets → wallet_transfer` entries.
- **`StoreAccountRequest`/`UpdateAccountRequest` validation rules**
  already rejected `'wallet'` — defense-in-depth was already in place.

---

## 3. Files Changed

| File | Change |
|------|--------|
| `app/Services/Wallet/WalletTransactionService.php` | (a) `ensureCustomerAccount()` writes `module_type='wallet_transfer'` and re-tags pre-existing accounts created by `CustomerLedgerObserver`. (b) `updateTransaction()` now reposts the ledger when amount/service_fee/amount_paid/wallet_account_id/cash_account_id change. (c) Extracted `postMainSendPair`, `postMainReceivePair`, `postSettlementSend`, `postSettlementReceive` from `accountForSend`/`accountForReceive` so the main pair and the settlement can be re-issued independently. |
| `app/Filament/Admin/Resources/WalletTransactions/WalletTransactionResource.php` | Added `->where('module_type', 'wallet_transfer')` filter on both `wallet_account_id` and `cash_account_id` Selects. |
| `app/Services/Reports/FinancialReportService.php` | Replaced all 4 occurrences of `'wallet'` in `module_type` whereIn / where clauses with `'wallet_transfer'` (lines 480, 487, 509, 681). Note: `case 'wallet'` UI filter keys on lines 483, 656 are kept as-is — they map user-facing input to the new DB value. `case 'wallet_transfer'` on line 1150 is the label map that still includes `'wallet'` for legacy `$custMod` values that may come from booking-count fallbacks. |
| `app/Support/Finance/AccountModuleDivision.php` | Removed `'wallet'` and `'wallets'` entries from `LEGACY_MODULE_TO_TYPE`. Removed the special-case `if (in_array($module, ['wallet', 'wallet_transfer', 'wallets'], true))` block in `applyModuleFilter()` — no longer needed since the DB no longer has both values. |

### `WalletAccountResource` (intentionally UNCHANGED)

`navigationLabel = 'كل المحافظ الإلكترونية'` confirms it is the **umbrella
view** across all `AccountType::Wallet` rows regardless of `module_type`.
Adding a `module_type` filter here would break its design intent.

The per-module view `TransferAccounts/TransferWalletResource` already
filters to `module_type='wallet_transfer'` exclusively.

---

## 4. The `updateTransaction()` Repost Pattern

This mirrors the **Online TransactionService Phase 9** fix (commit `a12702b`)
and the **HajjUmraBookingService Phase 8** fix. The pre-fix bug: only the
model mutated, leaving `Transaction` / `AccountEntry` rows orphaned.

### 4.1 What gets reposted

`updateTransaction()` now detects ACTUAL field changes (vs same-value
no-ops) and reposts:

| Field | What gets reposted |
|-------|---------------------|
| `amount` | main income + main expense |
| `service_fee` | main income (because total_amount depends on fee for Send) |
| `amount_paid` | optional settlement (3rd ledger row, identified by `from_account_id` + `to_account_id` pair) |
| `wallet_account_id` | main expense (Send) / main income (Receive) |
| `cash_account_id` | main income (anonymous Send/Receive) + settlement |

### 4.2 Repost flow

```
updateTransaction($tx, $data)
├── Detect actual changes (amountChanged, serviceFeeChanged, etc.)
├── Recompute total_amount if amount/fee changed
│     - Send:    total = amount + fee
│     - Receive: total = amount - fee
├── $transaction->update($data)         ← model mutation (was the only step)
└── if $anyLedgerAffectingChange:
      ├── repostMainTransactions()      ← reverse old pair, post new pair
      │     (uses postMainSendPair / postMainReceivePair — settlement NOT included)
      └── repostSettlementTransaction() ← reverse old settlement, post new (or no-op if amount_paid=0)
```

### 4.3 Why split `postMainSendPair` from `postSettlementSend`?

Originally `accountForSend`/`accountForReceive` posted BOTH the main pair
AND the settlement in one shot. Calling them from `repostMainTransactions`
would double-post the settlement (once via main, once via settlement
helper). By extracting the lean `postMainSendPair` (main only) and
`postSettlementSend` (settlement only) helpers, the repost flow can
re-issue each independently without duplication.

### 4.4 Additive reversal (not destructive)

All repost flows use `TransactionService::reverseTransaction($old)` —
which appends `'عكس: '` to the old transaction's `notes` and inserts a
counter `AccountEntry`. The original row is **never deleted** — preserving
the audit trail. The test verifies `str_starts_with($old->notes, 'عكس:')`.

### 4.5 Wrapping in `LedgerBalanceMutationGuard`

The repost flow uses `Account::balance` mutations internally (via
`recordIncome` / `recordExpense` / `reverseTransaction`). Each of those
already wraps its own `LedgerBalanceMutationGuard::run(...)`, so the
repost inherits the protection.

For the customer-account re-tag in `ensureCustomerAccount()` we
explicitly wrap in `LedgerBalanceMutationGuard::run(...)` because
`Account::save()` may touch `balance` on dirty casts and the
`Account::updating` boot guard (Phase 4) would otherwise reject it.

---

## 5. Database State Before Phase 8

```
SELECT module_type, COUNT(*) FROM accounts GROUP BY module_type
┌────────────────┬───────┐
│ module_type    │ count │
├────────────────┼───────┤
│ office         │    10 │
│ bus            │     7 │
│ flights        │     5 │
│ visas          │     5 │
│ hajj_umra      │     4 │
│ online         │     4 │
│ tourism        │     2 │
│ fawry          │     1 │
│ wallet         │     0 │
│ wallet_transfer│     0 │
│ wallets        │     0 │
└────────────────┴───────┘

SELECT COUNT(*) FROM wallet_transactions
                              count: 0
```

**Zero data loss risk.** No migration script needed.

---

## 6. Test Contract (`scripts/phase_8_wallet_module_fix.php`)

28 checks across 10 sections, all rolled back via `DB::rollBack()`:

| # | Section | Checks |
|---|---------|--------|
| ① | `ensureCustomerAccount` writes `module_type='wallet_transfer'` | 2 |
| ② | `TransferDashboardController` surfaces wallet_transfer accounts | 3 |
| ③ | `WalletAccountResource` stays umbrella (intentional) | 2 |
| ④ | `TransferWalletResource` filters to wallet_transfer only | 2 |
| ⑤ | `updateTransaction` reposts main pair on amount/fee change | 9 |
| ⑥ | `updateTransaction` skips repost on notes-only edit | 2 |
| ⑦ | `updateTransaction` reposts settlement on amount_paid change | 2 |
| ⑧ | `deleteTransaction` still works (no regression) | 5 |
| ⑨ | `AccountModuleDivision` cleanup verified | 6 |
| ⑩ | `StoreAccountRequest` validation rejects `'wallet'` | 2 |

### Run

```bash
php scripts/phase_8_wallet_module_fix.php
# → Result file: storage/logs/phase_8_wallet_result.json
```

### Latest Result

```json
{
  "success": true,
  "failed_count": 0,
  "failed_labels": [],
  "ran_at": "2026-07-12T..."
}
```

---

## 7. Risks & Rollback

| Risk | Mitigation |
|------|------------|
| `repostMainTransactions` reposts even when fields are unchanged | Uses `array_key_exists` + float-equality check; if no field actually changed, `repostMainTransactions` is not invoked at all. |
| Customer account re-tag in `ensureCustomerAccount` triggers `Account::updating` boot guard | Wrapped in `LedgerBalanceMutationGuard::run(...)` which sets the bypass flag. |
| `updateTransaction` is called for non-Completed transactions | Wallet has no `status` field (every tx is finalized at create), so no guard needed. |
| `deleteTransaction` regression | Phase 8 left `deleteTransaction()` untouched (it was already additive). Test ⑧ validates it. |

---

## 8. Future Considerations

- **`customer_id` change** in `updateTransaction` would require reversing the
  entire tx, switching the customer account, and re-posting. Not yet
  supported (would need full re-route). The `UpdateWalletTransactionRequest`
  currently allows only `notes`, so this is moot for now.
- **`type` change** (Send → Receive) would similarly need full reversal +
  re-route. Same status: not supported.
- If a future PR adds `customer_id` / `type` to the editable form, both
  flows need to call `deleteTransaction()` then `createTransaction()`
  rather than extend the repost helpers.

---

## 9. Atomic Commit Plan (suggested)

1. **`fix(wallet): unify module_type on wallet_transfer + extend ensureCustomerAccount re-tag`**
   - `WalletTransactionService::ensureCustomerAccount()`
2. **`fix(wallet): add module_type=wallet_transfer filter to Filament Selects`**
   - `WalletTransactionResource`
3. **`fix(reports): replace bare 'wallet' with 'wallet_transfer' in FinancialReportService`**
   - `FinancialReportService`
4. **`refactor(wallet): split main-pair and settlement posting in WalletTransactionService`**
   - `WalletTransactionService::accountForSend/Receive` + new helpers
5. **`fix(wallet): repost income/expense/settlement transactions on update (Phase 9 pattern)`**
   - `WalletTransactionService::updateTransaction`
6. **`refactor(finance): drop legacy wallet mappings from AccountModuleDivision`**
   - `AccountModuleDivision`
7. **`chore(wallet): add Phase 8 test script + README`**
   - `scripts/phase_8_wallet_module_fix.php`
   - `docs/WALLET_MODULE_PHASE_8.md`

---

*Phase 8 closes the third "medium-risk" module. Online, HajjUmra, Visa,
Bus, and Wallet now share the same additive-reversal + repost contract.*