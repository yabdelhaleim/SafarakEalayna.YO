<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static string|\UnitEnum|null $navigationGroup = 'الحجوزات والعملاء';
    protected static ?string $modelLabel = 'عميل';
    protected static ?string $pluralModelLabel = 'العملاء';
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Section::make('معلومات العميل الشخصية')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('الاسم الكامل')
                        ->required()
                        ->placeholder('محمد أحمد علي'),

                    Forms\Components\TextInput::make('email')
                        ->label('البريد الإلكتروني')
                        ->email()
                        ->unique(ignoreRecord: true)
                        ->required(),

                    Forms\Components\TextInput::make('phone')
                        ->label('رقم الهاتف')
                        ->tel()
                        ->required(),

                    Forms\Components\TextInput::make('national_id')
                        ->label('الرقم القومي')
                        ->length(14)
                        ->unique(ignoreRecord: true),

                    Forms\Components\Select::make('gender')
                        ->label('الجنس')
                        ->options([
                            'male'   => 'ذكر',
                            'female' => 'أنثى',
                        ]),

                    Forms\Components\DatePicker::make('date_of_birth')
                        ->label('تاريخ الميلاد')
                        ->native(false),
                ]),

            \Filament\Schemas\Components\Section::make('معلومات جواز السفر والإقامة')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('passport_number')
                        ->label('رقم جواز السفر'),

                    Forms\Components\DatePicker::make('passport_expiry')
                        ->label('تاريخ انتهاء جواز السفر')
                        ->native(false),

                    Forms\Components\Select::make('nationality')
                        ->label('الجنسية')
                        ->options([
                            'EG'    => 'مصر',
                            'SA'    => 'السعودية',
                            'AE'    => 'الإمارات',
                            'KW'    => 'الكويت',
                            'QA'    => 'قطر',
                            'BH'    => 'البحرين',
                            'OM'    => 'عُمان',
                            'JO'    => 'الأردن',
                            'OTHER' => 'أخرى',
                        ])
                        ->default('EG')
                        ->required(),

                    Forms\Components\Textarea::make('address')
                        ->label('العنوان')
                        ->columnSpanFull(),
                ]),

            \Filament\Schemas\Components\Section::make('الحالة المالية والعضوية')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('حالة الحساب')
                        ->options([
                            'active'  => 'نشط',
                            'blocked' => 'محظور',
                            'vip'     => 'عميل VIP ⭐️',
                        ])
                        ->default('active')
                        ->required(),

                    Forms\Components\TextInput::make('total_spent')
                        ->label('إجمالي المصروفات (ج.م)')
                        ->numeric()
                        ->prefix('ج.م')
                        ->default(0),

                    Forms\Components\TextInput::make('bookings_count')
                        ->label('عدد الحجوزات')
                        ->numeric()
                        ->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->weight('bold')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('رقم الهاتف')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->searchable(),

                Tables\Columns\TextColumn::make('nationality')
                    ->label('الجنسية')
                    ->formatStateUsing(fn ($state) => match($state) {
                        'EG' => 'مصر 🇪🇬',
                        'SA' => 'السعودية 🇸🇦',
                        'AE' => 'الإمارات 🇦🇪',
                        'KW' => 'الكويت 🇰🇼',
                        'QA' => 'قطر 🇶🇦',
                        'BH' => 'البحرين 🇧🇭',
                        'OM' => 'عُمان 🇴🇲',
                        'JO' => 'الأردن 🇯🇴',
                        'OTHER' => 'أخرى 🌐',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'active' => 'نشط',
                        'blocked' => 'محظور',
                        'vip' => 'VIP ⭐️',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'active' => 'success',
                        'blocked' => 'danger',
                        'vip' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total_spent')
                    ->label('إجمالي الإنفاق')
                    ->money('EGP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('bookings_count')
                    ->label('الحجوزات')
                    ->sortable()
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'active' => 'نشط',
                        'blocked' => 'محظور',
                        'vip' => 'VIP ⭐️',
                    ]),
                Tables\Filters\SelectFilter::make('nationality')
                    ->label('الجنسية')
                    ->options([
                        'EG' => 'مصر',
                        'SA' => 'السعودية',
                        'AE' => 'الإمارات',
                        'KW' => 'الكويت',
                        'OTHER' => 'أخرى',
                    ]),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make()->label('عرض'),
                \Filament\Actions\EditAction::make()->label('تعديل'),
                \Filament\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view'   => Pages\ViewCustomer::route('/{record}'),
            'edit'   => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
