<?php

namespace App\Filament\Resources\Employee;

use App\Filament\Resources\Employee\EmployeeResource\Pages;
use App\Models\Employee;
use BackedEnum;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Illuminate\Database\Eloquent\Builder;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationLabel = 'الموظفون';

    protected static ?int $navigationSort = 1;

    protected static string|\UnitEnum|null $navigationGroup = 'الموظفين';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('المعلومات الشخصية')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('first_name', 'الاسم الأول')
                                    ->required()
                                    ->maxLength(100),
                                TextInput::make('last_name', 'اسم العائلة')
                                    ->required()
                                    ->maxLength(100),
                                TextInput::make('national_id', 'رقم الهوية')
                                    ->unique()
                                    ->maxLength(20),
                                TextInput::make('phone', 'رقم الهاتف')
                                    ->tel()
                                    ->maxLength(20),
                            ]),
                    ])
                    ->columns(2),

                Section::make('معلومات التوظيف')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('position', 'المنصب')
                                    ->required()
                                    ->maxLength(100),
                                TextInput::make('department', 'القسم')
                                    ->maxLength(100),
                                Select::make('employment_type', 'نوع التوظيف')
                                    ->options([
                                        'full_time' => 'دوام كامل',
                                        'part_time' => 'دوام جزئي',
                                        'contract' => 'عقد',
                                        'temporary' => 'مؤقت',
                                    ])
                                    ->default('full_time')
                                    ->required(),
                                DatePicker::make('hire_date', 'تاريخ التعيين')
                                    ->required()
                                    ->displayFormat('d/m/Y'),
                            ]),
                    ])
                    ->columns(2),

                Section::make('المعلومات المالية')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('salary', 'الراتب')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->required()
                                    ->step(0.01),
                                TextInput::make('bank_account_number', 'رقم الحساب البنكي')
                                    ->maxLength(50),
                                TextInput::make('bank_name', 'اسم البنك')
                                    ->maxLength(100),
                            ]),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('user.name', 'الموظف')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('position', 'المنصب')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('department', 'القسم')
                    ->searchable()
                    ->sortable(),

                BadgeColumn::make('employment_type', 'نوع التوظيف')
                    ->colors([
                        'full_time' => 'success',
                        'part_time' => 'warning',
                        'contract' => 'primary',
                        'temporary' => 'gray',
                    ])
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        return match($state) {
                            'full_time' => 'دوام كامل',
                            'part_time' => 'دوام جزئي',
                            'contract' => 'عقد',
                            'temporary' => 'مؤقت',
                            default => $state,
                        };
                    }),

                BadgeColumn::make('status', 'الحالة')
                    ->colors([
                        'active' => 'success',
                        'inactive' => 'danger',
                    ])
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        return match($state) {
                            'active' => 'نشط',
                            'inactive' => 'غير نشط',
                            default => $state,
                        };
                    }),

                TextColumn::make('salary', 'الراتب')
                    ->money('jod')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                // Tables\Actions\EditAction::make(),
                // Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            'user',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/index'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
            'view' => Pages\ViewEmployee::route('/{record}'),
        ];
    }
}
