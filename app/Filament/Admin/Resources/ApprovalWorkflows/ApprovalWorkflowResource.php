<?php

namespace App\Filament\Admin\Resources\ApprovalWorkflows;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalWorkflow;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ApprovalWorkflowResource extends Resource
{
    protected static ?string $model = ApprovalWorkflow::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?string $navigationLabel = 'سير العمل';
    protected static ?string $pluralLabel = 'سير العمل';
    protected static ?string $modelLabel = 'سير عمل';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('approvable_type')
                    ->label('نوع النموذج')
                    ->maxLength(255),

                TextInput::make('approvable_id')
                    ->label('معرف النموذج')
                    ->numeric(),

                Select::make('status')
                    ->label('الحالة')
                    ->options(ApprovalStatus::class)
                    ->required(),

                Select::make('action_type')
                    ->label('نوع الإجراء')
                    ->options([
                        'create' => 'إنشاء',
                        'update' => 'تعديل',
                        'delete' => 'حذف',
                        'approve' => 'اعتماد',
                        'reject' => 'رفض',
                    ]),

                Select::make('requested_by')
                    ->label('الموظف المسؤول')
                    ->relationship('requestedBy', 'name')
                    ->searchable(),

                Select::make('approved_by')
                    ->label('المعتمد')
                    ->relationship('approvedBy', 'name')
                    ->searchable(),

                DatePicker::make('approved_at')
                    ->label('تاريخ الاعتماد'),

                TextInput::make('rejection_reason')
                    ->label('سبب الرفض')
                    ->maxLength(500),

                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->rows(3),
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

                TextColumn::make('approvable_type', 'نوع النموذج')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('approvable_id', 'معرف النموذج')
                    ->toggleable(),

                BadgeColumn::make('status', 'الحالة')
                    ->colors([
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                    ])
                    ->formatStateUsing(fn ($state) => match($state) {
                        'pending' => 'قيد الانتظار',
                        'approved' => 'معتمد',
                        'rejected' => 'مرفوض',
                        default => $state,
                    }),

                BadgeColumn::make('action_type', 'الإجراء')
                    ->colors([
                        'create' => 'info',
                        'update' => 'warning',
                        'delete' => 'danger',
                        'approve' => 'success',
                        'reject' => 'danger',
                    ]),

                TextColumn::make('requestedBy.name', 'الباحث')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('approvedBy.name', 'المعتمد')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('approved_at', 'تاريخ الاعتماد')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at', 'تاريخ الإضافة')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status', 'الحالة')
                    ->options(ApprovalStatus::class),

                SelectFilter::make('action_type', 'الإجراء')
                    ->options([
                        'create' => 'إنشاء',
                        'update' => 'تعديل',
                        'delete' => 'حذف',
                        'approve' => 'اعتماد',
                        'reject' => 'رفض',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\CreateAction::make(),
                BulkActionGroup::make([
                    \Filament\Actions\ExportBulkAction::make(),
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageApprovalWorkflows::route('/'),
        ];
    }
}
