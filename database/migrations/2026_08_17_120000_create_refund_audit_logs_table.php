<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tourism Employee Refund audit trail — dedicated `refund_audit_logs` table.
 *
 * Why a new table (and not just `audit_logs`):
 *   - `audit_logs` is generic (action + JSON blobs). Refund-domain fields
 *     (paid_amount_before, previously_refunded, remaining_refundable,
 *     transaction_id, account_entry_ids, affected_account_id) are
 *     queryable columns that admins need to filter on.
 *   - Per the audit spec: "extend existing infrastructure instead of
 *     duplicating data" — we ALSO insert a row into the generic
 *     `audit_logs` (see RefundAuditLogger). The dedicated table is the
 *     refund-domain queryable view; the generic table is the activity
 *     timeline.
 *
 * Scope:
 *   - Flight, Hajj/Umra, Visa refunds only.
 *   - One row per refund attempt (success or rejected).
 *   - Actor = authenticated backend user (NEVER trusted from payload).
 *
 * Invariants:
 *   - `user_id` always references `users.id` of the authenticated employee.
 *   - `user_name` is denormalized so the admin view does not break if the
 *     user record is later deleted or renamed.
 *   - `account_entry_ids` is a JSON array of inverse account_entry IDs
 *     created by the additive reversal pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_audit_logs', function (Blueprint $t) {
            $t->id();

            // Actor
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('user_name', 191)->nullable();

            // Module + booking reference
            $t->string('module', 32)->index(); // flight|hajj_umra|visa
            $t->unsignedBigInteger('booking_id')->nullable();
            $t->string('booking_reference', 64)->nullable();

            // Customer
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->string('customer_name', 191)->nullable();

            // Amounts
            $t->decimal('refund_amount', 15, 2)->default(0);
            $t->string('currency', 3)->default('EGP');
            $t->decimal('paid_amount_before', 15, 2)->default(0);
            $t->decimal('previously_refunded', 15, 2)->default(0);
            $t->decimal('remaining_refundable', 15, 2)->default(0);

            // Reason
            $t->text('reason')->nullable();

            // Ledger links
            $t->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $t->json('account_entry_ids')->nullable();
            $t->foreignId('affected_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            // Idempotency / replay
            $t->string('idempotency_key', 100)->nullable();

            // Request context (for audit only)
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 512)->nullable();

            $t->timestamps();

            $t->index(['module', 'booking_id'], 'ral_module_booking_idx');
            $t->index(['user_id', 'created_at'], 'ral_user_created_idx');
            $t->index('created_at', 'ral_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_audit_logs');
    }
};