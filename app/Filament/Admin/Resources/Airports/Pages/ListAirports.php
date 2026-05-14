<?php

namespace App\Filament\Admin\Resources\Airports\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\Airports\AirportResource;
use App\Services\Airports\AirportCatalogImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAirports extends ListRecords
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = AirportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importDefaultCatalog')
                ->label('استيراد الحزمة الافتراضية')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('استيراد مطارات من الملف')
                ->modalDescription('يُنشأ أو يُحدَّث كل مطار حسب كود IATA من الملف: database/data/airports_by_iata.php (يمكنك تعديل الملف ثم إعادة الاستيراد).')
                ->action(function (): void {
                    $path = database_path('data/airports_by_iata.php');
                    if (! is_file($path)) {
                        Notification::make()
                            ->title('الملف غير موجود')
                            ->body('تأكد من وجود database/data/airports_by_iata.php')
                            ->danger()
                            ->send();

                        return;
                    }

                    /** @var array<string, array<string, mixed>> $data */
                    $data = require $path;
                    $result = app(AirportCatalogImporter::class)->importKeyedByIata($data);

                    $body = "تمت معالجة {$result['imported']} مطاراً بنجاح.";
                    if ($result['errors'] !== []) {
                        $body .= ' — بعض السطور تُركت: '.implode('؛ ', array_slice($result['errors'], 0, 8));
                    }

                    Notification::make()
                        ->title('انتهى الاستيراد')
                        ->body($body)
                        ->success()
                        ->send();
                })
                ->after(fn () => $this->resetTable()),
            Action::make('importFromJson')
                ->label('استيراد من JSON')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->modalHeading('استيراد كائن JSON')
                ->modalDescription('المفتاح في الجذر هو كود IATA (مثل CAI). القيمة: كائن يحتوي الحقول نفسها كما في نموذج المطار (بدون إلزام بتكرار iata_code داخل القيمة).')
                ->form([
                    Textarea::make('payload')
                        ->label('JSON')
                        ->required()
                        ->rows(14)
                        ->helperText('مثال: {"DXB":{"icao_code":"OMDB","city_name_ar":"دبي","city_name_en":"Dubai",...}}'),
                ])
                ->action(function (array $data): void {
                    $decoded = json_decode($data['payload'], true);
                    if (! is_array($decoded)) {
                        Notification::make()
                            ->title('JSON غير صالح')
                            ->body('تأكد أن الحمولة عبارة عن كائن { ... } وليست مصفوفة.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $result = app(AirportCatalogImporter::class)->importFromJsonObject($decoded);

                    $body = "تمت معالجة {$result['imported']} مطاراً.";
                    if ($result['errors'] !== []) {
                        $body .= ' — '.implode('؛ ', array_slice($result['errors'], 0, 10));
                    }

                    Notification::make()
                        ->title('انتهى الاستيراد من JSON')
                        ->body($body)
                        ->success()
                        ->send();
                })
                ->after(fn () => $this->resetTable()),
            CreateAction::make()->modal(false),
        ];
    }
}
