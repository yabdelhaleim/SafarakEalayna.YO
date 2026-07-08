<?php

namespace App\Filament\Admin\Pages;

use App\Services\System\MaintenanceModeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Admin control panel for the application's maintenance mode.
 *
 * Allows toggling `php artisan down` / `up` from the Filament admin UI
 * without needing SSH access. Auto-discovered by AdminPanelProvider.
 */
class MaintenanceModePage extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'وضع الصيانة';

    protected static ?string $title = 'وضع الصيانة';

    protected static string|\UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'maintenance-mode';

    protected string $view = 'filament.admin.pages.maintenance-mode';

    /** @var array<string, mixed> */
    public array $status = [];

    public bool $isDown = false;

    public function mount(MaintenanceModeService $service): void
    {
        $this->refreshStatus($service);
    }

    public function refreshStatus(MaintenanceModeService $service): void
    {
        $this->status = $service->status();
        $this->isDown = (bool) ($this->status['is_down'] ?? false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enable')
                ->label('تفعيل وضع الصيانة')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn (): bool => ! $this->isDown)
                ->modalHeading('تفعيل وضع الصيانة')
                ->modalDescription('سيتم إغلاق الموقع أمام الزوار فوراً وعرض صفحة 503 المخصصة. تأكد من إدخال Secret Token للدخول كمشرف أثناء الصيانة.')
                ->modalWidth('2xl')
                ->form([
                    TextInput::make('secret')
                        ->label('Secret Token (للوصول أثناء الصيانة)')
                        ->placeholder('مثال: admin-bypass-2026')
                        ->helperText('URL للدخول أثناء الصيانة: '.url('/').'/{token}')
                        ->maxLength(120)
                        ->required(),
                    TextInput::make('retry')
                        ->label('Retry-After (بالثواني)')
                        ->numeric()
                        ->default(60)
                        ->minValue(1)
                        ->maxValue(86400)
                        ->helperText('المدة التي تنتظرها المتصفحات قبل إعادة المحاولة.'),
                    TextInput::make('redirect')
                        ->label('رابط التحويل (اختياري)')
                        ->url()
                        ->placeholder('https://status.safarakealayna.com')
                        ->maxLength(255)
                        ->helperText('لو مُلئ سيتم تحويل الزوار لهذا الرابط بدلاً من صفحة الصيانة.'),
                    TextInput::make('render')
                        ->label('View مخصص (اختياري)')
                        ->placeholder('errors::503')
                        ->default('errors::503')
                        ->maxLength(120)
                        ->helperText('اتركه فارغاً لاستخدام صفحة 503.blade.php الافتراضية.'),
                    Repeater::make('allow')
                        ->label('استثناء IP addresses')
                        ->helperText('عناوين IP تُسمح لها بدخول الموقع حتى أثناء الصيانة (كل سطر عنوان).')
                        ->schema([
                            TextInput::make('ip')
                                ->label('IP / CIDR')
                                ->placeholder('192.168.1.10 أو 10.0.0.0/8')
                                ->maxLength(64),
                        ])
                        ->columns(1)
                        ->defaultItems(0)
                        ->addActionLabel('إضافة IP')
                        ->collapsible(),
                ])
                ->action(function (array $data, MaintenanceModeService $service): void {
                    $allow = collect($data['allow'] ?? [])
                        ->pluck('ip')
                        ->filter(fn ($v) => filled($v))
                        ->values()
                        ->all();

                    $result = $service->enable([
                        'secret' => $data['secret'] ?? null,
                        'retry' => $data['retry'] ?? 60,
                        'redirect' => $data['redirect'] ?? null,
                        'render' => $data['render'] ?? 'errors::503',
                        'allow' => $allow,
                    ]);

                    $this->refreshStatus($service);

                    if ($result['ok']) {
                        $bypass = url('/'.$data['secret']);
                        Notification::make()
                            ->title('تم تفعيل وضع الصيانة')
                            ->body('رابط الدخول للمشرفين: '.$bypass)
                            ->success()
                            ->duration(15000)
                            ->send();
                    } else {
                        Notification::make()
                            ->title('تعذر تفعيل وضع الصيانة')
                            ->body($result['message'])
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('disable')
                ->label('إيقاف وضع الصيانة')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn (): bool => $this->isDown)
                ->requiresConfirmation()
                ->modalHeading('إيقاف وضع الصيانة')
                ->modalDescription('هل تريد بالتأكيد إعادة الموقع للعمل؟ سيتمكن الزوار من الدخول فوراً.')
                ->action(function (MaintenanceModeService $service): void {
                    $result = $service->disable();
                    $this->refreshStatus($service);

                    if ($result['ok']) {
                        Notification::make()
                            ->title('تم إيقاف وضع الصيانة')
                            ->body('الموقع عاد للعمل بشكل طبيعي.')
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('تعذر إيقاف وضع الصيانة')
                            ->body($result['message'])
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}