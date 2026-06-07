<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Models\Account;
use App\Models\Flight\FlightSystem;
use App\Services\Flight\FlightSystemRechargeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Collection;

class FlightSystemsBalancesPage extends Page
{
    use BelongsToFlightModuleNavigation;
    use HasSafarakFlightModulePageStyles;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'أرصدة أنظمة الطيران';

    protected static ?string $title = 'أرصدة أنظمة الطيران';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.flight-system-balances';

    /** @var Collection<int, FlightSystem> */
    public $systems;

    public function mount(): void
    {
        $this->refreshSystems();
    }

    public function refreshSystems(): void
    {
        $this->systems = FlightSystem::query()
            ->orderBy('name')
            ->get();
    }

    protected static function accountOptionLabel(Account $a): string
    {
        $typeVal = $a->type instanceof BackedEnum ? $a->type->value : (string) $a->type;
        $bal = number_format((float) $a->balance, 2);
        $cur = $a->currency ?? 'EGP';

        if ($typeVal === AccountType::Wallet->value) {
            $prov = $a->wallet_provider instanceof BackedEnum
                ? $a->wallet_provider->value
                : (string) ($a->wallet_provider ?? '');
            $pl = WalletProvider::tryFrom($prov)?->label() ?? ($prov !== '' ? $prov : 'محفظة');
            $num = trim((string) ($a->wallet_number ?? ''));
            $mid = $num !== '' ? "{$pl} — {$num}" : $pl;

            return "{$a->name} — {$mid} — {$bal} {$cur}";
        }

        $typeLabel = match ($typeVal) {
            AccountType::Cashbox->value => 'نقدي / درج',
            AccountType::Treasury->value => 'خزينة عامة',
            AccountType::Bank->value => 'بنك',
            default => $typeVal,
        };

        return "{$a->name} — {$typeLabel} — {$bal} {$cur}";
    }

    protected function getHeaderActions(): array
    {
        $systemOptions = fn (): array => FlightSystem::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (FlightSystem $s) => [
                $s->id => "{$s->name} ({$s->code}) — {$s->currency}",
            ])
            ->all();

        return [
            Action::make('rechargeFlightSystem')
                ->label('شحن رصيد نظام')
                ->icon('heroicon-o-arrow-trending-up')
                ->color('success')
                ->modalHeading('إعادة شحن رصيد نظام حجز')
                ->modalDescription('يُخصم المبلغ من حساب تحصيل (محفظة / بنك / خزينة) بنفس عملة النظام، ويُضاف لرصيد النظام مع قيد في الطيران.')
                ->form([
                    Select::make('flight_system_id')
                        ->label('نظام الحجز')
                        ->options($systemOptions())
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('from_account_id', null)),
                    Select::make('from_account_id')
                        ->label('من حساب (محفظة / بنك / خزينة)')
                        ->options(function (Get $get): array {
                            $sid = $get('flight_system_id');
                            if (! $sid) {
                                return [];
                            }
                            $system = FlightSystem::query()->find((int) $sid);
                            if (! $system) {
                                return [];
                            }

                            $types = [
                                AccountType::Cashbox->value,
                                AccountType::Wallet->value,
                                AccountType::Bank->value,
                                AccountType::Treasury->value,
                            ];

                            return Account::query()
                                ->where('is_active', true)
                                ->where('module_type', 'flights')
                                ->whereIn('type', $types)
                                ->where('currency', $system->currency)
                                ->orderBy('type')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Account $a) => [$a->id => self::accountOptionLabel($a)])
                                ->all();
                        })
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->disabled(fn (Get $get): bool => ! filled($get('flight_system_id'))),
                    TextInput::make('amount')
                        ->label('المبلغ')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->suffix(fn (Get $get): ?string => filled($get('flight_system_id'))
                            ? (FlightSystem::query()->find((int) $get('flight_system_id'))?->currency)
                            : null),
                    Textarea::make('notes')
                        ->label('ملاحظات (اختياري)')
                        ->rows(2)
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $system = FlightSystem::query()->findOrFail((int) $data['flight_system_id']);
                    $account = Account::query()->findOrFail((int) $data['from_account_id']);
                    $amount = (float) $data['amount'];
                    $notes = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;

                    try {
                        app(FlightSystemRechargeService::class)->rechargeFromAccount(
                            $system,
                            $account,
                            $amount,
                            $notes,
                        );
                        $this->refreshSystems();
                        Notification::make()
                            ->title('تم شحن رصيد النظام بنجاح')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('تعذر تنفيذ الشحن')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
