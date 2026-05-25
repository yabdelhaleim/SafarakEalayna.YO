<?php

namespace App\Filament\Admin\Resources\Employees;

use App\Filament\Admin\Resources\Employees\Pages\ManageEmployees;
use App\Models\Employee;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static string|\UnitEnum|null $navigationGroup = 'الموظفين';

    protected static ?string $navigationLabel = 'الموظفين';

    protected static ?string $pluralLabel = 'الموظفين';

    protected static ?string $modelLabel = 'موظف';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('EmployeeTabs')
                    ->tabs([
                        Tab::make('personal')
                            ->label('البيانات الشخصية')
                            ->icon('heroicon-o-user')
                            ->schema([
                                TextInput::make('first_name')->label('الاسم الأول')->required(),
                                TextInput::make('last_name')->label('اللقب / العائلة')->required(),
                                TextInput::make('full_name')->label('الاسم الكامل')->helperText('سيتم استخدامه في التقارير'),
                                TextInput::make('national_id')->label('رقم البطاقة / الهوية')->required(),
                                TextInput::make('nationality')->label('الجنسية')->default('مصري'),
                                DatePicker::make('date_of_birth')->label('تاريخ الميلاد'),
                                Select::make('gender')
                                    ->label('الجنس')
                                    ->options(['male' => 'ذكر', 'female' => 'أنثى']),
                            ])->columns(2),
                        
                        Tab::make('employment')
                            ->label('بيانات الوظيفة')
                            ->icon('heroicon-o-briefcase')
                            ->schema([
                                Select::make('user_id')
                                    ->label('حساب النظام المرتبط')
                                    ->relationship('user', 'name')
                                    ->searchable(),
                                TextInput::make('salary')
                                    ->label('الراتب الأساسي')
                                    ->numeric()
                                    ->prefix('ج.م'),

                                DatePicker::make('hire_date')->label('تاريخ التعيين')->default(now()),
                                Select::make('employment_type')
                                    ->label('نوع التوظيف')
                                    ->options([
                                        'full_time' => 'دوام كامل',
                                        'part_time' => 'دوام جزئي',
                                        'contract' => 'عقد',
                                        'temporary' => 'مؤقت',
                                    ])->default('full_time'),
                                Select::make('employment_status')
                                    ->label('حالة الموظف')
                                    ->options([
                                        'active' => 'نشط',
                                        'on_leave' => 'في إجازة',
                                        'terminated' => 'مستقيل / مفصول',
                                    ])->default('active'),
                            ])->columns(2),

                        Tab::make('contact')
                            ->label('الاتصال والبنك')
                            ->icon('heroicon-o-phone')
                            ->schema([
                                TextInput::make('phone')->label('رقم الهاتف')->tel(),
                                TextInput::make('address')->label('العنوان'),
                                TextInput::make('city')->label('المدينة'),
                                TextInput::make('bank_name')->label('اسم البنك'),
                                TextInput::make('bank_account_number')->label('رقم الحساب'),
                                TextInput::make('iban')->label('IBAN'),
                                TextInput::make('emergency_contact_name')->label('جهة اتصال للطوارئ'),
                                TextInput::make('emergency_contact_phone')->label('هاتف الطوارئ')->tel(),
                            ])->columns(2),
                    ])->columnSpanFull()
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('البيانات الأساسية')
                    ->icon('heroicon-o-user')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('display_name')
                            ->label('اسم الموظف')
                            ->columnSpanFull()
                            ->getStateUsing(function ($record) {
                                if ($record->full_name) return $record->full_name;
                                $name = trim(($record->first_name ?? '') . ' ' . ($record->last_name ?? ''));
                                if ($name) return $name;
                                return $record->user?->name ?? 'غير محدد';
                            }),
                        TextEntry::make('salary')
                            ->label('الراتب الأساسي')
                            ->money('EGP')
                            ->placeholder('-'),
                        TextEntry::make('employment_type')
                            ->label('نوع التوظيف')
                            ->badge()
                            ->formatStateUsing(fn ($state) => match($state) {
                                'full_time' => 'دوام كامل',
                                'part_time' => 'دوام جزئي',
                                'contract' => 'عقد',
                                'temporary' => 'مؤقت',
                                default => $state ?? '-',
                            })
                            ->color(fn ($state) => match($state) {
                                'full_time' => 'success',
                                'part_time' => 'warning',
                                'contract' => 'info',
                                'temporary' => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('employment_status')
                            ->label('حالة الموظف')
                            ->badge()
                            ->formatStateUsing(fn ($state) => match($state) {
                                'active' => 'نشط',
                                'on_leave' => 'في إجازة',
                                'terminated' => 'مستقيل / مفصول',
                                default => $state ?? '-',
                            })
                            ->color(fn ($state) => match($state) {
                                'active' => 'success',
                                'on_leave' => 'warning',
                                'terminated' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('hire_date')
                            ->label('تاريخ التعيين')
                            ->date('d/m/Y')
                            ->placeholder('غير محدد'),
                        TextEntry::make('created_at')
                            ->label('تاريخ الإضافة')
                            ->dateTime('d/m/Y H:i'),
                    ]),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label('اسم الموظف')
                    ->searchable(['full_name', 'first_name', 'last_name'])
                    ->sortable()
                    ->getStateUsing(function ($record) {
                        if ($record->full_name) return $record->full_name;
                        $name = trim(($record->first_name ?? '') . ' ' . ($record->last_name ?? ''));
                        if ($name) return $name;
                        return $record->user?->name ?? '-';
                    }),
                TextColumn::make('salary')
                    ->label('الراتب')
                    ->money('egp')
                    ->sortable(),
                TextColumn::make('employment_status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'on_leave' => 'warning',
                        'terminated' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match($state) {
                        'active' => 'نشط',
                        'on_leave' => 'في إجازة',
                        'terminated' => 'مستقيل / مفصول',
                        default => $state,
                    }),
                TextColumn::make('phone')
                    ->label('الهاتف')
                    ->searchable(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('employment_status')
                    ->label('حالة الموظف')
                    ->options([
                        'active' => 'نشط',
                        'on_leave' => 'في إجازة',
                        'terminated' => 'مستقيل / مفصول',
                    ]),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEmployees::route('/'),
        ];
    }
}
