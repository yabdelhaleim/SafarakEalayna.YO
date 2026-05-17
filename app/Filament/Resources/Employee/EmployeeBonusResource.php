<?php

namespace App\Filament\Resources\Employee;

use App\Filament\Resources\Employee\EmployeeBonusResource\Pages;
use App\Models\Employee\EmployeeBonus;
use App\Enums\BonusType;
use BackedEnum;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\TextInput;
use Filament\Components\Forms\MoneyInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

class EmployeeBonusResource extends Resource
{
    protected static ?string $model = EmployeeBonus::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'المكافآت والخصومات';

    protected static ?int $navigationSort = 3;

    protected static string|\UnitEnum|null $navigationGroup = 'الموظفين';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('employee_id', 'الموظف')
                    ->relationship('employee', 'user.name')
                    ->searchable()
                    ->required()
                    ->createOptionFormUsing(fn (Form $form) => $form->schema([
                        TextInput::make('name', 'الاسم')
                            ->required(),
                    ])),

                Select::make('type', 'النوع')
                    ->options([
                        'bonus' => 'مكافأة',
                        'deduction' => 'خصم',
                    ])
                    ->default('bonus')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if ($state === 'deduction') {
                            $set('amount_color', 'danger');
                        } else {
                            $set('amount_color', 'success');
                        }
                    }),

                TextInput::make('amount', 'المبلغ')
                    ->numeric()
                    ->prefix('ج.م')
                    ->required()
                    ->step(0.01)
                    ->minValue(0.01),

                DatePicker::make('date', 'التاريخ')
                    ->required()
                    ->displayFormat('d/m/Y')
                    ->default(now()),

                Textarea::make('reason', 'السبب')
                    ->required()
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('employee.user.name', 'الموظف')
                    ->searchable()
                    ->sortable(),

                BadgeColumn::make('type', 'النوع')
                    ->colors([
                        'bonus' => 'success',
                        'deduction' => 'danger',
                    ])
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        return match($state) {
                            'bonus' => 'مكافأة',
                            'deduction' => 'خصم',
                            default => $state,
                        };
                    }),

                TextColumn::make('amount', 'المبلغ')
                    ->money('jod')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('reason', 'السبب')
                    ->limit(50)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('date', 'التاريخ')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('created_at', 'تاريخ التسجيل')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type', 'النوع')
                    ->options([
                        'bonus' => 'مكافآت',
                        'deduction' => 'خصومات',
                    ]),
            ])
            ->defaultSort('date', 'desc')
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            'employee.user',
            'createdBy',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeBonuses::route('/index'),
            'create' => Pages\CreateEmployeeBonus::route('/create'),
            'edit' => Pages\EditEmployeeBonus::route('/{record}/edit'),
        ];
    }
}
