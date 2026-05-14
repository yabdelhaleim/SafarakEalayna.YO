<?php

namespace App\Filament\Admin\Resources\FlightTreasuries\Schemas;

use Filament\Schemas\Schema;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

class FlightTreasuryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات الخزينة الأساسية')
                    ->description('تحديد نوع الحساب وكيفية الاحتفاظ بالأموال.')
                    ->icon(Heroicon::OutlinedBuildingLibrary)
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم الخزينة / الحساب')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: خزينة طيران الكاش، حساب بنك مصر، محفظة فودافون الرئيسية'),

                        Hidden::make('type')->default(AccountType::Cashbox->value),
                    ])->columns(1),

                Section::make('الرصيد والعملة')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->schema([
                        Select::make('currency')
                            ->label('العملة')
                            ->options([
                                'EGP' => 'جنيه مصري (EGP)',
                                'SAR' => 'ريال سعودي (SAR)',
                                'USD' => 'دولار أمريكي (USD)',
                            ])
                            ->default('EGP')
                            ->required()
                            ->native(false),

                        TextInput::make('balance')
                            ->label('الرصيد الافتتاحي')
                            ->numeric()
                            ->default(0)
                            ->step(0.01)
                            ->disabledOn('edit')
                            ->helperText('لا يمكن تعديل الرصيد يدوياً بعد الإنشاء، يتم تغييره فقط من خلال المعاملات.'),
                    ])->columns(2),

                Section::make('خيارات إضافية')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('حساب نشط')
                            ->default(true),

                        Toggle::make('is_module_vault')
                            ->label('الخزينة الرئيسية للطيران')
                            ->helperText('فعّلها إذا كانت هذه الخزينة هي التي يتم الاعتماد عليها كخزينة رسمية للموديول.')
                            ->default(false),

                        Textarea::make('notes')
                            ->label('ملاحظات (رقم الحساب، اسم الفرع...)')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),

                Hidden::make('module_type')->default('flights'),
                Hidden::make('owner_type')->default('owner'),
                Hidden::make('created_by')->default(fn () => auth()->id()),
            ]);
    }
}
