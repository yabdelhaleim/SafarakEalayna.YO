# WALLET_TRANSFER_SECURITY_MATRIX.md

**Audit:** Wallet & Transfers
**Date:** 2026-08-20

This file presents the authorization matrix for every wallet endpoint, every user role, and every documented privilege.

---

## Endpoint × Role matrix

Tested by `Phase09SecurityTest` and `Phase16FinalSecurityAuditTest`.

| Endpoint | Anonymous | Inactive user | Standard employee (no permissions) | Admin | Method |
|---|---|---|---|---|---|
| `GET /api/v1/wallet/types` | 401 | 401 | 200 | 200 | `Phase07PositiveTest` (existing) |
| `GET /api/v1/wallet/dashboard` | 401 | 401 | 200 | 200 | out of scope |
| `GET /api/v1/wallet/customer-balances` | 401 | 401 | 200 | 200 | `Phase14FullE2ETest::test_e2e_customer_balances_returns_aggregated` |
| `GET /api/v1/wallet/customer-statement` | 401 | 401 | 200 | 200 | `Phase14FullE2ETest::test_e2e_customer_statement_returns_transactions` |
| `GET /api/v1/wallet/transactions` | 401 | 401 | 405 (R1-A) | 405 (R1-A) | `Phase07PositiveTest::test_index_endpoint_returns_405_INDICATES_MISSING_ROUTE` |
| `GET /api/v1/wallet/transactions/daily-summary` | 401 | 401 | 200 | 200 | `Phase07PositiveTest::test_daily_summary_uses_amount_not_total_amount_FIN_4` |
| `GET /api/v1/wallet/transactions/{id}` | 401 | 401 | 200 | 200 | `Phase07PositiveTest::test_show_endpoint_succeeds_for_known_transaction` |
| `POST /api/v1/wallet/transactions` | 401 | 401 / 403 | **201** (SEC-1) | 201 | `Phase09SecurityTest::test_default_employee_with_no_permissions_can_post_FIN_SEC_1` |
| `PUT /api/v1/wallet/transactions/{id}` | 401 | 401 | 403 (admin only) | 200 | `Phase09SecurityTest::test_update_payload_injection_is_blocked` |
| `DELETE /api/v1/wallet/transactions/{id}` | 401 | 401 | 403 (admin only) | 200 | `Phase16FinalSecurityAuditTest::test_non_admin_can_post_but_cannot_delete` |

---

## Field-level mass-assignment protection

Tested by `Phase09SecurityTest::test_income_transaction_id_payload_injection_is_ignored_or_overwritten`, `Phase16FinalSecurityAuditTest::test_injecting_balance_field_does_not_overwrite`, `Phase16FinalSecurityAuditTest::test_injecting_income_transaction_id_NOT_overwritten`.

