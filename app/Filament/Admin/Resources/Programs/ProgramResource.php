<?php

namespace App\Filament\Admin\Resources\Programs;

use App\Filament\Admin\Resources\Programs\Pages\CreateProgram;
use App\Filament\Admin\Resources\Programs\Pages\EditProgram;
use App\Filament\Admin\Resources\Programs\Pages\ListPrograms;
use App\Filament\Admin\Resources\Programs\Pages\ViewProgram;
use App\Filament\Admin\Resources\Programs\Widgets\ProgramProfitability;
use App\Models\HajjUmra\AccommodationType;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\TripSupervisor;
use App\Models\Program;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProgramResource extends Resource
{
    protected static ?string $model = Program::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'الحج والعمرة';

    protected static ?string $navigationLabel = 'برامج الحج والعمرة';

    protected static ?string $pluralLabel = 'البرامج';

    protected static ?string $modelLabel = 'برنامج';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'program_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات البرنامج الأساسية')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('program_name')->label('اسم البرنامج')->required()->maxLength(150),
                        Select::make('program_type')
                            ->label('نوع البرنامج')
                            ->options(['hajj' => 'حج', 'umra' => 'عمرة'])
                            ->required(),
                        TextInput::make('season')->label('الموسم (مثل ١٤٤٧)')->maxLength(50),
                        TextInput::make('total_nights')->label('إجمالي الليالي')->numeric()->required(),
                    ]),
                ])->columnSpanFull(),

            Section::make('الفنادق والإقامة')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('mecca_hotel_id')
                            ->label('فندق مكة (من القائمة)')
                            ->relationship('meccaHotel', 'name', fn ($query) => $query->where('city', 'like', '%مكة%'))
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')->label('اسم الفندق')->required(),
                                TextInput::make('city')->label('المدينة')->default('مكة'),
                            ]),
                        TextInput::make('mecca_hotel_name')->label('اسم فندق مكة (نص اختياري)')->maxLength(150),
                        TextInput::make('mecca_nights')->label('عدد ليالي مكة')->numeric()->minValue(0),

                        Select::make('medina_hotel_id')
                            ->label('فندق المدينة (من القائمة)')
                            ->relationship('medinaHotel', 'name', fn ($query) => $query->where('city', 'like', '%مدينة%'))
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')->label('اسم الفندق')->required(),
                                TextInput::make('city')->label('المدينة')->default('المدينة'),
                            ]),
                        TextInput::make('medina_hotel_name')->label('اسم فندق المدينة (نص اختياري)')->maxLength(150),
                        TextInput::make('medina_nights')->label('عدد ليالي المدينة')->numeric()->minValue(0),

                        Select::make('accommodation_type_id')
                            ->label('نوع التسكين')
                            ->options(fn () => AccommodationType::active()->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                            ->searchable(),
                    ]),
                ])->columnSpanFull(),

            Section::make('السفر والتشغيل')
                ->schema([
                    Grid::make(2)->schema([
                        DatePicker::make('departure_date')->label('تاريخ السفر')->native(false),
                        DatePicker::make('return_date')->label('تاريخ العودة')->native(false),
                        TextInput::make('airline')->label('شركة الطيران')->maxLength(100),
                        TextInput::make('departure_point')->label('نقطة الانطلاق')->maxLength(100),
                        Select::make('executing_company_id')
                            ->label('الشركة المنفذة')
                            ->options(fn () => HajjUmraExecutingCompany::active()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        Select::make('trip_supervisor_id')
                            ->label('مشرف الرحلة')
                            ->options(fn () => TripSupervisor::active()->orderBy('full_name')->pluck('full_name', 'id')->all())
                            ->searchable(),
                    ]),
                ])->columnSpanFull(),

            Section::make('التسعير الافتراضي وحالة البرنامج')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('default_purchase_price')->label('تكلفة افتراضية')->numeric()->prefix('ج.م'),
                        TextInput::make('default_selling_price')->label('سعر بيع افتراضي')->numeric()->prefix('ج.م'),
                        Select::make('booking_status')
                            ->label('حالة الحجز للبرنامج')
                            ->options([
                                'open' => 'مفتوح',
                                'closed' => 'مغلق',
                                'success' => 'ناجح',
                                'cancelled' => 'ملغي',
                            ])->default('open'),
                        Toggle::make('is_active')->label('مفعّل (يظهر في الواجهة)')->default(true),
                    ]),
                ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('departure_date', 'desc')
            ->columns([
                TextColumn::make('program_name')->label('البرنامج')->searchable()->sortable(),
                TextColumn::make('program_type')->label('النوع')->badge()
                    ->formatStateUsing(fn (?string $s) => $s === 'hajj' ? 'حج' : ($s === 'umra' ? 'عمرة' : '-'))
                    ->color(fn (?string $s) => $s === 'hajj' ? 'success' : 'info'),
                TextColumn::make('total_nights')->label('الليالي')->sortable(),
                TextColumn::make('departure_date')->label('السفر')->date('Y-m-d')->sortable(),
                TextColumn::make('return_date')->label('العودة')->date('Y-m-d')->toggleable(),
                TextColumn::make('executingCompany.name')->label('الشركة المنفذة')->toggleable(),
                TextColumn::make('tripSupervisor.full_name')->label('المشرف')->toggleable(),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->filters([
                SelectFilter::make('program_type')->label('النوع')->options(['hajj' => 'حج', 'umra' => 'عمرة']),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrograms::route('/'),
            'create' => CreateProgram::route('/create'),
            'view' => ViewProgram::route('/{record}'),
            'edit' => EditProgram::route('/{record}/edit'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            ProgramProfitability::class,
        ];
    }
}
