<?php

namespace App\Filament\Resources\FlightGroup;

use App\Filament\Resources\FlightGroup\Pages;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightCarrier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FlightGroupResource extends Resource
{
    protected static ?string $model = FlightGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'مجموعات السفر';

    protected static ?string $modelLabel = 'مجموعة';

    protected static ?string $pluralModelLabel = 'مجموعات السفر';

    protected static ?string $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('معلومات المجموعة')
                    ->schema([
                        Forms\Components\Select::make('flight_carrier_id')
                            ->label('شركة الطيران')
                            ->relationship('carrier', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive(),

                        Forms\Components\TextInput::make('name')
                            ->label('اسم المجموعة')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: الشعلة، فرياج، العلا'),

                        Forms\Components\TextInput::make('code')
                            ->label('الكود')
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->placeholder('مثال: SHA, VOY, ALA'),
                    ])->columns(3),

                Forms\Components\Section::make('معلومات الاتصال')
                    ->schema([
                        Forms\Components\TextInput::make('contact_person')
                            ->label('الشخص المسؤول')
                            ->maxLength(255)
                            ->placeholder('اسم المسؤول عن المجموعة'),

                        Forms\Components\TextInput::make('contact_phone')
                            ->label('رقم الهاتف')
                            ->tel()
                            ->maxLength(20)
                            ->placeholder('مثال: +965 1234 5678'),

                        Forms\Components\TextInput::make('contact_email')
                            ->label('البريد الإلكتروني')
                            ->email()
                            ->maxLength(255)
                            ->placeholder('example@email.com'),
                    ])->columns(3),

                Forms\Components\Section::make('المعلومات المالية')
                    ->schema([
                        Forms\Components\TextInput::make('commission_rate')
                            ->label('نسبة العمولة (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->nullable()
                            ->placeholder('اختياري')
                            ->helperText('اختياري: نسبة العمولة التي تحصل عليها المجموعة من قيمة التذكرة. اتركه فارغاً إن لم تطبَّق عمولة.'),

                        Forms\Components\TextInput::make('credit_limit')
                            ->label('حد الائتمان (الدين المسموح)')
                            ->numeric()
                            ->minValue(0)
                            ->default(999999999)
                            ->suffix(fn ($get) => ' ' . strtoupper((string) (\App\Models\Flight\FlightCarrier::find($get('flight_carrier_id'))?->currency ?? 'EGP')))
                            ->helperText(
                                'الحد الأقصى للدين المسموح للمجموعة. '.
                                'الافتراضي كبير (999,999,999) للسماح بالأجل التلقائي. '.
                                'حدد رقماً لتحديد سقف أقصى للدين — لما يتجاوزه النظام هيرفض الحجز.'
                            )
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('credit_limit', max(0, (float) $state));
                            }),
                    ])->columns(2),

                Forms\Components\Section::make('معلومات إضافية')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(3)
                            ->placeholder('ملاحظات إضافية عن المجموعة...'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('الكود')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('name')
                    ->label('اسم المجموعة')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('carrier.name')
                    ->label('شركة الطيران')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('carrier.currency')
                    ->label('العملة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {                        'EGP' => 'success',                        'KWD' => 'warning',                        'SAR' => 'info',                        'USD' => 'primary',                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('العمولة')
                    ->suffix('%')
                    ->sortable()
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('contact_person')
                    ->label('المسؤول')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('contact_phone')
                    ->label('الهاتف')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('flight_carrier_id')
                    ->label('شركة الطيران')
                    ->relationship('carrier', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('نشط')
                    ->placeholder('الكل')
                    ->trueLabel('نشط فقط')
                    ->falseLabel('غير نشط فقط'),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name', 'asc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlightGroups::route('/'),
            'create' => Pages\CreateFlightGroup::route('/create'),
            'edit' => Pages\EditFlightGroup::route('/{record}/edit'),
        ];
    }
}
