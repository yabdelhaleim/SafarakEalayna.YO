<?php

namespace App\Filament\Admin\Resources\FlightTreasuries\Tables;

use App\Enums\AccountType;
use App\Filament\Support\AccountTableFilters;
use App\Models\Account;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;


class FlightTreasuriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),



                TextColumn::make('balance')
                    ->label('الرصيد')
                    ->money(fn (Account $record): string => strtolower($record->currency ?? 'egp'))
                    ->sortable()
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),

                TextColumn::make('currency')
                    ->label('العملة')
                    ->badge()
                    ->color('info'),

                IconColumn::make('is_module_vault')
                    ->label('خزنة الموديول الرسمية')
                    ->boolean()
                    ->toggleable(),

                BadgeColumn::make('is_active')
                    ->label('الحالة')
                    ->colors([
                        true => 'success',
                        false => 'danger',
                    ])
                    ->formatStateUsing(fn ($state) => $state ? 'نشط' : 'غير نشط'),
            ])
            ->filters(AccountTableFilters::defaults())
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modal(false)
                    ->label('حذف')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->action(function (Account $record, DeleteAction $action) {
                        try {
                            $record->delete();
                            Notification::make()->title('تم حذف الحساب بنجاح')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('تعذّر الحذف')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('vaultTransfer')
                    ->label('تحويل عُهدة')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Account $record) => "تحويل من: {$record->name}")
                    ->modalDescription('نقل سيولة من هذه الخزينة إلى خزانة أو درج آخر.')
                    ->form([
                        TextInput::make('amount')
                            ->label('المبلغ')
                            ->numeric()
                            ->required()
                            ->minValue(0.01),
                        Select::make('to_account_id')
                            ->label('تحويل إلى (حساب)')
                            ->options(function (Account $record) {
                                return Account::query()
                                    ->where('id', '!=', $record->id)
                                    ->where('is_active', true)
                                    ->where('currency', $record->currency)
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->required(),
                        Textarea::make('notes')
                            ->label('ملاحظات التحويل')
                            ->placeholder('مثال: تحويل أرباح أو سيولة الطيران')
                            ->rows(2),
                    ])
                    ->action(function (Account $record, array $data): void {
                        try {
                            $toAccountId = (int) $data['to_account_id'];
                            $amount = (float) $data['amount'];
                            $notes = $data['notes'] ?? '';

                            $toAccount = Account::findOrFail($toAccountId);

                            app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
                                'amount' => $amount,
                                'from_account_id' => $record->id,
                                'to_account_id' => $toAccountId,
                                'module' => \App\Enums\TransactionModule::Flight->value,
                                'notes' => "تحويل عُهدة من خزينة الطيران [{$record->name}] إلى [{$toAccount->name}]: " . $notes,
                                'created_by' => auth()->id(),
                                'allow_from_negative' => false,
                            ]);

                            Notification::make()
                                ->title('تم التحويل بنجاح')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('فشل التحويل')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
