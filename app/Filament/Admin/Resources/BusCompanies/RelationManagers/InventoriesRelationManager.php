<?php

namespace App\Filament\Admin\Resources\BusCompanies\RelationManagers;

use App\Enums\BusInventoryPaymentType;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
