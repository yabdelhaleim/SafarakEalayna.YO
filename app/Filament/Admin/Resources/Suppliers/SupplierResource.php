<?php

namespace App\Filament\Admin\Resources\Suppliers;

use App\Enums\SupplierType;
use App\Filament\Admin\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $navigationLabel = 'الموردين';
    protected static ?string $pluralLabel = 'الموردين';
    protected static ?string $modelLabel = 'مورد';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Tabs::make('SupplierTabs')
                    ->tabs([
                        \Filament\Forms\Components\Tabs\Tab::make('basic')
                            ->label('البيانات الأساسية')
                            ->schema([
                                TextInput::make('name')->label('اسم المورد')->required(),
                                TextInput::make('code')->label('كود المورد')->required(),
                                Select::make('type')
                                    ->label('نوع المورد')
                                    ->options(SupplierType::class)
                                    ->required(),
                                Select::make('account_id')
                                    ->label('الحساب المالي المرتبط')
                                    ->relationship('account', 'name')
                                    ->searchable(),
                            ])->columns(2),
                        \Filament\Forms\Components\Tabs\Tab::make('contact')
                            ->label('بيانات الاتصال')
                            ->schema([
                                TextInput::make('contact_person')->label('الشخص المسؤول'),
                                TextInput::make('phone')->label('الهاتف')->tel(),
                                TextInput::make('email')->label('البريد الإلكتروني')->email(),
                                TextInput::make('address')->label('العنوان'),
                                TextInput::make('city')->label('المدينة'),
                            ])->columns(2),
                    ])->columnSpanFull()
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المورد')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('الكود')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('النوع')
                    ->badge(),
                TextColumn::make('account.balance')
                    ->label('الرصيد الحالي')
                    ->money('egp')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('type')
                    ->label('نوع المورد')
                    ->options(SupplierType::class),
            ])
            ->actions([
                Action::make('statement')
                    ->label('كشف الحساب')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->visible(fn ($record) => $record->account_id !== null)
                    ->url(fn ($record): string => \App\Filament\Admin\Resources\Transactions\Pages\AccountStatement::getUrl(['accountId' => $record->account_id])),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppliers::route('/'),
        ];
    }
}
