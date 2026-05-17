<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HotelResource\Pages;
use App\Models\HajjUmra\Hotel;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class HotelResource extends Resource
{
    protected static ?string $model = Hotel::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';
    protected static string|\UnitEnum|null $navigationGroup = 'الحج والزيارة';
    protected static ?string $modelLabel = 'فندق';
    protected static ?string $pluralModelLabel = 'الفنادق';
    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Section::make('معلومات الفندق الأساسية')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('اسم الفندق')
                        ->required()
                        ->placeholder('فندق هيلتون مكة'),

                    Forms\Components\TextInput::make('city')
                        ->label('المدينة')
                        ->required()
                        ->placeholder('مكة المكرمة'),

                    Forms\Components\TextInput::make('country')
                        ->label('الدولة')
                        ->required()
                        ->placeholder('السعودية'),

                    Forms\Components\Select::make('stars')
                        ->label('تصنيف النجوم')
                        ->options([
                            1 => '⭐️',
                            2 => '⭐️⭐️',
                            3 => '⭐️⭐️⭐️',
                            4 => '⭐️⭐️⭐️⭐️',
                            5 => '⭐️⭐️⭐️⭐️⭐️',
                        ])
                        ->default(3)
                        ->required(),
                ]),

            \Filament\Schemas\Components\Section::make('التوافر والأسعار')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('price_per_night')
                        ->label('السعر للغرفة/الليلة (ج.م)')
                        ->numeric()
                        ->prefix('ج.م')
                        ->required(),

                    Forms\Components\TextInput::make('total_rooms')
                        ->label('إجمالي الغرف')
                        ->numeric()
                        ->required(),

                    Forms\Components\TextInput::make('available_rooms')
                        ->label('الغرف المتاحة')
                        ->numeric()
                        ->required(),
                ]),

            \Filament\Schemas\Components\Section::make('معلومات التواصل والمميزات')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('contact_phone')
                        ->label('رقم التواصل')
                        ->tel(),

                    Forms\Components\TextInput::make('contact_email')
                        ->label('البريد الإلكتروني')
                        ->email(),

                    Forms\Components\TagsInput::make('amenities')
                        ->label('الخدمات والمرافق')
                        ->placeholder('أضف خدمة واضغط Enter (مثال: WiFi، مسبح، إلخ...)')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('وصف الفندق')
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('نشط / فعال')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم الفندق')
                    ->searchable()
                    ->weight('bold')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('city')
                    ->label('المدينة')
                    ->searchable(),

                Tables\Columns\TextColumn::make('country')
                    ->label('الدولة')
                    ->searchable(),

                Tables\Columns\TextColumn::make('stars')
                    ->label('النجوم')
                    ->formatStateUsing(fn ($state) => str_repeat('⭐', $state ?? 0))
                    ->color('warning'),

                Tables\Columns\TextColumn::make('price_per_night')
                    ->label('السعر/الليلة')
                    ->money('EGP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('available_rooms')
                    ->label('الغرف المتاحة')
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state > 10 => 'success',
                        $state > 0  => 'warning',
                        default     => 'danger',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('الحالة')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stars')
                    ->label('النجوم')
                    ->options([
                        3 => '3 نجوم',
                        4 => '4 نجوم',
                        5 => '5 نجوم',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('النشاط'),
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
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListHotels::route('/'),
            'create' => Pages\CreateHotel::route('/create'),
            'view'   => Pages\ViewHotel::route('/{record}'),
            'edit'   => Pages\EditHotel::route('/{record}/edit'),
        ];
    }
}
