<?php

namespace App\Filament\Admin\Resources\AuditLogs;

use App\Models\AuditLog;
use BackedEnum;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static string|\UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?string $navigationLabel = 'سجل العمليات';
    protected static ?string $pluralLabel = 'سجل العمليات';
    protected static ?string $modelLabel = 'سجل عملية';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('user_id')
                    ->label('المستخدم')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required(),

                TextInput::make('action')
                    ->label('الإجراء')
                    ->maxLength(255)
                    ->required(),

                TextInput::make('model_type')
                    ->label('نوع النموذج')
                    ->maxLength(255),

                TextInput::make('model_id')
                    ->label('معرف النموذج')
                    ->numeric(),

                Textarea::make('old_values')
                    ->label('القيم القديمة')
                    ->rows(3)
                    ->disabled(),

                Textarea::make('new_values')
                    ->label('القيم الجديدة')
                    ->rows(3)
                    ->disabled(),

                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->rows(3),

                TextInput::make('ip_address')
                    ->label('عنوان IP')
                    ->maxLength(45)
                    ->disabled(),

                TextInput::make('user_agent')
                    ->label('User Agent')
                    ->maxLength(500)
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user.name', 'المستخدم')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('action', 'الإجراء')
                    ->badge()
                    ->color('info'),

                TextColumn::make('model_type', 'النموذج')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('model_id', 'المعرف')
                    ->toggleable(),

                TextColumn::make('created_at', 'التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action', 'الإجراء')
                    ->searchable(),

                SelectFilter::make('model_type', 'نوع النموذج')
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                \Filament\Tables\Actions\ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \Filament\Tables\Actions\ExportBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAuditLogs::route('/'),
        ];
    }
}
