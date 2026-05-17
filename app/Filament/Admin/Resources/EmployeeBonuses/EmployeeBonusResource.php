<?php

namespace App\Filament\Admin\Resources\EmployeeBonuses;

use App\Models\Employee\EmployeeBonus;
use App\Filament\Admin\Resources\EmployeeBonuses\Pages\ManageEmployeeBonuses;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
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

                DatePicker::make('date')
                    ->label('التاريخ')
                    ->required()
                    ->default(now()),

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
                TextColumn::make('employee.full_name')
                    ->label('الموظف')
                    ->searchable()
                    ->sortable(),

                BadgeColumn::make('type')
                    ->label('النوع')
                    ->colors([
                        'bonus' => 'success',
                        'deduction' => 'danger',
                    ])
                    ->formatStateUsing(fn ($state): string => match($state instanceof \BackedEnum ? $state->value : (string) $state) {
                        'bonus' => 'مكافأة',
                        'deduction' => 'خصم',
                        default => $state instanceof \BackedEnum ? $state->value : (string) $state,
                    }),

                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('egp')
                    ->sortable(),

                TextColumn::make('date')
                    ->label('التاريخ')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make(),
                \Filament\Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEmployeeBonuses::route('/'),
        ];
    }
}
