<?php

namespace App\Filament\Admin\Resources\HajjUmraExecutingCompanies;

use App\Filament\Admin\Resources\HajjUmraExecutingCompanies\Pages\ManageHajjUmraExecutingCompanies;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HajjUmraExecutingCompanyResource extends Resource
{
    protected static ?string $model = HajjUmraExecutingCompany::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|\UnitEnum|null $navigationGroup = 'الحج والعمرة';

    protected static ?string $navigationLabel = 'الشركات المنفذة';
    protected static ?string $pluralLabel = 'الشركات المنفذة';
    protected static ?string $modelLabel = 'شركة منفذة';
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('اسم الشركة')->required()->maxLength(150),
            TextInput::make('license_number')->label('رقم الترخيص')->maxLength(100),
            TextInput::make('phone')->label('الهاتف')->tel()->maxLength(30),
            \Filament\Forms\Components\Select::make('account_id')
                ->label('الحساب المالي المرتبط')
                ->relationship('account', 'name')
                ->searchable()
                ->preload(),
            Textarea::make('notes')->label('ملاحظات')->rows(3),
            Toggle::make('is_active')->label('مفعّلة')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الاسم')->searchable(),
            TextColumn::make('account.balance')
                ->label('الرصيد')
                ->money('egp')
                ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),
            TextColumn::make('license_number')->label('رقم الترخيص')->toggleable(),
            TextColumn::make('phone')->label('الهاتف')->toggleable(),
            IconColumn::make('is_active')->label('مفعّلة')->boolean(),
        ])
        ->recordActions([
            Action::make('statement')
                ->label('كشف الحساب')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->visible(fn ($record) => $record->account_id !== null)
                ->url(fn ($record): string => \App\Filament\Admin\Resources\Transactions\Pages\AccountStatement::getUrl(['accountId' => $record->account_id])),
            Action::make('advances')
                ->label('سحب/سداد')
                ->icon('heroicon-o-arrows-right-left')
                ->color('warning')
                ->visible(fn ($record) => $record->account_id !== null)
                ->url(fn ($record): string => \App\Filament\Admin\Pages\HajjUmraExecutingCompanyAdvances::getUrl(['companyId' => $record->id])),
            EditAction::make()
        ])
        ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHajjUmraExecutingCompanies::route('/'),
        ];
    }
}
