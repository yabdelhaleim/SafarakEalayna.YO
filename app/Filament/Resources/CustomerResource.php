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
                ->columns(4)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('نوع العميل')
                        ->options([
                            'individual' => 'عميل فردي (كاونتر)',
                            'company'    => 'شركة (شركات)',
                        ])
                        ->default('individual')
                        ->required(),

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
                    ->formatStateUsing(fn ($state) => match($state) {                        'EG' => 'مصر 🇪🇬',                        'SA' => 'السعودية 🇸🇦',                        'AE' => 'الإمارات 🇦🇪',                        'KW' => 'الكويت 🇰🇼',                        'QA' => 'قطر 🇶🇦',                        'BH' => 'البحرين 🇧🇭',                        'OM' => 'عُمان 🇴🇲',                        'JO' => 'الأردن 🇯🇴',                        'OTHER' => 'أخرى 🌐',                        default => $state,
                    }),

                 Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {                        'active' => 'نشط',                        'blocked' => 'محظور',                        'vip' => 'VIP ⭐️',                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {                        'active' => 'success',                        'blocked' => 'danger',                        'vip' => 'warning',                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {                        'individual', \App\Enums\CustomerType::Individual => 'عميل فردي',                        'company', \App\Enums\CustomerType::Company => 'شركة',                        default => $state instanceof \App\Enums\CustomerType ? $state->label() : $state,
                    })
                    ->color(fn ($state) => match($state) {                        'individual', \App\Enums\CustomerType::Individual => 'gray',                        'company', \App\Enums\CustomerType::Company => 'info',                        default => 'gray',
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
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع العميل')
                    ->options([
                        'individual' => 'عميل فردي',
                        'company'    => 'شركة',
                    ]),
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
                \Filament\Actions\Action::make('payDebt')
                    ->label('تسديد دين')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn (Customer $record): bool => (float) ($record->account?->balance ?? 0) > 0)
                    ->form([
                        \Filament\Forms\Components\TextInput::make('amount')
                            ->label('المبلغ')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(fn (Customer $record) => (float) ($record->account?->balance ?? 0))
                            ->default(fn (Customer $record) => (float) ($record->account?->balance ?? 0))
                            ->prefix('ج.م'),
                        \Filament\Forms\Components\Select::make('account_id')
                            ->label('حساب السداد (خزينة / بنك / محفظة)')
                            ->relationship('account', 'name', fn ($query) => $query->whereIn('type', ['cashbox', 'bank', 'wallet'])->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('اختر الخزينة أو الحساب الذي سيتم السداد منه'),
                        \Filament\Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (Customer $record, array $data): void {
                        try {
                            $resp = \Illuminate\Support\Facades\Http::withHeaders([
                                'Authorization' => 'Bearer ' . (\Illuminate\Support\Facades\Auth::user()?->createToken('filament')->plainTextToken ?? ''),
                                'Accept' => 'application/json',
                            ])->post(url('/api/v1/customers/' . $record->id . '/pay-debt'), [
                                'amount' => (float) $data['amount'],
                                'account_id' => (int) $data['account_id'],
                                'notes' => $data['notes'] ?? null,
                                'module' => 'flight',
                            ]);
                            if ($resp->successful()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('تم تسجيل السداد بنجاح')
                                    ->body('رصيد العميل الجديد: ' . number_format($resp->json('data.new_balance'), 2) . ' EGP')
                                    ->success()
                                    ->send();
                            } else {
                                throw new \Exception($resp->json('message') ?? 'فشل السداد');
                            }
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('فشل السداد')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
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
