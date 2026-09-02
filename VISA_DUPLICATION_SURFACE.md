# VISA DUPLICATION & CONCURRENCY SURFACE

## Concurrency Analysis

| Operation | Concurrency Protection | Idempotency Guard | Risk Rating |
| --- | --- | --- | --- |
| `createBooking` | `DB::transaction()` | Customer / detail uniqueness | Low |
| `addPayment` | `VisaBooking::lockForUpdate()` | Overpayment guard (`$amount > $remaining + 0.01`) | Low (Fixed 2026-08-14) |
| `addDebtPayment` | `DB::transaction()` | Remaining amount check | Low |
| `cancel` | Status guard check | Status `Cancelled` check | Low |
| `refund` | Status guard check | Status `Refunded` check | Low |
| `deleteWithReversal` | `DB::transaction()` | Trashed check | Low |
