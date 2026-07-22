<?php

namespace App\Services\Flight;

use App\Models\Account;
use App\Models\Flight\FlightGroup;
use App\Models\User;
use App\Notifications\FlightGroupThresholdNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Evaluates a FlightGroup against its configured thresholds and dispatches
 * notifications when the available balance drops below a new (more severe)
 * level than the one previously notified.
 *
 * Semantics (matches the approved plan):
 *   available_balance = account.balance + flight_groups.credit_limit
 *   info_threshold    -> when available <= info_threshold
 *   warning_threshold -> when available <= warning_threshold
 *   danger_threshold  -> when available <= danger_threshold
 *
 * "Once per level":
 *   - Track last_threshold_level on the FlightGroup row.
 *   - Fire a notification ONLY if current level is MORE severe than last level
 *     (or last level is null). Never notify on a less severe level.
 *
 * "Reset on payment":
 *   - FlightGroupController::payDebt calls $group->resetThresholdTracking()
 *     so that a fresh descent after a payment triggers new notifications.
 */
class FlightGroupThresholdService
{
    /**
     * Evaluate the threshold situation for a group.
     *
     * @param  FlightGroup  $group  The group to evaluate.
     * @param  float|null   $newBalance  Optional explicit new balance (e.g. inside
     *                                   a transaction before the row is reloaded).
     *                                   If null, the current account.balance is used.
     * @return array|null  Evaluation payload (level, available, threshold, ...) or null
     *                     when no notification should fire.
     */
    public function evaluate(FlightGroup $group, ?float $newBalance = null): ?array
    {
        if (! $group->account_id) {
            return null;
        }

        $account = Account::find($group->account_id);
        if (! $account) {
            return null;
        }

        $balance     = $newBalance ?? (float) $account->balance;
        $creditLimit = (float) $group->credit_limit;
        $available   = $balance + $creditLimit;
        $currency    = $account->currency ?? 'EGP';

        $info    = (float) ($group->notification_threshold_info ?? 0);
        $warning = (float) ($group->notification_threshold_warning ?? 0);
        $danger  = (float) ($group->notification_threshold_danger ?? 0);

        // If no thresholds are configured, skip evaluation entirely.
        if ($info <= 0 && $warning <= 0 && $danger <= 0) {
            return null;
        }

        // Determine the highest level crossed.
        $currentLevel = null;
        $currentThreshold = 0;
        if ($danger > 0 && $available <= $danger) {
            $currentLevel = 'danger';
            $currentThreshold = $danger;
        } elseif ($warning > 0 && $available <= $warning) {
            $currentLevel = 'warning';
            $currentThreshold = $warning;
        } elseif ($info > 0 && $available <= $info) {
            $currentLevel = 'info';
            $currentThreshold = $info;
        }

        if ($currentLevel === null) {
            return null;
        }

        // "Once per level": only fire if current is more severe than last.
        $lastLevel     = $group->last_threshold_level;
        $currentSev    = FlightGroup::THRESHOLD_LEVEL_SEVERITY[$currentLevel] ?? 0;
        $lastSev       = $lastLevel ? (FlightGroup::THRESHOLD_LEVEL_SEVERITY[$lastLevel] ?? 0) : 0;

        if ($currentSev <= $lastSev) {
            return null;
        }

        return [
            'level'        => $currentLevel,
            'available'    => round($available, 2),
            'threshold'    => round($currentThreshold, 2),
            'balance'      => round($balance, 2),
            'credit_limit' => round($creditLimit, 2),
            'currency'     => $currency,
        ];
    }

    /**
     * Evaluate, persist the new level, and dispatch notifications.
     *
     * Returns the evaluation payload (so callers can surface it in the booking
     * response for an immediate Toast in the SPA), or null if no notification
     * was triggered.
     *
     * Notification dispatch respects the per-group channel toggles:
     *   - notify_via_bell -> DB notifications sent to all active admins/owners
     *   - notify_via_toast / notify_via_widget -> these are presentation hints;
     *     the frontend decides how to render based on the response payload.
     */
    public function evaluateAndNotify(FlightGroup $group, ?float $newBalance = null): ?array
    {
        $evaluation = $this->evaluate($group, $newBalance);
        if (! $evaluation) {
            return null;
        }

        // Persist the new level + timestamp.
        $group->last_threshold_level = $evaluation['level'];
        $group->last_threshold_notified_at = now();
        $group->save();

        // DB notification (bell) if enabled.
        if ($group->notify_via_bell) {
            $admins = User::query()
                ->whereIn('role', ['admin', 'owner'])
                ->where('is_active', true)
                ->get();

            if ($admins->isNotEmpty()) {
                Notification::send(
                    $admins,
                    new FlightGroupThresholdNotification(
                        $group,
                        $evaluation['level'],
                        $evaluation['available'],
                        $evaluation['threshold'],
                        $evaluation['currency'],
                    )
                );
            }
        }

        Log::info('FlightGroup threshold notification dispatched', [
            'group_id'   => $group->id,
            'group_name' => $group->name,
            'level'      => $evaluation['level'],
            'available'  => $evaluation['available'],
            'threshold'  => $evaluation['threshold'],
            'currency'   => $evaluation['currency'],
        ]);

        return $evaluation;
    }

    /**
     * Build a lightweight summary used by the dashboard widget.
     * Returns counts per level + the top N groups that need attention.
     */
    public function buildSummary(int $topN = 5): array
    {
        $groups = FlightGroup::query()
            ->with(['carrier', 'account'])
            ->where('is_active', true)
            ->get();

        $warningCount = 0;
        $dangerCount  = 0;
        $safeCount    = 0;
        $topGroups    = [];

        foreach ($groups as $group) {
            $evaluation = $this->evaluate($group);
            $severity = $evaluation ? (FlightGroup::THRESHOLD_LEVEL_SEVERITY[$evaluation['level']] ?? 0) : 0;

            if ($severity === 0) {
                $safeCount++;
                continue;
            }

            if ($severity >= 3) {
                $dangerCount++;
            } elseif ($severity >= 2) {
                $warningCount++;
            }

            $topGroups[] = [
                'id'                 => $group->id,
                'name'               => $group->name,
                'code'               => $group->code,
                'level'              => $evaluation['level'],
                'available'          => $evaluation['available'],
                'threshold'          => $evaluation['threshold'],
                'currency'           => $evaluation['currency'],
                'last_notified_at'   => optional($group->last_threshold_notified_at)->toIso8601String(),
            ];
        }

        // Sort top groups: danger first, then warning, then by oldest notification.
        usort($topGroups, function ($a, $b) {
            $sa = FlightGroup::THRESHOLD_LEVEL_SEVERITY[$a['level']] ?? 0;
            $sb = FlightGroup::THRESHOLD_LEVEL_SEVERITY[$b['level']] ?? 0;
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            return strcmp((string) $a['last_notified_at'], (string) $b['last_notified_at']);
        });

        return [
            'warning_count' => $warningCount,
            'danger_count'  => $dangerCount,
            'safe_count'    => $safeCount,
            'total_groups'  => $groups->count(),
            'top_groups'    => array_slice($topGroups, 0, $topN),
        ];
    }
}