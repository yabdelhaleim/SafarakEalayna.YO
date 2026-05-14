<?php

namespace App\Filament\Admin\Resources\TicketModifications;

use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\TicketModifications\Pages;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\TicketModification;
use App\Services\Flight\ModificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class TicketModificationResource extends Resource
{
    use BelongsToFlightModuleNavigation;

    protected static ?string $model = TicketModification::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'تعديلات التذاكر';

    protected static ?string $modelLabel = 'تعديل تذكرة';

    protected static ?string $pluralModelLabel = 'تعديلات التذاكر';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات الحجز المستهدف')
                    ->description('تطبيق قاعدة الخصم المالي الثابتة: يتم الخصم تلقائياً من رصيد الطيران الأصلي للحجز دون إتاحة خيار تجاوزه.')
                    ->columns(2)
                    ->schema([
                        Select::make('booking_id')
                            ->label('الحجز الأصلي')
                            ->relationship('booking', 'booking_number')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state) {
                                if (!$state) {
                                    return;
                                }
                                $booking = FlightBooking::find($state);
                                if (!$booking) {
                                    return;
                                }
                                $set('currency', $booking->currency ?: 'EGP');
                            }),

                        Select::make('modification_type')
                            ->label('نوع التعديل')
                            ->options([
                                'date_change' => 'تغيير الموعد فقط',
                                'destination_change' => 'تغيير الوجهة/خط السير فقط',
                                'both' => 'تغيير الموعد والوجهة معاً',
                            ])
                            ->required()
                            ->default('date_change')
                            ->live(),

                        Grid::make(3)
                            ->columnSpanFull()
                            ->schema([
                                DatePicker::make('new_departure_date')
                                    ->label('تاريخ المغادرة الجديد')
                                    ->required(fn (Get $get): bool => in_array($get('modification_type'), ['date_change', 'both']))
                                    ->visible(fn (Get $get): bool => in_array($get('modification_type'), ['date_change', 'both'])),

                                TextInput::make('new_destination')
                                    ->label('الوجهة الجديدة')
                                    ->required(fn (Get $get): bool => in_array($get('modification_type'), ['destination_change', 'both']))
                                    ->visible(fn (Get $get): bool => in_array($get('modification_type'), ['destination_change', 'both'])),

                                TextInput::make('new_flight_number')
                                    ->label('رقم الرحلة الجديد'),
                            ]),
                    ]),

                Section::make('التسعير والعمولات (GL Accounting Layer)')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('airline_change_fee')
                                    ->label('غرامة شركة الطيران (صافي التكلفة)')
                                    ->numeric()
                                    ->default(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::updateTotal($get, $set)),

                                TextInput::make('agency_commission')
                                    ->label('عمولة الوكالة (الربح الثابت)')
                                    ->numeric()
                                    ->default(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::updateTotal($get, $set)),

                                TextInput::make('total_charged_to_customer')
                                    ->label('الإجمالي المحصل من العميل')
                                    ->numeric()
                                    ->required()
                                    ->readOnly(),
                            ]),

                        Grid::make(3)
                            ->schema([
                                TextInput::make('currency')
                                    ->label('العملة')
                                    ->maxLength(3)
                                    ->default('EGP')
                                    ->required(),

                                Select::make('payment_method')
                                    ->label('طريقة التحصيل')
                                    ->options([
                                        'cash' => 'نقدي',
                                        'bank_transfer' => 'تحويل بنكي',
                                        'wallet' => 'محفظة إلكترونية',
                                    ])
                                    ->default('cash'),

                                Select::make('status')
                                    ->label('حالة سير العمل')
                                    ->options([
                                        'draft' => 'مسودة (Draft)',
                                        'pending' => 'قيد الانتظار (Pending)',
                                        'quoted' => 'مسعر (Quoted)',
                                        'approved' => 'معتمد مالياً (Approved)',
                                        'confirmed' => 'مؤكد ومرحل (Confirmed)',
                                    ])
                                    ->default('draft')
                                    ->required(),
                            ]),

                        TextInput::make('reason_for_change')
                            ->label('سبب التعديل')
                            ->maxLength(255),

                        Textarea::make('notes')
                            ->label('ملاحظات إضافية')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected static function updateTotal(Get $get, Set $set): void
    {
        $fee = (float) $get('airline_change_fee');
        $comm = (float) $get('agency_commission');
        $set('total_charged_to_customer', $fee + $comm);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('رقم الحجز')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->color('primary'),

                Tables\Columns\BadgeColumn::make('modification_type')
                    ->label('النوع')
                    ->colors([
                        'primary' => 'date_change',
                        'warning' => 'destination_change',
                        'success' => 'both',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'date_change' => 'تعديل موعد',
                        'destination_change' => 'تعديل وجهة',
                        'both' => 'موعد ووجهة',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('airline_change_fee')
                    ->label('غرامة الطيران')
                    ->numeric(2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('agency_commission')
                    ->label('عمولة الوكالة')
                    ->numeric(2)
                    ->sortable()
                    ->color('success'),

                Tables\Columns\TextColumn::make('total_charged_to_customer')
                    ->label('إجمالي العميل')
                    ->numeric(2)
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('الحالة')
                    ->colors([
                        'secondary' => 'draft',
                        'warning' => 'pending',
                        'primary' => 'quoted',
                        'info' => 'approved',
                        'success' => 'confirmed',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'مسودة',
                        'pending' => 'انتظار',
                        'quoted' => 'مسعر',
                        'approved' => 'معتمد',
                        'confirmed' => 'مؤكد ومرحل',
                        default => $state,
                    }),

                Tables\Columns\BadgeColumn::make('reconciliation_status')
                    ->label('حالة التسوية')
                    ->colors([
                        'danger' => 'unreconciled',
                        'success' => 'matched',
                        'warning' => 'disputed',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'unreconciled' => 'غير مسوى',
                        'matched' => 'مطابق',
                        'disputed' => 'متنازع عليه',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الطلب')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'draft' => 'مسودة',
                        'pending' => 'انتظار',
                        'quoted' => 'مسعر',
                        'approved' => 'معتمد',
                        'confirmed' => 'مؤكد ومرحل',
                    ]),

                Tables\Filters\SelectFilter::make('reconciliation_status')
                    ->label('التسوية مع الطيران')
                    ->options([
                        'unreconciled' => 'غير مسوى',
                        'matched' => 'مطابق',
                        'disputed' => 'متنازع عليه',
                    ]),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('تأكيد وترحيل مالي')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد التعديل وتطبيق قواعد المحاسبة')
                    ->modalDescription('تأكيد التعديل سيقوم فوراً بخصم الغرامة من حساب الطيران الأصلي، إنشاء قيود اليومية المزدوجة، وتحديث بيانات التذكرة التشغيلية لتعكس الموعد والوجهة الجديدة.')
                    ->visible(fn (TicketModification $record): bool => $record->status !== 'confirmed')
                    ->action(function (TicketModification $record) {
                        try {
                            $user = Auth::user();
                            if ($user && !$user->can('modifications.confirm') && !in_array($user->role, ['admin', 'owner', 'head_of_finance'])) {
                                throw new \Exception("تأكيد التعديل والترحيل المالي النهائي مقتصر على مديري المالية والمسؤولين.");
                            }

                            $userId = $user?->id ?: 1;
                            app(ModificationService::class)->confirmModification($record->id, $userId);

                            Notification::make()
                                ->title('تم التأكيد والترحيل بنجاح')
                                ->body('تم خصم غرامة الطيران، تسجيل القيود المزدوجة وتحديث الحقل الأساسي للمغادرة في كشوفات المسافرين.')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('فشل التأكيد')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('reconcile')
                    ->label('مطابقة الفاتورة')
                    ->icon('heroicon-o-document-check')
                    ->color('info')
                    ->form([
                        TextInput::make('invoice_number')
                            ->label('رقم فاتورة شركة الطيران')
                            ->required(),
                    ])
                    ->visible(fn (TicketModification $record): bool => $record->status === 'confirmed' && $record->reconciliation_status !== 'matched')
                    ->action(function (array $data, TicketModification $record) {
                        try {
                            app(ModificationService::class)->reconcileModification($record->id, $data['invoice_number']);

                            Notification::make()
                                ->title('تمت التسوية بنجاح')
                                ->body("تمت مطابقة التعديل مع فاتورة الطيران رقم {$data['invoice_number']}.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('فشل التسوية')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTicketModifications::route('/'),
            'create' => Pages\CreateTicketModification::route('/create'),
            'edit' => Pages\EditTicketModification::route('/{record}/edit'),
        ];
    }
}
