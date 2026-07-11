<?php

namespace App\Filament\Admin\Resources\BusCompanies\RelationManagers;

use App\Enums\BusInventoryPaymentType;
use App\Models\Bus\BusInventory;
use App\Services\Bus\BusInventoryService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class InventoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'inventories';

    protected static ?string $title = 'الرحلات (الوجهات والمواعيد)';
    protected static ?string $recordTitleAttribute = 'route';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('route')
                    ->label('المسار (من .. إلى)')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\DatePicker::make('travel_date')
                    ->label('تاريخ السفر')
                    ->required()
                    ->native(false),
                Forms\Components\TimePicker::make('departure_time')
                    ->label('وقت المغادرة')
                    ->seconds(false)
                    ->required(),
                Forms\Components\TextInput::make('total_tickets')
                    ->label('عدد المقاعد (السعة)')
                    ->numeric()
                    ->required()
                    ->default(50),
                Forms\Components\TextInput::make('cost_per_ticket')
                    ->label('سعر التكلفة (ج.م)')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('selling_price')
                    ->label('سعر البيع للعميل (ج.م)')
                    ->numeric()
                    ->required(),
                Forms\Components\Select::make('payment_type')
                    ->label('نوع سداد التكلفة للشركة')
                    ->options(BusInventoryPaymentType::class)
                    ->default('deferred')
                    ->required()
                    ->native(false),
                Forms\Components\Hidden::make('available_tickets')
                    ->default(fn (Forms\Get $get) => $get('total_tickets')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('route')
                    ->label('المسار'),
                Tables\Columns\TextColumn::make('travel_date')
                    ->label('تاريخ السفر')
                    ->date('d/m/Y'),
                Tables\Columns\TextColumn::make('departure_time')
                    ->label('وقت المغادرة')
                    ->time('H:i'),
                Tables\Columns\TextColumn::make('total_tickets')
                    ->label('السعة'),
                Tables\Columns\TextColumn::make('cost_per_ticket')
                    ->label('سعر التكلفة')
                    ->money('EGP'),
                Tables\Columns\TextColumn::make('selling_price')
                    ->label('سعر البيع')
                    ->money('EGP'),
            ])
            ->filters([
                SelectFilter::make('payment_type')
                    ->label('نوع الدفع')
                    ->options(BusInventoryPaymentType::class),
                SelectFilter::make('has_available')
                    ->label('هل فيه مقاعد؟')
                    ->options([
                        '1' => 'فيه مقاعد متاحة',
                        '0' => 'مافيش مقاعد',
                    ])
                    ->query(function ($query, $value) {
                        if ($value === '1') {
                            return $query->where('available_tickets', '>', 0);
                        }

                        if ($value === '0') {
                            return $query->where('available_tickets', '<=', 0);
                        }

                        return $query;
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة رحلة جديدة')
                    ->modalHeading('إضافة رحلة للشركة')
                    ->createAnother(true)
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['available_tickets'] = $data['total_tickets'] ?? 50;
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // ✅ Unified deletion path — routes through BusInventoryService::deleteInventory(),
                // which reverses the cash purchase expense (if Cash) AND wraps in
                // BusInventory::run() so the ModelDeletionGuard's `deleting` observer
                // allows the soft-delete. Direct `$record->delete()` is blocked.
                Tables\Actions\Action::make('deleteInventory')
                    ->label('حذف')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('حذف رحلة الباص')
                    ->modalDescription(
                        'سيتم حذف الرحلة (soft-delete) وعكس مصروف الشراء النقدي تلقائياً إن وُجد. '
                        .'يجب ألّا تكون هناك حجوزات نشطة على هذه الرحلة.'
                    )
                    ->modalSubmitActionLabel('نعم، احذف الرحلة')
                    ->action(function (BusInventory $record): void {
                        try {
                            app(BusInventoryService::class)->deleteInventory($record);

                            Notification::make()
                                ->title('تم حذف الرحلة')
                                ->body('تم أرشفة الرحلة وعكس مصروف الشراء النقدي (إن وُجد).')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('فشل حذف الرحلة')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            throw $e;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // ✅ Unified bulk deletion — same service delegation as the
                    // single-record action. Per-record failures reported via Notification.
                    BulkAction::make('deleteInventories')
                        ->label('حذف المحدد')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('حذف الرحلات المحددة')
                        ->modalDescription(
                            'سيتم حذف الرحلات المحددة عبر BusInventoryService::deleteInventory(). '
                            .'أي رحلة بها حجوزات نشطة ستفشل مع تقرير خطأ منفصل.'
                        )
                        ->modalSubmitActionLabel('نعم، احذف المحدد')
                        ->action(function (Collection $records): void {
                            $service = app(BusInventoryService::class);
                            $success = 0;
                            $failures = [];

                            foreach ($records as $record) {
                                try {
                                    $service->deleteInventory($record);
                                    $success++;
                                } catch (\Throwable $e) {
                                    $failures[] = [
                                        'route' => $record->route,
                                        'message' => $e->getMessage(),
                                    ];
                                }
                            }

                            if ($success > 0) {
                                Notification::make()
                                    ->title("تم حذف {$success} رحلة بنجاح")
                                    ->success()
                                    ->send();
                            }

                            foreach ($failures as $fail) {
                                Notification::make()
                                    ->title('فشل حذف: '.$fail['route'])
                                    ->body($fail['message'])
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }
}
