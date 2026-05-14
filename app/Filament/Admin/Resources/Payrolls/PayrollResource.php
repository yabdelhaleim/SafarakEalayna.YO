<?php

namespace App\Filament\Admin\Resources\Payrolls;

use App\Filament\Admin\Resources\Payrolls\Pages\ManagePayrolls;
use App\Models\Payroll;
use App\Models\Employee;
use App\Enums\TransactionType;
use App\Enums\TransactionModule;
use App\Services\Finance\AccountingService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class PayrollResource extends Resource
{
    protected static ?string $model = Payroll::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'شؤون الموظفين';

    protected static ?string $navigationLabel = 'الرواتب';
    protected static ?string $pluralLabel = 'الرواتب';
    protected static ?string $modelLabel = 'راتب';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('الموظف')
                    ->relationship('employee', 'full_name')
                    ->searchable()
                    ->required(),
                TextInput::make('month')->label('الشهر')->numeric()->required(),
                TextInput::make('year')->label('السنة')->numeric()->required(),
                TextInput::make('base_salary')->label('الراتب الأساسي')->numeric()->prefix('ج.م')->required(),
                TextInput::make('total_bonuses')->label('إجمالي الحوافز')->numeric()->prefix('ج.م')->default(0),
                TextInput::make('total_deductions')->label('إجمالي الاستقطاعات')->numeric()->prefix('ج.م')->default(0),
                TextInput::make('net_salary')->label('صافي الراتب')->numeric()->prefix('ج.م')->required(),
                Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'draft' => 'مسودة',
                        'paid' => 'تم الصرف',
                        'cancelled' => 'ملغي',
                    ])->default('draft'),
                Textarea::make('notes')->label('ملاحظات')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('الموظف')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('month')
                    ->label('الشهر')
                    ->formatStateUsing(fn ($state, $record) => $state . ' / ' . $record->year),
                TextColumn::make('base_salary')->label('الأساسي')->money('egp'),
                TextColumn::make('net_salary')->label('الصافي')->money('egp'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'warning',
                        'paid' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match($state) {
                        'draft' => 'مسودة',
                        'paid' => 'تم الصرف',
                        'cancelled' => 'ملغي',
                        default => $state,
                    }),
            ])
            ->filters([
                SelectFilter::make('month')->label('الشهر')->options(array_combine(range(1,12), range(1,12))),
                SelectFilter::make('year')->label('السنة')->options([2024 => '2024', 2025 => '2025', 2026 => '2026']),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\Action::make('pay')
                    ->label('صرف الراتب')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'draft')
                    ->form([
                        \Filament\Forms\Components\Select::make('account_id')
                            ->label('صرف من حساب')
                            ->options(\App\Models\Account::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        DB::beginTransaction();
                        try {
                            $transaction = app(\App\Services\Finance\AccountingService::class)->recordExpense([
                                'amount' => $record->net_salary,
                                'from_account_id' => $data['account_id'],
                                'module' => TransactionModule::General->value,
                                'notes' => "صرف راتب الموظف: {$record->employee->full_name} - شهر {$record->month}/{$record->year}",
                            ]);

                            $record->update([
                                'status' => 'paid',
                                'paid_at' => now(),
                                'transaction_id' => $transaction->id,
                            ]);

                            DB::commit();
                        } catch (\Exception $e) {
                            DB::rollBack();
                            throw $e;
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePayrolls::route('/'),
        ];
    }
}
