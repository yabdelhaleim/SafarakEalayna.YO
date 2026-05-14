<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('transactions', 'posting_channel')) {
                $table->string('posting_channel', 32)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('transactions', 'correlation_id')) {
                $table->uuid('correlation_id')->nullable()->after('posting_channel');
            }
            if (! Schema::hasColumn('transactions', 'http_method')) {
                $table->string('http_method', 16)->nullable()->after('correlation_id');
            }
            if (! Schema::hasColumn('transactions', 'request_route')) {
                $table->string('request_route', 255)->nullable()->after('http_method');
            }
            if (! Schema::hasColumn('transactions', 'client_ip')) {
                $table->string('client_ip', 45)->nullable()->after('request_route');
            }
            if (! Schema::hasColumn('transactions', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('client_ip');
            }
        });

        if (! Schema::hasTable('ledger_reconciliation_runs')) {
            Schema::create('ledger_reconciliation_runs', function (Blueprint $table): void {
                $table->id();
                $table->timestamp('run_at')->useCurrent();
                $table->unsignedInteger('transactions_scanned')->default(0);
                $table->unsignedInteger('imbalanced_count')->default(0);
                $table->unsignedInteger('missing_entries_count')->default(0);
                $table->string('status', 24)->default('completed');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ledger_reconciliation_findings')) {
            Schema::create('ledger_reconciliation_findings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('ledger_reconciliation_run_id');
                $table->foreign('ledger_reconciliation_run_id', 'lr_find_run_fk')
                    ->references('id')
                    ->on('ledger_reconciliation_runs')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->foreign('transaction_id', 'lr_find_tx_fk')
                    ->references('id')
                    ->on('transactions')
                    ->nullOnDelete();
                $table->string('issue_type', 48);
                $table->decimal('debit_sum', 18, 2)->nullable();
                $table->decimal('credit_sum', 18, 2)->nullable();
                $table->decimal('delta', 18, 4)->nullable();
                $table->text('detail')->nullable();
                $table->timestamps();

                $table->index(['issue_type'], 'lr_find_issue_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_reconciliation_findings');
        Schema::dropIfExists('ledger_reconciliation_runs');

        Schema::table('transactions', function (Blueprint $table): void {
            foreach (['user_agent', 'client_ip', 'request_route', 'http_method', 'correlation_id', 'posting_channel'] as $col) {
                if (Schema::hasColumn('transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
