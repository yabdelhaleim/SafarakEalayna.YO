<?php

namespace App\Filament\Admin\Resources\FawryTransactions;

use App\Filament\Admin\Concerns\BelongsToFawryModuleNavigation;
use App\Filament\Admin\Support\FawryModuleNavigation;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Fawry\FawryTransaction;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Notifications\Notification;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FawryTransactionResource extends Resource
{
    use BelongsToFawryModuleNavigation;

    protected static ?string $model = FawryTransaction::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = FawryModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'المعاملات';

    protected static ?string $pluralLabel = 'معاملات فوري';

    protected static ?string $modelLabel = 'معاملة فوري';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'client_name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('client_id')
                    ->label('العميل')
                    ->relationship('client', 'full_name')
                    ->searchable()
                    ->preload(),

                TextInput::make('client_name')
                    ->label('اسم العميل')
                    ->required()
                    ->maxLength(255),

                Select::make('operation_type')
                    ->label('نوع العملية')
                    ->options(fn (): array => FawryOperationType::query()->orderBy('order')->pluck('name_ar', 'code')->all())
                    ->searchable()
                    ->required(),

                Select::make('currency_id')
                    ->label('العملة')
                    ->relationship('currency', 'name_ar')
                    ->searchable()
                    ->preload(),

                TextInput::make('client_amount')
                    ->label('مبلغ العميل')
                    ->numeric()
                    ->prefix('ج.م')
                    ->step(0.01),

                TextInput::make('fawry_price')
                    ->label('سعر فوري')
                    ->numeric()
                    ->prefix('ج.م')
                    ->step(0.01),

                TextInput::make('selling_price')
                    ->label('سعر البيع')
                    ->numeric()
                    ->prefix('ج.م')
                    ->step(0.01),

                TextInput::make('profit')
                    ->label('الربح')
                    ->numeric()
                    ->prefix('ج.م')
                    ->step(0.01)
                    ->disabled(),

                Select::make('employee_id')
                    ->label('الموظف المسؤول')
                    ->relationship('employee', 'name')
                    ->searchable()
                    ->required(),

                Select::make('account_id')
                    ->label('حساب التسوية / الخزينة')
                    ->relationship('account', 'name', fn ($query) => $query->whereIn('module_type', ['fawry', 'office'])->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options(fn (): array => FawryPaymentMethod::query()->orderBy('order')->pluck('name_ar', 'code')->all())
                    ->searchable()
                    ->required(),

                TextInput::make('amount')
                    ->label('المبلغ المدفوع')
                    ->numeric()
                    ->prefix('ج.م')
                    ->step(0.01),

                TextInput::make('reference_number')
                    ->label('رقم المرجع')
                    ->maxLength(255),

                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->rows(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        $paymentOptions = FawryPaymentMethod::query()->orderBy('order')->pluck('name_ar', 'code')->all();

        return $table
            ->recordTitleAttribute('client_name')
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('client_name', 'اسم العميل')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('operation_type', 'نوع العملية')
                    ->badge(),

                TextColumn::make('client_amount', 'مبلغ العميل')
                    ->money('egp')
                    ->sortable(),

                TextColumn::make('selling_price', 'سعر البيع')
                    ->money('egp')
                    ->sortable(),

                TextColumn::make('profit', 'الربح')
                    ->money('egp')
                    ->sortable()
                    ->color('success'),

                TextColumn::make('employee.name', 'الموظف')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('payment_method', 'طريقة الدفع')
                    ->badge(),

                TextColumn::make('created_at', 'التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('payment_method', 'طريقة الدفع')
                    ->options($paymentOptions),

                SelectFilter::make('employee_id', 'الموظف')
                    ->relationship('employee', 'name')
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()
                    ->successNotificationTitle('تم تحديث المعاملة')
                    ->successNotification(function (Notification $notification, FawryTransaction $record): Notification {
                        return $notification
                            ->persistent()
                            ->body(static::apiEnvelopePreviewBody($record, 'Fawry transaction updated successfully.'));
                    }),
                DeleteAction::make()
                    ->using(function (Model $record): bool {
                        if (! $record instanceof FawryTransaction) {
                            throw new \InvalidArgumentException('Expected FawryTransaction.');
                        }
                        return app(\App\Services\Fawry\FawryTransactionService::class)->deleteTransaction($record);
                    })
                    ->successNotificationTitle('تم حذف المعاملة')
                    ->successNotification(function (Notification $notification, FawryTransaction $record): Notification {
                        $body = json_encode([
                            'status' => true,
                            'message' => 'Fawry transaction deleted successfully.',
                            'data' => null,
                            'errors' => null,
                            'deleted_id' => $record->getKey(),
                        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

                        return $notification->persistent()->body($body);
                    }),
            ])
            ->toolbarActions([
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageFawryTransactions::route('/'),
        ];
    }

    /**
     * معاينة بصياغة قريبة من استجابة REST لعرضها في إشعار Filament بعد إنشاء/تعديل سجل.
     */
    public static function apiEnvelopePreviewBody(FawryTransaction $record, string $message): string
    {
        $record->refresh();
        $record->loadMissing(['employee:id,name', 'account:id,name', 'currency:id,name_ar,code']);

        $envelope = [
            'status' => true,
            'message' => $message,
            'data' => array_merge(
                $record->attributesToArray(),
                [
                    'employee' => $record->employee ? ['id' => $record->employee->id, 'name' => $record->employee->name] : null,
                    'account' => $record->account ? ['id' => $record->account->id, 'name' => $record->account->name] : null,
                    'currency' => $record->currency ? [
                        'id' => $record->currency->id,
                        'name_ar' => $record->currency->name_ar,
                        'code' => $record->currency->code ?? null,
                    ] : null,
                ]
            ),
            'errors' => null,
        ];

        return json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
