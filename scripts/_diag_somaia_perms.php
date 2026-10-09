<?php

/**
 * READ-ONLY SECURITY INVESTIGATION: Somaia's permissions + the 2 wallet #66 transactions
 * ────────────────────────────────────────────────────────────────────────────────────────
 * Usage on server:
 *   cd /var/www/safarakealayna
 *   php _diag_somaia_perms.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo str_repeat('═', 100).PHP_EOL;
echo ' SECURITY INVESTIGATION'.PHP_EOL;
echo '  - Who is User #4 (سميه اشرف)?'.PHP_EOL;
echo '  - What permissions does she have?'.PHP_EOL;
echo '  - Can she create treasury transfers?'.PHP_EOL;
echo '  - Who actually created the 2 wallet #66 entries?'.PHP_EOL;
echo "  - Is there any 'impersonation' feature?".PHP_EOL;
echo str_repeat('═', 100).PHP_EOL.PHP_EOL;

// =====================================================================================
// 1) SOMAIA'S FULL PROFILE
// =====================================================================================
echo str_repeat('─', 100).PHP_EOL;
echo " 1) SOMAIA'S PROFILE (User #4)".PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

$somaia = DB::table('users')->where('id', 4)->first();
if ($somaia) {
    echo "  ID:         {$somaia->id}".PHP_EOL;
    echo "  Name:       {$somaia->name}".PHP_EOL;
    echo "  Email:      {$somaia->email}".PHP_EOL;
    echo '  Active:     '.($somaia->is_active ? 'YES' : 'NO').PHP_EOL;
    echo "  Created:    {$somaia->created_at}".PHP_EOL;
    echo "  Updated:    {$somaia->updated_at}".PHP_EOL;
    echo '  Email verified: '.($somaia->email_verified_at ?? 'NO').PHP_EOL;
}

// =====================================================================================
// 2) SOMAIA'S ROLES (Spatie or any RBAC system)
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo " 2) SOMAIA'S ROLES (RBAC tables)".PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

try {
    $roles = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_has_roles.model_id', 4)
        ->where('model_has_roles.model_type', 'App\\Models\\User')
        ->get(['roles.id', 'roles.name', 'roles.guard_name']);
    if ($roles->isEmpty()) {
        echo '  ℹ️  No roles assigned to User #4'.PHP_EOL;
    } else {
        foreach ($roles as $r) {
            echo "  🔑 Role: #{$r->id} '{$r->name}' (guard: {$r->guard_name})".PHP_EOL;
        }
    }
} catch (Throwable $e) {
    echo '  ⚠️  Could not check roles: '.$e->getMessage().PHP_EOL;
}

// =====================================================================================
// 3) SOMAIA'S DIRECT PERMISSIONS
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo " 3) SOMAIA'S DIRECT PERMISSIONS".PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

try {
    $perms = DB::table('model_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
        ->where('model_has_permissions.model_id', 4)
        ->where('model_has_permissions.model_type', 'App\\Models\\User')
        ->get(['permissions.id', 'permissions.name', 'permissions.guard_name']);
    if ($perms->isEmpty()) {
        echo '  ℹ️  No direct permissions assigned to User #4'.PHP_EOL;
    } else {
        foreach ($perms as $p) {
            echo "  ✅ Perm: #{$p->id} '{$p->name}' (guard: {$p->guard_name})".PHP_EOL;
        }
    }
} catch (Throwable $e) {
    echo '  ⚠️  Could not check permissions: '.$e->getMessage().PHP_EOL;
}

// =====================================================================================
// 4) ALL ROLES IN THE SYSTEM + how many users each
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo ' 4) ALL ROLES IN THE SYSTEM'.PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

try {
    $allRoles = DB::table('roles')->get(['id', 'name', 'guard_name']);
    foreach ($allRoles as $r) {
        $count = DB::table('model_has_roles')
            ->where('role_id', $r->id)
            ->where('model_type', 'App\\Models\\User')
            ->count();
        echo "  📋 Role #{$r->id} '{$r->name}' (guard: {$r->guard_name}) — {$count} users".PHP_EOL;
    }
} catch (Throwable $e) {
    echo '  ⚠️  Could not load roles: '.$e->getMessage().PHP_EOL;
}

// =====================================================================================
// 5) SOMAIA'S TRANSACTIONS — All entries she created
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo ' 5) ALL TRANSACTIONS CREATED BY SOMAIA (User #4)'.PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

$somaiaTxns = DB::table('transactions')
    ->where('created_by', 4)
    ->orderBy('id')
    ->get(['id', 'type', 'amount', 'module', 'from_account_id', 'to_account_id', 'notes', 'client_ip', 'created_at']);

echo '  Total: '.count($somaiaTxns).PHP_EOL;
foreach ($somaiaTxns as $t) {
    $from = $t->from_account_id ? DB::table('accounts')->where('id', $t->from_account_id)->value('name') : '-';
    $to = $t->to_account_id ? DB::table('accounts')->where('id', $t->to_account_id)->value('name') : '-';
    printf("    Txn #%-4d  %s  %-10s/%-8s  %-7s  %s → %s  IP=%-15s  Notes=%s\n",
        $t->id, $t->created_at, $t->type ?? '-', $t->module ?? '-',
        number_format((float) $t->amount, 2),
        mb_substr($from ?? '?', 0, 25), mb_substr($to ?? '?', 0, 25),
        $t->client_ip ?? '-',
        mb_substr($t->notes ?? '-', 0, 50)
    );
}

// =====================================================================================
// 6) DEEP DIVE ON THE 2 WALLET #66 TRANSACTIONS
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo ' 6) DETAILED DEEP DIVE — the 2 wallet #66 transactions'.PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

$twoTxns = DB::table('transactions as t')
    ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
    ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
    ->leftJoin('users as u', 'u.id', '=', 't.created_by')
    ->whereIn('t.id', [395, 398])
    ->get([
        't.id', 't.created_at', 't.amount', 't.module', 't.type', 't.notes',
        't.from_account_id', 't.to_account_id', 't.created_by',
        't.client_ip', 't.posting_channel', 't.correlation_id',
        't.http_method', 't.request_route', 't.user_agent',
        'fa.name as from_name', 'fa.type as from_type',
        'ta.name as to_name', 'ta.type as to_type',
        'u.name as user_name', 'u.email as user_email', 'u.is_active as user_active',
    ]);

foreach ($twoTxns as $t) {
    echo str_repeat('·', 100).PHP_EOL;
    echo "  📦 TRANSACTION #{$t->id}".PHP_EOL;
    echo str_repeat('·', 100).PHP_EOL;
    echo "    When:              {$t->created_at}".PHP_EOL;
    echo "    Type/Module:       {$t->type} / {$t->module}".PHP_EOL;
    echo '    Amount:            '.number_format((float) $t->amount, 2).PHP_EOL;
    echo '    HTTP Method:       '.($t->http_method ?? '-').PHP_EOL;
    echo '    Request Route:     '.($t->request_route ?? '-').PHP_EOL;
    echo '    Posting Channel:   '.($t->posting_channel ?? '-').PHP_EOL;
    echo '    User Agent:        '.mb_substr($t->user_agent ?? '-', 0, 80).PHP_EOL;
    echo '    Client IP:         '.($t->client_ip ?? '-').PHP_EOL;
    echo '    Correlation ID:    '.($t->correlation_id ?? '-').PHP_EOL;
    echo "    From:              #{$t->from_account_id} {$t->from_name} ({$t->from_type})".PHP_EOL;
    echo "    To:                #{$t->to_account_id} {$t->to_name} ({$t->to_type})".PHP_EOL;
    echo '    Notes:             '.($t->notes ?? '-').PHP_EOL;
    echo PHP_EOL;
    echo "    👤 Created By:     #{$t->created_by} {$t->user_name} <{$t->user_email}>".PHP_EOL;
    echo '       User Active:    '.($t->user_active ? 'YES' : 'NO').PHP_EOL;
    echo PHP_EOL;

    // Audit logs for this transaction
    echo '    📋 AUDIT LOGS for this transaction:'.PHP_EOL;
    if (Schema::hasTable('audit_logs')) {
        try {
            $logs = DB::table('audit_logs')
                ->leftJoin('users as au', 'au.id', '=', 'audit_logs.user_id')
                ->where(function ($q) use ($t) {
                    $q->where('model_type', 'transaction')->where('model_id', $t->id);
                })
                ->orWhere(function ($q) use ($t) {
                    $q->where('model_type', 'App\\Models\\Transaction')->where('model_id', $t->id);
                })
                ->orderBy('audit_logs.created_at')
                ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.user_id',
                    'audit_logs.created_at', 'audit_logs.ip_address', 'audit_logs.old_values', 'audit_logs.new_values',
                    'au.name as actor_name', 'au.email as actor_email']);
            if ($logs->isEmpty()) {
                echo '      (none)'.PHP_EOL;
            } else {
                foreach ($logs as $l) {
                    echo "      🔍 Audit #{$l->id}  Action={$l->action}  At={$l->created_at}  IP={$l->ip_address}".PHP_EOL;
                    echo "         Actor: #{$l->user_id} ".($l->actor_name ?? 'NULL').' <'.($l->actor_email ?? 'NULL').'>'.PHP_EOL;
                    if ($l->new_values) {
                        $nv = is_string($l->new_values) ? json_decode($l->new_values, true) : $l->new_values;
                        echo '         New: '.json_encode($nv, JSON_UNESCAPED_UNICODE).PHP_EOL;
                    }
                    if ($l->old_values) {
                        $ov = is_string($l->old_values) ? json_decode($l->old_values, true) : $l->old_values;
                        echo '         Old: '.json_encode($ov, JSON_UNESCAPED_UNICODE).PHP_EOL;
                    }
                }
            }
        } catch (Throwable $e) {
            echo '      ⚠️  Could not load audit_logs: '.$e->getMessage().PHP_EOL;
        }
    }

    // Personal access tokens (Sanctum) for this user around that time
    echo PHP_EOL.'    🔑 PERSONAL ACCESS TOKENS for User #4 around that time:'.PHP_EOL;
    if (Schema::hasTable('personal_access_tokens')) {
        try {
            $tokens = DB::table('personal_access_tokens')
                ->where('tokenable_id', 4)
                ->where('tokenable_type', 'App\\Models\\User')
                ->where('created_at', '<=', $t->created_at)
                ->where(function ($q) use ($t) {
                    $q->whereNull('last_used_at')->orWhere('last_used_at', '<=', $t->created_at);
                })
                ->orderByDesc('created_at')
                ->limit(3)
                ->get(['id', 'name', 'created_at', 'last_used_at']);
            if ($tokens->isEmpty()) {
                echo '      (none active at that time)'.PHP_EOL;
            } else {
                foreach ($tokens as $tok) {
                    echo "      Token #{$tok->id} '{$tok->name}' Created={$tok->created_at} LastUsed=".($tok->last_used_at ?? 'never').PHP_EOL;
                }
            }
        } catch (Throwable $e) {
            echo '      ⚠️  Could not load tokens: '.$e->getMessage().PHP_EOL;
        }
    }
}

// =====================================================================================
// 7) CHECK FOR IMPERSONATION FEATURE
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo " 7) ANY 'IMPERSONATE' / 'SWITCH USER' IN THE APP?".PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

// Search for impersonation in routes
$routesFile = base_path('routes/web.php');
if (file_exists($routesFile)) {
    $content = file_get_contents($routesFile);
    if (preg_match('/(impersonat|switch_user|loginAs|takeUser)/i', $content)) {
        echo '  ⚠️  Found impersonation-related text in routes/web.php'.PHP_EOL;
        foreach (['impersonat', 'switch_user', 'loginAs', 'takeUser'] as $needle) {
            if (stripos($content, $needle) !== false) {
                echo "     • matched: $needle".PHP_EOL;
            }
        }
    } else {
        echo '  ✅ No impersonation text in routes/web.php'.PHP_EOL;
    }
}

// Search in all route files
$routesPath = base_path('routes');
foreach (glob($routesPath.'/*.php') as $file) {
    $content = file_get_contents($file);
    if (preg_match('/(impersonat|switch_user|loginAs|actAs|becomeUser)/i', $content)) {
        echo '  ⚠️  Impersonation-like keywords found in '.basename($file).PHP_EOL;
    }
}

// =====================================================================================
// 8) ALL USERS + Their CREATED TRANSACTION COUNTS
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo ' 8) WHO CREATED THE 2 ENTRIES #790, #796? (Cross-check)'.PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

foreach ($twoTxns as $t) {
    echo "  Txn #{$t->id} ({$t->created_at}):".PHP_EOL;
    // count entries for this txn
    $entryCount = DB::table('account_entries')->where('transaction_id', $t->id)->count();
    echo "    Entries on this transaction: {$entryCount}".PHP_EOL;
    // list entries
    $entries = DB::table('account_entries')->where('transaction_id', $t->id)->get(['id', 'account_id', 'debit', 'credit']);
    foreach ($entries as $e) {
        $accName = DB::table('accounts')->where('id', $e->account_id)->value('name');
        printf("      Entry #%-4d Account #%-3d %-30s Dr=%-8s Cr=%-8s\n",
            $e->id, $e->account_id, mb_substr($accName ?? '?', 0, 30),
            number_format((float) $e->debit, 2), number_format((float) $e->credit, 2));
    }
}

echo PHP_EOL.str_repeat('═', 100).PHP_EOL;
echo ' INVESTIGATION COMPLETE'.PHP_EOL;
echo ' Review the output above carefully.'.PHP_EOL;
echo str_repeat('═', 100).PHP_EOL;
