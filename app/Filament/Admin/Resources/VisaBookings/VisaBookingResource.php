<?php

namespace App\Filament\Admin\Resources\VisaBookings;

use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Filament\Admin\Resources\VisaBookings\Pages\CreateVisaBooking;
use App\Filament\Admin\Resources\VisaBookings\Pages\EditVisaBooking;
use App\Filament\Admin\Resources\VisaBookings\Pages\ListVisaBookings;
use App\Filament\Admin\Resources\VisaBookings\Pages\ViewVisaBooking;
use App\Models\VisaBooking;
use App\Services\Visa\VisaBookingService;
use BackedEnum;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use App\Filament\Admin\Resources\VisaBookings\VisaBookingResource\Widgets\VisaStats;
use App\Filament\Clusters\VisaCluster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VisaBookingResource extends Resource
{
    protected static ?string $model = VisaBooking::class;

    protected static ?string $cluster = VisaCluster::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'حجوزات التأشيرات';
    protected static ?string $pluralLabel = 'حجوزات التأشيرات';
    protected static ?string $modelLabel = 'حجز تأشيرة';
    protected static ?int $navigationSort = 0;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('العميل')
                ->schema([
                    Select::make('customer_id')
                        ->label('العميل')
                        ->relationship('customer', 'full_name')
                        ->searchable(['full_name', 'phone', 'passport_number'])
                        ->preload()
                        ->required(),
                ])->columnSpanFull(),

            Section::make('بيانات التأشيرة')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('visa_details.visa_type')
                            ->label('نوع التأشيرة')
                            ->options(collect(VisaType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all())
                            ->required(),

                        TextInput::make('visa_details.country')
                            ->label('الدولة')
                            ->required()
                            ->maxLength(100),

                        Select::make('visa_details.visa_duration_id')
                            ->label('مدة التأشيرة (من القائمة)')
                            ->options(fn () => \App\Models\HajjUmra\VisaDuration::active()->orderBy('sort_order')->pluck('label_ar', 'id')->all())
                            ->searchable(),

                        Select::make('visa_details.entry_type')
                            ->label('نوع الدخول')
                            ->options(collect(VisaEntryType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()),

                        Select::make('visa_details.visa_agent_id')
                            ->label('الوكيل المنفذ')
                            ->options(fn () => \App\Models\HajjUmra\VisaAgent::active()->orderBy('company_name')->pluck('company_name', 'id')->all())
                            ->searchable(),

                        TextInput::make('visa_details.executing_agent')
                            ->label('شخص الاتصال (نص اختياري)')->maxLength(150),

                        DatePicker::make('visa_details.submission_date')->label('تاريخ التقديم')->default(now())->native(false),
                        DatePicker::make('visa_details.expected_result_date')->label('التاريخ المتوقع للنتيجة')->native(false),
                        TextInput::make('visa_details.visa_number')->label('رقم التأشيرة (يُملأ بعد القبول)')->maxLength(100),
                    ]),
                ])->columnSpanFull(),

            Section::make('التسعير')
                ->description('سعر الشراء (ما يدفعه المكتب للوكيل) — سعر البيع (ما يحصّله من العميل) — رسوم خدمة (اختياري) — الربح يُحسب آلياً.')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('purchase_price')->label('سعر الشراء (التكلفة)')
                            ->numeric()->required()->prefix('ج.م')->step(0.01)->minValue(0),
                        TextInput::make('selling_price')->label('سعر البيع')
                            ->numeric()->required()->prefix('ج.م')->step(0.01)->minValue(0),
                        TextInput::make('service_fee')->label('رسوم الخدمة')
                            ->numeric()->prefix('ج.م')->step(0.01)->minValue(0)->default(0),
                    ]),
                ])->columnSpanFull(),

            Section::make('المحاسبة والدفع')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('account_id')
                            ->label('حساب التسوية / الخزينة')
                            ->relationship('account', 'name', fn (Builder $query) => $query->where('is_active', true))
                            ->searchable()->preload()->required(),

                        Select::make('status')
                            ->label('حالة الطلب')
                            ->options(VisaStatus::forDropdown())
                            ->default(VisaStatus::Submitted->value)
                            ->required(),

                        Select::make('employee_id')
                            ->label('الموظف القائم بالطلب')
                            ->relationship('employee', 'name')
                            ->searchable()->preload(),

                        TextInput::make('agent_name')->label('اسم الموظف (نص)')->maxLength(150),
                    ]),
                ])->columnSpanFull(),

            Section::make('ملاحظات')->schema([
                Textarea::make('notes')->label('ملاحظات')->rows(3)->maxLength(1000),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer.full_name')->label('العميل')->searchable()->wrap(),
                TextColumn::make('customer.phone')->label('الهاتف')->toggleable(),
                TextColumn::make('visaDetail.country')->label('الدولة')->searchable(),
                TextColumn::make('visaDetail.visa_type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($s) => $s instanceof VisaType ? $s->label() : (VisaType::tryFrom((string) $s)?->label() ?? '-')),
                TextColumn::make('visaDetail.entry_type')->label('الدخول')->toggleable()
                    ->formatStateUsing(fn ($s) => $s instanceof VisaEntryType ? $s->label() : (VisaEntryType::tryFrom((string) $s)?->label() ?? '-')),
                TextColumn::make('purchase_price')->label('الشراء')->money('EGP')->sortable(),
                TextColumn::make('selling_price')->label('البيع')->money('EGP')->sortable(),
                TextColumn::make('profit')->label('الربح')->money('EGP')->color('success')->sortable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof VisaStatus ? $state->label() : (VisaStatus::tryFrom((string) $state)?->label() ?? $state))
                    ->color(fn ($state) => $state instanceof VisaStatus ? $state->color() : (VisaStatus::tryFrom((string) $state)?->color() ?? 'secondary')),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(VisaStatus::forDropdown()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->successNotificationTitle('تم تحديث الطلب'),
                Action::make('addPayment')
                    ->label('تسجيل دفعة')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->schema([
                        TextInput::make('amount')->label('المبلغ')->numeric()->required()->prefix('ج.م'),
                        Select::make('account_id')->label('الحساب')
                            ->relationship('account', 'name', fn (Builder $query) => $query->where('is_active', true))
                            ->searchable()->preload()->required(),
                        Select::make('payment_method')->label('طريقة الدفع')->required()
                            ->options([
                                'cash' => 'نقدي',
                                'bank_transfer' => 'تحويل بنكي',
                                'wallet' => 'محفظة إلكترونية',
                                'postal' => 'بريد',
                                'other' => 'أخرى',
                            ]),
                        DatePicker::make('payment_date')->label('تاريخ الدفع')->default(now())->native(false),
                        TextInput::make('reference')->label('رقم المرجع')->maxLength(100),
                        TextInput::make('paid_by')->label('المدفوع بواسطة')->maxLength(150),
                    ])
                    ->action(function (VisaBooking $record, array $data) {
                        app(VisaBookingService::class)->addPayment($record, $data);
                        Notification::make()->title('تم تسجيل الدفعة')->success()->send();
                    }),
            ])
            ->toolbarActions([
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVisaBookings::route('/'),
            'create' => CreateVisaBooking::route('/create'),
            'view' => ViewVisaBooking::route('/{record}'),
            'edit' => EditVisaBooking::route('/{record}/edit'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            VisaStats::class,
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
