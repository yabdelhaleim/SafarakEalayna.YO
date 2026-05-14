<?php

namespace App\Filament\Resources\Base;
use BackedEnum;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;

abstract class BaseResource extends Resource
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 100;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([])
                     ->actions([
                         \Filament\Tables\Actions\ViewAction::make(),
                         \Filament\Tables\Actions\EditAction::make(),
                         \Filament\Tables\Actions\DeleteAction::make(),
                     ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecords::route('/index'),
            'create' => Pages\CreateRecord::route('/create'),
            'edit' => Pages\EditRecord::route('/{record}/edit'),
            'view' => Pages\ViewRecord::route('/{record}'),
        ];
    }
}