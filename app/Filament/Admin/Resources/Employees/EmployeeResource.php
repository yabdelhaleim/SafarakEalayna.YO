<?php

namespace App\Filament\Admin\Resources\Employees;

use App\Filament\Admin\Resources\Employees\Pages\ManageEmployees;
use App\Models\Employee;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Tabs::make('EmployeeTabs')
                    ->tabs([
                        \Filament\Forms\Components\Tabs\Tab::make('personal')
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
                        
                        \Filament\Forms\Components\Tabs\Tab::make('employment')
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
                                TextInput::make('position')->label('المنصب'),
                                TextInput::make('department')->label('القسم'),
                                TextInput::make('job_title')->label('المسمى الوظيفي'),
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

                        \Filament\Forms\Components\Tabs\Tab::make('contact')
                            ->label('الاتصال والبنك')
                            ->icon('heroicon-o-phone')
                            ->schema([
                                TextInput::make('phone')->label('رقم الهاتف')->tel(),
                                TextInput::make('email')->label('البريد الإلكتروني')->email(),
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
                TextEntry::make('user.name')
                    ->label('User')
                    ->placeholder('-'),
                TextEntry::make('salary')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('status'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('first_name')
                    ->placeholder('-'),
                TextEntry::make('last_name')
                    ->placeholder('-'),
                TextEntry::make('full_name')
                    ->placeholder('-'),
                TextEntry::make('national_id')
                    ->placeholder('-'),
                TextEntry::make('nationality')
                    ->placeholder('-'),
                TextEntry::make('date_of_birth')
                    ->date()
                    ->placeholder('-'),
                TextEntry::make('gender')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('phone')
                    ->placeholder('-'),
                TextEntry::make('address')
                    ->placeholder('-'),
                TextEntry::make('city')
                    ->placeholder('-'),
                TextEntry::make('country')
                    ->placeholder('-'),
                TextEntry::make('hire_date')
                    ->date()
                    ->placeholder('-'),
                TextEntry::make('termination_date')
                    ->date()
                    ->placeholder('-'),
                TextEntry::make('position')
                    ->placeholder('-'),
                TextEntry::make('department')
                    ->placeholder('-'),
                TextEntry::make('job_title')
                    ->placeholder('-'),
                TextEntry::make('employment_type')
                    ->badge(),
                TextEntry::make('employment_status')
                    ->badge(),
                TextEntry::make('bank_account_number')
                    ->placeholder('-'),
                TextEntry::make('bank_name')
                    ->placeholder('-'),
                TextEntry::make('iban')
                    ->placeholder('-'),
                TextEntry::make('emergency_contact_name')
                    ->placeholder('-'),
                TextEntry::make('emergency_contact_phone')
                    ->placeholder('-'),
                TextEntry::make('performance_rating')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('contract_path')
                    ->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('اسم الموظف')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('job_title')
                    ->label('المسمى الوظيفي')
                    ->searchable(),
                TextColumn::make('department')
                    ->label('القسم')
                    ->badge(),
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
