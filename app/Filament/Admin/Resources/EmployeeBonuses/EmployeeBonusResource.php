<?php

namespace App\Filament\Admin\Resources\EmployeeBonuses;

use App\Models\Employee\EmployeeBonus;
use App\Filament\Admin\Resources\EmployeeBonuses\Pages\ManageEmployeeBonuses;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeeBonusResource extends Resource
{
    protected static ?string $model = EmployeeBonus::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'المكافآت والخصومات';

    protected static ?string $pluralLabel = 'المكافآت والخصومات';

    protected static ?string $modelLabel = 'مكافأة/خصم';

    protected static string|\UnitEnum|null $navigationGroup = 'الموظفين';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('الموظف')
                    ->relationship('employee', 'full_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) =>
                        $record->full_name
                        ?? trim(($record->first_name ?? '') . ' ' . ($record->last_name ?? ''))
                        ?: '-'
                    )
                    ->searchable()
                    ->required(),

                Select::make('type')
                    ->label('النوع')
                    ->options([
                        'bonus' => 'مكافأة',
                        'deduction' => 'خصم',
                    ])
                    ->default('bonus')
                    ->required(),

                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->prefix('ج.م')
                    ->required(),

                Textarea::make('reason')
                    ->label('السبب')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('employee_id')
                    ->label('كود الموظف')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match(is_object($state) ? $state->value : (string)$state) {
                        'bonus' => 'مكافأة',
                        'deduction' => 'خصم',
                        default => (string)(is_object($state) ? $state->value : $state),
                    })
                    ->color(fn ($state): string => match(is_object($state) ? $state->value : (string)$state) {
                        'bonus' => 'success',
                        'deduction' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('egp')
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('السبب')
                    ->limit(40),

                TextColumn::make('created_at')
                    ->label('تاريخ التسجيل')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make(),
                \Filament\Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEmployeeBonuses::route('/'),
        ];
    }
}
