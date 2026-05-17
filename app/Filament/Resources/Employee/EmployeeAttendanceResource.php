<?php

namespace App\Filament\Resources\Employee;

use App\Filament\Resources\Employee\EmployeeAttendanceResource\Pages;
use App\Models\EmployeeAttendance;
use BackedEnum;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

class EmployeeAttendanceResource extends Resource
{
    protected static ?string $model = EmployeeAttendance::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationLabel = 'الحضور والغياب';

    protected static ?int $navigationSort = 2;

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

                DatePicker::make('attendance_date', 'تاريخ الحضور')
                    ->required()
                    ->displayFormat('d/m/Y')
                    ->default(now()),

                Select::make('status', 'الحالة')
                    ->options([
                        'present' => 'حاضر',
                        'absent' => 'غائب',
                        'late' => 'تأخير',
                    ])
                    ->default('present')
                    ->required()
                    ->live(),

                Textarea::make('notes', 'ملاحظات')
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

                TextColumn::make('attendance_date', 'التاريخ')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status', 'الحالة')
                    ->badge()
                    ->color(fn ($state) => match($state instanceof \BackedEnum ? $state->value : $state) {
                        'present' => 'success',
                        'absent' => 'danger',
                        'late' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(function ($state): string {
                        $val = $state instanceof \BackedEnum ? $state->value : $state;
                        return match($val) {
                            'present' => 'حاضر',
                            'absent' => 'غائب',
                            'late' => 'تأخير',
                            default => (string) $val,
                        };
                    }),

                TextColumn::make('notes', 'ملاحظات')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at', 'تاريخ التسجيل')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status', 'الحالة')
                    ->options([
                        'present' => 'حاضر',
                        'absent' => 'غائب',
                        'late' => 'تأخير',
                    ]),
            ])
            ->defaultSort('attendance_date', 'desc')
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['employee.user', 'createdBy']);
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
            'index' => Pages\ListEmployeeAttendances::route('/index'),
            'create' => Pages\CreateEmployeeAttendance::route('/create'),
            'edit' => Pages\EditEmployeeAttendance::route('/{record}/edit'),
        ];
    }
}
