<?php

namespace App\Filament\Admin\Resources\FlightGroups;

use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\FlightGroups\Pages\CreateFlightGroup;
use App\Filament\Admin\Resources\FlightGroups\Pages\EditFlightGroup;
use App\Filament\Admin\Resources\FlightGroups\Pages\ListFlightGroups;
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
                    ->schema([
                        TextInput::make('commission_rate')
                            ->label('نسبة العمولة (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->default(0)
                            ->helperText('نسبة العمولة التي تحصل عليها المجموعة'),
                    ]),
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
                TextColumn::make('account.balance')
                    ->label('الرصيد / المديونية')
                    ->sortable()
                    ->money('EGP')
                    ->color(fn ($state): string => $state < 0 ? 'danger' : 'success')
                    ->badge(),
                TextColumn::make('carrier.name')
                    ->label('شركة الطيران')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('carrier.currency')
                    ->label('العملة')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'EGP' => 'success',
                        'KWD' => 'warning',
                        'SAR' => 'info',
                        'USD' => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('commission_rate')
                    ->label('العمولة')
                    ->suffix('%')
                    ->sortable()
                    ->badge()
                    ->color('success'),
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
