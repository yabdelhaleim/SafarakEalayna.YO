<?php

namespace App\Filament\Admin\Resources\TripSupervisors;

use App\Filament\Admin\Resources\TripSupervisors\Pages\ManageTripSupervisors;
use App\Models\HajjUmra\TripSupervisor;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TripSupervisorResource extends Resource
{
    protected static ?string $model = TripSupervisor::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'الحج والعمرة';

    protected static ?string $navigationLabel = 'مشرفو الرحلات';
    protected static ?string $pluralLabel = 'مشرفو الرحلات';
    protected static ?string $modelLabel = 'مشرف رحلة';
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('full_name')->label('الاسم الكامل')->required()->maxLength(150),
            TextInput::make('phone')->label('الهاتف')->tel()->maxLength(30),
            TextInput::make('national_id')->label('الرقم القومي')->maxLength(30),
            Textarea::make('notes')->label('ملاحظات')->rows(3),
            Toggle::make('is_active')->label('مفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label('الاسم')->searchable(),
                TextColumn::make('phone')->label('الهاتف')->toggleable(),
                TextColumn::make('national_id')->label('الرقم القومي')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
                TextColumn::make('created_at')->label('أنشئ في')->dateTime('d/m/Y')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('مفعّل'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTripSupervisors::route('/'),
        ];
    }
}
