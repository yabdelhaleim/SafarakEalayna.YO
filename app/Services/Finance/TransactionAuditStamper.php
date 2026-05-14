<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Support\Finance\PostingContextRegistry;
use Illuminate\Support\Str;

/**
 * يوسم {@see Transaction} ببيانات المصدر (HTTP / كونسول / داخلي).
 */
class TransactionAuditStamper
{
    public function __construct(
        protected PostingContextRegistry $postingContextRegistry,
    ) {}

    public function stamp(Transaction $transaction): void
    {
        if (! config('accounting.audit.enabled', true)) {
            return;
        }

        $ctx = $this->postingContextRegistry->peek();

        if ($ctx === null) {
            if (app()->runningInConsole()) {
                $transaction->forceFill([
                    'posting_channel' => 'console',
                    'correlation_id' => (string) Str::uuid(),
                ])->saveQuietly();
            } elseif (config('accounting.audit.warn_missing_context', false)) {
                logger()->notice('financial_posting_without_context', [
                    'transaction_id' => $transaction->id,
                ]);
            }
        } else {
            $route = $ctx->routeName ?? $ctx->requestPath;

            $transaction->forceFill([
                'posting_channel' => $ctx->channel,
                'correlation_id' => $ctx->correlationId,
                'http_method' => $ctx->httpMethod,
                'request_route' => $route,
                'client_ip' => $ctx->clientIp,
                'user_agent' => $ctx->userAgent,
            ])->saveQuietly();
        }

        $this->writeAuditLogRow($transaction);
    }

    protected function writeAuditLogRow(Transaction $transaction): void
    {
        if (! config('accounting.audit.log_to_audit_logs_table', true)) {
            return;
        }

        try {
            AuditLog::create([
                'user_id' => $transaction->created_by,
                'action' => 'ledger_transaction_posted',
                'model_type' => Transaction::class,
                'model_id' => $transaction->id,
                'ip_address' => $transaction->client_ip ?? (function_exists('request') && request() ? request()->ip() : null),
                'user_agent' => $transaction->user_agent ?? (function_exists('request') && request() ? substr((string) request()->userAgent(), 0, 2000) : null),
                'new_values' => [
                    'transaction_id' => $transaction->id,
                    'type' => is_object($transaction->type) ? $transaction->type->value : $transaction->type,
                    'module' => is_object($transaction->module) ? $transaction->module->value : $transaction->module,
                    'amount' => (float) $transaction->amount,
                    'posting_channel' => $transaction->posting_channel,
                    'correlation_id' => $transaction->correlation_id,
                    'request_route' => $transaction->request_route,
                ],
                'notes' => 'Financial posting audit snapshot',
            ]);
        } catch (\Throwable $e) {
            logger()->warning('audit_log_snapshot_failed', [
                'transaction_id' => $transaction->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
