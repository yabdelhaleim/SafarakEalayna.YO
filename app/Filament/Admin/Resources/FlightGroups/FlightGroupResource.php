<?php

namespace App\Filament\Admin\Resources\FlightGroups;

use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\FlightGroups\Pages\CreateFlightGroup;
use App\Filament\Admin\Resources\FlightGroups\Pages\EditFlightGroup;
use App\Filament\Admin\Resources\FlightGroups\Pages\ListFlightGroups;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FlightGroupResource extends Resource
{
    use BelongsToFlightModuleNavigation;

    protected static ?string $model = FlightGroup::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?string $navigationLabel = 'مجموعات الشركات';

    protected static ?string $pluralLabel = 'مجموعات الشركات';

    protected static ?string $modelLabel = 'مجموعة';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات المجموعة')
                    ->description('بيانات المجموعة أو المكتب المورد للتذاكر بالأجل')
                    ->schema([
                        Select::make('flight_carrier_id')
                            ->label('شركة الطيران التابعة (اختياري)')
                            ->relationship('carrier', 'name')
                            ->searchable(),
                        TextInput::make('name')
                            ->label('اسم المجموعة')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('الشعلة، فرياج، العلا…'),
                        TextInput::make('code')
                            ->label('الكود')
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->placeholder('SHA، VOY، ALA'),
                    ])
                    ->columns(3),
                Section::make('معلومات الاتصال')
                    ->schema([
                        TextInput::make('contact_person')
                            ->label('الشخص المسؤول')
                            ->maxLength(255)
                            ->placeholder('اسم المسؤول عن المجموعة'),
                        TextInput::make('contact_phone')
                            ->label('رقم الهاتف')
                            ->tel()
                            ->maxLength(20)
                            ->placeholder('+965 1234 5678'),
                        TextInput::make('contact_email')
                            ->label('البريد الإلكتروني')
                            ->email()
                            ->maxLength(255)
                            ->placeholder('example@email.com'),
                    ])
                    ->columns(3),
                Section::make('المعلومات المالية')
                    ->description('حدود الائتمان والعمولة')
                    ->schema([
                        TextInput::make('commission_rate')
                            ->label('نسبة العمولة (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->nullable()
                            ->placeholder('اختياري'),
                        TextInput::make('credit_limit')
                            ->label('حد الائتمان (الدين المسموح)')
                            ->numeric()
                            ->minValue(0)
                            ->default(999999999)
                            ->suffix(fn ($get) => ' ' . strtoupper((string) (FlightCarrier::find($get('flight_carrier_id'))?->currency ?? 'EGP')))
                            ->helperText(
                                'الحد الأقصى للدين المسموح للمجموعة. '.
                                'الافتراضي كبير (999,999,999) للسماح بالأجل التلقائي. '.
                                'حدد رقماً لتحديد سقف أقصى للدين — لما يتجاوزه النظام هيرفض الحجز.'
                            ),
                    ])
                    ->columns(2),
                Section::make('إعدادات الإشعارات')
                    ->description('تنبيهات عند اقتراب المجموعة من سقف الدين (info / warning / danger)')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('notification_threshold_info')
                                ->label('عتبة معلومة (Info)')
                                ->numeric()
                                ->minValue(0)
                                ->nullable()
                                ->suffix(fn ($get) => ' ' . strtoupper((string) (FlightCarrier::find($get('flight_carrier_id'))?->currency ?? 'EGP')))
                                ->helperText('إشعار بداية الاقتراب'),
                            TextInput::make('notification_threshold_warning')
                                ->label('عتبة تحذير (Warning)')
                                ->numeric()
                                ->minValue(0)
                                ->nullable()
                                ->suffix(fn ($get) => ' ' . strtoupper((string) (FlightCarrier::find($get('flight_carrier_id'))?->currency ?? 'EGP')))
                                ->helperText('تحتاج متابعة'),
                            TextInput::make('notification_threshold_danger')
                                ->label('عتبة خطر (Danger)')
                                ->numeric()
                                ->minValue(0)
                                ->nullable()
                                ->suffix(fn ($get) => ' ' . strtoupper((string) (FlightCarrier::find($get('flight_carrier_id'))?->currency ?? 'EGP')))
                                ->helperText('تدخل فوري'),
                        ]),
                        Grid::make(3)->schema([
                            Toggle::make('notify_via_toast')
                                ->label('Toast Popup فوري')
                                ->helperText('يظهر مباشرة بعد الحجز')
                                ->default(true)
                                ->inline(false),
                            Toggle::make('notify_via_widget')
                                ->label('Dashboard Widget')
                                ->helperText('يظهر في لوحة الطيران')
                                ->default(true)
                                ->inline(false),
                            Toggle::make('notify_via_bell')
                                ->label('In-App Notification 🔔')
                                ->helperText('يظهر في جرس الإشعارات')
                                ->default(true)
                                ->inline(false),
                        ]),
                    ])
                    ->columns(1)
                    ->collapsible(),
                Section::make('معلومات إضافية')
                    ->schema([
                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(3)
                            ->placeholder('ملاحظات إضافية عن المجموعة...'),
                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2),
                Hidden::make('created_by')
                    ->default(fn () => auth()->id())
                    ->dehydrated(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('code')
                    ->label('الكود')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                TextColumn::make('name')
                    ->label('اسم المجموعة')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('carrier.name')
                    ->label('شركة الطيران')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('carrier.currency')
                    ->label('العملة')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {                        'EGP' => 'success',                        'KWD' => 'warning',                        'SAR' => 'info',                        'USD' => 'primary',                        default => 'gray',
                    }),
                TextColumn::make('contact_person')
                    ->label('المسؤول')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('contact_phone')
                    ->label('الهاتف')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
                TextColumn::make('credit_limit')
                    ->label('حد الائتمان')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn ($record) => ' ' . strtoupper($record->carrier?->currency ?? 'EGP'))
                    ->toggleable(),
                TextColumn::make('last_threshold_level')
                    ->label('آخر مستوى')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'info' => 'info',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'info' => 'معلومة',
                        'warning' => 'تحذير',
                        'danger' => 'خطر',
                        default => '—',
                    })
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('flight_carrier_id')
                    ->label('شركة الطيران')
                    ->relationship('carrier', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label('الحالة')
                    ->placeholder('الكل')
                    ->trueLabel('نشط')
                    ->falseLabel('غير نشط'),
                TrashedFilter::make(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->modal(false),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlightGroups::route('/'),
            'create' => CreateFlightGroup::route('/create'),
            'edit' => EditFlightGroup::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