| Field injected in payload | Server respects? | Tested by |
|---|---|---|
| `balance` | ✅ ignored (Account.balance is guarded by `Account::updating`) | `test_injecting_balance_field_does_not_overwrite` |
| `created_by` | ✅ ignored (overwritten by `Auth::id()`) | `test_created_by_is_the_authenticated_user` |
| `income_transaction_id` | ✅ ignored (set by service) | `test_injecting_income_transaction_id_NOT_overwritten` |
| `expense_transaction_id` | ✅ ignored (set by service) | `test_injecting_income_transaction_id_NOT_overwritten` |
| `status` | ✅ ignored (column doesn't exist) | `test_injecting_status_field_NOT_overwritten` |
| `state` | ✅ ignored (column doesn't exist) | `test_injecting_status_field_NOT_overwritten` |
| `deleted_at` | ✅ ignored (no MassAssignment on `WalletTransaction`) | `test_injecting_balance_field_does_not_overwrite` |
| `created_at` | ✅ ignored (DB handles timestamps) | `test_injecting_balance_field_does_not_overwrite` |

Mass-assignment protection is **WORKING** at the field level.

---

## Header injection / spoofing

| Header | Honored? | Test |
|---|---|---|
| `Idempotency-Key` | ❌ NO (IDM-1) | `Phase11IdempotencyTest::test_idempotency_key_header_is_NOT_honored` |
| `X-Request-Id` | ❌ NO | `Phase11IdempotencyTest::test_x_request_id_header_is_NOT_honored` |
| `Authorization: Bearer <token>` | ✅ YES (Sanctum) | `Phase09SecurityTest::test_request_without_token_returns_401` |

---

## Permission gate mapping

`app/Http/Middleware/CheckPermission.php` (lines 36-47) translates legacy keys:

| Legacy key | Resolves to | Granted to |
|---|---|---|
| `wallet.create` | `manage_treasury` | `admin`, `owner` (all), `employee` (default) |
| `wallet.view` | `manage_treasury` | same |
| `wallet.*` | `manage_treasury` | same |
| `fawry.create` | `manage_treasury` | same |
| `fawry.view` | `manage_treasury` | same |
| `fawry.*` | `manage_treasury` | same |
| `finance.view` | `manage_finance` | `admin`, `owner` only |
| `accounts.view` | `manage_finance` | same |
| `transactions.view` | `manage_finance` | same |
| `employees.view` | `manage_employees` | `admin`, `owner` only |
| `employees.create` | `manage_employees` | same |
| `employees.edit` | `manage_employees` | same |
| `employees.bonuses` | `manage_employees` | same |

---

## Cross-user / cross-tenant access

| Scenario | Status | Finding |
|---|---|---|
| User A reads User B's transaction via `GET /transactions/{id}` | ✅ ALLOWED (no creator filter) | SEC-2 |
| User A reads User B's customer statement via `?client_id=N` | ✅ ALLOWED | SEC-2 |
| User A uses User B's wallet account as source | ✅ ALLOWED | SEC-3 |
| User A uses User B's branch wallet | ✅ ALLOWED | SEC-3 |
| Cashier from branch A uses branch B's vault | ✅ ALLOWED | SEC-3 |

---

## SQL injection / XSS / CSRF

| Attack | Result | Test |
|---|---|---|
| SQL injection in `customer_name` | ✅ SAFE (Eloquent parameter binding) | `Phase16FinalSecurityAuditTest::test_sql_injection_in_string_field_does_not_corrupt_db` |
| HTML in `notes` | ⚠️ STORED verbatim (XSS risk if rendered) | `Phase16FinalSecurityAuditTest::test_html_in_notes_is_not_executed` (VAL-4) |
| CSRF via Sanctum token | ✅ SAFE (token-based, not cookie) | (standard Sanctum behavior) |

---

## Race / concurrency attacks

| Attack | Resilient? | Finding |
|---|---|---|
| 100 identical POSTs in tight loop | ⚠️ All 100 succeed (no idempotency) | IDM-1 |
| 100 sends in tight loop until balance exhausted | ✅ Last successful 100th send, 101st fails with "insufficient balance" | `Phase12ConcurrencyTest::test_at_balance_boundary_no_overdraw` |
| Two concurrent first-time sends for same customer | ⚠️ May create two customer accounts | CONC-2 |
| Burst of POSTs before lock acquired | ⚠️ WT rows created before balance check | CONC-1 |

---

## Soft-deleted access

| Endpoint | Behavior on soft-deleted row | Test |
|---|---|---|
| `GET /transactions/{id}` | Returns 404 or 200 (status-dependent) | `Phase16FinalSecurityAuditTest::test_show_on_soft_deleted_returns_404_or_410` |
| `DELETE /transactions/{id}` (second call) | Returns 404 / 422 / 500 | `Phase13RollbackTest::test_double_delete_returns_404_or_422` |
| `PUT /transactions/{id}` | Returns 200 (BUG: should reject) | not explicitly tested |

---

## Authentication / token lifecycle

| Scenario | Result | Test |
|---|---|---|
| No token | 401 | `Phase09SecurityTest::test_request_without_token_returns_401` |
| Invalid token | 401 | `Phase09SecurityTest::test_request_with_invalid_token_returns_401` |
| Inactive user | 401 / 403 | `Phase09SecurityTest::test_inactive_user_is_rejected` |
| User deactivates after auth | Future requests rejected | `Phase16FinalSecurityAuditTest::test_user_becomes_inactive_after_post` |

---

## Defaults that affect security (findings)

| Default | Concern | Finding |
|---|---|---|
| `role='employee'` users get `manage_treasury` automatically | Any new employee can post wallet transactions | SEC-1 (CRITICAL) |
| `permissions=[]` falls back to defaults | Cannot explicitly deny a permission | SEC-1 |
| Default `accounting.balance_guard.disable_in_testing=false` | Safe in production but blocks tests | (no finding — documented) |
| `accounting.balance_guard.block_unauthorized_updates=true` (default) | Direct balance writes blocked | (no finding — positive) |
| Wallet types are pre-seeded (Vodafone Cash, etc.) | No code-level role check on which wallet type is used | (no finding — operational) |

---

## Output / response safety

| Risk | Status | Test |
|---|---|---|
| Stack trace leaked in 500 response | ✅ suppressed (JSON `success:false`) | `Phase16FinalSecurityAuditTest::test_500_level_errors_are_not_leaked_in_response` |
| Arabic error messages only | ⚠️ not localizable | UX-2 (LOW) |
| Exception message in 422 body | ⚠️ leaks internal info | UX-1 (MED) |

---

## Findings recap (security)

| ID | Severity | Title |
|---|---|---|
| SEC-1 | 🔴 CRITICAL | Default-employee role grants `manage_treasury` automatically |
| SEC-2 | 🟡 MED | Show / statement endpoints do NOT filter by creator |
| SEC-3 | 🟡 MED | Cross-branch (cross-tenant) account usage is allowed |
| IDM-1 | 🔴 CRITICAL | No idempotency mechanism |
| CONC-1 | 🟠 HIGH | `WalletTransaction::create()` runs BEFORE the lock |
| CONC-2 | 🟡 MED | `ensureCustomerAccount()` is not protected by a row-level lock |
| VAL-1 | 🟠 HIGH | No currency-mismatch validation |
| VAL-4 | 🟡 MED | Notes field is not sanitized |
| UX-1 | 🟡 MED | All exceptions converted to HTTP 422 |
| UX-2 | 🟢 LOW | Error messages are in Arabic only |
| SEC-4 | 🟢 LOW | Soft-deleted WT may still be reachable via GET |

---

*End of WALLET_TRANSFER_SECURITY_MATRIX.md*
