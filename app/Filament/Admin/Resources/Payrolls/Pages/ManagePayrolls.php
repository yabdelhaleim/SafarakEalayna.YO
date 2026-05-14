<?php

namespace App\Filament\Admin\Resources\Payrolls\Pages;

use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePayrolls extends ManageRecords
{
    protected static string $resource = PayrollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('إضافة يدوي'),
            \Filament\Actions\Action::make('generate')
                ->label('توليد مسودة الرواتب')
                ->color('warning')
                ->icon('heroicon-o-cpu-chip')
                ->action(function () {
                    $employees = \App\Models\Employee::where('employment_status', 'active')->get();
                    $month = now()->month;
                    $year = now()->year;

                    foreach ($employees as $employee) {
                        \App\Models\Payroll::firstOrCreate([
                            'employee_id' => $employee->id,
                            'month' => $month,
                            'year' => $year,
                        ], [
                            'base_salary' => $employee->salary,
                            'net_salary' => $employee->salary,
                            'status' => 'draft',
                        ]);
                    }

                    \Filament\Notifications\Notification::make()
                        ->title('تم توليد الرواتب بنجاح')
                        ->success()
                        ->send();
                }),
        ];
    }
}
