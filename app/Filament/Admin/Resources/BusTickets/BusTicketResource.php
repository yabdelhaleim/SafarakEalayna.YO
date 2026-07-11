<?php

namespace App\Filament\Admin\Resources\BusTickets;

use App\Models\BusTicket;
use App\Services\BusTicketService;
use BackedEnum;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class BusTicketResource extends Resource
{
    protected static ?string $model = BusTicket::class;

    /** موديل قديم — إدارة الباص الحالية من موارد BusCompany / BusInventory / BusBooking. */
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'الباصات';

    protected static ?string $navigationLabel = 'تذاكر الباص (قديم)';
    protected static ?string $pluralLabel = 'تذاكر الباص (قديم)';
    protected static ?string $modelLabel = 'تذكرة باص (قديم)';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'passenger_name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('passenger_name')
                    ->label('اسم الراكب')
                    ->required()
                    ->maxLength(255)
                    ->searchable(),

                TextInput::make('phone')
                    ->label('رقم الهاتف')
                    ->tel()
                    ->maxLength(20)
                    ->searchable(),

                TextInput::make('country')
                    ->label('الدولة')
                    ->maxLength(100)
                    ->required(),

                TextInput::make('bus_name')
                    ->label('اسم الباص')
                    ->maxLength(255)
                    ->required(),

                TextInput::make('ticket_count')
                    ->label('عدد التذاكر')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->required(),

                DatePicker::make('departure_date')
                    ->label('تاريخ المغادرة')
                    ->required(),

                DatePicker::make('return_date')
                    ->label('تاريخ العودة'),

                TextInput::make('purchase_price')
                    ->label('سعر الشراء')
                    ->numeric()
                    ->prefix('ج.م')
                    ->step(0.01)
                    ->required(),

                TextInput::make('selling_price')
                    ->label('سعر البيع')
                    ->numeric()
                    ->prefix('ج.م')
                    ->step(0.01)
                    ->required(),

                Select::make('employee_id')
                    ->label('الموظف المسؤول')
                    ->relationship('employee', 'name')
                    ->searchable()
                    ->required(),

                Select::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options([
                        'cash' => 'نقدي',
                        'bank_transfer' => 'تحويل بنكي',
                        'card' => 'بطاقة',
                        'other' => 'أخرى',
                    ])
                    ->required(),

                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->prefix('ج.م')
                    ->step(0.01),

                TextInput::make('reference_number')
                    ->label('رقم المرجع')
                    ->maxLength(255),

                TextInput::make('notes')
                    ->label('ملاحظات')
                    ->maxLength(500),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('passenger_name')
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('passenger_name', 'اسم الراكب')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone', 'الهاتف')
                    ->searchable(),

                TextColumn::make('country', 'الدولة')
                    ->searchable(),

                TextColumn::make('bus_name', 'اسم الباص')
                    ->searchable(),

                TextColumn::make('ticket_count', 'عدد التذاكر')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('departure_date', 'تاريخ المغادرة')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('return_date', 'تاريخ العودة')
                    ->date('d/m/Y'),

                TextColumn::make('selling_price', 'سعر البيع')
                    ->money('jod')
                    ->sortable(),

                TextColumn::make('profit', 'الربح')
                    ->money('jod')
                    ->sortable()
                    ->color('success'),

                BadgeColumn::make('status', 'الحالة')
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'confirmed' => 'success',
                        'cancelled' => 'danger',
                        'completed' => 'info',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'قيد الانتظار',
                        'confirmed' => 'مؤكد',
                        'cancelled' => 'ملغي',
                        'completed' => 'مكتمل',
                        default => $state,
                    }),

                TextColumn::make('employee.name', 'الموظف')
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status', 'الحالة')
                    ->options([
                        'pending' => 'قيد الانتظار',
                        'confirmed' => 'مؤكد',
                        'cancelled' => 'ملغي',
                        'completed' => 'مكتمل',
                    ]),

                SelectFilter::make('country', 'الدولة')
                    ->searchable(),

                SelectFilter::make('employee_id', 'الموظف')
                    ->relationship('employee', 'name')
                    ->searchable(),
            ])
            ->defaultSort('departure_date', 'desc')
            ->recordActions([
                \Filament\Tables\Actions\EditAction::make(),
                // ✅ Unified deletion path — routes through BusTicketService::delete(),
                // which wraps in BusTicket::run() so the ModelDeletionGuard's
                // `deleting` observer allows the soft-delete. Even though this
                // resource is hidden from navigation (`shouldRegisterNavigation=false`),
                // the deletion entry point is still wired through the service to keep
                // the same ModelDeletionGuard contract as the rest of the Bus module.
                \Filament\Tables\Actions\Action::make('deleteTicket')
                    ->label('حذف')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('حذف تذكرة الباص (قديم)')
                    ->modalDescription(
                        'سيتم حذف التذكرة (soft-delete) ولن تظهر في القوائم. '
                        .'هذا الإجراء مخصّص للسجلات القديمة فقط.'
                    )
                    ->modalSubmitActionLabel('نعم، احذف التذكرة')
                    ->action(function (BusTicket $record): void {
                        try {
                            app(BusTicketService::class)->delete($record);

                            Notification::make()
                                ->title('تم حذف التذكرة')
                                ->body('تم أرشفة التذكرة ولن تظهر في القوائم.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('فشل حذف التذكرة')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            throw $e;
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // ✅ Unified bulk deletion — same service delegation.
                    BulkAction::make('deleteTickets')
                        ->label('حذف المحدد')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('حذف التذاكر المحددة')
                        ->modalDescription(
                            'سيتم حذف التذاكر المحددة عبر BusTicketService::delete().'
                        )
                        ->modalSubmitActionLabel('نعم، احذف المحدد')
                        ->action(function (Collection $records): void {
                            $service = app(BusTicketService::class);
                            $success = 0;
                            $failures = [];

                            foreach ($records as $record) {
                                try {
                                    $service->delete($record);
                                    $success++;
                                } catch (\Throwable $e) {
                                    $failures[] = [
                                        'name' => $record->passenger_name,
                                        'message' => $e->getMessage(),
                                    ];
                                }
                            }

                            if ($success > 0) {
                                Notification::make()
                                    ->title("تم حذف {$success} تذكرة بنجاح")
                                    ->success()
                                    ->send();
                            }

                            foreach ($failures as $fail) {
                                Notification::make()
                                    ->title('فشل حذف: '.$fail['name'])
                                    ->body($fail['message'])
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBusTickets::route('/'),
        ];
    }
}
