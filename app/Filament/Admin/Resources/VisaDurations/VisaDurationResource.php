<?php

namespace App\Filament\Admin\Resources\VisaDurations;

use App\Filament\Admin\Resources\VisaDurations\Pages\ManageVisaDurations;
use App\Models\HajjUmra\VisaDuration;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VisaDurationResource extends Resource
{
    protected static ?string $model = VisaDuration::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'التأشيرات';

    protected static ?string $navigationLabel = 'مدد التأشيرة';
    protected static ?string $pluralLabel = 'مدد التأشيرة';
    protected static ?string $modelLabel = 'مدة تأشيرة';
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('الكود')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(50)
                ->helperText('مثل: 1y_multiple, 6m_single, umrah ...'),

            TextInput::make('label_ar')->label('الاسم بالعربية')->required()->maxLength(150),
            TextInput::make('label_en')->label('الاسم بالإنجليزية')->maxLength(150),

            TextInput::make('months')
                ->label('عدد الأشهر')
                ->numeric()
                ->minValue(0)
                ->maxValue(120),

            Select::make('entry_type')
                ->label('نوع الدخول')
                ->options([
                    'single' => 'دخول واحد',
                    'multiple' => 'دخول متعدد',
                    'triple' => 'دخول ثلاثي',
                ]),

            TextInput::make('sort_order')->label('الترتيب')->numeric()->default(0),

            Toggle::make('is_active')->label('مفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label('الكود')->searchable(),
                TextColumn::make('label_ar')->label('الاسم العربي')->searchable(),
                TextColumn::make('months')->label('الأشهر')->sortable()->toggleable(),
                TextColumn::make('entry_type')->label('نوع الدخول')->badge()
                    ->formatStateUsing(fn (?string $s) => match ($s) {                        'single' => 'دخول واحد',                        'multiple' => 'دخول متعدد',                        'triple' => 'دخول ثلاثي',                        default => '-',
                    }),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('مفعّل'),
                SelectFilter::make('entry_type')
                    ->label('نوع الدخول')
                    ->options([
                        'single' => 'دخول واحد',
                        'multiple' => 'دخول متعدد',
                        'triple' => 'دخول ثلاثي',
                    ]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVisaDurations::route('/'),
        ];
    }
}
