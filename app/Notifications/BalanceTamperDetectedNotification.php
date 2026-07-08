<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 🚨 Phase 1 alert: تلاعب مباشر بعمود balance المحمي في flight_carriers أو flight_systems.
 *
 * يُرسَل تلقائياً عن طريق DB::listen() في AppServiceProvider لما يكتشف
 * UPDATE خارج سياق LedgerBalanceMutationGuard::run().
 *
 * القنوات: database (يظهر في Filament notification bell) + mail (لو مفعل).
 */
class BalanceTamperDetectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $table,
        public string $sqlPreview,
        public string $callerFile,
        public int $callerLine,
        public ?string $userIdentifier,
        public ?string $connectionName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        $channels = ['database'];

        // mail اختياري — يعتمد على ما إذا كان الـ notifiable عنده email
        if (! empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('🚨 محاولة تعديل مباشر على رصيد محمي')
            ->line("تم رصد **تعديل مباشر** على عمود `balance` في الجدول `{$this->table}` خارج المسار المعتمد.")
            ->line('')
            ->line("**الملف**: `{$this->callerFile}` : السطر {$this->callerLine}")
            ->line("**SQL مختصر**: `{$this->sqlPreview}`")
            ->line("**المستخدم**: " . ($this->userIdentifier ?? 'غير معروف'))
            ->line("**Connection**: " . ($this->connectionName ?? '?'))
            ->line('')
            ->line('⚠️ هذا التعديل لو تم، يسبب desync بين الرصيد التشغيلي والحساب المسبق المحاسبي.')
            ->line('✅ استخدم دائماً: `FlightCarrierRechargeService::rechargeFromAccount()` أو `debit()/credit()`.')
            ->line('')
            ->action('افتح تقرير الحادثة', url('/admin'))
            ->line('تم تسجيل هذه المحاولة في `' . storage_path('logs/laravel.log') . '`.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        return [
            'severity'        => 'critical',
            'category'        => 'balance_tamper_attempt',
            'table'           => $this->table,
            'sql_preview'     => $this->sqlPreview,
            'caller_file'     => $this->callerFile,
            'caller_line'     => $this->callerLine,
            'user_identifier' => $this->userIdentifier,
            'connection'      => $this->connectionName,
            'message'         => "محاولة تعديل مباشر على {$this->table}.balance في {$this->callerFile}:{$this->callerLine}",
            'icon'            => 'heroicon-o-shield-exclamation',
            'color'           => 'danger',
        ];
    }
}
