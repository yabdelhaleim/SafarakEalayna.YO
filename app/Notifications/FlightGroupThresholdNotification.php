<?php

namespace App\Notifications;

use App\Models\Flight\FlightGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies admins when a FlightGroup crosses a configured threshold
 * (info / warning / danger) of available balance.
 *
 * Always stored as a database notification (shown in the SPA bell and
 * optionally in Filament). Email is intentionally NOT used because the
 * user requested toast/widget/bell channels only.
 */
class FlightGroupThresholdNotification extends Notification
{
    use Queueable;

    /**
     * Severity-based metadata (icon + color + Arabic label).
     */
    public const LEVEL_META = [
        FlightGroup::THRESHOLD_LEVEL_INFO => [
            'icon'  => 'heroicon-o-information-circle',
            'color' => 'info',
            'label' => 'معلومة',
        ],
        FlightGroup::THRESHOLD_LEVEL_WARNING => [
            'icon'  => 'heroicon-o-exclamation-triangle',
            'color' => 'warning',
            'label' => 'تحذير',
        ],
        FlightGroup::THRESHOLD_LEVEL_DANGER => [
            'icon'  => 'heroicon-o-shield-exclamation',
            'color' => 'danger',
            'label' => 'خطر',
        ],
    ];

    public function __construct(
        public FlightGroup $group,
        public string $level,
        public float $available,
        public float $threshold,
        public string $currency,
    ) {
    }

    /**
     * Channels: database only. Email intentionally excluded per product spec.
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * Payload stored in the `notifications.data` JSON column.
     * `notification_type` is used by the SPA bell to distinguish
     * passenger alerts from group-threshold alerts.
     */
    public function toArray($notifiable): array
    {
        $meta = self::LEVEL_META[$this->level] ?? self::LEVEL_META[FlightGroup::THRESHOLD_LEVEL_INFO];

        return [
            // Generic discriminator (consumed by frontend)
            'notification_type' => 'flight_group_threshold',

            // Level meta
            'level'  => $this->level,
            'icon'   => $meta['icon'],
            'color'  => $meta['color'],
            'label'  => $meta['label'],

            // Group refs
            'group_id'    => $this->group->id,
            'group_name'  => $this->group->name,
            'group_code'  => $this->group->code,
            'account_id'  => $this->group->account_id,

            // Numbers
            'available_amount' => round($this->available, 2),
            'threshold_amount' => round($this->threshold, 2),
            'currency'         => $this->currency,

            // Display
            'title'   => "{$meta['label']} — {$this->group->name}",
            'message' => sprintf(
                'مجموعة «%s»: المتاح %s %s تحت عتبة %s (%s %s).',
                $this->group->name,
                number_format($this->available, 2),
                $this->currency,
                $meta['label'],
                number_format($this->threshold, 2),
                $this->currency,
            ),
        ];
    }
}