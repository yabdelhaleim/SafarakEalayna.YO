<?php

namespace App\Filament\Resources\FlightSystem;

use App\Filament\Resources\FlightSystem\Pages;
use App\Models\Flight\FlightSystem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FlightSystemResource extends Resource
{
    protected static ?string $model = FlightSystem::class;

    protected static ?string $navigationIcon = 'heroicon-o-server';

    protected static ?string $navigationLabel = 'أنظمة الحجز';

    protected static ?string $modelLabel = 'نظام حجز';

    protected static ?string $pluralModelLabel = 'أنظمة الحجز';

    protected static ?string $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('معلومات النظام')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('اسم النظام')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: Amadeus, NDC, NDC X'),

                        Forms\Components\TextInput::make('code')
                            ->label('الكود')
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->placeholder('مثال: AMA, NDC, NDCX')
                            ->helperText('كود مختصر فريد للنظام'),

                        Forms\Components\Select::make('type')
                            ->label('نوع النظام')
                            ->options([
                                'gds' => 'GDS (Global Distribution System)',
                                'ndc' => 'NDC (New Distribution Capability)',
                                'other' => 'أخرى',
                            ])
                            ->default('gds')
                            ->required(),

                        Forms\Components\Textarea::make('description')
                            ->label('الوصف')
                            ->rows(3)
                            ->placeholder('وصف مختصر عن النظام...'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('الكود')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('name')
                    ->label('اسم النظام')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {                        'gds' => 'success',                        'ndc' => 'warning',                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {                        'gds' => 'GDS',                        'ndc' => 'NDC',                        default => 'أخرى',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('carriers_count')
                    ->label('عدد الشركات')
                    ->counts('carriers')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع النظام')
                    ->options([
                        'gds' => 'GDS',
                        'ndc' => 'NDC',
                        'other' => 'أخرى',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('نشط')
                    ->placeholder('الكل')
                    ->trueLabel('نشط فقط')
                    ->falseLabel('غير نشط فقط'),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),

                // 🔒 زر الشحن — الطريق الوحيد لزيادة رصيد نظام الحجز.
                // يستدعي FlightSystemRechargeService::rechargeFromAccount() الذي يحدّث:
                //   1) flight_systems.balance (عن طريق debit()/credit() الآمن)
                //   2) account "رصيد مسبق — أنظمة حجز الطيران" (Prepaid GL Account) — في نفس DB transaction
                Tables\Actions\Action::make('rechargeBalance')
                    ->label('إعادة شحن')
                    ->icon('heroicon-o-arrow-trending-up')
                    ->color('success')
                    ->visible(fn (FlightSystem $record): bool => (bool) $record->is_active)
                    ->modalHeading(fn (FlightSystem $record): string => 'شحن رصيد نظام: '.$record->name.' ('.$record->code.')')
                    ->modalDescription(fn (FlightSystem $record): string => 'العملة: '.$record->currency.
                        ' — الرصيد الحالي: '.number_format((float) $record->balance, 2).' '.$record->currency.
                        ' — يُخصم من حساب تحصيل بنفس العملة. الطريق الوحيد لتعديل الرصيد.'
                    )
                    ->form([
                        Forms\Components\Select::make('from_account_id')
                            ->label('من حساب (محفظة / بنك / خزينة)')
                            ->options(function (FlightSystem $record) {
                                return self::accountOptionsForSystem($record);
                            })
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->helperText('فقط الحسابات النشطة بعملة النظام.'),
                        Forms\Components\TextInput::make('amount')
                            ->label('المبلغ')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->step(0.01),
                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات (اختياري)')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (FlightSystem $record, array $data): void {
                        $account = Account::query()->findOrFail((int) $data['from_account_id']);
                        $amount = (float) $data['amount'];
                        $notes = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;

                        try {
                            app(FlightSystemRechargeService::class)->rechargeFromAccount(
                                $record,
                                $account,
                                $amount,
                                $notes,
                            );
                            Notification::make()
                                ->title('تم شحن رصيد النظام بنجاح')
                                ->body('الرصيد الجديد: '.number_format($record->fresh()->balance, 2).' '.$record->currency)
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

                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name', 'asc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlightSystems::route('/'),
            'create' => Pages\CreateFlightSystem::route('/create'),
            'edit' => Pages\EditFlightSystem::route('/{record}/edit'),
        ];
    }

    /**
     * خيارات الحسابات المتاحة لشحن رصيد نظام الحجز (نفس العملة فقط).
     *
     * @return array<int, string>
     */
    protected static function accountOptionsForSystem(FlightSystem $system): array
    {
        $types = [AccountType::Cashbox->value, AccountType::Wallet->value, AccountType::Bank->value];

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
            AccountType::Bank->value => 'خزينة عامة',
            AccountType::Bank->value => 'بنك',
            default => $typeVal,
        };

        return "{$a->name} — {$typeLabel} — {$bal} {$cur}";
    }
}
