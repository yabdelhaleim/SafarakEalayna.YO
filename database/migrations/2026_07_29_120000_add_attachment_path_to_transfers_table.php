<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add `attachment_path` column to `transfers`.
     *
     * Rationale: until now, attachments uploaded via POST /finance/transfers
     * were stored ONLY on `transactions.attachment_path`. The Transfer record
     * had no column for the attachment, so the transfer API response could not
     * expose `attachment_url` directly. Storing it on both `transactions` and
     * `transfers` lets the TransferResource and TransferHistoryResource
     * surface the file without a join.
     *
     * The column is nullable because:
     *  - not every transfer carries an attachment, and
     *  - existing rows (pre-feature) must remain valid (NULL).
     */
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('attachment_path');
        });
    }
};