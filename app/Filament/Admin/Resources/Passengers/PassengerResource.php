<?php

namespace App\Filament\Admin\Resources\Passengers;

use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\Passengers\Pages\ManagePassengers;
use App\Models\Passenger;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PassengerResource extends Resource
{
    use BelongsToFlightModuleNavigation;

    protected static ?string $model = Passenger::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'الشركاء والعملاء';

    protected static ?string $navigationLabel = 'المسافرين';
    protected static ?string $pluralLabel = 'المسافرين';
    protected static ?string $modelLabel = 'مسافر';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'first_name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('flight_booking_id')
                    ->label('حجز الطيران')
                    ->relationship('booking', 'booking_reference')
                    ->searchable()
                    ->required(),
                TextInput::make('first_name')
                    ->label('الاسم الأول')
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->label('اللقب / اسم العائلة')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->label('نوع المسافر')
                    ->options([
                        'adult' => 'بالغ',
                        'child' => 'طفل',
                        'infant' => 'رضيع',
                    ])
                    ->required(),
                DatePicker::make('date_of_birth')
                    ->label('تاريخ الميلاد'),
                TextInput::make('relation_to_customer')
                    ->label('صلة القرابة بالعميل'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('first_name')
            ->columns([
                TextColumn::make('booking.booking_reference')
                    ->label('رقم الحجز')
                    ->searchable(),
                TextColumn::make('first_name')
                    ->label('الاسم الأول')
                    ->searchable(),
                TextColumn::make('last_name')
                    ->label('اللقب')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->color(function ($state): string {
                        $val = $state instanceof \BackedEnum ? $state->value : $state;
                        return match ($val) {
                            'adult' => 'success',
                            'child' => 'warning',
                            'infant' => 'info',
                            default => 'gray',
                        };
                    })
                    ->formatStateUsing(function ($state): string {
                        $val = $state instanceof \BackedEnum ? $state->value : $state;
                        return match ($val) {
                            'adult' => 'بالغ',
                            'child' => 'طفل',
                            'infant' => 'رضيع',
                            default => (string) $val,
                        };
                    }),
                TextColumn::make('date_of_birth')
                    ->label('تاريخ الميلاد')
                    ->date(),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make(),
                \Filament\Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Tables\Actions\BulkActionGroup::make([
                    \Filament\Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePassengers::route('/'),
        ];
    }
}
