<?php

namespace App\Filament\Admin\Resources\VisaAgents;

use App\Filament\Admin\Resources\VisaAgents\Pages\ManageVisaAgents;
use App\Filament\Clusters\VisaCluster;
use App\Models\HajjUmra\VisaAgent;
use App\Models\VisaBooking;
use BackedEnum;
use Filament\Tables\Actions\Action;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VisaAgentResource extends Resource
{
    protected static ?string $model = VisaAgent::class;

    protected static ?string $cluster = VisaCluster::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationLabel = 'الوكلاء المنفذون';
    protected static ?string $pluralLabel = 'الوكلاء المنفذون';
    protected static ?string $modelLabel = 'وكيل تأشيرات';
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('company_name')->label('اسم الشركة / الوكيل')->required()->maxLength(150),
            TextInput::make('contact_person')->label('شخص الاتصال')->maxLength(150),
            TextInput::make('phone')->label('الهاتف')->tel()->maxLength(30),
            TextInput::make('email')->label('البريد الإلكتروني')->email()->maxLength(150),
            TextInput::make('country')->label('الدولة')->maxLength(100),
            \Filament\Forms\Components\Select::make('account_id')
                ->label('الحساب المالي المرتبط')
                ->relationship('account', 'name')
                ->searchable()
                ->preload(),
            Textarea::make('notes')->label('ملاحظات')->rows(3),
            Toggle::make('is_active')->label('مفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('company_name')->label('الشركة')->searchable(),
            TextColumn::make('contact_person')->label('شخص الاتصال')->searchable()->toggleable(),
            TextColumn::make('account.name')->label('الحساب المالي')->toggleable(),
            TextColumn::make('account.balance')
                ->label('الرصيد')
                ->money('egp')
                ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),
            TextColumn::make('phone')->label('الهاتف')->toggleable(),
            TextColumn::make('email')->label('البريد الإلكتروني')->toggleable(),
            TextColumn::make('country')->label('الدولة')->toggleable(),
            IconColumn::make('is_active')->label('مفعّل')->boolean(),
        ])
        ->filters([
            TernaryFilter::make('is_active')->label('مفعّل'),
            SelectFilter::make('country')
                ->label('الدولة')
                ->options(fn (): array => VisaAgent::query()
                    ->whereNotNull('country')
                    ->where('country', '!=', '')
                    ->distinct()
                    ->orderBy('country')
                    ->pluck('country', 'country')
                    ->all()),
        ])
        ->recordActions([
            Action::make('statement')
                ->label('كشف الحساب')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->visible(fn ($record) => $record->account_id !== null)
                ->url(fn ($record): string => \App\Filament\Admin\Pages\AccountStatement::getUrl(['accountId' => $record->account_id])),
            Action::make('debts')
                ->label('مديونيات (الآجل)')
                ->icon('heroicon-o-exclamation-circle')
                ->color('warning')
                ->visible(fn ($record): bool => self::hasPendingDebts($record))
                ->url(fn ($record): string => \App\Filament\Admin\Pages\VisaAgentDebtStatement::getUrl(['agentId' => $record->id])),
            EditAction::make(),
            DeleteAction::make()
        ])
        ->toolbarActions([
            BulkActionGroup::make([
                DeleteBulkAction::make(),
            ]),
        ]);
    }

    private static function hasPendingDebts(VisaAgent $record): bool
    {
        $totalBookings = VisaBooking::whereHas('visaDetail', function($q) use ($record) {
            return $q->where('visa_agent_id', $record->id);
        })->count();

        $unpaidBookings = VisaBooking::whereHas('visaDetail', function($q) use ($record) {
            return $q->where('visa_agent_id', $record->id);
        })->where(function($q) {
            $q->where('status', 'pending')
              ->orWhere('status', 'processing');
        })->count();

        return $unpaidBookings > 0;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVisaAgents::route('/'),
        ];
    }
}
