<?php

namespace App\Filament\Admin\Resources\EmployeeAttendances;

use App\Filament\Admin\Resources\EmployeeAttendances\Pages\ManageEmployeeAttendances;
use App\Models\EmployeeAttendance;
use App\Models\Employee;
use App\Enums\AttendanceStatus;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Support\Icons\Heroicon;

class EmployeeAttendanceResource extends Resource
{
    protected static ?string $model = EmployeeAttendance::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'شؤون الموظفين';

    protected static ?string $navigationLabel = 'الحضور والانصراف';
    protected static ?string $pluralLabel = 'سجلات الحضور';
    protected static ?string $modelLabel = 'سجل حضور';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('الموظف')
                    ->relationship('employee', 'full_name')
                    ->searchable()
                    ->required(),
                DatePicker::make('attendance_date')
                    ->label('التاريخ')
                    ->default(now())
                    ->required(),
                Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'present' => 'حاضر',
                        'absent' => 'غائب',
                        'late' => 'متأخر',
                        'excused' => 'مستأذن / إجازة',
                    ])
                    ->required(),
                Textarea::make('notes')
                    ->label('ملاحظات')
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
                TextColumn::make('attendance_date')
                    ->label('التاريخ')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'present' => 'success',
                        'absent' => 'danger',
                        'late' => 'warning',
                        'excused' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match($state) {
                        'present' => 'حاضر',
                        'absent' => 'غائب',
                        'late' => 'متأخر',
                        'excused' => 'مستأذن / إجازة',
                        default => $state,
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'present' => 'حاضر',
                        'absent' => 'غائب',
                        'late' => 'متأخر',
                        'excused' => 'مستأذن / إجازة',
                    ]),
                \Filament\Tables\Filters\Filter::make('attendance_date')
                    ->label('التاريخ')
                    ->form([
                        DatePicker::make('from')->label('من'),
                        DatePicker::make('to')->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q) => $q->whereDate('attendance_date', '>=', $data['from']))
                            ->when($data['to'], fn ($q) => $q->whereDate('attendance_date', '<=', $data['to']));
                    })
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEmployeeAttendances::route('/'),
        ];
    }
}
