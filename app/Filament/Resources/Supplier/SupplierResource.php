<?php

namespace App\Filament\Resources\Supplier;

use App\Models\Supplier;
use BackedEnum;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use App\Enums\SupplierType;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'الموردين';

    protected static ?int $navigationSort = 2;

    protected static string|\UnitEnum|null $navigationGroup = 'المالية';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('المعلومات الأساسية')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name', 'اسم المورد')
                                    ->required()
                                    ->maxLength(255)
                                    ->searchable(),

                                TextInput::make('code', 'الكود')
                                    ->maxLength(50)
                                    ->unique(ignoreRecord: true),

                                Select::make('type', 'نوع المورد')
                                    ->options(SupplierType::class)
                                    ->required(),

                                TextInput::make('contact_person', 'الشخص المسؤول')
                                    ->maxLength(255),

                                TextInput::make('email', 'البريد الإلكتروني')
                                    ->email()
                                    ->maxLength(255),

                                TextInput::make('phone', 'الهاتف')
                                    ->tel()
                                    ->maxLength(20),

                                TextInput::make('mobile', 'الجوال')
                                    ->tel()
                                    ->maxLength(20),

                                TextInput::make('address', 'العنوان')
                                    ->maxLength(500),

                                TextInput::make('city', 'المدينة')
                                    ->maxLength(100),

                                TextInput::make('country', 'الدولة')
                                    ->maxLength(100),

                                TextInput::make('payment_terms', 'شروط الدفع')
                                    ->maxLength(255),

                                TextInput::make('credit_limit', 'حد الائتمان')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->step(0.01),

                                Select::make('is_active', 'حactivation')
                                    ->options([
                                        true => 'نشط',
                                        false => 'غير نشط',
                                    ])
                                    ->default(true)
                                    ->required(),
                            ]),
                    ])
                    ->columns(2),

                Section::make('ملاحظات')
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                \Filament\Forms\Components\Textarea::make('notes')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('code', 'الكود')
                    ->searchable(),

                TextColumn::make('name', 'اسم المورد')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type', 'النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {                        'individual' => 'فرد',                        'company' => 'شركة',                        'government' => 'حكومي',                        default => $state,
                    }),

                TextColumn::make('contact_person', 'المسؤول')
                    ->searchable(),

                TextColumn::make('phone', 'الهاتف')
                    ->searchable(),

                TextColumn::make('email', 'البريد الإلكتروني')
                    ->searchable(),

                TextColumn::make('city', 'المدينة')
                    ->searchable(),

                BadgeColumn::make('is_active', 'الحالة')
                    ->colors([
                        true => 'success',
                        false => 'danger',
                    ])
                    ->formatStateUsing(fn ($state) => $state ? 'نشط' : 'غير نشط'),

                TextColumn::make('current_debt', 'المديونية الحالية')
                    ->money('jod')
                    ->color('danger')
                    ->sortable(),

                TextColumn::make('credit_limit', 'حد الائتمان')
                    ->money('jod')
                    ->sortable(),

                TextColumn::make('created_at', 'تاريخ الإضافة')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type', 'النوع')
                    ->options(SupplierType::class),

                SelectFilter::make('is_active', 'الحالة')
                    ->options([
                        true => 'نشط',
                        false => 'غير نشط',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            'createdBy',
            'account',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/index'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
            'view' => Pages\ViewSupplier::route('/{record}'),
        ];
    }
}
