<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feature: Threshold notifications for FlightGroup credit limit.
 *
 * Adds 8 columns to `flight_groups`:
 *   - 3 absolute-amount thresholds (info / warning / danger)
 *   - 3 boolean channel toggles (toast / widget / bell)
 *   - 2 last-notification tracking columns (for "once per level" semantics)
 *
 * Also performs a one-time backfill so existing groups have:
 *   - credit_limit = 999,999,999 (Part A default)
 *   - notification channels = true
 *   - threshold amounts = NULL (no notification until configured)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_groups', function (Blueprint $table) {
            // عتبات الإشعار (مبالغ مطلقة)
            $table->decimal('notification_threshold_info', 15, 2)
                ->nullable()
                ->after('credit_limit')
                ->comment('إشعار info لما المتاح ينخفض تحت هذا المبلغ.');

            $table->decimal('notification_threshold_warning', 15, 2)
                ->nullable()
                ->after('notification_threshold_info')
                ->comment('إشعار warning لما المتاح ينخفض تحت هذا المبلغ.');

            $table->decimal('notification_threshold_danger', 15, 2)
                ->nullable()
                ->after('notification_threshold_warning')
                ->comment('إشعار danger لما المتاح ينخفض تحت هذا المبلغ.');

            // قنوات الإشعار
            $table->boolean('notify_via_toast')
                ->default(true)
                ->after('notification_threshold_danger')
                ->comment('إظهار Toast Popup فوري بعد الحجز.');

            $table->boolean('notify_via_widget')
                ->default(true)
                ->after('notify_via_toast')
                ->comment('إظهار في Dashboard Widget على لوحة الطيران.');

            $table->boolean('notify_via_bell')
                ->default(true)
                ->after('notify_via_widget')
                ->comment('إظهار في In-App Notification Bell.');

            // تتبع آخر إشعار (لتجنب التكرار + reset على السداد)
            $table->string('last_threshold_level', 16)
                ->nullable()
                ->after('notify_via_bell')
                ->comment('آخر مستوى إشعار تم إرساله (info|warning|danger|null).');

            $table->timestamp('last_threshold_notified_at')
                ->nullable()
                ->after('last_threshold_level')
                ->comment('وقت آخر إشعار threshold.');
        });

        // ─── Backfill ─────────────────────────────────────────────────────────
        // 1) ضبط credit_limit لكل المجموعات الموجودة على 999,999,999
        //    لتفعيل السلوك "أجل تلقائي" افتراضياً (Part A).
        DB::table('flight_groups')
            ->where('credit_limit', '<=', 0)
            ->update(['credit_limit' => 999999999]);

        // 2) تأكد أن كل المجموعات النشطة عندها القنوات مفعّلة.
        DB::table('flight_groups')
            ->whereNull('notify_via_toast')
            ->update(['notify_via_toast' => true, 'notify_via_widget' => true, 'notify_via_bell' => true]);
    }

    public function down(): void
    {
        Schema::table('flight_groups', function (Blueprint $table) {
            $table->dropColumn([
                'notification_threshold_info',
                'notification_threshold_warning',
                'notification_threshold_danger',
                'notify_via_toast',
                'notify_via_widget',
                'notify_via_bell',
                'last_threshold_level',
                'last_threshold_notified_at',
            ]);
        });
    }
};